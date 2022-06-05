<?php

namespace Ekok\One;

class Kernel implements \ArrayAccess
{
    const ENV_DEV = 'dev';
    const ENV_PROD = 'prod';
    const ENV_TEST = 'test';
    const CALL_EXPR = '/^(.+)?\s*([:@])\s*(.+)$/';
    const CACHE_FOLDER = 'folder';

    protected $hive = array();
    protected $binds = array();
    protected $instances = array();
    protected $events = array();
    protected $sorted = array();

    public function __construct(array $context = null)
    {
        $this->initialize($context ?? array());
    }

    public function context(): array
    {
        return $this->hive;
    }

    public function isDev(): bool
    {
        return static::ENV_DEV === $this->hive['APP_ENV'];
    }

    public function isProduction(): bool
    {
        return static::ENV_PROD === $this->hive['APP_ENV'];
    }

    public function isTest(): bool
    {
        return static::ENV_TEST === $this->hive['APP_ENV'];
    }

    public function isDebug(): bool
    {
        return !!$this->hive['DEBUG'];
    }

    public function env(string ...$checks): string|bool
    {
        $env = $this->hive['APP_ENV'];

        if ($checks) {
            return static::some(
                $checks,
                static fn (string $check) => 0 === strcasecmp($check, $env),
            );
        }

        return $env;
    }

    public function config(string|null ...$files): static
    {
        array_walk(
            $files,
            function (string|null $file) {
                if (!$file || !file_exists($file)) {
                    return;
                }

                preg_match_all(
                    '/(?<=^|\n)(?:' .
                        '\[(?<section>.+?)\]|' .
                        '(?<key>[^\h\r\n;].*?)\h*=\h*' .
                        '(?<val>(?:\\\\\h*\r?\n|.+?)*)' .
                    ')(?=\r?\n|$)/',
                    static::read($file),
                    $matches,
                    PREG_SET_ORDER,
                );
                $cmd = null;
                $hok = null;

                array_walk(
                    $matches,
                    function (array $match) use (&$sec, &$cmd, &$hok) {
                        if ($match['section']) {
                            // prepare section with hooks
                            if (
                                preg_match(
                                    '/^(?<sec>[^:]+)(?:\:(?<fun>.+))?/',
                                    $match['section'],
                                    $hok,
                                )
                                && 0 !== strcasecmp('globals', $hok['sec'])
                                && !preg_match(static::CALL_EXPR, $hok['sec'])
                            ) {
                                $cmd = null;
                                $hok += array(
                                    'fun' => null,
                                    'add' => $hok['sec'] . '.',
                                );

                                $this->devoid($hok['sec'], null);
                            } else {
                                $hok = null;

                                preg_match(static::CALL_EXPR, $match['section'], $cmd);
                            }

                            return;
                        }

                        $key = $this->configReplace($match['key'], $replaced);
                        $val = array_map(
                            function ($val) {
                                $val = $this->configReplace(
                                    trim(preg_replace('/\\\\"/', '"', $val)),
                                    $replaced,
                                );

                                return $replaced ? $val : $this->cast($val);
                            },
                            // Mark quoted strings with 0x00 whitespace
                            str_getcsv(
                                preg_replace(
                                    '/(?<!\\\\)(")(.*?)\1/',
                                    "\\1\x00\\2\\1",
                                    trim(
                                        preg_replace(
                                            '/\\\\\h*(\r?\n)/',
                                            '\1',
                                            $match['val'],
                                        ),
                                    ),
                                ),
                            ),
                        );

                        if ($cmd) {
                            $this->call(
                                $cmd[0],
                                $replaced ? $key : $this->cast($key),
                                ...$val,
                            );
                        } else {
                            if ($hok['fun'] ?? null) {
                                $val = $this->callArguments($hok['fun'], $val);
                            } elseif (count($val) === 1) {
                                $val = $val[0];
                            }

                            $this->set(($hok['add'] ?? null) . $key, $val);
                        }
                    },
                );
            },
        );

        return $this;
    }

