<?php

declare(strict_types=1);

namespace Lychee\support;

use InvalidArgumentException;
use think\Container;
use think\helper\Str;

/**
 * 驱动管理器抽象基类。
 *
 * 提供多驱动的解析与缓存逻辑，子类实现 getDefaultDriver() / resolveType() /
 * resolveConfig() 即可接入新的驱动体系。
 */
abstract class Manager
{
    /** @var array<string, mixed> */
    protected array $drivers = [];

    /** 驱动类命名空间 */
    protected ?string $namespace = null;

    public function __construct(protected Container $app)
    {
    }

    /**
     * 获取驱动实例（带缓存）。
     */
    protected function driver(?string $name = null): mixed
    {
        $name = $name ?: $this->getDefaultDriver();

        if ($name === null) {
            throw new InvalidArgumentException(sprintf(
                'Unable to resolve NULL driver for [%s].',
                static::class
            ));
        }

        return $this->drivers[$name] = $this->getDriver($name);
    }

    protected function getDriver(string $name): mixed
    {
        return $this->drivers[$name] ?? $this->createDriver($name);
    }

    /**
     * 解析驱动类型。
     */
    protected function resolveType(string $name): string
    {
        return $name;
    }

    /**
     * 解析驱动配置。
     */
    protected function resolveConfig(string $name): mixed
    {
        return $name;
    }

    /**
     * 解析驱动类名。
     */
    protected function resolveClass(string $type): string
    {
        if ($this->namespace || str_contains($type, '\\')) {
            $class = str_contains($type, '\\') ? $type : $this->namespace . Str::studly($type);

            if (class_exists($class)) {
                return $class;
            }
        }

        throw new InvalidArgumentException("Driver [{$type}] not supported.");
    }

    /**
     * 解析传给驱动构造函数的参数。
     */
    protected function resolveParams(string $name): array
    {
        $config = $this->resolveConfig($name);

        return [$config];
    }

    /**
     * 创建驱动实例。
     */
    protected function createDriver(string $name): mixed
    {
        $type   = $this->resolveType($name);
        $method = 'create' . Str::studly($type) . 'Driver';
        $params = $this->resolveParams($name);

        if (method_exists($this, $method)) {
            return $this->{$method}(...$params);
        }

        $class = $this->resolveClass($type);

        return $this->app->invokeClass($class, $params);
    }

    /**
     * 移除已缓存的驱动实例。
     */
    public function forgetDriver(string|array|null $name = null): static
    {
        $name = $name ?? $this->getDefaultDriver();

        foreach ((array) $name as $driverName) {
            if (isset($this->drivers[$driverName])) {
                unset($this->drivers[$driverName]);
            }
        }

        return $this;
    }

    /**
     * 获取默认驱动名。
     */
    abstract public function getDefaultDriver(): ?string;

    /**
     * 动态调用转发到默认驱动。
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->driver()->{$method}(...$parameters);
    }
}
