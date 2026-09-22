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

## 助手函数

框架基于 think-cache，自动提供全局 `cache()` 助手函数，可直接完成常用读写：

```php
// 写入（第三个参数为过期秒数，也支持 ['expire' => 3600]）
cache('key', 'value');
cache('key', 'value', 3600);

// 读取，不存在时返回 null
$value = cache('key');

// 判断是否存在（键名以 ? 开头）
cache('?key');

// 删除
cache('key', null);

// 缓存标签（第四个参数）
cache('key', 'value', 3600, 'tag');
```

注意：

- `cache()` 不支持无参调用，需要获取管理器实例或切换驱动时仍使用 `app('cache')`：

  ```php
  app('cache')->store('redis')->get('key');
  ```

- `cache('key')` 读取时不支持默认值参数，需要默认值请使用 `app('cache')->get('key', '默认值')`。
