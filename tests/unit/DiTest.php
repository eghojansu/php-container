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
        $this->di->setRule('my_obj', array('shared' => true));

        $expr1 = $this->di->callExpression('Box:foo');
        $expr2 = $this->di->callExpression('Box::foo');
        $expr3 = $this->di->callExpression('my_obj@foo');
        $expr4 = $this->di->callExpression('my_obj@@foo');

        $this->assertSame(array('Box', 'foo'), $expr1);
        $this->assertSame(array('Box', 'foo'), $expr2);
        $this->assertSame(array($this->di->make('my_obj'), 'foo'), $expr3);
        $this->assertSame(array($this->di->make('my_obj'), 'foo'), $expr4);
    }

    public function testCallExpressionException()
    {
        $this->expectException('ReflectionException');
        $this->expectExceptionMessageMatches('/^Class "foo" does not exist$/');

        $this->di->callExpression('foo');
    }

    public function testCall()
    {
        $format = 'Y-m-d';
        $date = date($format);

        $this->assertSame($date, $this->di->call('datetime@format', $format));
        $this->assertSame($date, $this->di->callArguments('datetime@format', array($format)));
    }

    public function testCallException()
    {
        $this->expectException('BadMethodCallException');
        $this->expectExceptionMessageMatches('/^Call to undefined method stdClass::foo$/');

        $this->di->callArguments('stdClass@foo');
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
            'bar' => 1,
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

        $this->assertFalse($this->di->getDefaults()['shared']);
        $this->assertInstanceOf('stdClass', $box);
        $this->assertNotSame($box2, $box);

        // enable shared
        $this->di->setDefaults(array('shared' => true));
        $this->di->setRule('datetime');

        $date = $this->di->make('datetime');

        $this->assertTrue($this->di->getDefaults()['shared']);
        $this->assertInstanceOf('DateTime', $date);
        $this->assertSame($date, $this->di->make('DateTime'));
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
        $this->di->setRule('CyclicB', array('shared' => true));

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
        $this->di->setRule('DateTimeInterface', array('shared' => true));

		$one = $this->di->make('DateTime');
		$two = $this->di->make('DateTime');

		$this->assertSame($one, $two);
	}

    public function testChain()
    {
        $this->di->setRule('today', array(
            'shared' => true,
            'class' => 'DateTime',
            'calls' => array('@format', 'Y-m-d'),
        ));
        $this->di->setRule('call', array(
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
        $this->di->setRule('MethodWithDefaultNull', array(
            'substitutions' => array('B' => null),
        ));

        $obj = $this->di->make('MethodWithDefaultNull');

        $this->assertInstanceOf('MethodWithDefaultNull', $obj);
		$this->assertNull($obj->b);
	}

    public function testSubstitutionText()
    {
        $this->di->setRule('A', array(
            'substitutions' => array('B' => 'BExtended'),
        ));

		$a = $this->di->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testSubstitutionTextMixedCase()
    {
        $this->di->setRule('A', array(
            'substitutions' => array('B' => 'bexTenDed'),
        ));

		$a = $this->di->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testSubstitutionCallback()
    {
        $this->di->setRule('A', array(
            'substitutions' => array('B' => static fn(Di $di) => $di->make('BExtended')),
        ));

		$a = $this->di->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testSubstitutionObject()
    {
        $this->di->setRule('A', array(
            'substitutions' => array('B' => $this->di->make('BExtended')),
        ));

		$a = $this->di->make('A');

        $this->assertInstanceOf('A', $a);
		$this->assertInstanceOf('BExtended', $a->b);
	}

    public function testGetSelf()
    {
        $this->di->setRule('my_di', array(
            'params' => array(Di::class),
            'class' => DependsDi::class,
        ));
        $this->di->setRule('my_second_di', array(
            'params' => array('di'),
            'class' => DependsDi::class,
        ));
        $this->di->setRule('my_third_di', array(
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
        $this->di->setRule('foo', array(
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
        $this->di->setDefaults(array('shared' => true));
        $this->di->setRule('foo', array('tags' => 'tag', 'class' => 'stdClass'));
        $this->di->setRule('bar', array('tags' => 'tag', 'class' => 'DateTime'));

        $foo = $this->di->make('foo');
        $bar = $this->di->make('bar');
        $actual = array($foo, $bar);

        $this->assertSame($actual, $this->di->tagged('tag'));
    }

    public function testSelfAlias()
    {
        $this->di->setSelfAlias('foo');

        $this->assertSame('foo', $this->di->getSelfAlias());
        $this->assertSame($this->di, $this->di->make('foo'));
    }

    public function testParamResolving()
    {
        $expected = 'bar:1';
        $actual = $this->di->call(function (string $foo, int $no) {
            return $foo . ':' . $no;
        }, 1, 'bar');

        $this->assertSame($expected, $actual);
    }

    public function testParamResolvingException()
    {
        $this->expectException('TypeError');
        $this->expectExceptionMessageMatches('/Argument #2 \(\$no\) must be of type int, string given, called in .+ on line 247$/');

        $this->di->call(function (string $foo, int $no) {
            return $foo . ':' . $no;
        }, '1', 'bar');
    }

    public function testParamUnion()
    {
        $expected = '1:2';
        $actual = $this->di->call(function (string|int $foo, int $no) {
            return $foo . ':' . $no;
        }, 1, 2);

        $this->assertSame($expected, $actual);
    }

    public function testAddAlias()
    {
        $this->di->set(new stdClass(), array('alias' => 'std'));

        $this->assertInstanceOf('stdClass', $this->di->make('stdClass'));
        $this->assertSame($this->di->make('stdClass'), $this->di->make('std'));
        $this->assertInstanceOf('DateTime', $this->di->make('DateTime'));
        $this->assertSame($this->di->make('std'), $this->di->setAlias('DateTime', 'std')->make('DateTime'));
        $this->assertSame('stdclass', $this->di->getAlias('DateTime'));
        $this->assertSame($this->di->make('std'), $this->di->setAlias('foo', 'std')->make('foo'));
        $this->assertSame('stdclass', $this->di->getAlias('foo'));
    }

    public function testContainerAware()
    {
        $obj = $this->di->make(ContainerAware::class);

        $this->assertSame($this->di, $obj->container);
    }

    public function testFactory()
    {
        $this->di->setRule('std', 'StdFactory::create');
        $this->di->setRule('date', 'DateFactory@__invoke');

        $std = $this->di->make('std');
        $std2 = $this->di->make('std');

        $this->assertInstanceOf('stdClass', $std);
        $this->assertInstanceOf('stdClass', $std2);
        $this->assertNotSame($std, $std2);

        $date = $this->di->make('date');
        $date2 = $this->di->make('date');

        $this->assertInstanceOf('DateTime', $date);
        $this->assertInstanceOf('DateTime', $date2);
        $this->assertNotSame($date, $date2);
    }

    public function testGet()
    {
        $this->di->setDefaults(array('shared' => true));

        $this->assertInstanceOf('stdClass', $std = $this->di->make('stdClass'));
        $this->assertSame($std, $this->di->get('stdClass'));
    }
}
