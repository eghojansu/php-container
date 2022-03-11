<?php

use Ekok\Container\ListenableBox;

class ListenableBoxTest extends \Codeception\Test\Unit
{
    /** @var ListenableBox */
    private $box;

    protected function _before()
    {
        $this->box = new ListenableBox();
    }

    public function testEvents()
    {
        $this->box->set('FOO', true);

        $this->assertTrue($this->box->get('FOO'));
        $this->assertNull($this->box->get('BEFORE_REF_FOO'));
        $this->assertNull($this->box->get('AFTER_REF_FOO'));

        $this->box->remove('FOO');

        $this->assertNull($this->box->get('FOO'));
        $this->assertNull($this->box->get('BEFORE_UNREF_FOO'));
        $this->assertNull($this->box->get('AFTER_UNREF_FOO'));

        $this->box->beforeRef(static fn($key, ...$args) => $args[1]['BEFORE_REF_' . $key] = true);
        $this->box->afterRef(static fn($key, ...$args) => $args[1]['AFTER_REF_' . $key] = true);
        $this->box->beforeUnref(static fn($key, ...$args) => $args[1]['BEFORE_UNREF_' . $key] = true);
        $this->box->afterUnref(static fn($key, ...$args) => $args[1]['AFTER_UNREF_' . $key] = true);

        $this->box->set('FOO', true);

        $this->assertTrue($this->box->get('FOO'));
        $this->assertTrue($this->box->get('BEFORE_REF_FOO'));
        $this->assertTrue($this->box->get('AFTER_REF_FOO'));

        $this->box->remove('FOO');

        $this->assertNull($this->box->get('FOO'));
        $this->assertTrue($this->box->get('BEFORE_UNREF_FOO'));
        $this->assertTrue($this->box->get('AFTER_UNREF_FOO'));
    }
}
