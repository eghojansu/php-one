<?php

namespace Ekok\One;

class Kernel implements \ArrayAccess
{
    private $hive = array();

    public function getContext(): array
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
        $var = &$this->ref($key);
        $var = $value;

        return $this;
    }

    public function remove($key): static
    {
        $this->unref($key);

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
            fn (array $all, $key, $alias) => ($all + array(
                is_numeric($alias) ? $key : $alias => $this->get($key),
            )),
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

    public function &ref($key, bool $add = true, array &$ref = null)
    {
        $ref = array('found' => false, 'parts' => array($key));

        if ($add) {
            $var = &$this->hive;
        } else {
            $var = $this->hive;
        }

        if (
            ($found = isset($var[$key]) || array_key_exists($key, $var))
            || !is_string($key)
            || false === strpos($key, '.')
        ) {
            $ref['found'] = $found;
            $var = &$var[$key];

            return $var;
        }

        $ref['parts'] = static::parts($key);

        foreach ($ref['parts'] as $part) {
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
        $parts = array($key);
        $var = &$this->hive;

        if (
            (isset($var[$key]) || array_key_exists($key, $var))
            || !is_string($key)
            || false === strpos($key, '.')
        ) {
            unset($var[$key]);

            return $var;
        }

        unset($var);

        $parts = static::parts($key);
        $leaf = end($parts);
        $get = implode('.', array_slice($parts, 0, count($parts) - 1));
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

    public static function create(): static
    {
        return new static();
    }

    public static function createFromGlobals(): static
    {
        return self::create();
    }

    public static function parts(string $key): array
    {
        return preg_split('/(?<!\\\)\./', $key, 0, PREG_SPLIT_NO_EMPTY);
    }

    public static function some(iterable $items, callable $match, array &$found = null): bool
    {
        foreach ($items as $key => $value) {
            if ($match($value, $key)) {
                $found = compact('key', 'value');

                return true;
            }
        }

        return false;
    }

    public static function reduce(iterable $items, callable $transform, $initial = null)
    {
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

    public static function refExec(string $call, string $fn = null, $object = null, ...$args)
    {
        $ref = match (true) {
            $object && $fn => new \ReflectionMethod($object, $fn),
            !!$object => new \ReflectionClass($object),
            default => new \ReflectionFunction($fn),
        };

        return $ref->$call(...$args);
    }
}
