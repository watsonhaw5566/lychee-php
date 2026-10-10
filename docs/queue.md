# 队列 Queue

支持同步（Sync）和 Redis 驱动。

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
    ],
];
```

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

注意：`--timeout` 应小于 Redis 连接的 `retry_after`（默认 60 秒），否则旧任务尚未被杀掉、预留已过期，任务可能被其他 worker 重复消费。被强杀的任务留在 `:reserved` 集合中，超过 `retry_after` 后自动重新入队重试。

需要并发消费时，启动多个监听进程即可（可分别指定不同的 `--queue`）。
