<?php

namespace Ekok\One;

class Kernel implements \ArrayAccess
{
    const CACHE_FOLDER = 'folder';

    protected $hive = array();

    public function __construct(array $context = null)
    {
        $this->initialize($context ?? array());
    }

    public function context(): array
    {
        return $this->hive;
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

        is_dir($dir) || ($create && mkdir($dir, 0755, true));

        return $dir . '/' . $key . '.cache';
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

                    if (self::refExec('returnsReference', $get, $var)) {
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
            'CACHE_DRIVER' => null,
            'CACHE_REF' => null,
            'CACHE' => null,
            'COOKIE' => $context['COOKIE'] ?? null,
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
        return self::create(($context ?? array()) + array(
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
