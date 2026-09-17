# 日志 Log

基于 PSR-3 的日志管理器，内置文件驱动。

## 配置

创建 `config/log.php`：

```php
return [
    'default' => 'file',
    'channels' => [
        'file' => [
            'type'  => 'File',
            'path'  => runtime_path('log'),
            'level' => 'debug',
        ],
    ],
];
```

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
