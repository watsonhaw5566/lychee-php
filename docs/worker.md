# Worker 服务

Lychee PHP 通过可选的 [Workerman](https://www.workerman.net/) 集成，提供高性能的 **HTTP 服务** 和 **WebSocket 长连接** 能力。HTTP 和 WebSocket **共用同一个端口**，WebSocket 通过协议升级自动处理，无需额外端口配置。

> Worker 服务以**常驻进程**方式运行，与传统 PHP-FPM 的短生命周期请求模型不同。代码修改后需重启服务才能生效。

## 安装

Workerman 为**可选依赖**，不随框架默认安装。需要使用 Worker 服务时，手动安装：

```bash
composer require workerman/workerman:^5.0
```

> 若未安装 Workerman 时执行 `php lee worker`，会提示安装指令。模块采用按需注册——无 `config/worker.php` 时不会加载任何 Worker 相关代码。

## 配置

发布配置文件：

```bash
php lee config:publish worker
```

生成的 `config/worker.php`：

```php
return [
    // ── 服务监听 ─────────────────────────────────────
    'host'  => '0.0.0.0',
    'port'  => 8080,
    'count' => cpu_count(),

    // ── WebSocket（同端口，自动协议升级） ─────────────
    'websocket' => [
        'handlers' => [
            // '/chat' => \App\websocket\ChatHandler::class,
        ],
    ],

    // ── 队列消费进程（需已配置 config/queue.php） ─────
    // 'queue' => [
    //     'connection' => null,  // 队列连接名，null 使用默认
    //     'queue'      => null,  // 队列名，null 使用默认
    //     'count'      => 1,     // 消费进程数
    //     'sleep'      => 1,     // 轮询间隔（秒）
    //     'tries'      => 3,     // 最大重试次数
    // ],

    // ── 定时任务调度进程（需已配置 config/cron.php） ──
    'cron' => [
        'enable' => true,
    ],

    // ── 进程管理 ──────────────────────────────────────
    'pid_file' => runtime_path('worker.pid'),
    'log_file' => runtime_path('worker.log'),
];
```

| 配置项 | 说明 |
|--------|------|
| `host` | 监听地址（默认 `0.0.0.0`） |
| `port` | 监听端口（默认 `8080`） |
| `count` | Worker 进程数，建议设为 CPU 核心数 |
| `websocket.handlers` | WebSocket path 到 Handler 类的映射 |
| `queue.connection` | 队列连接名，`null` 使用默认连接 |
| `queue.queue` | 队列名，`null` 使用默认队列 |
| `queue.count` | 消费进程数（默认 `1`） |
| `queue.sleep` | 轮询间隔秒数（默认 `1`） |
| `queue.tries` | 任务最大重试次数（默认 `3`） |
| `cron.enable` | 是否启用内置定时任务调度进程（默认 `true`） |
| `pid_file` | 主进程 PID 文件路径 |
| `log_file` | Worker 日志文件路径 |

## 启动服务

```bash
php lee worker           # 启动服务
php lee worker -d        # 守护进程模式
```

启动后输出：

```
Worker listening on http://0.0.0.0:8080
WebSocket paths: /chat
Queue     consumer x1 (connection: default)
Cron      scheduler (3 task(s))
```

## 开发模式（热更新）

常驻进程中业务代码修改后不会自动生效。本地开发时，使用 `worker:dev` 命令监听文件变化并自动重启：

```bash
php lee worker:dev                  # 监听 app 目录，默认 1 秒轮询
php lee worker:dev --interval=2     # 自定义轮询间隔（秒）
php lee worker:dev --watch=config   # 追加监听目录（相对项目根，逗号分隔）
```

命令以子进程方式启动 Worker 服务，并轮询扫描监听目录下的 `.php` 文件。检测到文件新增、修改或删除时，自动停止并重启 Worker 子进程。

```
Worker dev mode started. Watching for file changes...
  watching: /path/to/project/app
  Press Ctrl+C to stop.
Change detected: /path/to/project/app/controller/UserController.php (modified), restarting worker...
```

实现基于轮询文件 `mtime`，跨平台且无需任何扩展依赖。按 `Ctrl+C` 退出时会自动清理 Worker 子进程。

> `worker:dev` 仅用于**本地开发**。生产环境请使用 `php lee worker -d` 配合进程守护（Supervisor / 宝塔），代码更新后执行 `php lee worker reload` 平滑重启。

## 同端口模式

HTTP 和 WebSocket 共用同一个端口。普通 HTTP 请求正常处理，客户端发起 WebSocket 握手（`Upgrade: websocket`）时，Workerman 自动完成协议升级，后续消息帧路由到对应的 Handler。

这意味着：
- 无需为 WebSocket 单独配置端口
- 客户端 WebSocket 连接地址与 HTTP 同域同端口：`ws://localhost:8080/chat`
- Nginx 反向代理只需一个 upstream

## HTTP Worker

HTTP Worker 将 Workerman 收到的 HTTP 请求桥接到 Lychee 的路由内核，完整复用框架的路由、中间件、控制器、认证、ORM 等能力。**无需修改任何业务代码**，现有的控制器和中间件直接生效。

相比 PHP-FPM 模式，常驻进程避免了每次请求的框架启动开销，适合高并发 API 场景。

### 请求间隔离

每个请求结束后，Worker 会自动清理：

- 容器中的 `request` 绑定（每个请求对应独立的 Request 实例）
- 数据库事务级别（未关闭的事务自动回滚）
- Dumper 样式注入标记

### 注意事项

- **全局状态**：避免在控制器中使用 `$_GET`、`$_POST`、`$_COOKIE`、`$_SERVER` 等超全局变量，应通过 `Request` 对象获取。框架内置的认证（SaToken）、Cookie 读取已自动适配。
- **内存泄漏**：常驻进程中要注意避免无限增长的静态数组、全局缓存等。
- **文件上传**：Workerman 环境下 `UploadedFile` 已自动适配，无需额外处理。

## 队列消费

Worker 服务可以内置队列消费进程，无需单独运行 `php lee queue:work`。在 `config/worker.php` 中取消 `queue` 配置的注释即可启用：

```php
'queue' => [
    'connection' => null,  // 队列连接名，null 使用默认
    'queue'      => null,  // 队列名，null 使用默认
    'count'      => 1,     // 消费进程数
    'sleep'      => 1,     // 轮询间隔（秒）
    'tries'      => 3,     // 最大重试次数
],
```

队列消费进程作为独立的 Workerman 进程运行，不监听端口，使用 Timer 定期轮询。需要提前配置好 `config/queue.php`（Redis 或其他驱动）。

> `queue:work` 和 `queue:listen` 命令仍然可用。队列消费进程只是提供了一种由 Worker 统一管理的方式，适合不想额外部署 Supervisor 的场景。

## 定时任务调度

Worker 服务可以内置定时任务调度进程，无需配置系统 crontab，也无需单独运行 `php lee cron:schedule`。只要存在 `config/cron.php`，默认即启用；在 `config/worker.php` 中可显式开关：

```php
'cron' => [
    'enable' => true,  // 设为 false 可关闭内置调度
],
```

调度进程作为独立的 Workerman 进程运行（固定单实例，避免同一分钟重复触发），不监听端口。启动后对齐到分钟边界，每分钟触发一次，自动执行 `config/cron.php` 中到期的任务。任务定义方式与 `php lee cron:run` 完全一致。

> `cron:run`、`cron:schedule`、`cron:list` 命令仍然可用。内置调度进程只是提供了一种由 Worker 统一管理的方式，和队列消费进程一样省去额外的守护部署。启用内置调度后，请勿再在系统 crontab 中配置 `cron:run`，否则任务会被重复执行。

## WebSocket

### 编写 Handler

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

在 HTTP 控制器、定时任务等场景中，通过 `ws()` 助手函数向在线连接推送消息：

```php
// 向 /chat 路径的所有连接广播
ws()->broadcast('/chat', json_encode(['type' => 'notice', 'msg' => '系统维护中']));

// 向指定连接 ID 发送
ws()->sendTo('/chat', 123, '你好');

// 查询某 path 的在线连接数
$online = ws()->count('/chat');
```

### 连接鉴权

通过 `auth()` 方法设置鉴权回调，在握手阶段校验连接合法性。返回 `false` 时拒绝连接：

```php
ws()->auth(function (Connection $conn): bool {
    $token = $conn->getQuery()['token'] ?? '';
    return satoken()->check($token);
});
```

> WebSocket 进程中无法使用 PHP-FPM 的 `$_SESSION`，认证必须基于 token。

### 客户端示例

```javascript
// 同端口连接，与 HTTP 共用地址
const ws = new WebSocket('ws://localhost:8080/chat?token=xxx');

ws.onopen = () => {
    ws.send(JSON.stringify({ msg: 'hello' }));
};

ws.onmessage = (event) => {
    console.log(JSON.parse(event.data));
};
```

## Nginx 反向代理

同端口模式下，Nginx 只需代理到一个后端地址。通过 `Upgrade` 头自动区分 HTTP 和 WebSocket：

```nginx
upstream worker {
    server 127.0.0.1:8080;
}

server {
    listen 80;
    server_name example.com;

    location / {
        proxy_pass http://worker;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 3600s;
    }
}
```

## 注意事项

1. **可选安装**：Workerman 不随框架默认安装，需手动 `composer require workerman/workerman:^5.0`。
2. **按需注册**：无 `config/worker.php` 时框架不加载任何 Worker 相关代码，零开销。
3. **代码热更新**：常驻进程中代码修改后需重启服务才能生效。本地开发用 `php lee worker:dev` 自动监听重启；生产环境用 `php lee worker reload` 平滑重启。
4. **超全局变量**：Worker 模式下 `$_GET`、`$_POST` 等超全局变量不可用，统一使用 `Request` 对象。
5. **数据库连接**：长连接可能断线，框架内置的断线重连（`break_reconnect`）配置在 Worker 模式下同样有效。
6. **多进程广播**：`count > 1` 时，不同进程的连接互不可见，跨进程广播需借助 Redis Pub/Sub 中转。
