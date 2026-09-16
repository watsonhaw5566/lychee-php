<?php

declare(strict_types=1);

namespace Tests\stub\app\job;

use Lychee\queue\Job;
use RuntimeException;
use Throwable;

/**
 * 测试用队列任务。
 *
 * 将数据写入静态属性，供测试断言。
 */
class TestJob
{
    public static mixed $lastData  = null;
    public static int $fireCount   = 0;
    public static bool $shouldFail = false;

    public function fire(Job $job, mixed $data): void
    {
        self::$lastData = $data;
        self::$fireCount++;

        if (self::$shouldFail) {
            throw new RuntimeException('Job failed intentionally');
        }

        $job->delete();
    }

    public function failed(mixed $data, Throwable $e): void
    {
        // 失败回调
    }
}
