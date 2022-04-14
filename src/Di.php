<?php

declare(strict_types=1);

namespace Ekok\Container;

use Ekok\Utils\Arr;
use Ekok\Utils\Call;
use Ekok\Utils\File;
use Ekok\Utils\Val;

class Di
{
    private $selfAlias = 'di';
    private $rules = array();
    private $maps = array();
    private $cache = array();
    private $tags = array();
    private $instances = array();
    private $defaults = array(
        'inherit' => true,
        'shared' => false,
    );

    public function __construct(array $rules = null)
    {
        $this->register($rules ?? array());
    }

    public static function ruleName(string $name): string
    {
        return ltrim(strtolower($name), '\\');
    }

    public function getSelfAlias(): string
    {
        return $this->selfAlias;
    }

    public function setSelfAlias(string $alias): static
    {
        $this->selfAlias = $alias;

        return $this;
    }

    public function getDefaults(): array
    {
        return $this->defaults;
    }

    public function setDefaults(array $defaults): static
    {
        $this->defaults = array_replace_recursive($this->defaults, $defaults);

        return $this;
    }

    public function getRule(string $class): array
    {
        $set = static::ruleName($class);
        $add = array('name' => $class, 'set' => $set);

        return (
            $this->rules[$class] ??
            $this->rules[$set] ??
            $this->rules[$this->maps[$class] ?? null] ??
            Arr::first(
                $this->rules,
                fn ($rule, $key) => (
                    '*' !== $key
                    && is_array($rule)
                    && empty($rule['class'])
                    && is_subclass_of($class, $key)
                    && $rule['inherit']
                ) ? $add + $rule : null,
            ) ?? $this->rules['*'] ?? array()
        ) + $add + $this->defaults;
    }

    public function setRule(string $name, array|callable|string $rule = null): static
    {
        $set = static::ruleName($name);

        if (is_callable($rule) || (is_string($rule) && Call::check($rule))) {
            $new = array('create' => $rule);
        } elseif (is_string($rule)) {
            $new = array('class' => $rule);
        } else {
            $new = $rule ?? array();
        }

        if (isset($new['class']) && ($new['inherit'] ?? $this->defaults['inherit'])) {
            $new = array_replace_recursive($this->getRule($new['class']), $new);
        }

        $this->rules[$set] = array_replace_recursive($this->getRule($name), $new);

        if ($alias = $this->rules[$set]['alias'] ?? null) {
            $use = is_string($alias) ? $alias : ($this->rules[$set]['class'] ?? $name);

            $this->maps[$use] = $set;
        }

        return $this;
    }

    public function getAlias(string $name): string|null
    {
        return $this->maps[$name] ?? null;
    }

    public function setAlias(string $name, string $alias): static
    {
        $this->maps[$name] = $this->maps[$alias] ?? strtolower($alias);

        return $this;
    }

    public function load(string ...$files): static
    {
        array_walk($files, fn(string $file) => $this->register(File::load($file) ?? array()));

        return $this;
    }

    public function register(array $rules): static
    {
        array_walk($rules, fn ($rule, $name) => $this->setRule($name, $rule));

        return $this;
    }

    public function get(string $key): object
    {
        return $this->make($key);
    }

    public function set($instance, array $rule = null): static
    {
        $name = static::ruleName($rule['name'] ?? get_class($instance));
        $alias = $rule['alias'] ?? null;

        $this->instances[$name] = $instance;

        if ($alias) {
            $use = is_string($alias) ? $alias : ($rule['class'] ?? get_class($instance));

            $this->maps[$use] = $name;
        }

        if (isset($rule['tags'])) {
            Arr::each((array) $rule['tags'], function ($tag) use ($name) {
                $tags = &$this->tags[$tag];
                $tags[] = $name;
            });
        }

        return $this;
    }

    public function tagged(string ...$tags): array
    {
        return array_reduce(
            $tags,
            fn (array $tagged, string $tag) => array_merge(
                $tagged,
                array_map(
                    fn($name) => $this->make($name),
                    $this->tags[$tag] ?? array(),
                ),
            ),
            array(),
        );
    }

    public function make(string $key, array $args = null, array $share = null)
    {
        if (__CLASS__ === $key || $this->selfAlias === $key) {
            return $this;
        }

        return (
            $this->instances[$key] ??
            $this->instances[$this->maps[$key] ?? null] ??
            (function () use ($key, $args, $share) {
                $rule = $this->getRule($key);

                return $this->instances[$rule['set']] ?? (function () use ($key, $args, $share, $rule) {
                    return (
                        $this->cache[$key] ??
                        $this->cache[$rule['set']] ??
                        ($this->cache[$rule['set']] = $this->getClosure($key, $rule))
                    )($args, $share);
                })();
            })()
        );
    }

    public function getParams(\ReflectionFunctionAbstract $method, array $rule = null): \Closure
    {
        return function (array $args = null, array $share = null) use ($method, $rule) {
            $useArgs = array_merge($args ?? array(), $this->expand($rule['params'] ?? array(), $share));
            $useShare = $share ?? array();

            return Arr::reduce($method->getParameters(), function(array $params, \ReflectionParameter $param) use ($rule, &$useArgs, &$useShare) {
                $type = $param->getType();

                array_push(
                    $params,
                    ...Arr::first(
                        $type instanceof \ReflectionUnionType ? $type->getTypes() : array($type),
                        function (\ReflectionNamedType|null $type) use ($param, $rule, &$useArgs, &$useShare) {
                            return $this->getParam($param, $type, $rule, $useArgs, $useShare);
                        }
                    ),
                );

                return $params;
            }, array());
        };
    }

