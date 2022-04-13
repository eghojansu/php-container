<?php

declare(strict_types=1);

namespace Ekok\Container;

class DiHolder extends Di
{
    /** @var static */
    private static $__selfCache;

    public static function obtain(array $rules = null): static
    {
        return (self::$__selfCache ?? (self::$__selfCache = new static()))->register($rules ?? array());
    }
}
