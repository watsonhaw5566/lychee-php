<?php

declare(strict_types=1);

namespace Lychee\log;

use Lychee\support\Manager;
use Psr\Log\LoggerInterface;

/**
 * 日志管理器。
 *
 * 继承 Manager 以复用驱动解析逻辑，支持多频道（channel）配置。
 * 通过 channel() 获取 PSR-3 Logger 实例，底层驱动负责实际写入。
 */
class LogManager extends Manager
{
    /**
     * 已创建的 Logger 实例缓存
     *
     * @var array<string, LoggerInterface>
     */
    protected array $loggers = [];

    /**
     * 驱动命名空间。
     */
    protected ?string $namespace = '\\Lychee\\log\\driver\\';

    /**
     * 获取指定频道的 Logger 实例。
     *
     * @param  string|null $name 频道名，为 null 时使用默认频道
     */
    public function channel(?string $name = null): LoggerInterface
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->loggers[$name] ??= $this->createLogger($name);
    }

    /**
     * 获取指定频道的驱动实例。
     *
     * 覆盖父类的 protected 方法为 public，供 Logger 写入日志时调用。
     *
     * @param  string|null $name 频道名
     * @return mixed 驱动实例（如 File）
     */
    public function driver(?string $name = null): mixed
    {
        return parent::driver($name);
    }

    /**
     * 创建指定频道的 Logger 实例。
     */
    protected function createLogger(string $name): LoggerInterface
    {
        $config = $this->getChannelConfig($name);
        $level  = $config['level'] ?? 'debug';

        return new Logger($this, $name, (string) $level);
    }

    /**
     * 获取默认频道名。
     */
    public function getDefaultDriver(): ?string
    {
        return $this->getConfig('default');
    }

    /**
     * 解析频道对应的驱动类型。
     */
    protected function resolveType(string $name): string
    {
        return (string) $this->getChannelConfig($name, 'type', 'file');
    }

    /**
     * 解析频道配置。
     */
    protected function resolveConfig(string $name): array
    {
        return $this->getChannelConfig($name);
    }

    /**
     * 创建驱动实例。
     */
    protected function createDriver(string $name): mixed
    {
        $type   = $this->resolveType($name);
        $config = $this->resolveConfig($name);
        $class  = $this->resolveClass($type);

        return $this->app->make($class, ['config' => $config], true);
    }

    /**
     * 获取日志配置。
     *
     * @param  string|null $name    配置键，支持点号分隔
     * @param  mixed       $default 默认值
     */
    public function getConfig(?string $name = null, mixed $default = null): mixed
    {
        $logConfig = $this->app->get('config')->get('log', []);

        if ($name === null) {
            return $logConfig;
        }

        if (!str_contains($name, '.')) {
            return $logConfig[$name] ?? $default;
        }

        [$channel, $key] = explode('.', $name, 2);

        return $logConfig['channels'][$channel][$key] ?? $default;
    }

    /**
     * 获取指定频道的完整配置。
     */
    protected function getChannelConfig(string $name, ?string $key = null, mixed $default = null): mixed
    {
        $channels = $this->getConfig('channels', []);
        $config   = $channels[$name] ?? [];

        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? $default;
    }
}
