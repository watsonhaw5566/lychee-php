<?php

declare(strict_types=1);

namespace Lychee\queue\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\input\Option;
use Lychee\console\Output;
use Lychee\queue\Job;
use Lychee\queue\QueueManager;
use Throwable;

/**
 * 队列消费命令。
 *
 * 用法：
 *   php lee queue:work --connection=redis --queue=default --sleep=3 --tries=3
 *
 * 持续从队列取出任务并执行，直到手动停止。
 */
class WorkCommand extends Command
{
    /** 退出码：正常（至少处理了一个任务，或无此约定的场景） */
    public const EXIT_SUCCESS = 0;

    /** 退出码：队列为空，未处理任何任务（供 queue:listen 父进程判断是否休眠） */
    public const EXIT_EMPTY = 2;

    protected string $name = 'queue:work';

    protected string $description = 'Process jobs on the queue';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);

        $this->addOption('connection', 'c', Option::VALUE_OPTIONAL, 'The queue connection to use', null);
        $this->addOption('queue', null, Option::VALUE_OPTIONAL, 'The queue to listen on', null);
        $this->addOption('sleep', null, Option::VALUE_OPTIONAL, 'Number of seconds to sleep when no job is available', '3');
        $this->addOption('tries', null, Option::VALUE_OPTIONAL, 'Number of times to attempt a job before logging it failed', '1');
        $this->addOption('once', null, Option::VALUE_NONE, 'Only process the next job on the queue');
        $this->addOption('stop-when-empty', null, Option::VALUE_NONE, 'Stop when the queue is empty');
    }

    protected function execute(Input $input, Output $output): int
    {
        /** @var QueueManager $manager */
        $manager    = $this->app->get(QueueManager::class);
        $connection = $input->getOption('connection') ?: $manager->getDefaultDriver();
        $queue      = $input->getOption('queue');
        $sleep      = (int) $input->getOption('sleep');
        $tries      = (int) $input->getOption('tries');
        $once       = (bool) $input->getOption('once');
        $stopEmpty  = (bool) $input->getOption('stop-when-empty');

        $connector = $manager->connection($connection);

        $output->writeln("<info>Processing jobs from connection [{$connection}]</info>");

        $processed = 0;

        while (true) {
            $job = $connector->pop($queue);

            if ($job === null) {
                if ($stopEmpty || $once) {
                    $output->writeln('<info>No more jobs. Exiting.</info>');

                    // --stop-when-empty 可能已处理过任务，此时仍视为成功；
                    // 完全空转则返回 EXIT_EMPTY，供 listen 父进程决定休眠
                    return $processed > 0 ? self::EXIT_SUCCESS : self::EXIT_EMPTY;
                }

                sleep($sleep);

                continue;
            }

            $this->processJob($job, $output, $tries);
            $processed++;

            if ($once) {
                return self::EXIT_SUCCESS;
            }
        }
    }

    /**
     * 处理单个任务。
     */
    protected function processJob(Job $job, Output $output, int $maxTries): void
    {
        $name = $job->getName();

        try {
            $output->writeln("  <comment>{$name}</comment> (attempt {$job->attempts()})");

            $job->fire();

            if (!$job->isDeletedOrReleased()) {
                $job->delete();
            }

            $output->writeln("    <info>OK</info>");
        } catch (Throwable $e) {
            $attempts = $job->attempts();

            if ($attempts >= $maxTries) {
                $job->markAsFailed();
                $job->failed($e);

                try {
                    $job->delete();
                } catch (Throwable) {
                    // ignore
                }

                $output->writeln("    <error>FAILED: {$e->getMessage()}</error>");
                logger()->error("Job failed: {$name}", [
                    'error'    => $e->getMessage(),
                    'attempts' => $attempts,
                ]);
            } else {
                $job->release(0);

                $output->writeln("    <comment>RETRY (attempt {$attempts}/{$maxTries})</comment>");
            }
        }
    }
}
