<?php

declare(strict_types=1);

namespace Lychee\filesystem;

use InvalidArgumentException;
use Lychee\config\Config;
use Lychee\support\Manager;

/**
 * 文件系统管理器。
 *
 * 支持多磁盘配置（local 等），通过 disk() 获取驱动实例。
 */
class FilesystemManager extends Manager
{
    protected ?string $namespace = '\\Lychee\\filesystem\\driver\\';

    /**
     * 获取指定磁盘的驱动实例。
     */
    public function disk(?string $name = null): Driver
    {
        return $this->driver($name);
    }

    public function getDefaultDriver(): ?string
    {
        return $this->getConfig('default');
    }

    protected function resolveType(string $name): string
    {
        return (string) $this->getDiskConfig($name, 'type', 'local');
    }

    protected function resolveConfig(string $name): array
    {
        $config = $this->getDiskConfig($name);

        return is_array($config) ? $config : [];
    }

    protected function createDriver(string $name): mixed
    {
        $type   = $this->resolveType($name);
        $config = $this->resolveConfig($name);
        $class  = $this->resolveClass($type);

        return $this->app->make($class, ['config' => $config], true);
    }

    public function getConfig(?string $name = null, mixed $default = null): mixed
    {
        /** @var Config $config */
        $config = $this->app->get('config');

        if ($name === null) {
            return $config->get('filesystem', []);
        }

        return $config->get('filesystem.' . $name, $default);
    }

    public function getDiskConfig(string $disk, ?string $name = null, mixed $default = null): mixed
    {
        $config = $this->getConfig("disks.{$disk}");

        if ($config === null) {
            throw new InvalidArgumentException("Disk [{$disk}] not found.");
        }

        if ($name === null) {
            return $config;
        }

        return is_array($config) ? ($config[$name] ?? $default) : $default;
    }
}
