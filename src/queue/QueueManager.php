<?php

declare(strict_types=1);

namespace Lychee\queue;

use Lychee\support\Manager;

/**
 * 队列管理器。
 *
 * 继承 Manager，支持多连接配置（sync / redis）。
 * 通过 connection() 获取连接器实例。
 */
class QueueManager extends Manager
{
    /**
     * 驱动命名空间。
     */
    protected ?string $namespace = '\\Lychee\\queue\\connector\\';
    /**
     * 获取指定连接的连接器。
     *
     * @param  string|null $name 连接名，为 null 时使用默认连接
     */
    public function connection(?string $name = null): Connector
    {
        return $this->driver($name);
    }

    /**
     * 获取默认连接名。
     */
    public function getDefaultDriver(): ?string
    {
        return $this->app->get('config')->get('queue.default');
    }

    /**
     * 解析连接对应的驱动类型。
     */
    protected function resolveType(string $name): string
    {
        return (string) $this->getConnectionConfig($name, 'type', 'sync');
    }

    /**
     * 解析连接配置。
     */
    protected function resolveConfig(string $name): array
    {
        return $this->getConnectionConfig($name);
    }

    /**
     * 创建驱动实例（连接器）。
     */
    protected function createDriver(string $name): mixed
    {
        $type   = $this->resolveType($name);
        $config = $this->resolveConfig($name);
        $class  = $this->resolveClass($type);

        /** @var Connector $connector */
        $connector = $this->app->make($class, ['config' => $config], true);
        $connector->setApp($this->app);
        $connector->setConnection($name);

        return $connector;
    }

    /**
     * 获取队列配置。
     *
     * @param  string|null $name    配置键，支持点号分隔
     * @param  mixed       $default 默认值
     */
    public function getConfig(?string $name = null, mixed $default = null): mixed
    {
        $queueConfig = $this->app->get('config')->get('queue', []);

        if ($name === null) {
            return $queueConfig;
        }

        if (!str_contains($name, '.')) {
            return $queueConfig[$name] ?? $default;
        }

        [$connection, $key] = explode('.', $name, 2);

        return $queueConfig['connections'][$connection][$key] ?? $default;
    }

    /**
     * 获取指定连接的配置。
     */
    protected function getConnectionConfig(string $name, ?string $key = null, mixed $default = null): mixed
    {
        $connections = $this->getConfig('connections', []);
        $config      = $connections[$name] ?? [];

        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? $default;
    }
}
