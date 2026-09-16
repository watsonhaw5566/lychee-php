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

```php
// app/job/SendEmail.php
namespace App\job;

use Lychee\queue\Job;

class SendEmail extends Job
{
    public function __construct(
        protected string $email,
        protected string $content,
    ) {}

    public function handle(): void
    {
        // 发送邮件逻辑
    }
}
```

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

```bash
php lee queue:work --connection=redis --queue=default --tries=3
```
