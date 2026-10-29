<?php

declare(strict_types=1);

namespace Lychee\orm;

use Closure;
use Lychee\orm\exception\DbException;

/**
 * 多连接管理器。
 *
 * 对齐 ThinkPHP DbManager 的职责：按配置名解析 Connection 并缓存，
 * 可全局注入 SQL 日志回调。模型与 facade\Db 都通过本类获取连接。
 */
class ConnectionManager
{
    protected array $config = [
        'default'     => 'mysql',
        'connections' => [],
    ];

    /** @var array<string, Connection> */
    protected array $connections = [];

    protected ?Closure $logger = null;

    public function setConfig(array $config): void
    {
        $this->config = array_replace_recursive($this->config, $config);
    }

    public function getConfig(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        $segments = explode('.', $key);
        $value    = $this->config;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * 注入 SQL 日志回调。
     *
     * 回调签名：function(string $type, string $message): void
     * $type 固定为 'sql'，便于日志通道按级别过滤。
     */
    public function setLog(Closure $logger): void
    {
        $this->logger = $logger;

        foreach ($this->connections as $connection) {
            $connection->setLogger($logger);
        }
    }

    /**
     * 获取指定连接；传入 null 时使用默认连接。
     */
    public function connect(?string $name = null): Connection
    {
        $name = $name ?: (string) ($this->config['default'] ?? 'mysql');

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $conns = $this->config['connections'] ?? [];
        if (!isset($conns[$name]) || !is_array($conns[$name])) {
            throw new DbException("database connection [{$name}] not configured");
        }

        $connection = new Connection($name, $conns[$name]);
        if ($this->logger instanceof Closure) {
            $connection->setLogger($this->logger);
        }

        return $this->connections[$name] = $connection;
    }

    public function getDefaultName(): string
    {
        return (string) ($this->config['default'] ?? 'mysql');
    }
}