    public function call(callable|string $cb, ...$args)
    {
        return $this->callArguments($cb, $args);
    }

    public function callArguments(callable|string $cb, array $args = null, array $share = null)
    {
        $call = $this->getCallable($cb, $callable);

        if (!$callable) {
            throw new \BadMethodCallException(sprintf(
                'Call to undefined method %s::%s',
                is_string($call[0]) ? $call[0] : get_class($call[0]),
                $call[1],
            ));
        }

        $params = $this->getParams(
            is_array($call) ?
                new \ReflectionMethod(...$call) :
                new \ReflectionFunction($call)
        );

        return $call(...$params($args, $share));
    }

    public function getCallable(callable|string $cb, bool &$callable = null): callable|array
    {
        $call = is_callable($cb) ? $cb : $this->callExpression($cb);
        $callable = is_callable($call);

        return $call;
    }

    public function callExpression(string $cb): callable|array
    {
        if (!Call::check($cb, $pos)) {
            // allow invokable class
            return $this->make($cb);
        }

        $make = '@' === $cb[$pos];
        $class = substr($cb, 0, $pos);
        $method = ltrim(substr($cb, $pos + 1), '@:');

        return array($make ? $this->make($class) : $class, $method);
    }

    private function getParam(\ReflectionParameter $param, \ReflectionType|null $type, array|null $rule, array &$args, array &$share): array|null
    {
        $class = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
        $sub = $rule['substitutions'][$class] ?? null;
        $params = null;

        if ($args && $this->matchClass($param, $class, $args, $value)) {
            $params[] = $value;
        } elseif ($share && $this->matchClass($param, $class, $share, $value)) {
            $params[] = $value;
        } elseif ($param->isVariadic()) {
            $params = array_merge($params ?? array() , array_values($args));
            $args = array();
        } elseif ($class) {
            try {
                $params[] = $sub ?
                    $this->expand($sub, $share, true) :
                    ($param->allowsNull() ? null : $this->make($class, null, $share));
            } catch (\InvalidArgumentException $e) {}
        } elseif (isset($args[$param->name])) {
            $params[] = $args[$param->name];
            unset($args[$param->name]);
        } elseif ($args && $type instanceof \ReflectionNamedType && $this->matchType($type->getName(), $args, $match)) {
            $params[] = array_splice($args, $match[0], 1)[0];
        } elseif ($args) {
            $params[] = $this->expand(array_shift($args));
        } else {
            $params[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
        }

        return $params;
    }

    private function expand($param, array $share = null, bool $createFromString = false)
    {
        return match(gettype($param)) {
            'array' => array_map(fn($param) => $this->expand($param, $share), $param),
            'object' => is_callable($param) ? $this->callArguments($param, null, $share) : $param,
            'string' => $createFromString ? $this->make($param) : Val::cast($param),
            default => $param,
        };
    }

    private function matchClass(\ReflectionParameter $param, string|null $class, array &$search = null, &$found = null): bool
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

    private function matchType(string $type, array $args, array &$match = null): bool
    {
        return $type && Arr::some($args, static fn($value) => ('is_' . $type)($value), $match);
    }

    private function getCallbackClosure($factory, array $rule): \Closure
    {
        if (is_string($factory) && Call::check($factory)) {
            $call = $this->callExpression($factory);
            $fun = new \ReflectionMethod(...$call);
            $params = $this->getParams($fun, $rule);
            $closure =  fn(array $args = null, array $share = null) => $fun->invokeArgs(
                is_string($call[0]) ? null : $call[0],
                $params($args, $share),
            );
        } else {
            $fun = new \ReflectionFunction($factory);
            $params = $this->getParams($fun, $rule);
            $closure = static fn(array $args = null, array $share = null) => $fun->invokeArgs(
                $params($args, $share),
            );
        }

        if ($rule['shared']) {
            $closure = function(array $args = null, array $share = null) use ($closure, $rule) {
                $this->set($instance = $closure($args, $share), $rule);

                return $instance;
            };
        }

        return $closure;
    }

    private function getClosure(string $name, array $rule): \Closure
    {
        if (isset($rule['create'])) {
            return $this->getCallbackClosure($rule['create'], $rule);
        }

        $class = new \ReflectionClass($rule['class'] ?? $rule['name'] ?? $name);
        $params = null;
        $constructor = $class->getConstructor();

        if ($constructor) {
            $params = $this->getParams($constructor, $rule);
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

                $this->set($instance, $rule);

                // Now call this constructor after constructing all the dependencies. This avoids problems with cyclic references.
                if ($constructor && !$class->isInternal()) {
                    $constructor->invokeArgs($instance, $params($args, $share));
                }

                return $instance;
            };
        }

        if ($class->implementsInterface(ContainerAwareInterface::class)) {
            $closure = function (array $args = null, array $share = null) use ($closure) {
                /** @var ContainerAwareInterface */
                $obj = $closure($args, $share);
                $obj->setContainer($this);

                return $obj;
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
				$params = $this->getParams($class->getMethod($method), array('shareInstances' => $rule['shareInstances'] ?? array()));
				$return = $object->{$method}(...$params($this->expand($callArgs), $share));

                if ($chain) {
                    if ($rule['shared']) {
                        $this->set($return, $rule);
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
}
