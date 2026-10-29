<?php

declare(strict_types=1);

namespace Lychee\container;

use Closure;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * 轻量级依赖注入容器（PSR-11 兼容）。
 *
 * 支持：
 * - bind / instance / singleton / make / get / has / bound
 * - 反射自动装配（构造函数参数依赖解析）
 * - invoke / invokeClass / invokeMethod / invokeFunction
 * - setInstance / getInstance 全局单例访问
 *
 * 不实现：ArrayAccess / Countable / IteratorAggregate / resolving 回调 / pull 等高级特性。
 */
class Container implements ContainerInterface
{
    /** 容器全局实例 */
    protected static ?self $instance = null;

    /**
     * 已注册的类绑定（抽象 → 闭包或具体类名）。
     *
     * @var array<string, Closure|string>
     */
    protected array $bind = [];

    /**
     * 已缓存的对象实例。
     *
     * @var array<string, object|string>
     */
    protected array $instances = [];

    public string $rootPath    = '';
    public string $appPath     = '';
    public string $runtimePath = '';
    public string $configPath  = '';

    protected string $namespace = 'app';

    /**
     * 获取全局容器实例。
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 设置全局容器实例。
     */
    public static function setInstance(?self $instance): void
    {
        self::$instance = $instance;
    }

    /**
     * 绑定一个类、闭包或实例到容器。
     *
     * @param string|array<string, mixed> $abstract 类标识或 [标识 => 实现] 批量数组
     * @param Closure|string|object|null  $concrete 具体实现
     */
    public function bind(string|array $abstract, string|object|null $concrete = null): static
    {
        if (is_array($abstract)) {
            foreach ($abstract as $key => $val) {
                $this->bind($key, $val);
            }

            return $this;
        }

        if ($concrete instanceof Closure) {
            $this->bind[$abstract] = $concrete;
        } elseif (is_object($concrete)) {
            $this->instance($abstract, $concrete);
        } elseif (is_string($concrete) && $concrete !== $abstract) {
            $this->bind[$abstract] = $concrete;
        }

        return $this;
    }

    /**
     * 绑定单例工厂（等价于 bind，解析结果会被缓存）。
     */
    public function singleton(string $abstract, callable $factory): void
    {
        $this->bind($abstract, $factory instanceof Closure ? $factory : Closure::fromCallable($factory));
    }

    /**
     * 直接注册一个已有对象实例。
     */
    public function instance(string $abstract, object|string $instance): static
    {
        $this->instances[$abstract] = $instance;

        return $this;
    }

