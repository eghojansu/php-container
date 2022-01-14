<?php

namespace Ekok\Container\Tests;

use Ekok\Container\Box;
use PHPUnit\Framework\TestCase;

class BoxTest extends TestCase
{
    /** @var Box */
    private $box;

    protected function setUp(): void
    {
        $this->box = new Box(
            array(
                'foo' => array('bar' => 'baz'),
                'my_obj' => fn(Box $box) => $box === $this->box ? new \stdClass() : null,
            ),
        );
    }

    public function testBox()
    {
        $this->assertTrue($this->box->has('foo.bar'));
        $this->assertSame('baz', $this->box->get('foo.bar'));
        $this->assertSame(3, $this->box->size('foo.bar'));
        $this->assertSame(1, $this->box->set('one', 1)->get('one'));
        $this->assertFalse($this->box->remove('one')->has('one'));
        $this->assertNull($this->box->get('one'));
        $this->assertSame('barbaz', $this->box->with('foo.bar', fn($baz) => 'bar' . $baz));

        $obj = $this->box->get('my_obj');

        $this->assertInstanceOf('stdClass', $obj);

        $obj->foo = 'bar';

        $this->assertSame($obj, $this->box->get('my_obj'));
        $this->assertSame(0, $this->box->size('my_obj'));
        $this->assertSame('bar', $this->box->get('my_obj.foo'));
        $this->assertFalse($this->box->remove('my_obj.foo')->has('my_obj.foo'));
        $this->assertNull($this->box->get('my_obj.foo'));

        $this->assertSame(array(1, 2, 3), $this->box->push('push', 1, 2, 3)->get('push'));
        $this->assertSame(array(1, 2, 3, 4, 5), $this->box->push('push', 4, 5)->get('push'));
        $this->assertSame(5, $this->box->pop('push'));
        $this->assertSame(4, $this->box->size('push'));
        $this->assertSame(array(1, 2, 3, 4), $this->box->get('push'));

        $this->assertSame(array(-1, 0), $this->box->unshift('unshift', -1, 0)->get('unshift'));
        $this->assertSame(array(-3, -2, -1, 0), $this->box->unshift('unshift', -3, -2)->get('unshift'));
        $this->assertSame(-3, $this->box->shift('unshift'));
        $this->assertSame(3, $this->box->size('unshift'));
        $this->assertSame(array(-2, -1, 0), $this->box->get('unshift'));

        $this->assertSame('remove', $this->box->set('to_be', 'remove')->pop('to_be'));
        $this->assertFalse($this->box->has('to_be'));

        $this->assertSame('remove', $this->box->set('to_be', 'remove')->shift('to_be'));
        $this->assertFalse($this->box->has('to_be'));

        $fn = fn() => true;

        $this->assertSame($fn, $this->box->protect('fn', $fn)->get('fn'));
        $this->assertInstanceOf('stdClass', $std = $this->box->factory('std', fn() => new \stdClass())->get('std'));
        $this->assertNotSame($std, $this->box->get('std'));
    }

    public function testMakeUnknown()
    {
        $this->expectErrorMessage('No rule defined for: "unknown"');

        $this->box->make('unknown');
    }

    public function testLoad()
    {
        $this->box->load(TEST_FIXTURES . '/configs/one.php', TEST_FIXTURES . '/configs/two.php');

        $this->assertSame('foo', $this->box->get('one'));
        $this->assertSame(array(1, 2, 3), $this->box->get('two'));
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

    public function testPropertyAccess()
    {
        $this->assertSame('baz', $this->box->{'foo.bar'});
        $this->assertTrue(isset($this->box->{'foo.bar'}));

        $this->box->add_prop = array(1, 2, 3);

        $this->assertSame(2, $this->box->{'add_prop.1'});

        unset($this->box->{'add_prop.1'});

        $this->assertEquals(array(1, 2 => 3), $this->box->add_prop);

        $obj = $this->box->my_obj;

        $this->assertInstanceOf('stdClass', $obj);
        $this->assertSame($obj, $this->box->my_obj);

        $obj->foo = 'bar';

        $this->assertSame('bar', $this->box->my_obj->foo);
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

        /** @var \stdClass */
        $obj = $this->box['my_obj'];

        $this->assertInstanceOf('stdClass', $obj);
        $this->assertSame($obj, $this->box['my_obj']);

        $obj->foo = 'bar';

        $this->assertSame('bar', $this->box['my_obj']->foo);
    }
}
