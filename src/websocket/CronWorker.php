<?php

declare(strict_types=1);

namespace Lychee\websocket;

use Lychee\container\Container;
use Lychee\cron\Scheduler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 定时任务调度 Worker：在 Workerman 自定义进程中运行 Scheduler。
 *
 * 对齐到分钟边界，每分钟触发一次 Scheduler::run()，执行到期任务。
 * 无需配置系统 crontab，也无需单独启动 php lee cron:schedule，由 php lee worker 统一管理。
 */
class CronWorker
{
    /**
     * @param array<string, mixed> $config 来自 config/worker.php 的 cron 子配置
     */
    public function __construct(
        private readonly Container $app,
        private readonly array $config,
    ) {
    }

    /**
     * 创建定时任务 Worker（不监听端口的自定义进程，固定单实例）。
     *
     * @return object Workerman\Worker
     */
    public function createWorker(): object
    {
        $workerClass = '\\Workerman\\Worker';

        /** @var object $worker */
        $worker       = new $workerClass();
        $worker->name = 'CronScheduler';
        // 定时任务必须单实例，否则同一分钟内会被多次触发
        $worker->count = 1;

        $worker->onWorkerStart = $this->onWorkerStart(...);

        return $worker;
    }

    /**
     * Worker 进程启动后，先对齐到下一分钟边界，再每分钟触发一次。
     */
    private function onWorkerStart(mixed $worker): void
    {
        $timerClass = '\\Workerman\\Timer';

        $align = 60 - (time() % 60);

        // 一次性对齐定时器：在下一分钟整点触发首次调度
        $timerClass::add($align, function () use ($timerClass): void {
            $this->tick();

            // 之后每 60 秒触发一次，持续运行
            $timerClass::add(60, $this->tick(...));
        }, [], false);
    }

    /**
     * 执行一次调度：取出到期任务并运行。
     */
    private function tick(): void
    {
        if (!$this->app->bound(Scheduler::class)) {
            return;
        }

        try {
            /** @var Scheduler $scheduler */
            $scheduler = $this->app->get(Scheduler::class);

            foreach ($scheduler->getDueTasks() as $name => $task) {
                try {
                    $task->run();
                } catch (Throwable $e) {
                    $this->log("Cron task failed: {$name} - {$e->getMessage()}");
                }
            }
        } catch (Throwable $e) {
            $this->log("Cron tick failed: {$e->getMessage()}");
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
