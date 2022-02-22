<?php

namespace Ekok\Container;

use Ekok\Utils\Arr;
use Ekok\Utils\File;
use Ekok\Utils\Val;

class Box implements \ArrayAccess
{
    protected $data = array();
    protected $rules = array();
    protected $maps = array();
    protected $cache = array();
    protected $protected = array();
    protected $defaults = array(
        'inherit' => true,
        'shared' => true,
    );

    public function __construct(array $data = null)
    {
        $this->allSet($data ?? array());
    }

    public function load(string ...$files): static
    {
        return $this->loadInto(null, ...$files);
    }

    public function loadInto(string|null $key, string ...$files): static
    {
        array_walk($files, fn(string $file) => $this->allSet(File::load($file) ?? array(), $key));

        return $this;
    }

    public function with(\Closure $cb, string $key = null, bool $flow = true)
    {
        $result = $cb($key ? $this->get($key) : $this, $this);

        return $flow ? $this : $result;
    }

    public function has($key): bool
    {
        return (
            Val::ref($key, $this->data, false, $exists)
            || $exists
            || isset($this->protected[$key])
            || isset($this->rules[$key])
            || isset($this->maps[$this->ruleName($key)])
        );
    }

    public function &get($key)
    {
        if (isset($this->protected[$key])) {
            return $this->protected[$key];
        }

        if (($val = &Val::ref($key, $this->data, true, $exists)) || $exists) {
            return $val;
        }

        $obj = $this->make($key, null, null, false);

        return $obj;
    }

    public function set($key, $value): static
    {
        if ($this->ruleCheck($key, $value, $set, $rule)) {
            $this->remove($key);

            $this->rules[$key] = $rule;
            $this->maps[$set] = $key;
        } else {
            $var = &Val::ref($key, $this->data, true);
            $var = $value;
        }

        return $this;
    }

    public function remove($key): static
    {
        $set = $this->ruleName($key);
        $map = $this->maps[$set] ?? null;

        unset(
            $this->protected[$key],
            $this->rules[$key],
            $this->rules[$map],
            $this->cache[$map],
            $this->maps[$set],
        );
        Val::unref($key, $this->data);

        return $this;
    }

    public function sizeOf($key): int
    {
        return match(gettype($val = $this->get($key))) {
            'object', 'resource' => is_countable($val) ? count($val) : 0,
            'array' => count($val),
            default => strlen($val),
        };
    }

    public function some(...$keys): bool
    {
        return Arr::some($keys, fn ($key) => $this->has($key));
    }

    public function all(...$keys): bool
    {
        return Arr::every($keys, fn ($key) => $this->has($key));
    }

    public function allGet(array $keys): array
    {
        return Arr::reduce(
            $keys,
            fn (array $set, $key, $alias) => $set + array(is_numeric($alias) ? $key : $alias => $this->get($key)),
            array(),
        );
    }

    public function allSet(array $values, string $prefix = null): static
    {
        Arr::each($values, fn($value, $key) => $this->set($prefix . $key, $value));

        return $this;
    }

    public function allRemove(...$keys): static
    {
        Arr::each($keys, fn($key) => $this->remove($key));

        return $this;
    }

    public function push($key, ...$values): static
    {
        $data = $this->get($key);

        if (!is_array($data)) {
            $data = (array) $data;
        }

        array_push($data, ...$values);

        return $this->set($key, $data);
    }

    public function pop($key)
    {
        $data = $this->get($key);

        if (is_array($data)) {
            $value = array_pop($data);

            $this->set($key, $data);

            return $value;
        }

        $this->remove($key);

        return $data;
    }

    public function unshift($key, ...$values): static
    {
        $data = $this->get($key);

        if (!is_array($data)) {
            $data = (array) $data;
        }

        array_unshift($data, ...$values);

        return $this->set($key, $data);
    }

    public function shift($key)
    {
        $data = $this->get($key);

        if (is_array($data)) {
            $value = array_shift($data);

            $this->set($key, $data);

            return $value;
        }

        $this->remove($key);

        return $data;
    }

    public function protect($key, callable $value): static
    {
        $this->protected[$key] = $value;

        return $this;
    }

