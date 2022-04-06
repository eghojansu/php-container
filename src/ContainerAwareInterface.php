<?php

declare(strict_types=1);

namespace Ekok\Container;

interface ContainerAwareInterface
{
    public function setContainer(Di $di);
}
