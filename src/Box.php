<?php

namespace Ekok\Container;

use Ekok\Utils\Arr;
use Ekok\Utils\File;
use Ekok\Utils\Val;

class Box implements \ArrayAccess
{
    protected $data = array();
    protected $rules = array();
    protected $cache = array();
    protected $instances = array();
    protected $defaults = array(
        'inherit' => true,
        'shared' => false,
    );

    public function __construct(array $data = null, array $rules = null)
    {
        $this->allSet($data ?? array());
        $this->ruleAll($rules ?? array());
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

    public function loadRules(string ...$files): static
    {
        array_walk($files, fn(string $file) => $this->ruleAll(File::load($file) ?? array()));

        return $this;
    }

    public function with(\Closure $cb, string $key = null, bool $chain = true)
    {
        $result = $cb($key ? $this->get($key) : $this, $this);

        return $chain ? $this : $result;
    }

    public function has($key): bool
    {
        return Val::ref($key, $this->data, false, $exists) || $exists;
    }

    public function &get($key)
    {
        $val = &Val::ref($key, $this->data, true);

        return $val;
    }

    public function set($key, $value): static
    {
        $var = &Val::ref($key, $this->data, true);
        $var = $value;

        return $this;
    }

    public function remove($key): static
    {
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

    public function call(callable|string $cb, ...$args)
    {
        return $this->callArguments($cb, $args);
    }

    public function make(string $key, array $args = null, array $share = null)
    {
        if (__CLASS__ === $key || '_box_' === $key) {
            return $this;
        }

        return $this->instances[$key] ?? (function () use ($key, $args, $share) {
            $rule = $this->ruleGet($key);

            return $this->instances[$rule['set']] ?? (function () use ($key, $args, $share, $rule) {
                $make = $this->cache[$key] ?? $this->cache[$rule['set']] ?? ($this->cache[$rule['set']] = $this->ruleMake($key, $rule));

                return $make($args, $share);
            })();
        })();
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

    public function ruleGet(string $class): array
    {
        $set = $this->ruleName($class);

        return array('name' => $class, 'set' => $set) + (
            $this->rules[$class] ?? $this->rules[$set] ?? Arr::first(
                $this->rules,
                fn ($rule, $key) => (
                    '*' !== $key
                    && is_array($rule)
                    && empty($rule['class'])
                    && is_subclass_of($class, $key)
                    && $rule['inherit']
                ) ? $rule : null,
            ) ?? $this->rules['*'] ?? array()
        ) + $this->defaults;
    }

    public function ruleAdd(string $name, array|callable|string $rule = null): static
    {
        $set = $this->ruleName($name);

        if (is_callable($rule)) {
            $new = array('create' => $rule);
        } elseif (is_string($rule)) {
            $new = array('class' => $rule);
        } else {
            $new = $rule ?? array();
        }

        if (isset($new['class']) && ($new['inherit'] ?? $this->defaults['inherit'])) {
            $new = array_replace_recursive($this->ruleGet($new['class']), $new);
        }

        $this->rules[$set] = array_replace_recursive($this->ruleGet($name), $new);

        return $this;
    }

    public function ruleInject($instance, string $key = null): static
    {
        $this->instances[strtolower($key ?? get_class($instance))] = $instance;

        return $this;
    }

    public function ruleAll(array $rules): static
    {
        array_walk($rules, fn ($rule, $name) => $this->ruleAdd($name, $rule));

        return $this;
    }

    public function ruleParams(\ReflectionFunctionAbstract $method, array $rule = null): \Closure
    {
        return function (array $args = null, array $share = null) use ($method, $rule) {
            $useArgs = array_merge($args ?? array(), $this->ruleExpand($rule['params'] ?? array(), $share));
            $useShare = $share ?? array();

            return Arr::reduce($method->getParameters(), function(array $params, \ReflectionParameter $param) use ($rule, &$useArgs, &$useShare) {
                $type = $param->getType();
                $class = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
                $sub = $rule['substitutions'][$class] ?? null;

                if ($useArgs && $this->ruleMatchParam($param, $class, $useArgs, $value)) {
                    $params[] = $value;
                } elseif ($useShare && $this->ruleMatchParam($param, $class, $useShare, $value)) {
                    $params[] = $value;
                } elseif ($param->isVariadic()) {
                    $params = array_merge($params, array_splice($useArgs, 0));
                } elseif ($class) {
                    try {
                        $params[] = $sub ?
                            $this->ruleExpand($sub, $useShare, true) :
                            ($param->allowsNull() ? null : $this->make($class, null, $useShare));
                    } catch (\InvalidArgumentException $e) {}
                } elseif ($useArgs && $this->ruleMatchType($type?->getName(), $useArgs, $match)) {
                    $params[] = array_splice($useArgs, $match[0], 1)[0];
                } elseif ($useArgs) {
                    $params[] = $this->ruleExpand(array_shift($useArgs));
                } else {
                    $params[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                }

                return $params;
            }, array());
        };
    }

    public function callArguments(callable|string $cb, array $args = null, array $share = null)
    {
        $call = is_callable($cb) ? $cb : $this->callExpression($cb);
        $params = $this->ruleParams(
            is_array($call) ?
                new \ReflectionMethod($call[0], $call[1]) :
                new \ReflectionFunction($call)
        );

        return $call(...$params($args, $share));
    }

    public function callExpression(string $cb): array
    {
        $pos = false === ($found = strpos($cb, '@')) ? strpos($cb, ':') : $found;

        if (false === $pos) {
            throw new \LogicException(sprintf('Invalid call expression: %s', $cb));
        }

        $make = '@' === $cb[$pos];
        $class = substr($cb, 0, $pos);
        $method = substr($cb, $pos + 1);

        return array($make ? $this->make($class) : $class, ltrim($method, '@:'));
    }

    protected function ruleExpand($param, array $share = null, bool $createFromString = false)
    {
        return match(gettype($param)) {
            'array' => array_map(fn($param) => $this->ruleExpand($param, $share), $param),
            'object' => is_callable($param) ? $this->callArguments($param, null, $share) : $param,
            'string' => $createFromString ? $this->make($param) : Val::cast($param),
            default => $param,
        };
    }

    protected function ruleMatchParam(\ReflectionParameter $param, string|null $class, array &$search = null, &$found = null): bool
    {
        $found = null;

        if ($class) {
            foreach ($search ?? array() as $key => $value) {
                if ($value instanceof $class || (null === $value && $param->allowsNull())) {
                    $found = array_splice($search, $key, 1)[0];

                    return true;
                }
            }
        }

        return false;
    }

    protected function ruleMatchType(string|null $type, array $args, array &$match = null): bool
    {
        return $type && Arr::some($args, static fn($value) => ('is_' . $type)($value), $match);
    }

    protected function ruleMakeCallback(\ReflectionFunction $fun, array $rule): \Closure
    {
        $params = $this->ruleParams($fun, $rule);
        $closure =  fn(array $args = null, array $share = null) => $fun->invokeArgs($params($args, $share));

        if ($rule['shared']) {
            $closure = function(array $args = null, array $share = null) use ($closure, $rule) {
                $this->ruleInject($instance = $closure($args, $share), $rule['name']);

                return $instance;
            };
        }

        return $closure;
    }

    protected function ruleMake(string $name, array $rule): \Closure
    {
        if (isset($rule['create'])) {
            return $this->ruleMakeCallback(new \ReflectionFunction($rule['create']), $rule);
        }

        $class = new \ReflectionClass($rule['class'] ?? $rule['name'] ?? $name);
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
            $closure = static fn(array $args = null, array $share = null) => new $class->name(...$params($args, $share));
        } else {
            // No constructor arguments, just instantiate the class
            $closure = static fn() => new $class->name;
        }

        if ($rule['shared']) {
            $closure = function (array $args = null, array $share = null) use ($class, $rule, $constructor, $params, $closure) {
                $instance = $class->isInternal() ?
                    //Internal classes may not be able to be constructed without calling the constructor
                    $closure($args, $share) :
                    //Otherwise, create the class without calling the constructor
                    $class->newInstanceWithoutConstructor();

                $this->ruleInject($instance, $rule['name']);

                // Now call this constructor after constructing all the dependencies. This avoids problems with cyclic references.
                if ($constructor && !$class->isInternal()) {
                    $constructor->invokeArgs($instance, $params($args, $share));
                }

                return $instance;
            };
        }

        if (empty($rule['calls'])) {
            return $closure;
        }

        // When $rule['calls'] is set, wrap the closure in another closure which will call the required methods after constructing the object
		// By putting this in a closure, the loop is never executed unless call is actually set
		return function (array $args = null, array $share = null) use ($closure, $class, $rule) {
			// Construct the object using the original closure
			$object = $closure($args, $share);

			foreach (is_array($rule['calls'][0] ?? null) ? $rule['calls'] : array($rule['calls']) as $callArgs) {
				// Generate the method arguments using getParams() and call the returned closure
                $chain = '@' === $callArgs[0][0];
                $method = ltrim(array_shift($callArgs), '@');
				$params = $this->ruleParams($class->getMethod($method), array('shareInstances' => $rule['shareInstances'] ?? array()));
				$return = $object->{$method}(...$params($this->ruleExpand($callArgs), $share));

                if ($chain) {
                    if ($rule['shared']) {
                        $this->ruleInject($return, $rule['name']);
                    }

                    if (is_object($return)) {
                        $class = new \ReflectionClass(get_class($return));
                    }

                    $object = $return;
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
