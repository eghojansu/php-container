<?php

namespace Ekok\Container;

use Ekok\Utils\Arr;
use Ekok\Utils\File;
use Ekok\Utils\Val;

class Box implements \ArrayAccess
{
    protected $data = array();

    public function __construct(array $data = null)
    {
        $this->allSet($data ?? array());
    }

    public function load(string ...$files): static
    {
        return $this->loadInto(null, ...$files);
    }

    public function loadInto(string|null $key, string ...$files): static
    {
        array_walk($files, fn(string $file) => $this->allSet(File::load($file) ?? array(), $key));

        return $this;
    }

    public function with(\Closure $cb, string $key = null, bool $chain = true)
    {
        $result = $cb($key ? $this->get($key) : $this, $this);

        return $chain ? $this : $result;
    }

    public function has($key): bool
    {
        return Val::ref($key, $this->data, false, $exists) || $exists;
    }

    public function &get($key)
    {
        $val = &Val::ref($key, $this->data, true);

        return $val;
    }

    public function set($key, $value): static
    {
        $var = &Val::ref($key, $this->data, true);
        $var = $value;

        return $this;
    }

    public function remove($key): static
    {
        Val::unref($key, $this->data);

        return $this;
    }

    public function sizeOf($key): int
    {
        return match(gettype($val = $this->get($key))) {
            'object', 'resource' => is_countable($val) ? count($val) : 0,
            'array' => count($val),
            default => strlen($val),
        };
    }

    public function some(...$keys): bool
    {
        return Arr::some($keys, fn ($key) => $this->has($key));
    }

    public function all(...$keys): bool
    {
        return Arr::every($keys, fn ($key) => $this->has($key));
    }

    public function allGet(array $keys): array
    {
        return Arr::reduce(
            $keys,
            fn (array $set, $key, $alias) => $set + array(is_numeric($alias) ? $key : $alias => $this->get($key)),
            array(),
        );
    }

    public function allSet(array $values, string $prefix = null): static
    {
        Arr::each($values, fn($value, $key) => $this->set($prefix . $key, $value));

        return $this;
    }

    public function allRemove(...$keys): static
    {
        Arr::each($keys, fn($key) => $this->remove($key));

        return $this;
    }

    public function occupy(array $data): static
    {
        Arr::each($data, fn($value, $key) => $this->has($key) ? null : $this->set($key, $value));

        return $this;
    }

    public function push($key, ...$values): static
    {
        $data = $this->get($key);

        if (!is_array($data)) {
            $data = (array) $data;
        }

        array_push($data, ...$values);

        return $this->set($key, $data);
    }

    public function pop($key)
    {
        $data = $this->get($key);

        if (is_array($data)) {
            $value = array_pop($data);

            $this->set($key, $data);

            return $value;
        }

        $this->remove($key);

        return $data;
    }

    public function unshift($key, ...$values): static
    {
        $data = $this->get($key);

        if (!is_array($data)) {
            $data = (array) $data;
        }

        array_unshift($data, ...$values);

        return $this->set($key, $data);
    }

    public function shift($key)
    {
        $data = $this->get($key);

        if (is_array($data)) {
            $value = array_shift($data);

            $this->set($key, $data);

            return $value;
        }

        $this->remove($key);

        return $data;
    }

    public function __isset($name)
    {
        return $this->has($name);
    }

    public function &__get($name)
    {
        $val = &$this->get($name);

        return $val;
    }

    public function __set($name, $value)
    {
        $this->set($name, $value);
    }

    public function __unset($name)
    {
        $this->remove($name);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function &offsetGet(mixed $offset): mixed
    {
        $val = &$this->get($offset);

        return $val;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }
}
