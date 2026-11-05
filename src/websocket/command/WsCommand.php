<?php

declare(strict_types=1);

namespace Lychee\websocket\command;

use Lychee\console\Command;
use Lychee\console\input\Argument;
use Lychee\console\input\Option;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\websocket\WebSocketServer;

/**
 * WebSocket 服务管理命令。
 *
 * 用法：
 *   php lee ws              # 启动服务（前台）
 *   php lee ws start -d     # 守护进程模式启动
 *   php lee ws stop         # 停止服务
 *   php lee ws reload       # 平滑重启（重载业务代码，连接基本不中断）
 *   php lee ws restart      # 完全重启
 *   php lee ws status       # 查看运行状态
 *
 * 注意：本命令仅负责 WebSocket 服务。HTTP 请求走 php-fpm，
 * 队列消费使用 php lee queue:work，定时任务使用 php lee cron:schedule。
 */
class WsCommand extends Command
{
    protected string $name = 'ws';

    protected string $description = 'Manage the WebSocket server';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);

        $this->addArgument('action', Argument::OPTIONAL, 'start|stop|reload|restart|status', 'start');
        $this->addOption('daemon', 'd', Option::VALUE_NONE, 'Run as a daemon');
    }

    protected function execute(Input $input, Output $output): int
    {
        if (!class_exists(\Workerman\Worker::class)) {
            $output->writeln('<error>Workerman is not installed.</error>');
            $output->writeln('  composer require workerman/workerman:^5.0');

            return 1;
        }

        $action  = (string) ($input->getArgument('action') ?? 'start');
        $config  = $this->app->get('config')->get('websocket', []);
        $pidFile = $config['pid_file'] ?? runtime_path('ws.pid');

        return match ($action) {
            'stop'    => $this->stop($output, $pidFile),
            'reload'  => $this->reload($output, $pidFile),
            'restart' => $this->restart($input, $output, $config, $pidFile),
            'status'  => $this->status($output, $pidFile),
            default   => $this->start($input, $output, $config, $pidFile),
        };
    }

    /**
     * 启动 WebSocket 服务。
     *
     * @param array<string, mixed> $config
     */
    private function start(Input $input, Output $output, array $config, string $pidFile): int
    {
        /** @var WebSocketServer $server */
        $server = $this->app->get(WebSocketServer::class);
        $server->createWorker();

        \Workerman\Worker::$pidFile = $pidFile;

        if (isset($config['log_file'])) {
            \Workerman\Worker::$logFile = $config['log_file'];
        }

        $host = $config['host'] ?? '0.0.0.0';
        $port = $config['port'] ?? 8080;
        $output->writeln("<info>WebSocket</info> listening on <comment>ws://{$host}:{$port}</comment>");

        $handlers = $config['handlers'] ?? [];
        if (!empty($handlers)) {
            $paths = implode(', ', array_keys($handlers));
            $output->writeln("<info>WebSocket</info> paths: <comment>{$paths}</comment>");
        }

        if ((bool) $input->getOption('daemon')) {
            \Workerman\Worker::$daemonize = true;
        }

        \Workerman\Worker::runAll();

        return 0;
    }

    /**
     * 停止服务（SIGTERM）。
     */
    private function stop(Output $output, string $pidFile): int
    {
        $pid = $this->getMasterPid($pidFile);

        if ($pid === null) {
            $output->writeln('<comment>WebSocket server is not running.</comment>');

            return 0;
        }

        $output->writeln('<info>Stopping WebSocket server...</info>');
        posix_kill($pid, SIGTERM);

        $timeout = 10;
        $start   = time();

        while (posix_kill($pid, 0)) {
            if (time() - $start >= $timeout) {
                $output->writeln('<error>Stop timeout, force killing...</error>');
                posix_kill($pid, SIGKILL);
                break;
            }

            usleep(100_000);
        }

        $output->writeln('<info>WebSocket server stopped.</info>');

        return 0;
    }

    /**
     * 平滑重载（SIGUSR1）。
     *
     * 向主进程发送 SIGUSR1，Workerman 会逐个重启子进程，
     * 子进程重启后重新加载业务代码。主进程不会重启，在线连接基本不中断。
     */
    private function reload(Output $output, string $pidFile): int
    {
        $pid = $this->getMasterPid($pidFile);

        if ($pid === null) {
            $output->writeln('<error>WebSocket server is not running.</error>');

            return 1;
        }

        posix_kill($pid, SIGUSR1);
        $output->writeln('<info>WebSocket server reloading...</info>');

        return 0;
    }

    /**
     * 完全重启：先停止再启动。
     *
     * @param array<string, mixed> $config
     */
    private function restart(Input $input, Output $output, array $config, string $pidFile): int
    {
        $this->stop($output, $pidFile);

        return $this->start($input, $output, $config, $pidFile);
    }

    /**
     * 查看运行状态。
     */
    private function status(Output $output, string $pidFile): int
    {
        $pid = $this->getMasterPid($pidFile);

        if ($pid === null) {
            $output->writeln('<comment>WebSocket server is not running.</comment>');

            return 0;
        }

        $output->writeln("<info>WebSocket server is running</info> (master pid: <comment>{$pid}</comment>)");

        return 0;
    }

    /**
     * 从 PID 文件读取主进程 PID，进程不存在时返回 null。
     */
    private function getMasterPid(string $pidFile): ?int
    {
        if (!is_file($pidFile)) {
            return null;
        }

        $pid = (int) file_get_contents($pidFile);

        if ($pid <= 0 || !posix_kill($pid, 0)) {
            return null;
        }

        return $pid;
    }
}
