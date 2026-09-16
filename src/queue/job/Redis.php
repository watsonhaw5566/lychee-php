<?php

declare(strict_types=1);

namespace Lychee\queue\job;

use Lychee\container\Container;
use Lychee\queue\Job;

/**
 * Redis 队列任务。
 *
 * 封装从 Redis 取出的任务，提供 delete / release 操作。
 */
class Redis extends Job
{
    /**
     * @param \Lychee\queue\connector\Redis $redis    Redis 连接器
     * @param string                         $job       原始 payload
     * @param string                         $reserved  预留 payload（带 attempts 计数）
     * @param string                         $connection 连接名
     * @param string                         $queue     队列名
     */
    public function __construct(
        Container $app,
        protected \Lychee\queue\connector\Redis $redis,
        protected string $job,
        protected string $reserved,
        string $connection,
        string $queue,
    ) {
        $this->app        = $app;
        $this->connection = $connection;
        $this->queue      = $queue;
    }

    public function delete(): void
    {
        parent::delete();

        $this->redis->deleteReserved($this->queue, $this);
    }

    public function release(int $delay = 0): void
    {
        parent::release();

        $this->redis->deleteAndRelease($this->queue, $this, $delay);
    }

    public function attempts(): int
    {
        return (int) $this->payload('attempts', 0);
    }

    public function getJobId(): mixed
    {
        return $this->payload('id');
    }

    public function getRawBody(): string
    {
        return $this->reserved;
    }

    public function getReservedJob(): string
    {
        return $this->reserved;
    }
}
