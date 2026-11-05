<?php

declare(strict_types=1);

namespace Lychee\websocket\command;

use Lychee\console\Command;
use Lychee\console\input\Option;
use Lychee\console\Input;
use Lychee\console\Output;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * WebSocket 开发模式命令。
 *
 * 以子进程方式启动 `php lee ws`，并监听 app 目录（及可选目录）下的
 * PHP 文件变化。检测到文件改动后自动重启 WebSocket 子进程，便于本地开发。
 *
 * 用法：
 *   php lee ws:dev                      # 监听 app 目录，默认 1 秒轮询
 *   php lee ws:dev --interval=2         # 自定义轮询间隔（秒）
 *   php lee ws:dev --watch=config       # 追加监听目录（相对项目根，可多次）
 *
 * 实现上使用轮询扫描文件 mtime，跨平台且无需任何扩展依赖。
 */
class DevCommand extends Command
{
    protected string $name = 'ws:dev';

    protected string $description = 'Run the WebSocket server in dev mode with file watching and auto-reload';

    /**
     * 当前运行的 Worker 子进程句柄。
     *
     * @var resource|null
     */
    private $process = null;

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);

        $this->addOption('interval', 'i', Option::VALUE_REQUIRED, '文件扫描间隔（秒）', '1');
        $this->addOption('watch', 'w', Option::VALUE_REQUIRED, '追加监听目录（相对项目根，逗号分隔）', '');
    }

    protected function execute(Input $input, Output $output): int
    {
        if (!class_exists(\Workerman\Worker::class)) {
            $output->writeln('<error>Workerman is not installed.</error>');
            $output->writeln('  composer require workerman/workerman:^5.0');

            return 1;
        }

        $interval = max(0.2, (float) ($input->getOption('interval') ?? 1));
        $dirs     = $this->resolveWatchDirs((string) ($input->getOption('watch') ?? ''));

        $output->writeln('<info>WebSocket dev mode</info> started. Watching for file changes...');
        foreach ($dirs as $dir) {
            $output->writeln("  watching: <comment>{$dir}</comment>");
        }
        $output->writeln('  Press <comment>Ctrl+C</comment> to stop.');

        // 启动时注册信号，确保退出时清理子进程
        $this->registerShutdown();

        $this->startWorker($output);

        $snapshot = $this->scan($dirs);

        while (true) {
            usleep((int) ($interval * 1_000_000));

            // 子进程异常退出则一并退出 dev 模式
            if (!$this->isWorkerAlive()) {
                $output->writeln('<error>WebSocket process exited. Stopping dev mode.</error>');

                return 1;
            }

            $current = $this->scan($dirs);

            if ($current !== $snapshot) {
                $changed = $this->diff($snapshot, $current);
                $output->writeln("<info>Change detected</info>: <comment>{$changed}</comment>, restarting WebSocket server...");

                $this->stopWorker();
                $this->startWorker($output);

                $snapshot = $current;
            }
        }
    }

    /**
     * 解析需要监听的目录列表（绝对路径，去重且真实存在）。
     *
     * @return array<int, string>
     */
    private function resolveWatchDirs(string $extra): array
    {
        $dirs = [app_path()];

        foreach (explode(',', $extra) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $dirs[] = base_path($item);
        }

        $resolved = [];
        foreach ($dirs as $dir) {
            $dir = rtrim($dir, DIRECTORY_SEPARATOR);
            if (is_dir($dir) && !in_array($dir, $resolved, true)) {
                $resolved[] = $dir;
            }
        }

        return $resolved;
    }

    /**
     * 扫描监听目录下所有 PHP 文件，返回 路径 => mtime 映射。
     *
     * @param array<int, string> $dirs
     * @return array<string, int>
     */
    private function scan(array $dirs): array
    {
        $files = [];

        foreach ($dirs as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[$file->getPathname()] = $file->getMTime();
                }
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * 比较两次快照，返回发生变化的首个文件路径描述。
     *
     * @param array<string, int> $old
     * @param array<string, int> $new
     */
    private function diff(array $old, array $new): string
    {
        foreach ($new as $path => $mtime) {
            if (!isset($old[$path])) {
                return $path . ' (added)';
            }
            if ($old[$path] !== $mtime) {
                return $path . ' (modified)';
            }
        }

        foreach ($old as $path => $mtime) {
            if (!isset($new[$path])) {
                return $path . ' (deleted)';
            }
        }

        return 'files changed';
    }

    /**
     * 以子进程方式启动 Worker 服务（前台）。
     */
    private function startWorker(Output $output): void
    {
        $command = $this->workerCommand();

        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        // 传入数组形式，直接执行 php 而不经由 /bin/sh 包裹，
        // 确保 proc_get_status 拿到的是 php（Workerman 主）进程的 pid。
        $process = proc_open($command, $descriptors, $pipes, base_path());

        if (!is_resource($process)) {
            $output->writeln('<error>Failed to start WebSocket process.</error>');

            return;
        }

        $this->process = $process;
    }

    /**
     * 停止当前 Worker 子进程。
     */
    private function stopWorker(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        $status = proc_get_status($this->process);
        $pid    = $status['pid'] ?? 0;

        if ($pid > 0 && function_exists('posix_kill')) {
            // 向进程组发送 SIGTERM，确保 Workerman 主/子进程全部退出
            posix_kill($pid, SIGTERM);

            $start = time();
            while (proc_get_status($this->process)['running'] ?? false) {
                if (time() - $start >= 10) {
                    posix_kill($pid, SIGKILL);
                    break;
                }
                usleep(100_000);
            }
        }

        proc_terminate($this->process, defined('SIGTERM') ? SIGTERM : 15);
        proc_close($this->process);

        $this->process = null;
    }

    /**
     * 判断 Worker 子进程是否仍在运行。
     */
    private function isWorkerAlive(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        return (bool) ($status['running'] ?? false);
    }

    /**
     * 注册退出信号处理，避免 dev 进程退出时遗留子进程。
     */
    private function registerShutdown(): void
    {
        $handler = function (): void {
            $this->stopWorker();
            exit(0);
        };

        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, $handler);
            pcntl_signal(SIGTERM, $handler);
        }

        register_shutdown_function(fn () => $this->stopWorker());
    }

    /**
     * 构造启动 Worker 的子进程命令（数组形式）。
     *
     * @return array<int, string>
     */
    private function workerCommand(): array
    {
        $argv  = $_SERVER['argv'] ?? [];
        $entry = is_string($argv[0] ?? null) ? $argv[0] : 'lee';

        return [PHP_BINARY, $entry, 'ws'];
    }
}
