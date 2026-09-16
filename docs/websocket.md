# WebSocket

Lychee PHP 的 WebSocket 模块基于 [Workerman](https://www.workerman.net/) 实现，提供轻量级的长连接服务能力，适合实时聊天、消息推送、状态同步等场景。

> WebSocket 服务以**常驻进程**方式运行，与传统 PHP-FPM 的短生命周期请求模型不同，需通过控制台命令单独启动。

## 安装

WebSocket 模块依赖 `workerman/workerman`，为**按需安装**（不随框架默认安装）。

在创建 `config/websocket.php` 配置文件后，执行：

```bash
composer require workerman/workerman:^5.0
```

> 若未安装 Workerman 时执行启动命令，会提示安装指令。模块本身采用按需注册，无 `config/websocket.php` 时不会加载任何 WebSocket 相关代码。

## 配置

在 `config/websocket.php` 中定义服务参数与 path 到 Handler 的映射：

```php
return [
    'host'      => '0.0.0.0',   // 监听地址
    'port'      => 2346,        // 监听端口
    'count'     => 1,           // 进程数
    'heartbeat' => 50,          // 心跳间隔（秒），Workerman 自动处理 ping/pong
    'handlers'  => [
        '/chat'   => App\websocket\ChatHandler::class,
        '/notify' => App\websocket\NotifyHandler::class,
    ],
];
```

模块采用**按需注册**：仅当 `config/websocket.php` 存在时才启动，无需使用时删除该配置文件即可。

## 编写 Handler

Handler 是 WebSocket 消息的处理单元，类似 HTTP 中的 Controller。实现 `Lychee\websocket\Handler` 接口：

```php
<?php

namespace App\websocket;

use Lychee\websocket\Connection;
use Lychee\websocket\Handler;

class ChatHandler implements Handler
{
    public function onOpen(Connection $conn): void
    {
        $conn->send(json_encode([
            'type' => 'system',
            'msg'  => '欢迎，连接 #' . $conn->id(),
        ]));
    }

    public function onMessage(Connection $conn, string $data): void
    {
        $payload = json_decode($data, true);

        // 向同 path 的所有在线连接广播
        ws()->broadcast('/chat', json_encode([
            'type' => 'message',
            'from' => $conn->id(),
            'msg'  => $payload['msg'] ?? $data,
        ]));
    }

    public function onClose(Connection $conn): void
    {
        ws()->broadcast('/chat', json_encode([
            'type' => 'system',
            'msg'  => '连接 #' . $conn->id() . ' 已离开',
        ]));
    }
}
```

### 接口方法

| 方法 | 触发时机 | 参数 |
|------|---------|------|
| `onOpen(Connection $conn)` | 客户端握手完成后 | 连接对象 |
| `onMessage(Connection $conn, string $data)` | 收到客户端文本帧 | 连接对象、原始消息 |
| `onClose(Connection $conn)` | 连接关闭时 | 连接对象 |

## 启动服务

通过控制台命令启动 WebSocket 服务：

```bash
php lee worker                               # 前台运行
php lee worker --host=0.0.0.0                # 指定监听地址
php lee worker --port=8080                   # 指定监听端口
php lee worker -d                            # 守护进程模式
```

启动后输出：

```
Starting WebSocket server...
  Listening on ws://0.0.0.0:2346
```

## 连接对象 Connection

`Lychee\websocket\Connection` 封装了底层 Workerman 连接，提供类型友好的方法：

| 方法 | 说明 |
|------|------|
| `$conn->send(string $data)` | 向客户端发送文本消息 |
| `$conn->close()` | 关闭连接 |
| `$conn->id()` | 连接唯一 ID |
| `$conn->getRemoteIp()` | 客户端 IP |
| `$conn->getRemotePort()` | 客户端端口 |
| `$conn->getPath()` | 握手请求的 path（用于路由） |
| `$conn->getQuery()` | 握手请求的 query 参数数组 |
| `$conn->getRaw()` | 获取原始 Workerman 连接对象 |

## 服务端推送

在 HTTP 控制器、定时任务等场景中，可通过 `ws()` 助手函数向在线连接推送消息：

```php
// 向 /chat 路径的所有连接广播
ws()->broadcast('/chat', json_encode(['type' => 'notice', 'msg' => '系统维护中']));

// 向指定连接 ID 发送
ws()->sendTo('/chat', 123, '你好');

// 查询某 path 的在线连接数
$online = ws()->count('/chat');
```

## 连接鉴权

通过 `auth()` 方法设置鉴权回调，在握手阶段校验连接合法性。返回 `false` 时拒绝连接：

```php
// 在服务提供者或启动脚本中
ws()->auth(function (Connection $conn): bool {
    $token = $conn->getQuery()['token'] ?? '';
    return satoken()->check($token);
});
```

> WebSocket 进程中无法使用 PHP-FPM 的 `$_SESSION`，认证必须基于 token。

## 客户端示例

```javascript
const ws = new WebSocket('ws://localhost:2346/chat?token=xxx');

ws.onopen = () => {
    ws.send(JSON.stringify({ msg: 'hello' }));
};

ws.onmessage = (event) => {
    console.log(JSON.parse(event.data));
};
```

## Nginx 反向代理

生产环境建议通过 Nginx 反向代理 WebSocket 连接，需配置 `Upgrade` 与 `Connection` 头透传：

```nginx
location /chat {
    proxy_pass http://127.0.0.1:2346;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600s;
}
```

## 注意事项

1. **长连接特性**：WebSocket 服务是常驻进程，代码修改后需重启服务才能生效。
2. **数据库连接**：常驻进程中的数据库连接可能断线，建议在 Handler 中捕获异常并重连。
3. **多进程**：`count > 1` 时，不同进程的连接互不可见，跨进程广播需借助 Redis Pub/Sub 中转。
4. **Session 不可用**：FPM 的 Session 在 Workerman 进程中不存在，认证走 token。
