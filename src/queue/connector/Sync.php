<?php

declare(strict_types=1);

namespace Lychee\queue\connector;

use Lychee\queue\Connector;
use Lychee\queue\Job;
use Lychee\queue\job\Sync as SyncJob;

/**
 * 同步队列连接器。
 *
 * 推送任务时立即执行，不做持久化。适用于开发环境或无需异步的场景。
 */
class Sync extends Connector
{
    /**
     * @param array<string, mixed> $config sync 连接器无配置项
     */
    public function __construct(protected array $config = [])
    {
    }

    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        $queue = $queue ?? 'sync';

        $syncJob = new SyncJob($this->app, $this->createPayload($job, $data), $this->connection, $queue);
        $syncJob->fire();

        return null;
    }

    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        $queue = $queue ?? 'sync';

        $syncJob = new SyncJob($this->app, $payload, $this->connection, $queue);
        $syncJob->fire();

        return null;
    }

    public function pop(?string $queue = null): ?Job
    {
        return null;
    }

    public function size(?string $queue = null): int
    {
        return 0;
    }
}