    public function make($key, array $args = null, array $share = null, bool $throw = true)
    {
        $rule = $this->ruleGet($key);

        if (!$rule) {
            if ($throw) {
                throw new \LogicException(sprintf('No rule defined for: "%s"', $key));
            }

            return null;
        }

        $make = $this->cache[$rule['set']] ?? ($this->cache[$rule['set']] = $this->ruleMake($rule));

        return $make($args ?? array(), $share ?? array());
    }

    public function ruleDefaults(array $defaults): static
    {
        $this->defaults = array_replace_recursive($this->defaults, $defaults);

        return $this;
    }

    public function ruleName(string $name): string
    {
        return ltrim(strtolower($name), '\\');
    }

    public function ruleCheck($name, $value, string &$set = null, array &$rule = null): bool
    {
        if (!is_callable($rule) && !(is_array($value) && isset($value['class']))) {
            $set = null;
            $rule = null;

            return false;
        }

        $rule = is_callable($value) ? array('create' => $value) : $value;

        if (isset($rule['class']) && ($rule['inherit'] ?? $this->defaults['inherit'])) {
            $rule = array_replace_recursive($this->ruleGet($rule['class']), $rule);
        }

        $set = $this->ruleName($name);
        $rule = array_replace_recursive($this->ruleGet($name), $rule, compact('name', 'set'));

        return true;
    }

    public function ruleGet(string $class): array
    {
        return $this->rules[$class] ?? $this->rules[$this->maps[$this->ruleName($class)]] ?? Arr::first(
            $this->rules,
            fn ($rule, $key) => (
                '*' !== $key
                && is_array($rule)
                && empty($rule['class'])
                && is_subclass_of($class, $key)
                && ($rule['inherit'] ?? $this->defaults['inherit'])
            ),
        ) ?? $this->rules['*'] ?? array();
    }

