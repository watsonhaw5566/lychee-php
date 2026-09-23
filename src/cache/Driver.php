<?php

declare(strict_types=1);

namespace Lychee\cache;

use Closure;
use DateInterval;
use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use Psr\SimpleCache\CacheInterface;

/**
 * 缓存驱动基类。
 *
 * 实现 PSR-16，并提供 TTL 归一化、序列化、计数器（inc/dec）、
 * remember / pull 等通用能力。具体驱动只需实现底层读写原语：
 * getRaw / set / delete / clear；具备原子计数能力的驱动可覆盖 inc/dec。
 */
abstract class Driver implements CacheInterface
{
    /**
     * 底层驱动句柄（如 \Redis）。
     */
    protected ?object $handler = null;

    /**
     * 驱动配置。
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * 获取实际的缓存标识（含前缀）。
     */
    public function getCacheKey(string $name): string
    {
        return ($this->options['prefix'] ?? '') . $name;
    }

    /**
     * 将 TTL 归一化为剩余秒数，0 表示永久。
     *
     * 支持整数秒、DateInterval、绝对时间 DateTimeInterface。
     */
    protected function getExpireTime(int|DateInterval|DateTimeInterface $expire): int
    {
        if ($expire instanceof DateTimeInterface) {
            $expire = $expire->getTimestamp() - time();
        } elseif ($expire instanceof DateInterval) {
            $expire = (int) DateTime::createFromFormat('U', (string) time())
                ->add($expire)
                ->format('U') - time();
        }

        return max(0, (int) $expire);
    }

    /**
     * 序列化数据。
     *
     * 数值原样存储为字符串，保证 Redis 端可使用原子 INCR/DECR；
     * 其它类型统一使用 PHP serialize。
     */
    protected function serialize(mixed $data): string
    {
        return is_numeric($data) ? (string) $data : serialize($data);
    }

    /**
     * 反序列化数据。
     *
     * 数值字符串还原为 int/float；数据损坏时抛 InvalidArgumentException，
     * 由 get 决定是否自愈删除。
     */
    protected function unserialize(string $data): mixed
    {
        if (is_numeric($data)) {
            $int = filter_var($data, FILTER_VALIDATE_INT);

            return $int === false ? (float) $data : $int;
        }

        $value = @unserialize($data);

        if ($value === false && $data !== serialize(false)) {
            throw new InvalidArgumentException('Invalid cache data.');
        }

        return $value;
    }

    /**
     * 解析默认值，支持闭包惰性求值。
     */
    protected function resolveDefault(mixed $default): mixed
    {
        return $default instanceof Closure ? $default() : $default;
    }

    /**
     * 读取底层原始数据。
     *
     * @return array{content: string, ttl: int}|null content 为序列化后的字符串，
     *                                                  ttl 为剩余秒数（0 表示永久）；不存在或已过期返回 null
     */
    abstract protected function getRaw(string $name): ?array;

    /**
     * 自增缓存（针对整数缓存）。
     *
     * 键不存在时按 0 处理，结果为 $step 且永久有效；
     * 键存在时保留原 TTL（绝对过期时刻不变）；
     * 值非整数时抛 InvalidArgumentException，不做隐式强转。
     *
     * 注意：默认实现为读-改-写，不具备跨进程原子性；
     * Redis 等驱动会覆盖为原子原生命令。
     */
    public function inc(string $name, int $step = 1): int
    {
        $raw = $this->getRaw($name);

        if ($raw === null) {
            $this->set($name, $step);

            return $step;
        }

        $value = $this->unserialize($raw['content']);

        if (!is_int($value)) {
            throw new InvalidArgumentException("Cache value of [{$name}] is not an integer.");
        }

        $value += $step;

        // 以剩余 TTL 回写，保持绝对过期时刻不变；ttl 为 0 时永久
        if ($raw['ttl'] > 0) {
            $this->set($name, $value, $raw['ttl']);
        } else {
            $this->set($name, $value);
        }

        return $value;
    }

    /**
     * 自减缓存，允许减到负数。
     */
    public function dec(string $name, int $step = 1): int
    {
        return $this->inc($name, -$step);
    }

    /**
     * 读取并删除缓存。
     */
    public function pull(string $name, mixed $default = null): mixed
    {
        $value = $this->get($name, $default);
        $this->delete($name);

        return $value;
    }

    /**
     * 缓存不存在时执行 $callback 并写入结果；存在则直接返回。
     *
     * 仅当缓存值为 null（未命中）时才执行回调。
     *
     * @param Closure|mixed                         $callback 闭包或直接写入的值
     * @param int|DateInterval|DateTimeInterface|null $ttl    写入时的有效期
     */
    public function remember(string $name, mixed $callback, int|DateInterval|DateTimeInterface|null $ttl = null): mixed
    {
        $value = $this->get($name);

        if ($value !== null) {
            return $value;
        }

        $value = $callback instanceof Closure ? $callback() : $callback;

        $this->set($name, $value, $ttl);

        return $value;
    }

    /**
     * 批量读取。
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * 批量写入。
     */
    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 批量删除。
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            if (!$this->delete((string) $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 返回底层句柄对象，可执行驱动的高级方法。
     */
    public function handler(): ?object
    {
        return $this->handler;
    }

    /**
     * 未知方法转发到底层句柄。
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->handler?->{$method}(...$args);
    }
}
