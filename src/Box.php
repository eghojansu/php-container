<?php

namespace Ekok\Container;

use Ekok\Utils\Arr;
use Ekok\Utils\File;
use Ekok\Utils\Val;

class Box implements \ArrayAccess
{
    private $data = array();
    private $events = array();

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
        return $this->ref($key, false, $exists) || $exists;
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

    public function copy($src, $dest): static
    {
        return $this->set($dest, $this->get($src));
    }

    public function cut($key)
    {
        $var = $this->get($key);

        $this->remove($key);

        return $var;
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

    public function setIfNone(array $data, string $prefix = null): static
    {
        Arr::each($data, fn($value, $key) => $this->has($set = $prefix . $key) ? null : $this->set($set, $value));

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

    public function on(string $event, callable $cb): static
    {
        $this->events[$event] = $cb;

        return $this;
    }

    public function beforeRef(callable $cb): static
    {
        return $this->on(__FUNCTION__, $cb);
    }

    public function afterRef(callable $cb): static
    {
        return $this->on(__FUNCTION__, $cb);
    }

    public function beforeUnref(callable $cb): static
    {
        return $this->on(__FUNCTION__, $cb);
    }

    public function afterUnref(callable $cb): static
    {
        return $this->on(__FUNCTION__, $cb);
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

    private function &ref($key, bool $add = true, bool &$exists = null)
    {
        $this->trigger('beforeRef', $key, $add);

        $var = &Val::ref($key, $this->data, $add, $exists);

        $this->trigger('afterRef', $key, $add);

        return $var;
    }

    private function unref($key): void
    {
        $this->trigger('beforeUnref', $key);

        Val::unref($key, $this->data);

        $this->trigger('afterUnref', $key);
    }

    private function trigger(string $event, $key, $add = null): void
    {
        $call = $this->events[$event] ?? null;

        if ($call && ($this->events[$free = 'FREE_CALL.' . $event] ?? true)) {
            $this->events[$free] = false;

            $call($key, $this->data, $this, $add);

            $this->events[$free] = true;
        }
    }
}
