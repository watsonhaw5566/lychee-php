<?php

declare(strict_types=1);

namespace Lychee\cron\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\cron\Scheduler;

/**
 * 定时任务常驻调度进程。
 *
 * 根据已注册任务的粒度自动选择 tick 频率：
 * - 全部为 5 段（分钟级）表达式时，每分钟 tick 一次
 * - 存在任意 6 段（秒级）表达式时，每秒 tick 一次
 *
 * 每次 tick 只做轻量的到期检查，有到期任务时才启动子进程执行
 * `cron:run`，无到期任务时直接休眠，避免不必要的子进程开销。
 * 建议配合 supervisor / systemd 守护运行。
 *
 * 用法：php lee cron:schedule
 */
class CronScheduleCommand extends Command
{
    protected string $name = 'cron:schedule';

    protected string $description = 'Start the cron schedule daemon (auto minute/second tick)';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
    }

    protected function execute(Input $input, Output $output): int
    {
        /** @var Scheduler $scheduler */
        $scheduler = $this->app->get(Scheduler::class);

        // 自动检测粒度：存在秒级任务则 1 秒 tick，否则 60 秒 tick
        $useSecondTick = $scheduler->requiresSecondTick();
        $interval      = $useSecondTick ? 1.0 : 60.0;

        $entryScript = $this->resolveEntryScript();
        $command     = sprintf(
            '%s %s cron:run',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($entryScript)
        );

        $granularity = $useSecondTick ? '1-second' : '1-minute';
        $output->writeln("<info>Cron schedule started.</info> ({$granularity} tick) Press Ctrl+C to stop.");

        while (true) {
            // 轻量检查到期任务，有到期才 fork 子进程执行
            if (!empty($scheduler->getDueTasks())) {
                $exitCode = $this->runSubprocess($command, $output);

                if ($exitCode !== 0) {
                    $output->writeln('<error>cron:run exited with code ' . $exitCode . '</error>');
                }
            }

            // 对齐到下一个 tick 边界休眠，避免子进程耗时累积导致触发时刻漂移，
            // 进而漏过整分钟 / 整秒任务
            $sleep = $interval - fmod(microtime(true), $interval);
            if ($sleep > 0) {
                usleep((int) ($sleep * 1_000_000));
            }
        }
    }

    /**
     * 启动子进程执行命令，并将其输出转发到当前控制台。
     *
     * @return int 子进程退出码
     */
    private function runSubprocess(string $command, Output $output): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            $output->writeln('<error>Failed to start cron:run process.</error>');

            return 1;
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($stdout !== '' && $stdout !== false) {
            $output->write($stdout);
        }

        if ($stderr !== '' && $stderr !== false) {
            $output->writeln($stderr);
        }

        return $exitCode;
    }

    /**
     * 解析入口脚本的绝对路径。
     *
     * 优先使用 SCRIPT_FILENAME（CLI 下为入口脚本的绝对路径），
     * 否则根据 rootPath 拼接 argv[0]。
     */
    private function resolveEntryScript(): string
    {
        if (!empty($_SERVER['SCRIPT_FILENAME']) && is_file($_SERVER['SCRIPT_FILENAME'])) {
            return (string) realpath($_SERVER['SCRIPT_FILENAME']);
        }

        $scriptName = $_SERVER['argv'][0] ?? 'lee';
        $rootPath   = rtrim($this->app->getRootPath(), DIRECTORY_SEPARATOR);
        $candidate  = $rootPath . DIRECTORY_SEPARATOR . basename($scriptName);

        if (is_file($candidate)) {
            return $candidate;
        }

        return $scriptName;
    }
}
