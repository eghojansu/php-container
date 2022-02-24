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
        ), array(
            'my_obj' => static function (Box $box) {
                $obj = new stdClass();
                $obj->box = $box;

                return $obj;
            },
        ));

        require_once TEST_DATA . '/classes.php';
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

    public function testCallExpression()
    {
        // updating option
        $this->box->ruleAdd('my_obj', array('shared' => true));

        $expr1 = $this->box->callExpression('Box:foo');
        $expr2 = $this->box->callExpression('Box::foo');
        $expr3 = $this->box->callExpression('my_obj@foo');
        $expr4 = $this->box->callExpression('my_obj@@foo');

        $this->assertSame(array('Box', 'foo'), $expr1);
        $this->assertSame(array('Box', 'foo'), $expr2);
        $this->assertSame(array($this->box->make('my_obj'), 'foo'), $expr3);
        $this->assertSame(array($this->box->make('my_obj'), 'foo'), $expr4);

        $this->expectException('LogicException');
        $this->expectExceptionMessage('Invalid call expression: foo');

        $this->box->callExpression('foo');
    }

    public function testCall()
    {
        $format = 'Y-m-d';
        $date = date($format);

        $this->assertSame($date, $this->box->call('datetime@format', $format));
        $this->assertSame($date, $this->box->callArguments('datetime@format', array($format)));
    }

    public function testDI()
    {
        $box = $this->box->make('my_obj');
        $box2 = $this->box->make('my_obj');

        $this->assertInstanceOf('stdClass', $box);
        $this->assertNotSame($box2, $box);

        // enable shared
        $this->box->ruleDefaults(array('shared' => true));
        $this->box->ruleAdd('datetime');

        $date = $this->box->make('datetime');

        $this->assertInstanceOf('DateTime', $date);
        $this->assertSame($date, $this->box->make('datetime'));
    }

    public function testDIInherit()
    {
        // set rule with array notation
        $this->box->ruleAdd('datetime', array('shared' => true));

        // set rule with string notation
        $this->box->ruleAdd('my_date', 'DateTime');

        $date = $this->box->make('datetime');
        $myDate = $this->box->make('my_date');

        $this->assertInstanceOf('DateTime', $date);
        $this->assertInstanceOf('DateTime', $myDate);
        $this->assertSame($date, $this->box->make('datetime'));
        $this->assertSame($myDate, $this->box->make('my_date'));
        $this->assertNotSame($myDate, $date);
    }

    public function testLoadRules()
    {
        $this->box->loadRules(TEST_DATA . '/configs/rules.php');

        $date = $this->box->make('my_date');
        $date2 = $this->box->make('my_date');
        $date3 = $this->box->make('my_date', array('tomorrow'));
        $format = 'Y-m-d';

        $this->assertInstanceOf('DateTime', $date);
        $this->assertInstanceOf('DateTime', $date2);
        $this->assertInstanceOf('DateTime', $date3);
        $this->assertNotSame($date, $date2);
        $this->assertSame($date->format($format), $date2->format($format));
        $this->assertSame(date($format, strtotime('tomorrow')), $date3->format($format));
        $this->assertNotSame($date2->format($format), $date3->format($format));
    }

    public function testRuleParams()
    {
        $make = static function (array $args) {
            $std = new stdClass();

            foreach ($args as $key => $val) {
                $std->$key = $val;
            }

            return $std;
        };
        $call = static fn (
            Box $box,
            stdClass $a,
            stdClass $b,
            stdClass $c,
            string $foo,
            $mixed,
            DateTime $tomorrow,
            int ...$numbers,
        ) => implode('-', array(
            isset($box['foo']) ? 'ok' : 'nok',
            $a->one,
            $b->two,
            $c->three,
            $foo,
            $mixed,
            implode(':', $numbers),
            $tomorrow->format('Y-m-d'),
        ));
        $params = $this->box->ruleParams(new \ReflectionFunction($call), array());
        $arguments = array(
            // ignore order
            'foo',
            'any',
            11,
            12,
            13,
            $make(array('one' => 1)),
            $make(array('two' => 2)),
            $make(array('three' => 3)),
        );
        $share = array(
            new DateTime('tomorrow'),
        );
        $actual = $call(...$params($arguments, $share));
        $expected = 'ok-1-2-3-foo-any-11:12:13' . date('-Y-m-d', strtotime('tomorrow'));

        $this->assertSame($expected, $actual);
    }

    public function testConstructInterface()
    {
        $this->expectException('InvalidArgumentException');
        $this->expectErrorMessage('Cannot instantiate interface');

        $this->box->make('DateTimeInterface');
    }

    public function testNoConstructor()
    {
        $this->assertInstanceOf('stdClass', $this->box->make('stdClass'));
    }

    public function testCyclicReferences()
    {
        $this->box->ruleAdd('CyclicB', array('shared' => true));

		$a = $this->box->make('CyclicA');

		$this->assertInstanceOf('CyclicB', $a->b);
		$this->assertInstanceOf('CyclicA', $a->b->a);

		$this->assertSame($a->b, $a->b->a->b);
	}

    public function testObjectGraphCreation()
    {
		$a = $this->box->make('A');

		$this->assertInstanceOf('B', $a->b);
		$this->assertInstanceOf('c', $a->b->c);
		$this->assertInstanceOf('D', $a->b->c->d);
		$this->assertInstanceOf('E', $a->b->c->e);
		$this->assertInstanceOf('F', $a->b->c->e->f);
	}

    public function testInterfaceRule()
    {
        $this->box->ruleAdd('DateTimeInterface', array('shared' => true));

		$one = $this->box->make('DateTime');
		$two = $this->box->make('DateTime');

		$this->assertSame($one, $two);
	}

    public function testDIChain()
    {
        $this->box->ruleAdd('today', array(
            'shared' => true,
            'class' => 'DateTime',
            'calls' => array('@format', 'Y-m-d'),
        ));
        $this->box->ruleAdd('call', array(
            'class' => 'CallA',
            'calls' => array(
                array('@getF'),
                array('@callMe'),
            ),
        ));

        $today = date('Y-m-d');

        $this->assertSame($today, $this->box->make('today'));
        $this->assertSame($today, $this->box->make('today'));
        $this->assertSame('F get called', $this->box->make('call'));
    }

    public function testNullSubstitution()
    {
        $this->box->ruleAdd('MethodWithDefaultNull', array(
            'substitutions' => array('B' => null),
        ));

        $obj = $this->box->make('MethodWithDefaultNull');

        $this->assertInstanceOf('MethodWithDefaultNull', $obj);
		$this->assertNull($obj->b);
	}

    public function testSubstitutionText()
    {
        $this->box->ruleAdd('A', array(
            'substitutions' => array('B' => 'BExtended'),
        ));

		$a = $this->box->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testSubstitutionTextMixedCase()
    {
        $this->box->ruleAdd('A', array(
            'substitutions' => array('B' => 'bexTenDed'),
        ));

		$a = $this->box->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testSubstitutionCallback()
    {
        $this->box->ruleAdd('A', array(
            'substitutions' => array('B' => static fn(Box $box) => $box->make('BExtended')),
        ));

		$a = $this->box->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testSubstitutionObject()
    {
        $this->box->ruleAdd('A', array(
            'substitutions' => array('B' => $this->box->make('BExtended')),
        ));

		$a = $this->box->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testGetBox()
    {
        $this->box->ruleAdd('my_box', array(
            'params' => array(Box::class),
            'class' => BoxEater::class,
        ));
        $this->box->ruleAdd('my_second_box', array(
            'params' => array('_box_'),
            'class' => BoxEater::class,
        ));
        $this->box->ruleAdd('my_third_box', array(
            'params' => array('Ekok\\Container\\bOX'),
            'class' => BoxEater::class,
        ));

        $this->assertSame($this->box, $this->box->call(static fn(Box $box) => $box));
        $this->assertSame($this->box, $this->box->make('my_box')->box);
        $this->assertSame($this->box, $this->box->make('my_second_box')->box);
        $this->assertSame($this->box, $this->box->make('my_third_box')->box);
    }
}
