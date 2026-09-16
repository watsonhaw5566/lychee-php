<?php

declare(strict_types=1);

namespace Tests\stub\app\websocket;

use Lychee\websocket\Connection;
use Lychee\websocket\Handler;

/**
 * 示例 WebSocket 消息处理器。
 */
class ChatHandler implements Handler
{
    public function onOpen(Connection $conn): void
    {
        $conn->send(json_encode([
            'type' => 'system',
            'msg'  => 'welcome, connection #' . $conn->id(),
        ]));
    }

    public function onMessage(Connection $conn, string $data): void
    {
        $payload = json_decode($data, true);
        $message = $payload['msg'] ?? $data;

        // 广播给同 path 的所有连接
        ws()->broadcast('/chat', json_encode([
            'type' => 'message',
            'from' => $conn->id(),
            'msg'  => $message,
        ]));
    }

    public function onClose(Connection $conn): void
    {
        ws()->broadcast('/chat', json_encode([
            'type' => 'system',
            'msg'  => 'connection #' . $conn->id() . ' left',
        ]));
    }
}
