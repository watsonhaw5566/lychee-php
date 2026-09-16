<?php

declare(strict_types=1);

namespace Lychee\container;

use think\Container as ThinkContainer;

/**
 * 依赖注入容器。
 *
 * 继承 think\Container 以兼容 Facade（Db::、Validate:: 等），
 * 同时提供应用路径、命名空间等框架级属性。
 *
 * @template T
 */
class Container extends ThinkContainer
{
    public string $rootPath    = '';
    public string $appPath     = '';
    public string $runtimePath = '';
    public string $configPath  = '';

    protected string $namespace = 'app';

    /**
     * @template TService
     * @param class-string<TService> $abstract
     * @return TService
     */
    public function get(string $abstract): mixed
    {
        return parent::get($abstract);
    }

    /**
     * @template TService
     * @param class-string<TService> $abstract
     * @return TService
     */
    public function make(string $abstract, array $vars = [], bool $newInstance = false): mixed
    {
        return parent::make($abstract, $vars, $newInstance);
    }

    public function has(string $name): bool
    {
        return parent::has($name) || class_exists($name);
    }

    /**
     * @template TService
     * @param class-string<TService> $abstract
     * @param callable(self): TService $factory
     */
    public function singleton(string $abstract, callable $factory): void
    {
        $this->bind($abstract, $factory);
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
