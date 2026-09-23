<?php

declare(strict_types=1);

namespace Lychee\cache;

use InvalidArgumentException;
use Lychee\support\Manager;
use Psr\SimpleCache\CacheInterface;
use DateInterval;

/**
 * 缓存管理器。
 *
 * 继承 Manager 复用驱动解析逻辑，通过 store() 切换缓存通道（file / redis 等）。
 * 管理器本身也实现 PSR-16，所有调用代理到默认通道。
 */
class CacheManager extends Manager implements CacheInterface
{
    /**
     * 驱动命名空间。
     */
    protected ?string $namespace = '\\Lychee\\cache\\driver\\';

    /**
     * 获取指定通道的缓存驱动。
     *
     * @param  string|null $name 通道名，为 null 时使用默认通道
     */
    public function store(?string $name = null): Driver
    {
        /** @var Driver */
        return $this->driver($name);
    }

    /**
     * 获取默认通道名。
     */
    public function getDefaultDriver(): ?string
    {
        $default = (string) ($this->getConfig()['default'] ?? 'file');

        return $default !== '' ? $default : null;
    }

    /**
     * 创建驱动实例前校验通道配置是否存在。
     */
    protected function createDriver(string $name): mixed
    {
        $stores = $this->getConfig()['stores'] ?? [];

        if (!isset($stores[$name]) || !is_array($stores[$name])) {
            throw new InvalidArgumentException("Undefined cache store: {$name}");
        }

        return parent::createDriver($name);
    }

    /**
     * 解析通道对应的驱动类型。
     */
    protected function resolveType(string $name): string
    {
        return (string) ($this->getStoreConfig($name)['type'] ?? 'File');
    }

    /**
     * 解析通道配置。
     */
    protected function resolveConfig(string $name): array
    {
        return $this->getStoreConfig($name);
    }

    /**
     * 获取全部缓存配置。
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = $this->app->get('config')->get('cache', []);

        return $config;
    }

    /**
     * 获取指定通道的配置。
     *
     * @return array<string, mixed>
     */
    protected function getStoreConfig(string $name): array
    {
        $stores = $this->getConfig()['stores'] ?? [];

        return is_array($stores[$name] ?? null) ? $stores[$name] : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()->get($key, $default);
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        return $this->store()->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->store()->delete($key);
    }

    public function clear(): bool
    {
        return $this->store()->clear();
    }

    public function has(string $key): bool
    {
        return $this->store()->has($key);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        return $this->store()->getMultiple($keys, $default);
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        return $this->store()->setMultiple($values, $ttl);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->store()->deleteMultiple($keys);
    }
}
