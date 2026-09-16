# 缓存 Cache

基于 ThinkCache，支持文件、Redis 驱动。

## 配置

创建 `config/cache.php`：

```php
return [
    'default' => 'file',
    'stores' => [
        'file' => [
            'type' => 'File',
            'path' => runtime_path('cache'),
        ],
        'redis' => [
            'type'     => 'redis',
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => '',
            'select'   => 0,
        ],
    ],
];
```

## 使用

```php
$cache = app('cache');

// 设置
$cache->set('key', 'value', 3600);    // 过期时间秒
$cache->set('key', 'value');            // 永不过期

// 读取
$value = $cache->get('key', '默认值');

// 判断
$cache->has('key');

// 删除
$cache->delete('key');

// 清空
$cache->clear();

// 自增自减
$cache->inc('counter');
$cache->dec('counter');
```
