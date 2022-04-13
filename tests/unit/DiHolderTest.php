<?php

use Ekok\Container\DiHolder;

class DiHolderTest extends \Codeception\Test\Unit
{
    /** @var DiHolder */
    private $di;

    protected function _before()
    {
        $this->di = DiHolder::obtain();
    }

    public function testInstance()
    {
        $this->assertSame($this->di, DiHolder::obtain());
    }
}