    protected function configReplace($value, bool &$replaced = null)
    {
        $replaced = false;

        if (
            is_string($value)
            && preg_match(
                '/^(.+)?\{([@\w.\\\]+)\}(.+)?$/',
                $value,
                $match,
            )
        ) {
            list($search, $prefix, $keyword, $suffix) = $match + array(3 => null);

            $update = match (true) {
                defined($keyword) => constant($keyword),
                '@' === $keyword[0] => $this->call($keyword),
                default => $this->get($keyword),
            };
            $replaced = true;

            return (
                ($prefix || $suffix) ?
                    $prefix . str_replace($search, $update, $value) . $suffix :
                    $update
            );
        }

        return $value;
    }

    public function has($key): bool
    {
        return $this->ref($key, false, $ref) || $ref['found'];
    }

    public function &get($key)
    {
        $var = &$this->ref($key);

        return $var;
    }

    public function set($key, $value): static
    {
        $var = &$this->ref($key, true, $ref);
        $var = $value;

        return $this->trigger(
            'contextset',
            $ref['parts'][0],
            $value,
            ...array_slice($ref['parts'], 1),
        );
    }

    public function remove($key): static
    {
        $this->unref($key, $parts);

        return $this->trigger('contextremove', ...$parts);
    }

    public function devoid($key, $value): static
    {
        $this->has($key) || $this->set($key, $value);

        return $this;
    }

    public function allHas(...$keys): bool
    {
        return static::some($keys, array($this, 'has'));
    }

    public function allGet(array $keys): array
    {
        return static::reduce(
            $keys,
            fn (array $all, $key, $alias) => $all + array(
                is_numeric($alias) ? $key : $alias => $this->get($key),
            ),
            array(),
        );
    }

    public function allSet(array $values, string $prefix = null): static
    {
        array_walk(
            $values,
            fn ($value, $key) => $this->set($prefix . $key, $value),
        );

        return $this;
    }

    public function allRemove(...$keys): static
    {
        array_walk($keys, fn ($key) => $this->remove($key));

        return $this;
    }

    public function merge($key, array $values): static
    {
        return $this->set($key, array_merge((array) $this->get($key), $values));
    }

    public function push($key, ...$values): static
    {
        return $this->set($key, array_merge((array) $this->get($key), $values));
    }

    public function pop($key)
    {
        $var = &$this->get($key);

        if (!is_array($var)) {
            $var = (array) $var;
        }

        return array_pop($var);
    }

    public function unshift($key, ...$values): static
    {
        return $this->set($key, array_merge($values, (array) $this->get($key)));
    }

    public function shift($key)
    {
        $var = &$this->get($key);

        if (!is_array($var)) {
            $var = (array) $var;
        }

        return array_shift($var);
    }

    public function cacheHas(string $key): bool
    {
        $this->cacheGet($key, $ref);

        return $ref['exists'];
    }

    public function cacheGet(string $key, array &$ref = null)
    {
        $cache = match ($this->hive['CACHE_DRIVER']) {
            static::CACHE_FOLDER => (
                file_exists($file = $this->cacheFile($key))
                && ($cache = file_get_contents($file)) ? $cache : null
            ),
            default => null,
        };

        list($value, $time) = (
            $cache
            && ($pairs = static::unserialize($cache))
            && isset($pairs[0], $pairs[1])
        ) ? $pairs : array(null, -1);

        $ref = array(
            'time' => $time,
            'exists' => $time >= 0,
            'expired' => $time > 0 && $time < time(),
        );

        if ($ref['expired']) {
            $value = null;
        }

        return $value;
    }

    public function cacheSet(
        string $key,
        $value,
        int $ttl = 0,
        bool &$saved = null,
    ): static {
        $saved = false;
        $cache = array($value, $ttl ? time() + $ttl : 0);

        list($save, $pos, $args) = match ($this->hive['CACHE_DRIVER']) {
            static::CACHE_FOLDER => array(
                'file_put_contents',
                1,
                array($this->cacheFile($key, true)),
            ),
            default => array(null, null, null),
        };

        if ($save) {
            $saved = (bool) $save(...array_replace($args, array(
                $pos => static::serialize($cache)
            )));
        }

        return $this;
    }

    public function cacheRemove(string $key, bool &$removed = null): static
    {
        $removed = false;

        list($remove, $args) = match ($this->hive['CACHE_DRIVER']) {
            static::CACHE_FOLDER => array(
                ($file = $this->cacheFile($key)) && file_exists($file) ? 'unlink' : null,
                array($file),
            ),
            default => array(null, null),
        };

        if ($remove) {
            $removed = $remove(...$args);
        }

        return $this;
    }

