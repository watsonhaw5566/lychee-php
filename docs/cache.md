# 缓存 Cache

框架内置缓存模块，遵循 PSR-16 规范，提供文件与 Redis 两种通道，并支持运行时切换通道。

## 配置

创建 `config/cache.php`（可通过 `php lee config:publish` 发布）：

```php
return [
    // 默认缓存通道
    'default' => 'file',

    // 缓存通道列表
    'stores' => [
        'file' => [
            'type'         => 'File',
            'path'         => runtime_path('cache'),
            'expire'       => 0,      // 通道默认过期时间，0 为永久
            'prefix'       => '',
            'cache_subdir' => true,   // 按哈希前两位分目录
            'hash_type'    => 'md5',
            'data_compress' => false,
        ],

        'redis' => [
            'type'       => 'Redis',
            'host'       => '127.0.0.1',
            'port'       => 6379,
            'password'   => '',
            'select'     => 0,
            'timeout'    => 0,
            'persistent' => false,
            'expire'     => 0,
            'prefix'     => '',
        ],
    ],
];
```

## 基本使用

通过 `app('cache')` 获取缓存管理器，管理器本身就是 PSR-16 实例，操作默认通道：

```php
$cache = app('cache');

// 写入，第三个参数为过期秒数（0 或不传为永久）
$cache->set('key', 'value', 3600);

// 读取，支持默认值
$value = $cache->get('key', '默认值');

// 判断是否存在
$cache->has('key');

// 删除
$cache->delete('key');

// 清空当前通道
$cache->clear();
```

`set` 的 TTL 除整数秒外，也支持 `DateInterval` 与绝对时间 `DateTimeInterface`：

```php
$cache->set('key', $value, new DateInterval('PT1H'));
$cache->set('key', $value, new DateTime('+1 hour'));
```

## 通道切换

使用 `store()` 获取指定通道的驱动实例，同一通道在单次请求内只实例化一次：

```php
app('cache')->store('redis')->set('key', $value, 3600);
app('cache')->store('redis')->get('key');
```

运行时需要重建连接（如配置变更），可先释放已缓存的实例：

```php
app('cache')->forgetDriver('redis');
```

## 助手函数

全局 `cache()` 助手用于快速获取缓存驱动，**第一参数是通道名**：

```php
// 无参数：默认通道
cache()->get('key');
cache()->set('key', 'value', 3600);

// 指定通道：切换到 redis
cache('redis')->get('key');
cache('redis')->set('key', $value, 3600);

// 常用操作
cache('redis')->has('key');
cache('redis')->delete('key');
```

> 注意：本框架的 `cache()` 语义为「通道优先」，与 think-cache 原生助手
> （`cache('key')` 直接读写键名）不同。读取键名为 `redis` 的缓存应写为
> `cache()->get('redis')`。

## 数值计数

`inc` / `dec` 用于整数缓存的自增自减，返回操作后的新值：

```php
cache()->inc('counter');      // +1，返回新值
cache()->inc('counter', 5);   // +5
cache()->dec('counter');      // -1
cache()->dec('counter', 5);   // -5
```

语义约定：

- 键不存在时按 0 处理，结果为步长，且**永久有效**；
- 键已存在时，计数操作保留原过期时间；
- 值不是整数时抛出 `InvalidArgumentException`，不做隐式类型转换；
- `dec` 允许减到负数，不做归零处理；
- **原子性随通道而异**：Redis 通道使用原生 `INCRBY` / `DECRBY`，是原子操作；
  文件通道为读-改-写，高并发下可能丢失计数。限流、库存扣减等强一致场景请使用 Redis 通道。

## 读取即删除

`pull()` 读取缓存后立即删除，适用于一次性令牌（如 flash 数据）：

```php
$value = cache()->pull('key', '默认值');
```

## 缓存回写

`remember()` 在缓存未命中时执行闭包，并将结果写入缓存：

```php
$users = cache()->remember('users', function () {
    return Db::table('user')->select()->toArray();
}, 3600);
```

第二参数也可以直接传值；仅当缓存值为 `null`（未命中）时才会执行写入。

## 批量操作

遵循 PSR-16 的批量读写接口：

```php
// 批量读取，返回 [key => value]
$values = cache()->getMultiple(['key1', 'key2'], '默认值');

// 批量写入
cache()->setMultiple(['key1' => 1, 'key2' => 2], 3600);

// 批量删除
cache()->deleteMultiple(['key1', 'key2']);
```

## 从旧版 think-cache 助手迁移

旧版助手以键名为第一参数，需按下表调整：

| 旧写法 | 新写法 |
|---|---|
| `cache('key')` | `cache()->get('key')` |
| `cache('key', $value)` | `cache()->set('key', $value)` |
| `cache('key', $value, 3600)` | `cache()->set('key', $value, 3600)` |
| `cache('?key')` | `cache()->has('key')` |
| `cache('key', null)` | `cache()->delete('key')` |
| `app('cache')->store('redis')->get('key')` | 保持不变（或 `cache('redis')->get('key')`） |
