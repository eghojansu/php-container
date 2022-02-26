<?php

namespace Ekok\Container;

use Ekok\Utils\Arr;
use Ekok\Utils\File;
use Ekok\Utils\Val;

class Di
{
    protected $rules = array();
    protected $maps = array();
    protected $cache = array();
    protected $tags = array();
    protected $instances = array();
    protected $defaults = array(
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

    public function load(string ...$files): static
    {
        array_walk($files, fn(string $file) => $this->register(File::load($file) ?? array()));

        return $this;
    }

    public function call(callable|string $cb, ...$args)
    {
        return $this->callArguments($cb, $args);
    }

    public function make(string $key, array $args = null, array $share = null)
    {
        if (__CLASS__ === $key || '_di_' === $key) {
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

    public function tagged(string ...$tags): array
    {
        return Arr::reduce(
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

    public function defaults(array $defaults): static
    {
        $this->defaults = array_replace_recursive($this->defaults, $defaults);

        return $this;
    }

    public function getRule(string $class): array
    {
        $set = static::ruleName($class);

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

    public function addRule(string $name, array|callable|string $rule = null): static
    {
        $set = static::ruleName($name);

        if (is_callable($rule)) {
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

        return $this;
    }

    public function inject($instance, array $rule = null): static
    {
        $name = strtolower($rule['name'] ?? get_class($instance));
        $alias = $rule['alias'] ?? null;

        $this->instances[$name] = $instance;

        if (($a = is_string($alias)) || ($alias && isset($rule['class']))) {
            $use = $a ? $alias : $rule['class'];

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

    public function register(array $rules): static
    {
        array_walk($rules, fn ($rule, $name) => $this->addRule($name, $rule));

        return $this;
    }

    public function getParams(\ReflectionFunctionAbstract $method, array $rule = null): \Closure
    {
        return function (array $args = null, array $share = null) use ($method, $rule) {
            $useArgs = array_merge($args ?? array(), $this->expand($rule['params'] ?? array(), $share));
            $useShare = $share ?? array();

            return Arr::reduce($method->getParameters(), function(array $params, \ReflectionParameter $param) use ($rule, &$useArgs, &$useShare) {
                $type = $param->getType();
                $class = $type instanceof \ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
                $sub = $rule['substitutions'][$class] ?? null;

                if ($useArgs && $this->matchClass($param, $class, $useArgs, $value)) {
                    $params[] = $value;
                } elseif ($useShare && $this->matchClass($param, $class, $useShare, $value)) {
                    $params[] = $value;
                } elseif ($param->isVariadic()) {
                    $params = array_merge($params, array_splice($useArgs, 0));
                } elseif ($class) {
                    try {
                        $params[] = $sub ?
                            $this->expand($sub, $useShare, true) :
                            ($param->allowsNull() ? null : $this->make($class, null, $useShare));
                    } catch (\InvalidArgumentException $e) {}
                } elseif (isset($useArgs[$param->name])) {
                    $params[] = $useArgs[$param->name];
                    unset($useArgs[$param->name]);
                } elseif ($useArgs && $this->matchType($type?->getName(), $useArgs, $match)) {
                    $params[] = array_splice($useArgs, $match[0], 1)[0];
                } elseif ($useArgs) {
                    $params[] = $this->expand(array_shift($useArgs));
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
        $params = $this->getParams(
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

    protected function expand($param, array $share = null, bool $createFromString = false)
    {
        return match(gettype($param)) {
            'array' => array_map(fn($param) => $this->expand($param, $share), $param),
            'object' => is_callable($param) ? $this->callArguments($param, null, $share) : $param,
            'string' => $createFromString ? $this->make($param) : Val::cast($param),
            default => $param,
        };
    }

    protected function matchClass(\ReflectionParameter $param, string|null $class, array &$search = null, &$found = null): bool
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

    protected function matchType(string|null $type, array $args, array &$match = null): bool
    {
        return $type && Arr::some($args, static fn($value) => ('is_' . $type)($value), $match);
    }

    protected function getCallbackClosure(\ReflectionFunction $fun, array $rule): \Closure
    {
        $params = $this->getParams($fun, $rule);
        $closure =  fn(array $args = null, array $share = null) => $fun->invokeArgs($params($args, $share));

        if ($rule['shared']) {
            $closure = function(array $args = null, array $share = null) use ($closure, $rule) {
                $this->inject($instance = $closure($args, $share), $rule);

                return $instance;
            };
        }

        return $closure;
    }

    protected function getClosure(string $name, array $rule): \Closure
    {
        if (isset($rule['create'])) {
            return $this->getCallbackClosure(new \ReflectionFunction($rule['create']), $rule);
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

                $this->inject($instance, $rule);

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
				$params = $this->getParams($class->getMethod($method), array('shareInstances' => $rule['shareInstances'] ?? array()));
				$return = $object->{$method}(...$params($this->expand($callArgs), $share));

                if ($chain) {
                    if ($rule['shared']) {
                        $this->inject($return, $rule);
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