    public function cacheClear(
        string $prefix = null,
        string $suffix = null,
        int &$removed = null,
    ): static {
        $removed = 0;
        $calls = match ($this->hive['CACHE_DRIVER']) {
            static::CACHE_FOLDER => array(
                glob(
                    $this->hive['CACHE_REF'] . '/' .
                    $prefix . '*' . $suffix . '.cache',
                ),
                array('file_exists'),
                array('unlink'),
            ),
            default => null,
        };

        if ($calls) {
            $args = array_shift($calls);

            array_walk(
                $args,
                function ($arg) use ($calls, &$removed) {
                    $removed += (int) array_reduce(
                        $calls,
                        function ($cont, $args) use ($arg) {
                            if ($cont) {
                                $call = array_shift($args);
                                $pos = array_shift($args) ?? 0;
                                $args[$pos] = $arg;

                                $cont = $call(...$args);
                            }

                            return $cont;
                        },
                        true,
                    );
                },
            );
        }

        return $this;
    }

    protected function cacheFile(string $key, bool $create = false): string|null
    {
        $dir = $this->hive['CACHE_REF'];

        if ($create) {
            static::mkdir($dir, true, 0755);
        }

        return $dir . '/' . $key . '.cache';
    }

    public function singleton(
        string $abstract,
        callable|object|string $fn = null,
    ): static {
        return $this->bind($abstract, $fn, true);
    }

    public function bind(
        string $abstract,
        callable|object|string $fn = null,
        bool $single = false,
    ): static {
        if (is_object($fn) && !$fn instanceof \Closure) {
            $this->instances[$abstract] = $fn;
        } else {
            $this->binds[$abstract] = array(
                match (true) {
                    null === $fn => static fn () => new $abstract(),
                    is_string($fn) => static fn () => new $fn(),
                    default => $fn,
                },
                $single,
            );
        }

        return $this;
    }

    public function make(string $abstract, ...$arguments)
    {
        if (!$arguments && static::class === $abstract) {
            return $this;
        }

        $instance = $this->instances[$abstract] ?? null;

        if (!$instance || $arguments) {
            list($bootstrap, $single) = $this->binds[$abstract] ?? array(
                $this->makeBootstrap($abstract),
                false,
            );

            if (!isset($this->binds[$abstract])) {
                $this->bind($abstract, $bootstrap);
            }

            $instance = $this->call($bootstrap, ...$arguments);

            if ($single) {
                $this->instances[$abstract] = $instance;
            }
        }

        return $instance;
    }

    public function call(callable|string $fn, ...$arguments)
    {
        $call = $fn;

        if (!is_callable($call)) {
            $call = $this->callEnsure($fn, true);
        }

        $params = $this->makeParams($this->callRef($call));

        return $call(...$params(...$arguments));
    }

    public function callArguments(callable|string $fn, array $arguments = null)
    {
        return $this->call($fn, ...array_values($arguments ?? array()));
    }

    public function callEnsure(string $fn, bool $throw = false): callable|bool
    {
        $call = $fn;

        if (preg_match(static::CALL_EXPR, $fn, $match)) {
            list(, $class, $mode, $method) = $match;

            $instance = '@' === $mode;
            $call = array(
                $class ? (
                    $instance ? $this->make($class) : $class
                ) : (
                    $instance ? $this : static::class
                ),
                $method,
            );
        }

        if (($failed = !is_callable($call)) && $throw) {
            throw new \InvalidArgumentException(sprintf('Invalid call: %s', $fn));
        }

        return $failed ? false : $call;
    }

    protected function callRef(callable $fn): \ReflectionFunctionAbstract
    {
        if (is_array($fn)) {
            return new \ReflectionMethod(...$fn);
        }

        return new \ReflectionFunction($fn);
    }

    protected function makeBootstrap(string $abstract): \Closure
    {
        $ref = new \ReflectionClass($abstract);
        $constructor = $ref->getConstructor();
        $params = $constructor ? $this->makeParams($constructor) : static fn () => array();

        return match (true) {
            !$ref->isInstantiable() => static fn () => throw new \InvalidArgumentException(
                sprintf('Cannot instantiate: %s', $abstract),
            ),
            null === $constructor => static fn () => new $abstract,
            default => static fn (...$arguments) => new $abstract(...$params(...$arguments)),
        };
    }

