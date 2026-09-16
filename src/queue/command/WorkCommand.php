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

        while (true) {
            $job = $connector->pop($queue);

            if ($job === null) {
                if ($stopEmpty || $once) {
                    $output->writeln('<info>No more jobs. Exiting.</info>');

                    return 0;
                }

                sleep($sleep);

                continue;
            }

            $this->processJob($job, $output, $tries);

            if ($once) {
                return 0;
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