    public function ruleParams(\ReflectionFunctionAbstract $method, array $rule): \Closure
    {
        $params = Arr::each(
            $method->getParameters(),
            static function (\ReflectionParameter $param) use ($rule) {
                $paramType = $param->getType();
                list($class, $type) = $paramType instanceof \ReflectionNamedType ? (
                    $paramType->isBuiltin() ? array(null, $paramType->getName()) : array($paramType->getName(), null)
                ) : array(null, null);

                return array(
                    $param,
                    $class,
                    $type,
                    isset($rule['substitutions'][$class]),
                );
            },
            true,
            true,
        );

        return function (array $args, array $share) use ($params, $rule) {
            $argsA = isset($rule['params']) ? array_merge($args, $this->ruleExpand($rule['params'] ?? array(), $share)) : $args;
            $argsB = $share;

            return Arr::reduce($params, function(array $params, array $info) use ($rule, &$argsA, &$argsB) {
                /** @var \ReflectionParameter */
                $param = $info[0];
                $class = $info[1];
                $type = $info[2];
                $sub = $info[3];

                if ($argsA && $this->ruleMatchParam($param, $class, $argsA, $value)) {
                    $params[] = $value;
                } elseif ($argsB && $this->ruleMatchParam($param, $class, $argsB, $value)) {
                    $params[] = $value;
                } elseif ($class) {
                    try {
                        $params[] = $sub ?
                            $this->ruleExpand($rule['subtitutions'][$class], $argsB, true) :
                            ($param->allowsNull() ? null : $this->make($class, null, $argsB));
                    } catch (\InvalidArgumentException $e) {}
                } elseif ($argsA && $type) {
                    $check = 'is_' . $type;

                    for ($i = 0, $j = count($argsB); $i < $j; $i++) {
                        if ($check($argsA[$i])) {
                            $params[] = array_splice($argsA, $i, 1)[0];
                            break;
                        }
                    }
                } elseif ($argsA) {
                    $params[] = $this->ruleExpand(array_shift($argsA));
                } elseif ($param->isVariadic()) {
                    $params = array_merge($param, $argsA);
                } else {
                    $params[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                }

                return $params;
            }, array());
        };
    }

    protected function ruleExpand($param, array $share = null, bool $createFromString = false)
    {
        if (is_string($param)) {
            return match(true) {
                '__box__' === $param => $this,
                $createFromString => $this->make($param),
                default => Val::cast($param),
            };
        }

        if (is_array($param)) {

        }

        return $param;
    }

    protected function ruleMatchParam(\ReflectionParameter $param, string|null $class, array &$search = null, &$found = null): bool
    {
        $found = null;

        if (!$class) {
            return false;
        }

        foreach ($search ?? array() as $key => $value) {
            if ($value instanceof $class || (null === $value && $param->allowsNull())) {
                $found = array_splice($search, $key, 1)[0];

                return true;
            }
        }

        return false;
    }

    protected function ruleMakeCallback(callable $creator, array $rule): \Closure
    {
        $fun = new \ReflectionFunction($creator);
        $params = $this->ruleParams($fun, $rule);

        return static fn(array $args, array $share) => $fun->invokeArgs($params($args, $share));
    }

    protected function ruleMake(array $rule): \Closure
    {
        if (isset($rule['create'])) {
            return $this->ruleMakeCallback($rule['create'], $rule);
        }

        $class = new \ReflectionClass($rule['class'] ?? $rule['name']);
        $params = null;
        $constructor = $class->getConstructor();

        if ($constructor) {
            $params = $this->ruleParams($constructor, $rule);
        }

        if ($class->isInterface()) {
            //PHP throws a fatal error rather than an exception when trying to instantiate an interface, detect it and throw an exception instead
            $closure = static fn() => throw new \InvalidArgumentException('Cannot instantiate interface');
        } else if ($params) {
            // Get a closure based on the type of object being created: Shared, normal or constructorless
            // This class has depenencies, call the $params closure to generate them based on $args and $share
            $closure = static fn(array $args, array $share) => new $class->name(...$params($args, $share));
        } else {
            // No constructor arguments, just instantiate the class
            $closure = static fn() => new $class->name;
        }

        if ($rule['shared'] ?? $this->defaults['shared']) {
            $closure = function (array $args, array $share) use ($class, $rule, $constructor, $params, $closure) {
                $instance = &Val::ref($rule['name'], $this->data, true);

                //Internal classes may not be able to be constructed without calling the constructor
                if ($class->isInternal()) {
                    $instance = $closure();
                } else {
                    //Otherwise, create the class without calling the constructor
                    $instance = $class->newInstanceWithoutConstructor();

                    // Now call this constructor after constructing all the dependencies. This avoids problems with cyclic references.
                    if ($constructor) {
                        $constructor->invokeArgs($instance, $params($args, $share));
                    }
                }

                return $instance;
            };
        }

        if (empty($rule['call'])) {
            return $closure;
        }

        // When $rule['call'] is set, wrap the closure in another closure which will call the required methods after constructing the object
		// By putting this in a closure, the loop is never executed unless call is actually set
		return function (array $args, array $share) use ($closure, $class, $rule) {
			// Construct the object using the original closure
			$object = $closure($args, $share);

			foreach ($rule['call'] as $call) {
				// Generate the method arguments using getParams() and call the returned closure
                $chain = str_starts_with($call[0], '->');
                $method = ltrim($call[0], '->');
				$params = $this->ruleParams($class->getMethod($method), array('shareInstances' => $rule['shareInstances'] ?? array()))(($this->ruleExpand($call[1] ?? array())), $share);
				$return = $object->{$method}(...$params);

				if (isset($call[2])) {
                    if (is_callable($call[2])) {
                        call_user_func($call[2], $return);
                    } elseif ($chain) {
						if ($rule['shared'] ?? false) {
                            $instance = &Val::ref($rule['name'], $this->data, true);
                            $instance = $return;
                        }

                        if (is_object($return)) {
                            $class = new \ReflectionClass(get_class($return));
                        }

						$object = $return;
					}
				}
			}

			return $object;
		};
    }

    public function __isset($name)
    {
        return $this->has($name);
    }

    public function &__get($name)
    {
        $val = &$this->get($name);

        return $val;
    }

    public function __set($name, $value)
    {
        $this->set($name, $value);
    }

    public function __unset($name)
    {
        $this->remove($name);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function &offsetGet(mixed $offset): mixed
    {
        $val = &$this->get($offset);

        return $val;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }
}
