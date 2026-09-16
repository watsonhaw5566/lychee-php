<?php

declare(strict_types=1);

namespace Lychee\queue\job;

use Lychee\container\Container;
use Lychee\queue\Job;

/**
 * 同步任务。
 *
 * 由 Sync 连接器创建，在 push 时立即执行。
 */
class Sync extends Job
{
    protected string $job;

    public function __construct(Container $app, string $job, string $connection, ?string $queue = null)
    {
        $this->app        = $app;
        $this->connection = $connection;
        $this->queue      = $queue ?? 'sync';
        $this->job        = $job;
    }

    public function attempts(): int
    {
        return 1;
    }

    public function getRawBody(): string
    {
        return $this->job;
    }

    public function getJobId(): string
    {
        return '';
    }
}
