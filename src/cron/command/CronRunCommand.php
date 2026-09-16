<?php

declare(strict_types=1);

namespace Lychee\cron\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\cron\Scheduler;
use Throwable;

/**
 * 定时任务运行命令。
 *
 * 用法：php lee cron:run
 *
 * 由系统 crontab 每分钟调用一次，调度器会自动判断并执行到期任务。
 */
class CronRunCommand extends Command
{
    protected string $name = 'cron:run';

    protected string $description = 'Run scheduled cron tasks';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
    }

    protected function execute(Input $input, Output $output): int
    {
        /** @var Scheduler $scheduler */
        $scheduler = $this->app->get(Scheduler::class);

        $dueTasks = $scheduler->getDueTasks();

        if (empty($dueTasks)) {
            $output->writeln('<info>No tasks due at this time.</info>');

            return 0;
        }

        $output->writeln(sprintf('<info>Running %d due task(s):</info>', count($dueTasks)));

        foreach ($dueTasks as $name => $task) {
            $output->writeln("  <comment>{$name}</comment> ({$task->getCronExpression()})");

            try {
                $task->run();
                $output->writeln("    <info>OK</info>");
            } catch (Throwable $e) {
                $output->writeln("    <error>FAIL: {$e->getMessage()}</error>");
            }
        }

        return 0;
    }
}
