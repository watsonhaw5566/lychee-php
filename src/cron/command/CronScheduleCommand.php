<?php

declare(strict_types=1);

namespace Lychee\cron\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;

/**
 * 定时任务常驻调度进程。
 *
 * 每分钟启动一个子进程执行 `cron:run`，无需依赖系统 crontab。
 * 建议配合 supervisor / systemd 守护运行。
 *
 * 用法：php lee cron:schedule
 */
class CronScheduleCommand extends Command
{
    protected string $name = 'cron:schedule';

    protected string $description = 'Start the cron schedule daemon (runs cron:run every minute)';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
    }

    protected function execute(Input $input, Output $output): int
    {
        $entryScript = $this->resolveEntryScript();
        $command     = sprintf(
            '%s %s cron:run',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($entryScript)
        );

        $output->writeln('<info>Cron schedule started.</info> Press Ctrl+C to stop.');

        while (true) {
            $exitCode = $this->runSubprocess($command, $output);

            if ($exitCode !== 0) {
                $output->writeln('<error>cron:run exited with code ' . $exitCode . '</error>');
            }

            sleep(60);
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
