<?php

declare(strict_types=1);

namespace Ekok\Container;

class ListenableBox extends Box
{
    private $events = array();

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

    protected function &ref($key, bool $add = true, bool &$exists = null)
    {
        $this->trigger('beforeRef', $key, $add);

        $var = &parent::ref($key, $add, $exists);

        $this->trigger('afterRef', $key, $add);

        return $var;
    }

    protected function unref($key): void
    {
        $this->trigger('beforeUnref', $key);

        parent::unref($key, $this->data);

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
