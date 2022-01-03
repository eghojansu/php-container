<?php

namespace Ekok\Container;

use Ekok\Utils\Arr;
use Ekok\Utils\Val;
use Ekok\Utils\Payload;

class Box
{
    protected $hive = array();
    protected $rules = array();
    protected $factories = array();
    protected $protected = array();

    public function __construct(array $hive = null, array $rules = null)
    {
        if ($hive) {
            $this->hive = $hive;
        }

        if ($rules) {
            $this->rules = $rules;
        }
    }

    public function config(string ...$files): void
    {
        static $load;

        if (!$load) {
            $load = static fn() => require func_get_arg(0);
        }

        Arr::walk($files, fn($file) => is_file($file) && is_array($config = require $file) && $this-($config));
    }

    public function with(string $key, \Closure $cb = null)
    {
        return $cb ? $cb(get($key)) : get($key);
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

    public function get($key)
    {
        return $this->protected[$key] ?? Val::ref($key, $this->hive) ?? $this->make($key, false) ?? null;
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

    public function push($key, ...$values): array
    {
        $data = $this->get($key);

        if (!is_array($data)) {
            $data = (array) $data;
        }

        array_push($data, ...$values);

        $this->set($key, $data);

        return $data;
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

    public function unshift($key, ...$values): array
    {
        $data = $this->get($key);

        if (!is_array($data)) {
            $data = (array) $data;
        }

        array_unshift($data, ...$values);

        $this->set($key, $data);

        return $data;
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
}
