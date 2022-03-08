<?php

use Ekok\Container\Box;

class BoxTest extends \Codeception\Test\Unit
{
    /** @var Box */
    private $box;

    protected function _before()
    {
        $this->box = new Box(array(
            'foo' => array('bar' => 'baz'),
            'obj' => new stdClass(),
        ));
    }

    public function testLoad()
    {
        $this->box->load(TEST_DATA . '/configs/one.php', TEST_DATA . '/configs/two.php');

        $this->assertSame('foo', $this->box->get('one'));
        $this->assertSame(array(1, 2, 3), $this->box->get('two'));
    }

    public function testContainer()
    {
        $this->assertTrue($this->box->has('foo.bar'));
        $this->assertSame('baz', $this->box->get('foo.bar'));
        $this->assertSame(3, $this->box->sizeOf('foo.bar'));
        $this->assertSame(1, $this->box->set('one', 1)->get('one'));
        $this->assertFalse($this->box->remove('one')->has('one'));
        $this->assertNull($this->box->get('one'));
        $this->assertSame('barbaz', $this->box->with(fn ($baz) => 'bar' . $baz, 'foo.bar', false));
        $this->assertSame($this->box, $this->box->with(fn () => null));

        $this->assertSame(array(1, 2, 3), $this->box->push('push', 1, 2, 3)->get('push'));
        $this->assertSame(array(1, 2, 3, 4, 5), $this->box->push('push', 4, 5)->get('push'));
        $this->assertSame(5, $this->box->pop('push'));
        $this->assertSame(4, $this->box->sizeOf('push'));
        $this->assertSame(array(1, 2, 3, 4), $this->box->get('push'));

        $this->assertSame(array(-1, 0), $this->box->unshift('unshift', -1, 0)->get('unshift'));
        $this->assertSame(array(-3, -2, -1, 0), $this->box->unshift('unshift', -3, -2)->get('unshift'));
        $this->assertSame(-3, $this->box->shift('unshift'));
        $this->assertSame(3, $this->box->sizeOf('unshift'));
        $this->assertSame(array(-2, -1, 0), $this->box->get('unshift'));

        $this->assertSame('remove', $this->box->set('to_be', 'remove')->pop('to_be'));
        $this->assertFalse($this->box->has('to_be'));

        $this->assertSame('remove', $this->box->set('to_be', 'remove')->shift('to_be'));
        $this->assertFalse($this->box->has('to_be'));

        $fn = fn () => true;

        $this->assertSame($fn, $this->box->set('fn', $fn)->get('fn'));
        $this->assertInstanceOf('stdClass', $this->box->get('obj'));
        $this->assertSame(0, $this->box->sizeOf(('obj')));
    }

    public function testPropertyAccess()
    {
        $this->assertSame('baz', $this->box->{'foo.bar'});
        $this->assertTrue(isset($this->box->{'foo.bar'}));

        $this->box->add_prop = array(1, 2, 3);

        $this->assertSame(2, $this->box->{'add_prop.1'});

        unset($this->box->{'add_prop.1'});

        $this->assertEquals(array(1, 2 => 3), $this->box->add_prop);
    }

    public function testArrayAccess()
    {
        $this->assertArrayHasKey('foo', $this->box);
        $this->assertArrayHasKey('foo.bar', $this->box);
        $this->assertSame('baz', $this->box['foo.bar']);
        $this->assertTrue(isset($this->box['foo.bar']));

        $this->box['add_array'] = array(1, 2, 3);

        $this->assertSame(2, $this->box['add_array.1']);

        unset($this->box['add_array.1']);

        $this->assertEquals(array(1, 2 => 3), $this->box['add_array']);
    }

    public function testMassive()
    {
        $this->box->allSet(array(0, 1, 2, 3), 'numbers_');

        $this->assertTrue($this->box->some('numbers_none', 'numbers_0', 'numbers_1'));
        $this->assertTrue($this->box->all('numbers_0', 'numbers_1', 'numbers_2', 'numbers_3'));
        $this->assertFalse($this->box->all('numbers_0', 'numbers_none', 'numbers_1'));
        $this->assertEquals(array(
            'numbers_0' => 0,
            'one' => 1,
            'two' => 2,
            'three' => 3,
        ), $this->box->allGet(array(
            'numbers_0',
            'one' => 'numbers_1',
            'two' => 'numbers_2',
            'three' => 'numbers_3',
        )));
        $this->assertFalse($this->box->allRemove('numbers_0', 'numbers_1', 'numbers_2', 'numbers_3')->some('numbers_0'));
    }

    public function testOccupy()
    {
        $this->box->allSet(array('foo' => 'bar'));
        $this->box->occupy(array('foo' => 'baz'));

        $this->assertSame('bar', $this->box->get('foo'));
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

        $this->box->beforeRef(static fn(Box $box, $key) => $box['BEFORE_REF_' . $key] = true);
        $this->box->afterRef(static fn(Box $box, $key) => $box['AFTER_REF_' . $key] = true);
        $this->box->beforeUnref(static fn(Box $box, $key) => $box['BEFORE_UNREF_' . $key] = true);
        $this->box->afterUnref(static fn(Box $box, $key) => $box['AFTER_UNREF_' . $key] = true);

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