    protected function makeParams(\ReflectionFunctionAbstract $ref): \Closure
    {
        return function (...$arguments) use ($ref) {
            $rest = $arguments;
            $result = array();

            foreach ($ref->getParameters() as $param) {
                $type = $param->getType();
                $class = null;

                if ($param->isVariadic()) {
                    array_push($result, ...array_splice($rest, 0));
                } elseif ($type && null !== ($pos = $this->paramTypeMatch($type, $rest, $class))) {
                    $result[] = array_splice($rest, $pos, 1)[0];
                } elseif ($param->isDefaultValueAvailable()) {
                    $result[] = $param->getDefaultValue();
                } elseif ($class) {
                    $result[] = $this->make($class);
                } elseif ($rest) {
                    $result[] = array_shift($rest);
                } elseif ($param->allowsNull()) {
                    $result[] = null;
                } else {
                    break;
                }
            }

            array_push($result, ...$rest);

            return $result;
        };
    }

    protected function paramTypeMatch(\ReflectionType $type, array $args, string &$class = null): int|null
    {
        $types = array();

        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $types = $type->getTypes();
        } elseif ($type instanceof \ReflectionNamedType) {
            $types = array($type);
        }

        $pos = static::some(
            $args,
            static fn ($arg) => static::some(
                $types,
                static fn (\ReflectionNamedType $type) => (
                    ($name = $type->getName())
                    && (
                        ($type->isBuiltin() && call_user_func('is_' . $name, $arg))
                        || $arg instanceof $name
                    )
                ),
            ),
            $found,
        ) ? $found['key'] : null;
        $class = null === $pos ? (
            static::some(
                $types,
                static fn (\ReflectionNamedType $type) => !$type->isBuiltin(),
                $found,
            ) ? $found['value']->getName() : null
        ) : null;

