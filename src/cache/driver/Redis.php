<?php

declare(strict_types=1);

namespace Lychee\cache\driver;

use BadFunctionCallException;
use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use Redis as PhpRedis;
use Lychee\cache\Driver;

/**
 * Redis 缓存驱动（基于 phpredis 扩展）。
 *
 * 数据以原始字符串存储，数值类型可直接使用 Redis 原子 INCR/DECR；
 * 写入 TTL 通过 SETEX 实现。
 */
class Redis extends Driver
{
    /**
     * @var array<string, mixed>
     */
    protected array $options = [
        'host'       => '127.0.0.1',
        'port'       => 6379,
        'password'   => '',
        'select'     => 0,
        'timeout'    => 0,
        'expire'     => 0,
        'persistent' => false,
        'prefix'     => '',
    ];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge($this->options, $options);
    }

    /**
     * 连接 Redis（惰性连接）。
     */
    public function handler(): PhpRedis
    {
        if (!isset($this->handler)) {
            if (!extension_loaded('redis')) {
                throw new BadFunctionCallException('PHP redis extension is required.');
            }

            $handler = new PhpRedis();

            if ($this->options['persistent']) {
                $handler->pconnect(
                    (string) $this->options['host'],
                    (int) $this->options['port'],
                    (int) $this->options['timeout'],
                    'persistent_' . $this->options['select'],
                );
            } else {
                $handler->connect(
                    (string) $this->options['host'],
                    (int) $this->options['port'],
                    (int) $this->options['timeout'],
                );
            }

            if ($this->options['password'] !== '') {
                $handler->auth((string) $this->options['password']);
            }

            if ((int) $this->options['select'] !== 0) {
                $handler->select((int) $this->options['select']);
            }

            $this->handler = $handler;
        }

        return $this->handler;
    }

    /**
     * 读取底层原始数据。
     */
    protected function getRaw(string $name): ?array
    {
        $key   = $this->getCacheKey($name);
        $value = $this->handler()->get($key);

        if ($value === false) {
            return null;
        }

        $ttl = (int) $this->handler()->ttl($key); // -1 永久，-2 不存在

        return ['content' => (string) $value, 'ttl' => $ttl > 0 ? $ttl : 0];
    }

    /**
     * 判断缓存是否存在。
     */
    public function has(string $key): bool
    {
        return $this->handler()->exists($this->getCacheKey($key)) > 0;
    }

    /**
     * 读取缓存，数据损坏时自愈删除。
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->handler()->get($this->getCacheKey($key));

        if ($value === false) {
            return $this->resolveDefault($default);
        }

        try {
            return $this->unserialize((string) $value);
        } catch (InvalidArgumentException) {
            $this->delete($key);

            return $this->resolveDefault($default);
        }
    }

    /**
     * 写入缓存。
     */
    public function set(string $key, mixed $value, int|DateInterval|DateTimeInterface|null $ttl = null): bool
    {
        if ($ttl === null) {
            $ttl = (int) $this->options['expire'];
        }

        $cacheKey = $this->getCacheKey($key);
        $seconds  = $this->getExpireTime($ttl);
        $data     = $this->serialize($value);

        if ($seconds > 0) {
            $this->handler()->setex($cacheKey, $seconds, $data);
        } else {
            $this->handler()->set($cacheKey, $data);
        }

        return true;
    }

    /**
     * 自增（原子操作，天然保留 TTL）。
     */
    public function inc(string $name, int $step = 1): int
    {
        return (int) $this->handler()->incrBy($this->getCacheKey($name), $step);
    }

    /**
     * 自减（原子操作）。
     */
    public function dec(string $name, int $step = 1): int
    {
        return (int) $this->handler()->decrBy($this->getCacheKey($name), $step);
    }

    /**
     * 删除缓存。
     */
    public function delete(string $key): bool
    {
        return $this->handler()->del($this->getCacheKey($key)) > 0;
    }

    /**
     * 清空当前数据库（谨慎使用）。
     */
    public function clear(): bool
    {
        return $this->handler()->flushDB();
    }
}