    /**
     * 判断是否已绑定或缓存。
     */
    public function bound(string $abstract): bool
    {
        return isset($this->bind[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * PSR-11：判断容器中是否可解析。
     *
     * 对已显式绑定的标识或存在的类都返回 true，便于 bindParams 自动装配。
     */
    public function has(string $id): bool
    {
        return $this->bound($id) || class_exists($id);
    }

    /**
     * PSR-11：按标识解析服务。
     */
    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    /**
     * 解析并返回对象，支持构造参数覆盖。
     *
     * @param array<int|string, mixed> $vars
     */
    public function make(string $abstract, array $vars = [], bool $newInstance = false): mixed
    {
        if (isset($this->instances[$abstract]) && !$newInstance) {
            return $this->instances[$abstract];
        }

        if (isset($this->bind[$abstract])) {
            $concrete = $this->bind[$abstract];

            if ($concrete instanceof Closure) {
                $object = $this->invokeFunction($concrete, $vars);
            } else {
                // 字符串别名：递归解析
                $object = $this->make($concrete, $vars, $newInstance);
            }
        } else {
            $object = $this->invokeClass($abstract, $vars);
        }

        if (!$newInstance) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * 执行一个 callable，可带自动装配。
     *
     * 支持形态：
     * - Closure
     * - 'funcName'
     * - 'Class::method'（静态方法）
     * - ['Class'|$instance, 'method']
     */
    public function invoke(mixed $callable, array $vars = []): mixed
    {
        if ($callable instanceof Closure) {
            return $this->invokeFunction($callable, $vars);
        }

        if (is_string($callable) && !str_contains($callable, '::')) {
            return $this->invokeFunction($callable, $vars);
        }

        return $this->invokeMethod($callable, $vars);
    }

    /**
     * 执行闭包或函数，并自动装配参数。
     */
    public function invokeFunction(string|Closure $function, array $vars = []): mixed
    {
        try {
            $reflect = new ReflectionFunction($function);
        } catch (ReflectionException $e) {
            throw new InvalidArgumentException('function not exists: ' . (is_string($function) ? $function : 'Closure'), 0, $e);
        }

        $args = $this->bindParams($reflect, $vars);

        return $function(...$args);
    }

    /**
     * 调用对象或类的方法，并自动装配参数。
     *
     * @param array{0: object|string, 1: string}|string $method
     */
    public function invokeMethod(array|string $method, array $vars = []): mixed
    {
        if (is_array($method)) {
            [$class, $methodName] = $method;
            $class                = is_object($class) ? $class : $this->invokeClass($class);
        } else {
            [$class, $methodName] = explode('::', $method);
        }

        try {
            $reflect = new ReflectionMethod($class, $methodName);
        } catch (ReflectionException $e) {
            $classStr = is_object($class) ? $class::class : $class;

            throw new InvalidArgumentException('method not exists: ' . $classStr . '::' . $methodName . '()', 0, $e);
        }

        $args = $this->bindParams($reflect, $vars);

        return $reflect->invokeArgs(is_object($class) ? $class : null, $args);
    }

    /**
     * 反射实例化类，支持构造参数自动装配。
     *
     * 若类定义了 public static __make(...) 方法，优先调用。
     */
    public function invokeClass(string $class, array $vars = []): object
    {
        try {
            $reflect = new ReflectionClass($class);
        } catch (ReflectionException $e) {
            throw new InvalidArgumentException('class not exists: ' . $class, 0, $e);
        }

        if ($reflect->hasMethod('__make')) {
            $method = $reflect->getMethod('__make');
            if ($method->isPublic() && $method->isStatic()) {
                $args = $this->bindParams($method, $vars);

                return $method->invokeArgs(null, $args);
            }
        }

        $constructor = $reflect->getConstructor();
        $args        = $constructor !== null ? $this->bindParams($constructor, $vars) : [];

        return $reflect->newInstanceArgs($args);
    }

    /**
     * 为反射目标绑定参数：
     * - 类型注入：按类名递归解析
     * - 标量参数：从 $vars 关联或位置取值，否则使用默认值
     *
     * @param array<int|string, mixed> $vars
     * @return array<int, mixed>
     */
    protected function bindParams(ReflectionFunctionAbstract $reflect, array $vars = []): array
    {
        if ($reflect->getNumberOfParameters() === 0) {
            return [];
        }

        // 数字索引数组按顺序填充；关联数组按名字匹配
        $isList = array_is_list($vars);
        $args   = [];

        foreach ($reflect->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();

            if ($param->isVariadic()) {
                return array_merge($args, array_values($vars));
            }

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $className = $type->getName();
                if ($className === 'self' && $param->getDeclaringClass() !== null) {
                    $className = $param->getDeclaringClass()->getName();
                }
                $args[] = $this->getObjectParam($className, $vars, $param);
                continue;
            }

            if ($isList && $vars !== []) {
                $args[] = array_shift($vars);
            } elseif (!$isList && array_key_exists($name, $vars)) {
                $args[] = $vars[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new InvalidArgumentException('method param miss: ' . $name);
            }
        }

        return $args;
    }

    /**
     * 解析类型为对象的参数。
     *
     * 若 $vars 首个元素已是目标类的实例则直接消费，否则通过 make 构造或回退到默认值。
     *
     * @param array<int|string, mixed> $vars
     */
    protected function getObjectParam(string $className, array &$vars, ReflectionParameter $param): mixed
    {
        $peek = $vars;
        $head = array_shift($peek);

        if ($head instanceof $className) {
            array_shift($vars);

            return $head;
        }

        if ($param->isDefaultValueAvailable()) {
            return $this->bound($className) ? $this->make($className) : $param->getDefaultValue();
        }

        return $this->make($className);
    }

    public function getRootPath(): string
    {
        return $this->rootPath;
    }

    public function getAppPath(): string
    {
        return $this->appPath;
    }

    public function getRuntimePath(): string
    {
        return $this->runtimePath;
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    public function runningInConsole(): bool
    {
        return PHP_SAPI === 'cli';
    }

    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function setNamespace(string $namespace): void
    {
        $this->namespace = $namespace;
    }
}
