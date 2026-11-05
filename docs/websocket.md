# WebSocket 服务

Lychee PHP 通过可选的 [Workerman](https://www.workerman.net/) 集成，提供 **WebSocket 长连接** 能力。WebSocket 服务独立监听一个端口，HTTP 请求仍由 PHP-FPM 处理，队列消费和定时任务走各自的 CLI 命令。

> WebSocket 服务以**常驻进程**方式运行，与传统 PHP-FPM 的短生命周期请求模型不同。代码修改后需重启服务才能生效。

## 安装

Workerman 为**可选依赖**，不随框架默认安装。需要使用 WebSocket 服务时，手动安装：

```bash
composer require workerman/workerman:^5.0
```

> 若未安装 Workerman 时执行 `php lee ws`，会提示安装指令。模块采用按需注册——无 `config/websocket.php` 时不会加载任何 WebSocket 相关代码。

## 配置

发布配置文件：

```bash
php lee config:publish websocket
```

生成的 `config/websocket.php`：

```php
return [
    // ── 服务监听 ─────────────────────────────────────
    'host'  => '0.0.0.0',
    'port'  => 8080,
    'count' => 1,

    // ── WebSocket Handler 路由 ───────────────────────
    'handlers' => [
        // '/chat' => \App\websocket\ChatHandler::class,
    ],

    // ── 进程管理 ──────────────────────────────────────
    'pid_file' => runtime_path('ws.pid'),
    'log_file' => runtime_path('ws.log'),
];
```

| 配置项 | 说明 |
|--------|------|
| `host` | 监听地址（默认 `0.0.0.0`） |
| `port` | 监听端口（默认 `8080`） |
| `count` | WebSocket 进程数 |
| `handlers` | WebSocket path 到 Handler 类的映射 |
| `pid_file` | 主进程 PID 文件路径 |
| `log_file` | 日志文件路径 |

## 启动服务

```bash
php lee ws           # 启动服务（前台）
php lee ws -d        # 守护进程模式
```

启动后输出：

```
WebSocket listening on ws://0.0.0.0:8080
WebSocket paths: /chat
```

其他管理动作：

```bash
php lee ws stop      # 停止服务
php lee ws reload    # 平滑重启（逐个重启子进程，连接基本不中断）
php lee ws restart   # 完全重启
php lee ws status    # 查看运行状态
```

## 开发模式（热更新）

常驻进程中业务代码修改后不会自动生效。本地开发时，使用 `ws:dev` 命令监听文件变化并自动重启：

```bash
php lee ws:dev                  # 监听 app 目录，默认 1 秒轮询
php lee ws:dev --interval=2     # 自定义轮询间隔（秒）
php lee ws:dev --watch=config   # 追加监听目录（相对项目根，逗号分隔）
```

命令以子进程方式启动 WebSocket 服务，并轮询扫描监听目录下的 `.php` 文件。检测到文件新增、修改或删除时，自动停止并重启子进程。

```
WebSocket dev mode started. Watching for file changes...
  watching: /path/to/project/app
  Press Ctrl+C to stop.
Change detected: /path/to/project/app/websocket/ChatHandler.php (modified), restarting WebSocket server...
```

实现基于轮询文件 `mtime`，跨平台且无需任何扩展依赖。按 `Ctrl+C` 退出时会自动清理子进程。

> `ws:dev` 仅用于**本地开发**。生产环境请使用 `php lee ws -d` 配合进程守护（Supervisor / 宝塔），代码更新后执行 `php lee ws reload` 平滑重启。

## 队列与定时任务

WebSocket 服务**不再**内置队列消费和定时任务调度。请使用对应的 CLI 命令：

- 队列消费：`php lee queue:work` 或 `php lee queue:listen`
- 定时任务：`php lee cron:schedule`（常驻）或配合系统 crontab 使用 `php lee cron:run`

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

### 连接对象 Connection

`Lychee\websocket\Connection` 封装了底层 Workerman 连接：

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

### 服务端推送

在 Handler、定时任务等场景中，通过 `ws()` 助手函数向在线连接推送消息：

```php
// 向 /chat 路径的所有连接广播
ws()->broadcast('/chat', json_encode(['type' => 'notice', 'msg' => '系统维护中']));

// 向指定连接 ID 发送
ws()->sendTo('/chat', 123, '你好');

// 查询某 path 的在线连接数
$online = ws()->count('/chat');
```

> 注意：`ws()` 推送仅在 WebSocket 进程内部有效。若需从 HTTP 控制器（PHP-FPM 进程）向 WebSocket 连接推送消息，需借助 Redis Pub/Sub 等跨进程通信机制中转。

### 连接鉴权

WebSocket 进程常驻运行，无法读取 PHP-FPM 的 `$_SESSION`，认证必须基于 token。框架支持两种鉴权方式。

#### 方式一：内置 SaToken 鉴权（推荐）

在 `config/websocket.php` 中开启 `auth.driver = 'satoken'`，框架会在握手阶段自动从 query 读取 token 并调用 SaToken 校验，无效则直接拒绝连接：

```php
'auth' => [
    'driver'     => 'satoken',   // 复用 SaToken 校验握手 token
    'token_name' => 'token',     // query 中的 token 字段名
],
```

客户端连接时携带 token：

```javascript
const ws = new WebSocket('ws://localhost:8080/chat?token=' + token);
```

#### 方式二：自定义鉴权回调

通过 `ws()->auth()` 注册回调，返回 `false` 时拒绝连接：

```php
ws()->auth(function (Connection $conn): bool {
    $token = $conn->getToken();
    if (empty($token)) {
        return false;
    }
    return satoken()->isLogin($token);
});
```

> 两种方式可同时使用：内置鉴权先执行，通过后再执行自定义回调，两者都放行才建立连接。

### 在 Handler 中获取登录用户

握手通过后，`Connection` 提供以下方法复用 SaToken 身份信息（无需手动传 token）：

| 方法 | 返回 | 说明 |
|------|------|------|
| `$conn->getToken()` | `?string` | 握手携带的 token |
| `$conn->getLoginId()` | `?int` | 登录用户 ID（首次调用时校验并缓存） |
| `$conn->getLoginExtra()` | `array` | token 中存储的 extra 数据 |

```php
public function onMessage(Connection $conn, string $data): void
{
    $loginId = $conn->getLoginId();
    if ($loginId === null) {
        $conn->close();
        return;
    }

    ws()->broadcast('/chat', json_encode([
        'type' => 'message',
        'from' => $loginId,
        'msg'  => $data,
    ]));
}
```

### 在闭包内复用 SaToken 无参方法

`hasPermission()`、`getCurrentLoginId()` 等无参方法默认从 HTTP 请求读取 token，在 WebSocket 进程中不可用。可使用 `withToken()` 在闭包内注入 token 上下文：

```php
satoken()->withToken($conn->getToken(), function () use ($conn, $data) {
    // 闭包内所有无参调用自动使用该 token
    if (!satoken()->hasPermission('chat.send')) {
        $conn->send(json_encode(['type' => 'error', 'msg' => '无权限']));
        return;
    }

    $loginId = satoken()->getCurrentLoginId();
    // ...
});
```

> `withToken()` 执行结束后自动恢复原上下文，不影响其他连接。Workerman 单进程事件循环内不会并发执行回调，因此是安全的。

### 客户端示例

```javascript
const ws = new WebSocket('ws://localhost:8080/chat?token=xxx');

ws.onopen = () => {
    ws.send(JSON.stringify({ msg: 'hello' }));
};

ws.onmessage = (event) => {
    console.log(JSON.parse(event.data));
};
```

## Nginx 反向代理

```nginx
upstream websocket {
    server 127.0.0.1:8080;
}

server {
    listen 80;
    server_name example.com;

    # WebSocket 路径
    location /chat {
        proxy_pass http://websocket;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
    }

    # 其他 HTTP 请求走 PHP-FPM
    location / {
        # ... PHP-FPM 配置
    }
}
```

## 注意事项

1. **可选安装**：Workerman 不随框架默认安装，需手动 `composer require workerman/workerman:^5.0`。
2. **按需注册**：无 `config/websocket.php` 时框架不加载任何 WebSocket 相关代码，零开销。
3. **代码热更新**：常驻进程中代码修改后需重启服务才能生效。本地开发用 `php lee ws:dev` 自动监听重启；生产环境用 `php lee ws reload` 平滑重启。
4. **数据库连接**：长连接可能断线，框架内置的断线重连（`break_reconnect`）配置在 WebSocket 进程中同样有效。
5. **多进程广播**：`count > 1` 时，不同进程的连接互不可见，跨进程广播需借助 Redis Pub/Sub 中转。
