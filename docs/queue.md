# 队列 Queue

支持同步（Sync）、Redis、阿里云 MNS 与腾讯云 CMQ 四种驱动。

## 配置

创建 `config/queue.php`：

```php
return [
    'default' => 'sync',
    'connections' => [
        'sync' => [
            'type' => 'sync',
        ],
        'redis' => [
            'type'     => 'redis',
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => '',
            'select'   => 0,
            'queue'    => 'default',
        ],

        // 阿里云轻量消息队列 MNS（需先 composer require aliyun/aliyun-mns-php-sdk）
        'mns' => [
            'type'         => 'mns',
            'access_id'    => '',
            'access_key'   => '',
            'endpoint'     => 'https://{AccountId}.mns.{RegionId}.aliyuncs.com',
            'queue'        => 'default',
            'wait_seconds' => 0,
        ],

        // 腾讯云 CMQ（纯 curl 实现，无需额外 SDK，需开启 curl 扩展）
        'cmq' => [
            'type'                 => 'cmq',
            'secret_id'            => '',
            'secret_key'           => '',
            'region'               => 'ap-guangzhou',
            'endpoint'             => '',
            'queue'                => 'default',
            'polling_wait_seconds' => 0,
        ],
    ],
];
```

### 长轮询

Redis 驱动的空队列轮询间隔由消费端的 `--sleep` 控制；MNS 的 `wait_seconds` 与 CMQ 的
`polling_wait_seconds` 是服务端长轮询：取消息的 HTTP 请求在服务端挂起等待，期间有消息立即
返回，建议生产环境设置为 10~30 秒，可同时降低空请求次数与任务延迟。

## 云队列重试机制与 Redis 的差异

云队列**没有** Redis 的 `:reserved` + `retry_after` 超时重投机制。任务失败重试时，驱动执行
"先删除原消息、再带累计 attempts 重新投递一条新消息"（新消息支持延迟秒数）；如果 worker 进程
崩溃导致 release 没执行，消息的重新可见依赖云队列自身的**不可见时间（VisibilityTimeout）**，
需要在云控制台为队列设置合理的值（如 `--tries=3`、单任务最长 60 秒，则设为 120 秒左右），
可见性超时后平台会把消息重新投递给其他消费者。

## 阿里云 MNS 驱动

- 需要先安装官方 SDK：`composer require aliyun/aliyun-mns-php-sdk`
- 队列需提前在 MNS 控制台创建；`endpoint` 从控制台队列详情页复制
- 消息以 JSON 原文收发（驱动以 `base64=false` 打开队列），可在控制台直接查看 payload
- 延迟投递、长轮询均使用 MNS 原生能力

## 腾讯云 CMQ 驱动

- 纯 curl + HMAC-SHA1 / TC3-HMAC-SHA256 签名实现，**无需安装额外 SDK**，但 PHP 需开启 curl 扩展
- `endpoint` 是 CMQ 队列服务接入地址（控制台分配），`region` 用于消息数查询等云 API v3 调用
- 队列需提前在 TDMQ for CMQ 控制台创建

## 失败任务与死信队列

框架不提供独立的失败任务存储：任务重试次数耗尽后会被删除并写入错误日志（任务名、异常、
尝试次数）。推荐两种处理方式：

1. **任务类 `failed()` 回调**：需要差异化失败处理（告警、状态回滚、标记人工介入）时实现，
   这是所有项目的最低配方案。
2. **云队列原生死信**：MNS 与 CMQ 都支持在控制台为队列配置死信策略（最大接收次数 + 死信
   队列），超限消息由消息平台自动转投，不依赖 worker 在线，比应用层自建表更可靠。

注意：业务上注定无法通过重试成功的错误（参数非法、数据不存在）应在 `fire()` 中直接
`$job->delete()` 主动丢弃，避免无谓重试。

## 定义任务

任务类为普通类，**不需要继承任何基类**，实现 `fire()` 方法即可：

```php
// app/job/SendEmail.php
namespace App\job;

use Lychee\queue\Job;
use Throwable;

class SendEmail
{
    /**
     * 执行任务。
     *
     * @param Job   $job  框架任务包装器，可调用 delete()/release()
     * @param mixed $data queue() 传入的数据
     */
    public function fire(Job $job, mixed $data): void
    {
        $email   = $data['email'];
        $content = $data['content'];

        // 发送邮件逻辑...

        $job->delete(); // 执行完成后删除任务
    }

    /**
     * 可选：任务全部重试失败后回调。
     */
    public function failed(mixed $data, Throwable $e): void
    {
        // 记录失败日志
    }
}
```