        return $pos;
    }

    public function listen(
        string $eventName,
        callable|string $fn,
        int $priority = null,
        bool $once = null,
        string $id = null,
    ): static {
        $event = array($fn, $once ?? false, $priority ?? -1);

        if ($id) {
            $this->events[$eventName][$id] = $event;
        } else {
            $this->events[$eventName][] = $event;
        }

        unset($this->sorted[$eventName]);

        return $this;
    }

    public function on(
        string $eventName,
        callable|string $fn,
        int $priority = null,
        string $id = null,
    ): static {
        return $this->listen($eventName, $fn, $priority, false, $id);
    }

    public function one(
        string $eventName,
        callable|string $fn,
        int $priority = null,
        string $id = null,
    ): static {
        return $this->listen($eventName, $fn, $priority, true, $id);
    }

    public function off(string $eventName, string|int $id = null): static
    {
        if (null === $id) {
            unset($this->events[$eventName]);
            unset($this->sorted[$eventName]);
        } else {
            unset($this->events[$eventName][$id]);
            unset($this->sorted[$eventName][$id]);
        }

        return $this;
    }

    public function dispatch(
        EventInterface $event,
        string $name = null,
        bool $once = false,
    ): static {
        $eventName = $name ?? $event->name() ?? get_class($event);
        $handlers = $this->eventHandlers($eventName);

        if ($once) {
            $this->off($eventName);
        }

        static::some(
            $handlers,
            function (array $handler, $id) use ($eventName, $once, $event) {
                if ($event->isPropagationStopped()) {
                    return true;
                }

                list($fn, $one) = $handler;

                $this->call($fn, $event);

                if ($one && !$once) {
                    $this->off($eventName, $id);
                }

                return $event->isPropagationStopped();
            },
        );

        return $this;
    }

    protected function eventHandlers(string $eventName): array
    {
        $sorted = &$this->sorted[$eventName];

        if (null === $sorted) {
            $sorted = $this->events[$eventName] ?? array();

            uasort(
                $sorted,
                static fn (array $a, array $b) => end($b) <=> end($a),
            );
        }

        return $sorted;
    }

    public function &ref($key, bool $add = true, array &$ref = null)
    {
        $this->trigger('contextprepare', ...($parts = static::parts($key)));

        if ($add) {
            $var = &$this->hive;
        } else {
            $var = $this->hive;
        }

        $ref = array('found' => false, 'parts' => $parts);

        if (!isset($parts[1])) {
            $ref['found'] = isset($var[$parts[0]]);
            $var = &$var[$parts[0]];

            return $var;
        }

        foreach ($parts as $part) {
            $get = null;
            $found = false;

            if (null === $var || is_scalar($var)) {
                $var = array();
            }

            if (
                is_array($var)
                && (
                    ($found = isset($var[$part]) || array_key_exists($part, $var))
                    || $add
                )
            ) {
                $var = &$var[$part];
                $ref['found'] = $found;
            } elseif (
                is_object($var)
                && (
                    (
                        method_exists($var, $get = 'get' . $part)
                        || method_exists($var, $get = 'is' . $part)
                        || method_exists($var, $get = 'get')
                        || method_exists($var, $get = 'offsetGet')
                        || method_exists($var, $get = '__get')
                        || null === ($get = null)
                    )
                    || $add
                )
            ) {
                if ($get) {
                    list($check, $args) = match ($get) {
                        'offsetGet' => array('offsetExists'),
                        '__get' => array('__isset'),
                        'get' => array('has'),
                        default => array('has' . $part, array()),
                    } + array(null, array($part));
                    $found = method_exists($var, $check) && $var->$check(...$args);

                    if (static::refExec('returnsReference', $get, $var)) {
                        $var = &$var->$get(...$args);
                    } else {
                        $var = $var->$get(...$args);
                    }
                } else {
                    $found = isset($var->$part);
                    $var = &$var->$part;
                }

                $ref['found'] = $found;
            } else {
                $var = null;
                $ref['found'] = false;

                break;
            }
        }

        return $var;
    }

    public function &unref($key, array &$parts = null)
    {
        $this->trigger('contextprepare', ...($parts = static::parts($key)));

        $var = &$this->hive;

        if (!isset($parts[1])) {
            unset($var[$parts[0]]);

            return $var;
        }

        unset($var);

        $leaf = end($parts);
        $get = implode('.', array_slice($parts, 0, -1));
        $var = &$this->ref($get);

        if (is_array($var)) {
            unset($var[$leaf]);
        } elseif (is_object($var)) {
            if (method_exists($var, $remove = 'remove' . $leaf)) {
                $var->$remove();
            } elseif (method_exists($var, $remove = 'remove')) {
                $var->$remove($leaf);
            } elseif ($var instanceof \ArrayAccess) {
                unset($var[$leaf]);
            } else {
                unset($var->$leaf);
            }
        } else {
            $var = null;
        }

        return $var;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function &offsetGet(mixed $offset): mixed
    {
        $var = &$this->get($offset);

        return $var;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }

    public function __isset($name)
    {
        return $this->has($name);
    }

    public function __get($name)
    {
        $var = &$this->get($name);

        return $var;
    }

    public function __set($name, $value)
    {
        $this->set($name, $value);
    }

    public function __unset($name)
    {
        $this->remove($name);
    }

    protected function initialize(array $context): void
    {
        $srv = $context['SERVER'] ?? null;
        $cli = 'cli' === PHP_SAPI;
        $dir = static::projectDir();

        $this->hive = array(
            'APP_ENV' => static::ENV_PROD,
            'CACHE_DRIVER' => null,
            'CACHE_REF' => null,
            'CACHE' => null,
            'COOKIE' => $context['COOKIE'] ?? null,
            'DEBUG' => false,
            'EMULATING' => $cli,
            'ENV' => $context['ENV'] ?? null,
            'FILES' => $context['FILES'] ?? null,
            'GET' => $context['GET'] ?? null,
            'POST' => $context['POST'] ?? null,
            'PROJECT' => $dir,
            'SERVER' => $context['SERVER'] ?? null,
            'SESSION_STARTED' => static::sessionActive(),
            'TMP' => $dir . '/var',
        );

        $this->allSet($context);
    }

    protected function trigger(string $action, $key, ...$args): static
    {
        if (method_exists($this, $prepare =  $key . $action)) {
            $this->$prepare(...$args);
        }

        return $this;
    }

    protected function sessionContextPrepare(): void
    {
        if ($this->hive['SESSION_STARTED']) {
            return;
        }

        $this->hive['SESSION_STARTED'] = $this->hive['EMULATING'] || session_start();
        $this->hive['SESSION'] = &$GLOBALS['_SESSION'];
    }

    protected function sessionContextRemove(...$keys): void
    {
        if ($keys) {
            return;
        }

        $this->hive['SESSION'] = null;

        static::sessionActive() && session_destroy() && session_unset();
    }

    protected function cacheContextSet($dsn): void
    {
        if (!$dsn) {
            $this->hive['CACHE_DRIVER'] = null;
            $this->hive['CACHE_REF'] = null;

            return;
        }

        list(
            $this->hive['CACHE_DRIVER'],
            $this->hive['CACHE_REF'],
        ) = match (true) {
            $dsn,
            !!preg_match(
                '/^(?:dir|folder)\h*=\h*(.+)$/i',
                $dsn,
                $match,
            ) => array(
                static::CACHE_FOLDER,
                $match[1] ?? ($this->hive['TMP'] . '/cache')
            ),
            default => null,
        } ?? array(null, null);
    }

    public static function create(array $context = null): static
    {
        return new static($context);
    }

    public static function createFromGlobals(array $context = null): static
    {
        return static::create(($context ?? array()) + array(
            'COOKIE' => $_COOKIE,
            'ENV' => $_ENV,
            'FILES' => $_FILES,
            'GET' => $_GET,
            'POST' => $_POST,
            'SERVER' => $_SERVER,
            'SESSION' => null,
        ));
    }

    public static function sessionActive(): bool
    {
        return PHP_SESSION_ACTIVE === session_status();
    }

    public static function slash(string $str): string
    {
        return strtr($str, '\\', '/');
    }

    public static function projectDir(): string
    {
        $path = static::refExec('getFileName', null, static::class);
        $pos = strpos($path, 'vendor');
        $vend = false !== $pos;

        return static::slash($vend ? substr($path, 0, $pos - 1) : dirname($path, 2));
    }

    public static function mkdir(
        string $path,
        bool $recursive = null,
        int $perms = null,
    ): bool {
        return is_dir($path) || mkdir($path, $perms ?? 0777, $recursive ?? false);
    }

    public static function read(string $file, bool $lf = false): string|null
    {
        if (!file_exists($file)) {
            return null;
        }

		$out = file_get_contents($file);

		return $lf ? preg_replace('/\r\n|\r/', "\n", $out) : $out;
	}

    public static function write(string $file, string $data, int $flag = LOCK_EX): int|false
    {
		return file_put_contents($file, $data, $flag);
	}

    public static function parts($key): array
    {
        if (!is_string($key) || false === strpos($key, '.')) {
            return array($key);
        }

        return array_map(
            static fn (string $part) => str_replace('\\', '', $part),
            preg_split('/(?<!\\\)\./', $key, 0, PREG_SPLIT_NO_EMPTY),
        );
    }

    public static function cast($val)
    {
        if (!is_string($val)) {
            return $val;
        }

        $str = trim($val);

        return match (true) {
            !!preg_match('/^(?:0x[0-9a-f]+|0[0-7]+|0b[01]+)$/i', $str) => intval($str, 0),
            defined($str) => constant($str),
            is_numeric($str) => $str + 0,
            default => $str,
        };
	}

    public static function serialize($value): string
    {
        return serialize($value);
    }

    public static function unserialize(string $data, array $options = null)
    {
        return unserialize($data, $options ?? array());
    }

    public static function some(
        iterable $items,
        callable $match,
        array &$found = null,
    ): bool {
        $found = null;

        foreach ($items as $key => $value) {
            if ($match($value, $key)) {
                $found = compact('key', 'value');

                return true;
            }
        }

        return false;
    }

    public static function reduce(
        iterable $items,
        callable $transform,
        $initial = null,
    ) {
        $result = $initial;

        foreach ($items as $key => $value) {
            $result = $transform($result, $value, $key);
        }

        return $result;
    }

    public static function map(iterable $items, callable $transform): array|null
    {
        $result = null;

        foreach ($items as $key => $value) {
            $result[$key] = $transform($value, $key);
        }

        return $result;
    }

    public static function refExec(
        string $call,
        string $fn = null,
        $object = null,
        ...$args,
    ) {
        $ref = match (true) {
            $object && $fn => new \ReflectionMethod($object, $fn),
            !!$object => new \ReflectionClass($object),
            default => new \ReflectionFunction($fn),
        };

        return $ref->$call(...$args);
    }
}
