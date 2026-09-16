<?php

declare(strict_types=1);

namespace Lychee\websocket\command;

use Lychee\console\Command;
use Lychee\console\input\Option;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\websocket\WebSocketServer;

/**
 * WebSocket 服务启动命令。
 *
 * 用法：
 *   php lee worker                       # 前台运行
 *   php lee worker --host=0.0.0.0 --port=2346
 *   php lee worker -d                    # 守护进程（需 Workerman 支持）
 */
class ServerCommand extends Command
{
    protected string $name = 'worker';

    protected string $description = 'Start the WebSocket server';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);

        $this->addOption('host', null, Option::VALUE_OPTIONAL, 'The host to listen on', null);
        $this->addOption('port', null, Option::VALUE_OPTIONAL, 'The port to listen on', null);
        $this->addOption('daemon', 'd', Option::VALUE_NONE, 'Run as a daemon');
    }

    protected function execute(Input $input, Output $output): int
    {
        if (!class_exists(\Workerman\Worker::class)) {
            $output->writeln('<error>Workerman is not installed.</error>');
            $output->writeln('  Run <comment>composer require workerman/workerman:^5.0</comment> to enable the WebSocket module.');

            return 1;
        }

        /** @var WebSocketServer $server */
        $server = $this->app->get(WebSocketServer::class);

        $host = $input->getOption('host');
        $port = $input->getOption('port');

        if ($host !== null || $port !== null) {
            // 允许通过命令行覆盖监听地址
            $config = $this->app->get('config')->get('websocket', []);
            if ($host !== null) {
                $config['host'] = $host;
            }
            if ($port !== null) {
                $config['port'] = (int) $port;
            }

            $server = new WebSocketServer($this->app, $config);
        }

        $listenHost = $host ?? '0.0.0.0';
        $listenPort = $port ?? '2346';

        $output->writeln('<info>Starting WebSocket server...</info>');
        $output->writeln("  Listening on <comment>ws://{$listenHost}:{$listenPort}</comment>");

        $server->start();

        return 0;
    }
}
