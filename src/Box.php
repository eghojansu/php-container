<?php

namespace Ekok\Container;

use Ekok\Utils\Arr;
use Ekok\Utils\File;
use Ekok\Utils\Val;
use Ekok\Utils\Payload;

class Box implements \ArrayAccess
{
    protected $hive = array();
    protected $rules = array();
    protected $factories = array();
    protected $protected = array();

    public function __construct(array $data = null)
    {
        if ($data) {
            $this->allSet($data);
        }
    }

    public function load(string ...$files): static
    {
        array_walk($files, fn(string $file) => $this->allSet(File::load($file) ?? array()));

        return $this;
    }

    public function with(string $key, \Closure $cb = null)
    {
        return $cb ? $cb($this->get($key)) : $this->get($key);
    }

    public function has($key): bool
    {
        return (
            Val::ref($key, $this->hive, false, $exists)
            || $exists
            || isset($this->rules[$key])
            || isset($this->protected[$key])
        );
    }

    public function &get($key)
    {
        if (isset($this->protected[$key])) {
            return $this->protected[$key];
        }

        if (($val = &Val::ref($key, $this->hive, true, $exists)) || $exists) {
            return $val;
        }

        $obj = $this->make($key, false);

        return $obj;
    }

    public function set($key, $value): static
    {
        if ($value instanceof \Closure || (is_array($value) && \is_callable($value))) {
            $this->remove($key);

            $this->rules[$key] = $value;
        } else {
            $var = &Val::ref($key, $this->hive, true);
            $var = $value;
        }

        return $this;
    }

    public function remove($key): static
    {
        unset($this->rules[$key], $this->protected[$key], $this->factories[$key]);
        Val::unref($key, $this->hive);

        return $this;
    }

    public function some(...$keys): bool
    {
        return Arr::some($keys, fn (Payload $key) => $this->has($key->value));
    }

    public function all(...$keys): bool
    {
        return Arr::every($keys, fn (Payload $key) => $this->has($key->value));
    }

    public function allGet(array $keys): array
    {
        return Arr::each($keys, fn(Payload $key) => $key->update($this->get($key->value), $key->indexed() ? $key->value : $key->key));
    }

    public function allSet(array $values, string $prefix = null): static
    {
        Arr::walk($values, fn(Payload $value) => $this->set($prefix . $value->key, $value->value));

        return $this;
    }

    public function allRemove(...$keys): static
    {
        Arr::walk($keys, fn(Payload $key) => $this->remove($key->value));

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

    public function size($key): int
    {
        return match(gettype($val = $this->get($key))) {
            'object', 'resource' => 0,
            'array' => count($val),
            default => strlen($val),
        };
    }

    public function protect($key, callable $value): static
    {
        $this->protected[$key] = $value;

        return $this;
    }

    public function factory($key, callable $value): static
    {
        $this->rules[$key] = $value;
        $this->factories[$key] = true;

        return $this;
    }

    public function make($key, bool $throw = true)
    {
        $rule = $this->rules[$key] ?? null;

        if (!$rule) {
            if ($throw) {
                throw new \LogicException(sprintf('No rule defined for: "%s"', $key));
            }

            return null;
        }

        if (isset($this->factories[$key])) {
            return $rule();
        }

        $instance = &Val::ref($key, $this->hive, true);

        return $instance ?? ($instance = $rule());
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

    public function offsetGet(mixed $offset): mixed
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
