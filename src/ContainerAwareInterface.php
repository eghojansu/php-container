<?php

namespace Ekok\Container;

interface ContainerAwareInterface
{
    public function setContainer(Di $di);
}
