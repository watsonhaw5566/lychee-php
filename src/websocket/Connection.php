<?php

declare(strict_types=1);

namespace Lychee\websocket;

/**
 * WebSocket 连接封装。
 *
 * 对 Workerman 原始连接对象做一层封装，
 * 提供类型友好的发送、关闭、属性读取方法。
 */
class Connection
{
    public function __construct(
        private readonly mixed $raw,
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
     * @return array<string, string>
     */
    public function getQuery(): array
    {
        return $this->raw->getQueryString() ?? [];
    }

    /**
     * 获取原始 Workerman 连接对象（用于高级操作）。
     */
    public function getRaw(): mixed
    {
        return $this->raw;
    }
}
