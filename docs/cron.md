# 定时任务 Cron

内置 Cron 表达式调度器。

## 配置

创建 `config/cron.php`：

```php
return [
    'tasks' => [
        [
            'name'        => 'cleanup',
            'cron'        => '0 0 * * *',
            'command'     => \App\cron\CleanupTask::class,
            'description' => '每日清理临时文件',
        ],
        [
            'name'        => 'backup',
            'cron'        => '0 3 * * *',
            'command'     => \App\cron\BackupTask::class,
            'description' => '每日数据备份',
        ],
    ],
];
```

每个任务支持以下字段：

| 字段          | 类型     | 必填 | 说明                                     |
| ------------- | -------- | ---- | ---------------------------------------- |
| `name`        | string   | 是   | 任务名称，需唯一                         |
| `cron`        | string   | 是   | Cron 表达式                             |
| `command`     | string   | 是   | 命令类名，需实现 `public function handle(): void` |
| `description` | string   | 否   | 任务描述                                 |

`command` 指向的类由容器解析，调用其 `handle()` 方法执行任务。

## 运行

由系统 crontab 每分钟调用一次：

```bash
* * * * * php lee cron:run
```

调度器自动判断并执行到期任务。

## 查看任务列表

```bash
php lee cron:list
```

输出所有已注册的定时任务，包含名称、Cron 表达式和描述：

```
Scheduled tasks: (2)

  cleanup  0 0 * * *     每日清理临时文件
  backup   0 3 * * *     每日数据备份
```

## Cron 表达式

```
* * * * *
│ │ │ │ │
│ │ │ │ └── 星期几 (0-7, 0 和 7 都是周日)
│ │ │ └──── 月份 (1-12)
│ │ └────── 日期 (1-31)
│ └──────── 小时 (0-23)
└────────── 分钟 (0-59)
```

常用示例：
- `* * * * *` — 每分钟
- `0 * * * *` — 每小时
- `0 0 * * *` — 每天零点
- `0 9 * * 1` — 每周一 9 点
- `*/5 * * * *` — 每 5 分钟
