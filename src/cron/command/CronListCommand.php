<?php

declare(strict_types=1);

namespace Lychee\cron\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\cron\Scheduler;

/**
 * 列出所有已注册的定时任务。
 *
 * 用法：php lee cron:list
 */
class CronListCommand extends Command
{
    protected string $name = 'cron:list';

    protected string $description = 'List all scheduled cron tasks';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
    }

    protected function execute(Input $input, Output $output): int
    {
        /** @var Scheduler $scheduler */
        $scheduler = $this->app->get(Scheduler::class);

        $tasks = $scheduler->getTasks();

        if (empty($tasks)) {
            $output->writeln('<comment>No scheduled tasks.</comment>');

            return 0;
        }

        $nameWidth = 4;
        $cronWidth = 4;
        $rows      = [];

        foreach ($tasks as $name => $task) {
            $nameWidth = max($nameWidth, strlen($name));
            $cronWidth = max($cronWidth, strlen($task->getCronExpression()));

            $rows[] = [$name, $task->getCronExpression(), $task->getDescription()];
        }

        $output->writeln('<info>Scheduled tasks:</info> (' . count($tasks) . ')');
        $output->writeln('');

        foreach ($rows as [$name, $cron, $description]) {
            $line = sprintf(
                '  <comment>%s</comment>  %s  %s',
                str_pad($name, $nameWidth, ' '),
                str_pad($cron, $cronWidth, ' '),
                $description
            );

            $output->writeln(rtrim($line));
        }

        $output->writeln('');

        return 0;
    }
}
