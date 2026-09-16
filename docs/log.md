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

```php
logger();                              // 获取 Logger 实例
logger('一条 debug 日志');             // 直接记录 debug 级别
logger('message', ['context' => 1]);
```
