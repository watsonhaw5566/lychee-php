<?php

declare(strict_types=1);

namespace Lychee\queue\connector;

use Exception;
use Lychee\queue\Connector;
use Lychee\queue\Job;
use Lychee\queue\job\Redis as RedisJob;
use RedisException;
use RuntimeException;

/**
 * Redis 队列连接器。
 *
 * 使用 Redis List 存储主队列，Sorted Set 存储延迟队列和预留队列。
 * 支持延迟任务、任务重试（reserved 机制）。
 */
class Redis extends Connector
{
    protected \Redis $redis;

    protected string $default;

    protected ?int $retryAfter = 60;

    /**
     * @param array<string, mixed> $config
     * @param \Redis|null          $redis  可选，注入的 Redis 客户端（用于测试）
     * @throws Exception 当 redis 扩展未安装时
     */
    public function __construct(array $config = [], ?\Redis $redis = null)
    {
        if ($redis !== null) {
            $this->redis = $redis;
        } else {
            if (!extension_loaded('redis')) {
                throw new Exception('redis 扩展未安装');
            }

            $host       = (string) ($config['host'] ?? '127.0.0.1');
            $port       = (int) ($config['port'] ?? 6379);
            $timeout    = (float) ($config['timeout'] ?? 5);
            $persistent = (bool) ($config['persistent'] ?? false);
            $password   = $config['password'] ?? '';
            $select     = (int) ($config['select'] ?? 0);

            $client = new \Redis();

            $connected = $persistent
                ? @$client->pconnect($host, $port, $timeout)
                : @$client->connect($host, $port, $timeout);

            if (!$connected) {
                throw new RuntimeException(sprintf('Unable to connect to Redis server at %s:%d', $host, $port));
            }

            if ('' !== $password) {
                $client->auth($password);
            }

            if (0 !== $select) {
                $client->select($select);
            }

            $this->redis = $client;
        }

        $this->default    = (string) ($config['queue'] ?? 'default');
        $this->retryAfter = isset($config['retry_after']) ? (int) $config['retry_after'] : 60;
    }

    public function size(?string $queue = null): int
    {
        $queue = $this->getQueue($queue);

        return $this->redis->lLen($queue)
            + $this->redis->zCard($queue . ':delayed')
            + $this->redis->zCard($queue . ':reserved');
    }

    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        $delay = $options['delay'] ?? null;

        if ($delay !== null && $delay > 0) {
            $this->redis->zAdd(
                $this->getQueue($queue) . ':delayed',
                $this->availableAt((int) $delay),
                $payload
            );
        } else {
            $this->redis->rPush($this->getQueue($queue), $payload);
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? ($decoded['id'] ?? null) : null;
    }

    public function pop(?string $queue = null): ?Job
    {
        $queue = $this->getQueue($queue);

        $this->migrate($queue);

        $rawBody = $this->redis->lPop($queue);

        if ($rawBody === false || $rawBody === null) {
            return null;
        }

        $rawBody = (string) $rawBody;

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            // 损坏的 payload 移到 failed 集合
            try {
                $this->redis->zAdd($queue . ':failed', time(), $rawBody);
            } catch (RedisException) {
                // 忽略
            }

            return null;
        }

        $decoded['attempts'] = (int) ($decoded['attempts'] ?? 0) + 1;
        $reservedPayload     = json_encode($decoded, JSON_UNESCAPED_UNICODE);

        $availableAt = $this->availableAt($this->retryAfter ?? 60);
        $this->redis->zAdd($queue . ':reserved', $availableAt, $reservedPayload);

        return new RedisJob(
            $this->app,
            $this,
            $rawBody,
            $reservedPayload,
            $this->connection,
            $queue
        );
    }

    /**
     * 将到期的延迟任务和超时的预留任务移回主队列。
     */
    protected function migrate(string $queue): void
    {
        $this->migrateExpiredJobs($queue . ':delayed', $queue);

        if ($this->retryAfter !== null) {
            $this->migrateExpiredJobs($queue . ':reserved', $queue);
        }
    }

    /**
     * 将 sorted set 中到期的任务移到主队列 list。
     */
    public function migrateExpiredJobs(string $from, string $to): void
    {
        $jobs = $this->redis->zRangeByScore($from, '-inf', (string) time());

        if (empty($jobs)) {
            return;
        }

        $this->redis->zRemRangeByRank($from, 0, count($jobs) - 1);

        foreach ($jobs as $job) {
            $this->redis->rPush($to, $job);
        }
    }

    /**
     * 删除已完成的预留任务。
     */
    public function deleteReserved(string $queue, RedisJob $job): void
    {
        $this->redis->zRem($this->getQueue($queue) . ':reserved', $job->getReservedJob());
    }

    /**
     * 释放预留任务（重新入队，可选延迟）。
     */
    public function deleteAndRelease(string $queue, RedisJob $job, int $delay = 0): void
    {
        $prefixed = $this->getQueue($queue);
        $reserved = $job->getReservedJob();

        $this->redis->zRem($prefixed . ':reserved', $reserved);

        if ($delay > 0) {
            $this->redis->zAdd($prefixed . ':delayed', $this->availableAt($delay), $reserved);
        } else {
            $this->redis->rPush($prefixed, $reserved);
        }
    }

    protected function getQueue(?string $queue): string
    {
        return $queue ?? $this->default;
    }

    protected function availableAt(int $delay = 0): int
    {
        return time() + $delay;
    }

    protected function createPayloadArray(object|string $job, mixed $data = ''): array
    {
        return array_merge(parent::createPayloadArray($job, $data), [
            'id'       => $this->getRandomId(),
            'attempts' => 0,
        ]);
    }

    protected function getRandomId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception) {
            return uniqid('', true);
        }
    }
}
