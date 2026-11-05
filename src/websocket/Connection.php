<?php

declare(strict_types=1);

namespace Lychee\websocket;

use Lychee\auth\SaToken;
use Lychee\container\Container;
use Throwable;

/**
 * WebSocket 连接封装。
 *
 * 对 Workerman 原始连接对象做一层封装，
 * 提供类型友好的发送、关闭、属性读取方法。
 *
 * 握手时 WebSocketServer 会将解析出的 token 存入 raw 连接的
 * websocketToken 属性，本类据此提供 getToken() / getLoginId() 等
 * 身份信息读取能力，底层复用 SaToken 做校验。
 */
class Connection
{
    public function __construct(
        private readonly mixed $raw,
        private readonly Container $app,
    ) {
    }

    /**
     * 向客户端发送文本消息。
     */
    public function send(string $data): void
    {
        $this->raw->send($data);
    }

    /**
     * 关闭连接。
     */
    public function close(): void
    {
        $this->raw->close();
    }

    /**
     * 连接唯一 ID。
     */
    public function id(): int
    {
        return $this->raw->id;
    }

    /**
     * 客户端 IP。
     */
    public function getRemoteIp(): string
    {
        return $this->raw->getRemoteIp();
    }

    /**
     * 客户端端口。
     */
    public function getRemotePort(): int
    {
        return $this->raw->getRemotePort();
    }

    /**
     * 握手请求的 path（用于 handler 路由）。
     */
    public function getPath(): string
    {
        return $this->raw->websocketPath ?? '/';
    }

    /**
     * 握手请求的 query 参数。
     *
     * 由 WebSocketServer 在握手时解析并存储在连接对象的 websocketQuery 属性上。
     *
     * @return array<string, string>
     */
    public function getQuery(): array
    {
        return $this->raw->websocketQuery ?? [];
    }

    /**
     * 握手时携带的 token。
     *
     * 来源为 query 中配置的 token 字段（默认 token），由 WebSocketServer
     * 在 onWebSocketConnect 中解析后存入 raw 连接的 websocketToken 属性。
     */
    public function getToken(): ?string
    {
        return $this->raw->websocketToken ?? null;
    }

    /**
     * 当前连接对应的登录用户 ID。
     *
     * 基于握手 token 调用 SaToken 校验。token 无效或未提供时返回 null。
     */
    public function getLoginId(): ?int
    {
        $token = $this->getToken();
        if (empty($token)) {
            return null;
        }

        try {
            return $this->saToken()->getCurrentLoginId($token);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 当前连接 token 中存储的 extra 数据。
     *
     * @return array<string, mixed>
     */
    public function getLoginExtra(): array
    {
        $token = $this->getToken();
        if (empty($token)) {
            return [];
        }

        try {
            return $this->saToken()->getExtra($token);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 获取原始 Workerman 连接对象（用于高级操作）。
     */
    public function getRaw(): mixed
    {
        return $this->raw;
    }

    private function saToken(): SaToken
    {
        return $this->app->get('satoken');
    }
}
