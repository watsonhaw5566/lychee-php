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

## 消费队列

```bash
php lee queue:work --connection=redis --queue=default --tries=3
```
