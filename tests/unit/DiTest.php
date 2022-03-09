<?php

use Ekok\Container\Di;

class DiTest extends \Codeception\Test\Unit
{
    /** @var Di */
    private $di;

    protected function _before()
    {
        $this->di = new Di(array(
            'my_obj' => static function (Di $di) {
                $obj = new stdClass();
                $obj->di = $di;

                return $obj;
            },
        ));

        require_once TEST_DATA . '/classes.php';
    }

    public function testCallExpression()
    {
        // updating option
        $this->di->addRule('my_obj', array('shared' => true));

        $expr1 = $this->di->callExpression('Box:foo');
        $expr2 = $this->di->callExpression('Box::foo');
        $expr3 = $this->di->callExpression('my_obj@foo');
        $expr4 = $this->di->callExpression('my_obj@@foo');

        $this->assertSame(array('Box', 'foo'), $expr1);
        $this->assertSame(array('Box', 'foo'), $expr2);
        $this->assertSame(array($this->di->make('my_obj'), 'foo'), $expr3);
        $this->assertSame(array($this->di->make('my_obj'), 'foo'), $expr4);

        $this->expectException('LogicException');
        $this->expectExceptionMessage('Invalid call expression: foo');

        $this->di->callExpression('foo');
    }

    public function testCall()
    {
        $format = 'Y-m-d';
        $date = date($format);

        $this->assertSame($date, $this->di->call('datetime@format', $format));
        $this->assertSame($date, $this->di->callArguments('datetime@format', array($format)));
    }

    public function testCallNamedArguments()
    {
        define('x', 1);
        $cb = static function (Di $di, string $foo, int $bar, ...$rest) {
            return $foo . '-' . $bar . '-' . implode(':', $rest);
        };
        $arguments = array(
            22,
            23,
            'bar' => '1',
            'foo' => 'foo',
        );
        $expected = 'foo-1-22:23';
        $actual = $this->di->callArguments($cb, $arguments);

        $this->assertSame($expected, $actual);
    }

    public function testDI()
    {
        $box = $this->di->make('my_obj');
        $box2 = $this->di->make('my_obj');

        $this->assertInstanceOf('stdClass', $box);
        $this->assertNotSame($box2, $box);

        // enable shared
        $this->di->defaults(array('shared' => true));
        $this->di->addRule('datetime');

        $date = $this->di->make('datetime');

        $this->assertInstanceOf('DateTime', $date);
        $this->assertSame($date, $this->di->make('DateTime'));
    }

    public function testDIInherit()
    {
        // set rule with array notation
        $this->di->addRule('datetime', array('shared' => true));

        // set rule with string notation
        $this->di->addRule('my_date', 'DateTime');

        $date = $this->di->make('datetime');
        $myDate = $this->di->make('my_date');

        $this->assertInstanceOf('DateTime', $date);
        $this->assertInstanceOf('DateTime', $myDate);
        $this->assertSame($date, $this->di->make('datetime'));
        $this->assertSame($myDate, $this->di->make('my_date'));
        $this->assertNotSame($myDate, $date);
    }

    public function testRegister()
    {
        $this->di->load(TEST_DATA . '/configs/rules.php');

        $date = $this->di->make('my_date');
        $date2 = $this->di->make('my_date');
        $date3 = $this->di->make('my_date', array('tomorrow'));
        $format = 'Y-m-d';

        $this->assertInstanceOf('DateTime', $date);
        $this->assertInstanceOf('DateTime', $date2);
        $this->assertInstanceOf('DateTime', $date3);
        $this->assertNotSame($date, $date2);
        $this->assertSame($date->format($format), $date2->format($format));
        $this->assertSame(date($format, strtotime('tomorrow')), $date3->format($format));
        $this->assertNotSame($date2->format($format), $date3->format($format));
    }

    public function testgetParams()
    {
        $make = static function (array $args) {
            $std = new stdClass();

            foreach ($args as $key => $val) {
                $std->$key = $val;
            }

            return $std;
        };
        $call = static fn (
            Di $di,
            stdClass $a,
            stdClass $b,
            stdClass $c,
            string $foo,
            $mixed,
            DateTime $tomorrow,
            int ...$numbers,
        ) => implode('-', array(
            $di ? 'ok' : 'nok',
            $a->one,
            $b->two,
            $c->three,
            $foo,
            $mixed,
            implode(':', $numbers),
            $tomorrow->format('Y-m-d'),
        ));
        $params = $this->di->getParams(new \ReflectionFunction($call), array());
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

        $this->di->make('DateTimeInterface');
    }

    public function testNoConstructor()
    {
        $this->assertInstanceOf('stdClass', $this->di->make('stdClass'));
    }

