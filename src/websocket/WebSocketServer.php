<?php

declare(strict_types=1);

namespace Lychee\websocket;

use Closure;
use Lychee\container\Container;
use RuntimeException;

/**
 * WebSocket 服务管理器。
 *
 * 基于 Workerman 的 websocket:// 协议提供纯 WebSocket 服务，
 * 不再承载 HTTP 请求、队列消费或定时任务（这些由 php-fpm / CLI 负责）。
 *
 * 职责：
 * - 创建并启动 Workerman WebSocket Worker
 * - 按握手请求 path 路由到对应的 Handler
 * - 维护连接注册表，支持按 path 广播
 * - 可选的连接鉴权回调
 */
class WebSocketServer
{
    /** @var array<string, Handler> 已实例化的 handler 缓存 */
    private array $handlers = [];

    /** @var array<string, array<int, Connection>> 按 path 分组的连接注册表 */
    private array $connections = [];

    /** @var Closure|null 鉴权回调 (Connection): bool */
    private ?Closure $authCallback = null;

    private readonly string $host;
    private readonly int $port;
    private readonly int $count;

    public function __construct(
        private readonly Container $app,
    ) {
        $config = $app->get('config')->get('websocket', []);

        $this->host  = (string) ($config['host'] ?? '0.0.0.0');
        $this->port  = (int) ($config['port'] ?? 8080);
        $this->count = (int) ($config['count'] ?? 1);
    }

    /**
     * 设置连接鉴权回调。
     *
     * 回调接收 Connection，返回 false 时拒绝连接。
     */
    public function auth(Closure $callback): static
    {
        $this->authCallback = $callback;

        return $this;
    }

    /**
     * 创建 Workerman WebSocket Worker（不启动，由命令统一 runAll）。
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
        $worker        = new $workerClass('websocket://' . $this->host . ':' . $this->port);
        $worker->name  = 'WebSocket';
        $worker->count = $this->count;

        // 握手完成回调：解析 path/query、鉴权、注册连接、触发 onOpen
        $worker->onWebSocketConnect = $this->onWebSocketConnect(...);

        // 消息回调：文本帧 payload
        $worker->onMessage = $this->onMessage(...);

        // 连接关闭回调
        $worker->onClose = $this->onClose(...);

        return $worker;
    }

    /**
     * WebSocket 握手回调。
     *
     * Workerman websocket:// 协议在收到 HTTP 升级请求时触发，
     * $buffer 为原始 HTTP 请求报文，可从中解析 path 与 query。
     */
    public function onWebSocketConnect(mixed $connection, mixed $buffer): void
    {
        [$path, $query] = $this->parseHandshake((string) $buffer);

        $connection->websocketPath  = $path;
        $connection->websocketQuery = $query;

        $conn = new Connection($connection);

        if ($this->authCallback !== null) {
            $auth = $this->authCallback;
            if (!$auth($conn)) {
                $connection->close();

                return;
            }
        }

        $this->connections[$path][$connection->id] = $conn;

        $handler = $this->resolveHandler($path);
        $handler?->onOpen($conn);
    }

    /**
     * WebSocket 消息回调。
     */
    public function onMessage(mixed $connection, mixed $data): void
    {
        $path    = $connection->websocketPath ?? '/';
        $handler = $this->resolveHandler($path);

        if ($handler === null) {
            return;
        }

        $handler->onMessage(new Connection($connection), (string) $data);
    }

    /**
     * 连接关闭回调。
     */
    public function onClose(mixed $connection): void
    {
        $path = $connection->websocketPath ?? null;

        if ($path === null) {
            return;
        }

        unset($this->connections[$path][$connection->id]);

        $handler = $this->resolveHandler($path);
        $handler?->onClose(new Connection($connection));
    }

    /**
     * 从握手 HTTP 报文中解析 path 与 query 参数。
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function parseHandshake(string $buffer): array
    {
        $firstLine = strstr($buffer, "\r\n", true) ?: $buffer;
        $parts     = explode(' ', $firstLine);
        $uri       = $parts[1] ?? '/';

        $path        = parse_url($uri, PHP_URL_PATH) ?: '/';
        $queryString = parse_url($uri, PHP_URL_QUERY);

        $query = [];
        if ($queryString !== null && $queryString !== '') {
            parse_str($queryString, $query);
        }

        return [$path, $query];
    }

    /**
     * 解析 path 对应的 Handler 实例（带缓存，通过容器创建以支持依赖注入）。
     */
    private function resolveHandler(string $path): ?Handler
    {
        if (isset($this->handlers[$path])) {
            return $this->handlers[$path];
        }

        $handlers = (array) $this->app->get('config')->get('websocket.handlers', []);
        $class    = $handlers[$path] ?? null;

        if ($class === null || !class_exists($class)) {
            return null;
        }

        /** @var Handler $handler */
        $handler               = $this->app->make($class);
        $this->handlers[$path] = $handler;

        return $handler;
    }

    /**
     * 向指定 path 的所有连接广播消息。
     */
    public function broadcast(string $path, string $data): void
    {
        foreach ($this->connections[$path] ?? [] as $conn) {
            $conn->send($data);
        }
    }

    /**
     * 向指定连接 ID 发送消息。
     */
    public function sendTo(string $path, int $connectionId, string $data): void
    {
        $conn = $this->connections[$path][$connectionId] ?? null;
        $conn?->send($data);
    }

    /**
     * 获取指定 path 的在线连接数。
     */
    public function count(string $path): int
    {
        return count($this->connections[$path] ?? []);
    }
}
