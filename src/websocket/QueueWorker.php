<?php

declare(strict_types=1);

namespace Lychee\websocket;

use Lychee\container\Container;
use Lychee\queue\Job;
use Lychee\queue\QueueManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 队列消费 Worker：在 Workerman 自定义进程中消费队列任务。
 *
 * 使用 Workerman Timer 定期轮询队列，取到任务立即执行。
 * 无需单独启动 php lee queue:work，由 php lee worker 统一管理。
 */
class QueueWorker
{
    /**
     * @param array<string, mixed> $config 来自 config/worker.php 的 queue 子配置
     */
    public function __construct(
        private readonly Container $app,
        private readonly array $config,
    ) {
    }

    /**
     * 创建队列消费 Worker（不监听端口的自定义进程）。
     *
     * @return object Workerman\Worker
     */
    public function createWorker(): object
    {
        $workerClass = '\\Workerman\\Worker';

        /** @var object $worker */
        $worker        = new $workerClass();
        $worker->name  = 'QueueConsumer';
        $worker->count = (int) ($this->config['count'] ?? 1);

        $worker->onWorkerStart = $this->onWorkerStart(...);

        return $worker;
    }

    /**
     * Worker 进程启动后，设置 Timer 定期轮询队列。
     */
    private function onWorkerStart(mixed $worker): void
    {
        $timerClass = '\\Workerman\\Timer';
        $sleep      = max(0.1, (float) ($this->config['sleep'] ?? 1));
        $tries      = max(1, (int) ($this->config['tries'] ?? 3));
        $connection = $this->config['connection'] ?? null;
        $queue      = $this->config['queue'] ?? null;

        $timerClass::add($sleep, function () use ($connection, $queue, $tries): void {
            $this->poll($connection, $queue, $tries);
        });
    }

    /**
     * 单次轮询：取出一个任务并执行。
     */
    private function poll(?string $connection, ?string $queue, int $tries): void
    {
        /** @var QueueManager $manager */
        $manager   = $this->app->get(QueueManager::class);
        $connector = $manager->connection($connection);

        $job = $connector->pop($queue);

        if ($job === null) {
            return;
        }

        $this->processJob($job, $tries);
    }

    /**
     * 处理单个任务。
     */
    private function processJob(Job $job, int $maxTries): void
    {
        $name = $job->getName();

        try {
            $job->fire();

            if (!$job->isDeletedOrReleased()) {
                $job->delete();
            }
        } catch (Throwable $e) {
            $attempts = $job->attempts();

            if ($attempts >= $maxTries) {
                $job->markAsFailed();
                $job->failed($e);

                try {
                    $job->delete();
                } catch (Throwable) {
                }

                $this->log("Queue job failed: {$name} - {$e->getMessage()}");
            } else {
                $job->release(0);
            }
        }
    }

    /**
     * 记录日志（如果日志模块可用）。
     */
    private function log(string $message): void
    {
        if ($this->app->bound(LoggerInterface::class)) {
            $this->app->get(LoggerInterface::class)->error($message);
        }
    }
}