    public function testCyclicReferences()
    {
        $this->di->addRule('CyclicB', array('shared' => true));

		$a = $this->di->make('CyclicA');

		$this->assertInstanceOf('CyclicB', $a->b);
		$this->assertInstanceOf('CyclicA', $a->b->a);

		$this->assertSame($a->b, $a->b->a->b);
	}

    public function testObjectGraphCreation()
    {
		$a = $this->di->make('A');

		$this->assertInstanceOf('B', $a->b);
		$this->assertInstanceOf('c', $a->b->c);
		$this->assertInstanceOf('D', $a->b->c->d);
		$this->assertInstanceOf('E', $a->b->c->e);
		$this->assertInstanceOf('F', $a->b->c->e->f);
	}

    public function testInterfaceRule()
    {
        $this->di->addRule('DateTimeInterface', array('shared' => true));

		$one = $this->di->make('DateTime');
		$two = $this->di->make('DateTime');

		$this->assertSame($one, $two);
	}

    public function testChain()
    {
        $this->di->addRule('today', array(
            'shared' => true,
            'class' => 'DateTime',
            'calls' => array('@format', 'Y-m-d'),
        ));
        $this->di->addRule('call', array(
            'class' => 'CallA',
            'calls' => array(
                array('@getF'),
                array('@callMe'),
            ),
        ));

        $today = date('Y-m-d');

        $this->assertSame($today, $this->di->make('today'));
        $this->assertSame($today, $this->di->make('today'));
        $this->assertSame('F get called', $this->di->make('call'));
    }

    public function testNullSubstitution()
    {
        $this->di->addRule('MethodWithDefaultNull', array(
            'substitutions' => array('B' => null),
        ));

        $obj = $this->di->make('MethodWithDefaultNull');

        $this->assertInstanceOf('MethodWithDefaultNull', $obj);
		$this->assertNull($obj->b);
	}

    public function testSubstitutionText()
    {
        $this->di->addRule('A', array(
            'substitutions' => array('B' => 'BExtended'),
        ));

		$a = $this->di->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testSubstitutionTextMixedCase()
    {
        $this->di->addRule('A', array(
            'substitutions' => array('B' => 'bexTenDed'),
        ));

		$a = $this->di->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testSubstitutionCallback()
    {
        $this->di->addRule('A', array(
            'substitutions' => array('B' => static fn(Di $di) => $di->make('BExtended')),
        ));

		$a = $this->di->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testSubstitutionObject()
    {
        $this->di->addRule('A', array(
            'substitutions' => array('B' => $this->di->make('BExtended')),
        ));

		$a = $this->di->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testGetSelf()
    {
        $this->di->addRule('my_di', array(
            'params' => array(Di::class),
            'class' => DependsDi::class,
        ));
        $this->di->addRule('my_second_di', array(
            'params' => array('_di_'),
            'class' => DependsDi::class,
        ));
        $this->di->addRule('my_third_di', array(
            'params' => array('Ekok\\Container\\dI'),
            'class' => DependsDi::class,
        ));

        $this->assertSame($this->di, $this->di->call(static fn(Di $di) => $di));
        $this->assertSame($this->di, $this->di->make('my_di')->di);
        $this->assertSame($this->di, $this->di->make('my_second_di')->di);
        $this->assertSame($this->di, $this->di->make('my_third_di')->di);
    }

    public function testRuleAlias()
    {
        $this->di->addRule('foo', array(
            'class' => 'MethodWithDefaultNull',
            'alias' => true,
            'shared' => true,
        ));

        $date = $this->di->make('DateTime');
        $date2 = $this->di->make('datetime');
        $foo = $this->di->make('foo');
        $foo2 = $this->di->make('MethodWithDefaultNull');

        $this->assertNotSame($date, $date2);
        $this->assertSame($foo, $foo2);
    }

    public function testTagged()
    {
        $this->di->defaults(array('shared' => true));
        $this->di->addRule('foo', array('tags' => 'tag', 'class' => 'stdClass'));
        $this->di->addRule('bar', array('tags' => 'tag', 'class' => 'stdClass'));
        $this->di->addRule('baz', array('tags' => 'tag', 'class' => 'stdClass'));

        $foo = $this->di->make('foo');
        $bar = $this->di->make('bar');
        $baz = $this->di->make('baz');
        $actual = array($foo, $bar, $baz);

        $this->assertSame($actual, $this->di->tagged('tag'));
    }

    public function testSelfAlias()
    {
        $this->di->setAlias('foo');

        $this->assertSame('foo', $this->di->getAlias());
        $this->assertSame($this->di, $this->di->make('foo'));
    }
}
