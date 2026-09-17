# 日志 Log

基于 PSR-3 的日志管理器，内置文件驱动。

## 配置

创建 `config/log.php`：

```php
return [
    'default' => 'file',
    'channels' => [
        'file' => [
            'type'      => 'File',
            'path'      => runtime_path('log'),
            'level'     => 'debug',
            'max_files' => 30, // 保留最近 30 天的日志文件，0 或不配置表示不限制
        ],
    ],
];
```

### max_files

文件驱动按日期切分日志（`YYYY-MM-DD.log`）。通过 `max_files` 可限制保留的日志文件数量，超出的旧文件会在下次写入时自动清理。

- 语义为「保留最近 N 天」，仅清理符合 `YYYY-MM-DD.log` 格式的文件
- 设为 `0` 或不配置时不限制，日志文件无限累积
- 清理在每次写入时触发，按文件名倒序保留最近 N 个

## 使用

```php
$log = app('log')->channel();

$log->debug('debug message');
$log->info('info message');
$log->warning('warning');
$log->error('error', ['user_id' => 1]);
```

## 辅助函数

`logger()` 始终返回 PSR-3 Logger 实例，由调用方显式指定日志级别：

```php
logger();                     // 获取默认频道的 Logger
logger('sql');                // 获取指定频道的 Logger

logger()->info('info msg');                 // 默认频道，info 级别
logger()->error('error', ['user_id' => 1]); // 默认频道，error 级别
logger('sql')->debug('query executed');     // sql 频道，debug 级别
```
