<?php

declare(strict_types=1);

namespace Lychee\websocket;

use Lychee\container\Container;
use Lychee\http\Kernel;
use Lychee\http\Request;
use Lychee\support\Dumper;
use RuntimeException;
use Throwable;

/**
 * 统一 Worker：同端口处理 HTTP 请求和 WebSocket 连接。
 *
 * 使用 Workerman 的 http:// 协议监听，普通 HTTP 请求通过 onMessage 桥接到 Kernel，
 * WebSocket 升级请求由 Workerman 自动处理协议升级，通过 onWebSocketConnect 回调。
 */
class HttpWorker
{
    private readonly string $host;
    private readonly int $port;
    private readonly int $count;

    /**
     * @param array<string, mixed> $config 来自 config/worker.php 的顶层配置
     */
    public function __construct(
        private readonly Container $app,
        array $config,
    ) {
        $this->host  = (string) ($config['host'] ?? '0.0.0.0');
        $this->port  = (int) ($config['port'] ?? 8080);
        $this->count = (int) ($config['count'] ?? 1);
    }

    /**
     * 创建 Worker 实例（不启动，由 ServerCommand 统一 runAll）。
     *
     * @return object Workerman\Worker
     * @throws RuntimeException 当 Workerman 未安装时
     */
    public function createWorker(): object
    {
        $workerClass = '\\Workerman\\Worker';

        if (!class_exists($workerClass)) {
            throw new RuntimeException(
                'Workerman is not installed. Run: composer require workerman/workerman:^5.0'
            );
        }

        /** @var object $worker */
        $worker        = new $workerClass('http://' . $this->host . ':' . $this->port);
        $worker->count = $this->count;

        // HTTP 请求处理
        $worker->onMessage = $this->onMessage(...);

        // WebSocket 协议升级回调（同端口自动升级）
        $wsServer = $this->resolveWebSocketServer();
        if ($wsServer !== null) {
            $worker->onWebSocketConnect = $wsServer->onWebSocketConnect(...);
            $worker->onClose            = $wsServer->onConnectionClose(...);
        }

        return $worker;
    }

    /**
     * 处理 HTTP 请求。
     *
     * Workerman 会自动判断：普通 HTTP 请求走 onMessage，
     * WebSocket 升级握手后的消息帧也走 onMessage。
     * 通过检查连接上的 websocketPath 属性区分两者。
     */
    private function onMessage(mixed $connection, mixed $data): void
    {
        // WebSocket 消息帧：已升级的连接带有 websocketPath 属性
        if (isset($connection->websocketPath)) {
            $wsServer = $this->resolveWebSocketServer();
            $wsServer?->onWebSocketMessage($connection, $data);

            return;
        }

        // 普通 HTTP 请求
        try {
            $request = Request::fromWorkerman($data);

            $this->app->instance('request', $request);
            $this->app->instance(Request::class, $request);

            /** @var Kernel $kernel */
            $kernel   = $this->app->get(Kernel::class);
            $response = $kernel->handle($request);
            $response->toWorkerman($connection);
        } catch (Throwable $e) {
            $workerResponse = new \Workerman\Protocols\Http\Response(
                500,
                [],
                'Internal Server Error',
            );
            $connection->send($workerResponse);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * 解析 WebSocketServer 实例（可能未配置）。
     */
    private function resolveWebSocketServer(): ?WebSocketServer
    {
        if ($this->app->bound(WebSocketServer::class)) {
            return $this->app->get(WebSocketServer::class);
        }

        return null;
    }

    /**
     * 请求结束后清理状态，防止跨请求污染。
     */
    private function cleanup(): void
    {
        // 重置 Dumper 样式注入标记
        Dumper::resetStyle();

        // 重置数据库事务级别
        if ($this->app->bound('db')) {
            /** @var \Lychee\orm\ConnectionManager $db */
            $db = $this->app->get('db');
            foreach ($db->getConnections() as $conn) {
                $conn->resetTransactionLevel();
            }
        }
    }
}
