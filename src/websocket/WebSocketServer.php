<?php

declare(strict_types=1);

namespace Lychee\websocket;

use Closure;
use Lychee\container\Container;

/**
 * WebSocket 服务管理器。
 *
 * 不再独立创建 Worker，而是由 HttpWorker 在同端口下委托调用。
 * 负责：
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

    public function __construct(
        private readonly Container $app,
    ) {
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
     * WebSocket 握手完成回调（由 HttpWorker 委托调用）。
     */
    public function onWebSocketConnect(mixed $connection, mixed $request): void
    {
        $path                      = parse_url($request->uri ?? '/', PHP_URL_PATH) ?: '/';
        $connection->websocketPath = $path;

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
     * WebSocket 消息回调（由 HttpWorker 委托调用）。
     */
    public function onWebSocketMessage(mixed $connection, mixed $data): void
    {
        $path    = $connection->websocketPath ?? '/';
        $handler = $this->resolveHandler($path);

        if ($handler === null) {
            return;
        }

        $handler->onMessage(new Connection($connection), (string) $data);
    }

    /**
     * 连接关闭回调（由 HttpWorker 委托调用）。
     *
     * 仅处理 WebSocket 连接的关闭，普通 HTTP 连接忽略。
     */
    public function onConnectionClose(mixed $connection): void
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
     * 解析 path 对应的 Handler 实例（带缓存，通过容器创建以支持依赖注入）。
     */
    private function resolveHandler(string $path): ?Handler
    {
        if (isset($this->handlers[$path])) {
            return $this->handlers[$path];
        }

        $handlers = (array) $this->app->get('config')->get('worker.websocket.handlers', []);
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
