<?php

use Ekok\Container\Box;

class CyclicA
{
    public function __construct(public CyclicB $b)
    {}
}

class CyclicB
{
    public function __construct(public CyclicA $a)
    {}
}

class A
{
	public function __construct(public B $b)
    {}
}

class B {
	public function __construct(public C $c)
    {}
}

class C
{
	public function __construct(public D $d, public E $e)
    {}
}


class D
{
}

class E
{
	public function __construct(public F $f)
    {}
}

class F
{
    public function callMe()
    {
        return 'F get called';
    }
}

class BExtended extends B {}

class CallA
{
    private $f;

    public function __construct()
    {
        $this->f = new F();
    }

    public function getF()
    {
        return $this->f;
    }
}

class MethodWithDefaultNull
{
	public function __construct(public A $a, public B|null $b = null)
    {}
}

class BoxEater
{
    public function __construct(public Box $box)
    {}
}
