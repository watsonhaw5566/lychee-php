<?php

declare(strict_types=1);

namespace Lychee\websocket;

/**
 * WebSocket 消息处理器接口。
 *
 * 开发者实现此接口处理连接生命周期事件，
 * 类似 HTTP Controller 的角色。
 */
interface Handler
{
    /**
     * 客户端连接建立（握手完成）时触发。
     */
    public function onOpen(Connection $conn): void;

    /**
     * 收到客户端消息时触发。
     *
     * @param string $data 原始消息内容（文本帧）
     */
    public function onMessage(Connection $conn, string $data): void;

    /**
     * 客户端连接关闭时触发。
     */
    public function onClose(Connection $conn): void;
}