说明：
- 默认调用 `fire()` 方法；如需指定其他方法，推送时使用 `Class@method` 语法，例如 `queue(SendEmail::class . '@handle', $data)`
- 数据通过 `fire()` 的 `$data` 参数传入，不走构造函数注入

## 推送任务

```php
queue(SendEmail::class, ['email' => 'a@b.com', 'content' => 'hi']);

// 指定队列
queue(SendEmail::class, $data, 'emails');
```

## 延迟任务

通过链式调用 `delay()` 设置延迟秒数，无需额外函数：

```php
// 60 秒后执行
queue(SendEmail::class, ['email' => 'a@b.com'])->delay(60);

// 7 天后执行（使用表达式更清晰）
queue(CleanupJob::class)->delay(86400 * 7);

// 指定队列
queue(SendEmail::class, $data, 'emails')->delay(300);
```

`queue()` 返回一个 `PendingDispatch` 对象，调用 `delay()` 后在对象销毁时自动分发。

### 实现原理（Redis 驱动）

Redis 连接器使用三个数据结构协作：

- 主队列 `{queue}`：List，存放立即可执行的任务
- 延迟队列 `{queue}:delayed`：Sorted Set，score 为任务可执行的时间戳
- 预留队列 `{queue}:reserved`：Sorted Set，存放正在执行的任务，用于超时重试

每次 `pop()` 取任务前，会调用 `migrate()` 将 `:delayed` 中已到期（score ≤ time()）的任务移回主队列。

### Sync 驱动

Sync 连接器在 `delay()` 大于 0 时会调用 `sleep()` 阻塞等待，然后立即执行任务。这是有意为之——Sync 定位为开发/测试环境，保证延迟语义与 Redis 驱动一致。生产环境请使用 Redis 驱动。

## 消费队列

### 常驻消费：queue:work

```bash
php lee queue:work --connection=redis --queue=default --tries=3
```

`queue:work` 是常驻进程，吞吐性能高，但 PHP 类一旦加载就驻留内存——**修改任务代码后必须重启进程**才会生效。

### 实时监听：queue:listen

```bash
php lee queue:listen --connection=redis --queue=default --sleep=3 --tries=3 --memory=128 --timeout=60
```

`queue:listen` 的父进程常驻，但**每个任务都在全新的 `queue:work --once` 子进程中执行**。子进程每次重新引导框架、重新加载代码，因此修改任务代码后无需重启监听进程，当前任务执行完，下一个任务自动使用新代码。

适用场景与取舍：

- 适合生产环境配合 Supervisor（如宝塔面板的 Supervisor 管理器）守护，实现部署后免重启
- 代价是每个任务需支付一次框架引导开销（读配置、建连接等），吞吐量低于常驻 `queue:work`，高并发队列仍应使用 `queue:work`
- 需要 PHP 未禁用 `proc_open`；可选安装 pcntl 扩展以支持 SIGTERM 优雅退出（无 pcntl 时任务依靠 Redis reserved 超时重试兜底）

选项说明：

| 选项 | 默认值 | 说明 |
| --- | --- | --- |
| `--connection` / `-c` | 配置默认连接 | 队列连接 |
| `--queue` | 连接默认队列 | 监听的队列名 |
| `--sleep` | 3 | 队列为空时再次轮询的等待秒数 |
| `--tries` | 1 | 任务失败后的最大尝试次数 |
| `--memory` | 128 | 监听进程内存上限（MB），超限后自动退出交由 Supervisor 重启 |
| `--timeout` | 60 | 单个 worker 子进程的最长执行秒数，超时强杀（0 表示不限制） |

注意：`--timeout` 应小于 Redis 连接的 `retry_after`（默认 60 秒），否则旧任务尚未被杀掉、预留已过期，任务可能被其他 worker 重复消费。被强杀的任务留在 `:reserved` 集合中，超过 `retry_after` 后自动重新入队重试。MNS/CMQ 驱动同理，`--timeout` 应小于队列在控制台配置的不可见时间（VisibilityTimeout）。

需要并发消费时，启动多个监听进程即可（可分别指定不同的 `--queue`）。
