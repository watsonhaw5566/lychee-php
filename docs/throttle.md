# 限速 Throttle

框架内置了一个基于缓存的限速中间件 `Lychee\throttle\ThrottleMiddleware`，采用**固定窗口计数器**算法，用于限制单位时间内的请求次数，防止接口被恶意刷请求。

## 工作原理

每次请求到来时，中间件根据配置的 `key_type` 生成一个缓存键，读取当前窗口内的命中次数：

- 若次数已达 `max_attempts`，直接返回 **429 Too Many Requests**，并附带 `Retry-After` 与 `X-RateLimit-*` 响应头
- 否则计数 +1（首次写入带 TTL，后续自增保持窗口结束时间不变），继续执行后续中间件与控制器

> 固定窗口算法实现简单、性能高，但存在窗口边界突刺问题（两个相邻窗口交界处可能瞬间通过 2 倍流量）。对于绝大多数 Web 接口场景已足够；如需更平滑的限流，可改用 Redis + 滑动窗口或令牌桶（自行扩展）。

## 配置

在 `config/` 目录下创建 `throttle.php` 定义全局默认值：

```php
// config/throttle.php
return [
    // 单位时间内最大请求次数
    'max_attempts'  => 60,

    // 窗口时长（秒）
    'decay_seconds' => 60,

    // 限流维度
    //   ip         - 按客户端 IP 限流（默认）
    //   user       - 按登录用户 ID 限流，未登录时回退到 IP
    //   route-ip   - 按 路由 + IP 限流
    //   route-user - 按 路由 + 用户 限流
    //   all        - 全局限流（所有请求共享一个计数器）
    'key_type'      => 'ip',

    // 缓存键前缀
    'prefix'        => 'throttle:',

    // 使用的缓存 store，留空使用默认缓存驱动
    'store'         => null,

    // 是否在响应中写入 X-RateLimit-* 头
    'with_headers'  => true,

    // 限流时返回的提示信息
    'message'       => '请求过于频繁，请稍后再试',
];
```

> 若不创建 `config/throttle.php`，中间件将使用代码内置的默认值（60 次 / 60 秒，按 IP 限流）。

## 使用方式

### 挂载到控制器或方法

通过 `#[Middleware]` 注解挂载，作用于单个方法或整个控制器：

```php
use Lychee\routing\Middleware;
use Lychee\routing\Route;
use Lychee\throttle\ThrottleMiddleware;

class UserController
{
    #[Route('POST', '/user/login')]
    #[Middleware(ThrottleMiddleware::class)]
    public function login()
    {
        // 登录接口：默认 60 次 / 60 秒 / IP
    }
}
```

### 作为全局中间件

在 `config/middleware.php` 中注册，对所有路由生效：

```php
// config/middleware.php
return [
    \Lychee\throttle\ThrottleMiddleware::class,
];
```

### 自定义限额（子类化）

不同接口往往需要不同的限流策略。通过继承 `ThrottleMiddleware` 并在构造函数中传入覆盖配置：

```php
// app/middleware/LoginThrottle.php
namespace App\middleware;

use Lychee\throttle\ThrottleMiddleware;
use think\CacheManager;

class LoginThrottle extends ThrottleMiddleware
{
    public function __construct(CacheManager $cache)
    {
        // 登录接口：每分钟最多 5 次
        parent::__construct($cache, [
            'max_attempts'  => 5,
            'decay_seconds' => 60,
        ]);
    }
}
```

```php
// app/middleware/SmsThrottle.php
namespace App\middleware;

use Lychee\throttle\ThrottleMiddleware;
use think\CacheManager;

class SmsThrottle extends ThrottleMiddleware
{
    public function __construct(CacheManager $cache)
    {
        // 短信发送：每小时最多 10 条，按用户限流
        parent::__construct($cache, [
            'max_attempts'  => 10,
            'decay_seconds' => 3600,
            'key_type'      => 'user',
            'message'       => '短信发送过于频繁，请 1 小时后再试',
        ]);
    }
}
```

然后像普通中间件一样挂载：

```php
#[Route('POST', '/user/login')]
#[Middleware(\App\middleware\LoginThrottle::class)]
public function login() {}

#[Route('POST', '/sms/send')]
#[Middleware(\App\middleware\SmsThrottle::class)]
public function sendSms() {}
```

## 限流响应

触发限流时返回 429 状态码，响应体为 JSON：

```json
{
  "code": 429,
  "msg": "请求过于频繁，请稍后再试"
}
```

响应头包含：

| 响应头 | 说明 |
| --- | --- |
| `Retry-After` | 需要等待的秒数 |
| `X-RateLimit-Limit` | 当前窗口允许的最大请求数 |
| `X-RateLimit-Remaining` | 当前窗口剩余请求数（被限流时为 0） |
| `X-RateLimit-Reset` | 窗口重置的 Unix 时间戳 |

未触发限流的正常响应也会携带 `X-RateLimit-*` 头（可通过 `with_headers => false` 关闭），便于前端展示剩余次数。

## 缓存依赖

限流计数器存储在缓存中，依赖框架的 `think\CacheManager`（`config/cache.php` 配置的缓存驱动）。

- **开发环境**：使用 File 驱动即可
- **生产环境**：推荐使用 Redis 驱动。File 驱动的 `inc` 操作是非原子的（读-改-写），高并发下可能计数不准；Redis 驱动的 `incrby` 是原子操作，计数精确

如需为限流单独指定缓存 store（例如与业务缓存隔离），配置 `store` 项：

```php
// config/throttle.php
return [
    'store' => 'redis',  // 对应 config/cache.php 中 stores.redis
    // ...
];
```
