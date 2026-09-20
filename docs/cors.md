# 跨域 CORS

框架内置了跨域资源共享（CORS）中间件 `Lychee\cors\CorsMiddleware`，用于处理浏览器跨域请求，包括预检（OPTIONS）与实际请求。

## 工作原理

跨域请求分为两类，中间件分别处理：

1. **预检请求**（`OPTIONS` + `Access-Control-Request-Method`）：浏览器在发送「非简单请求」前自动发出。中间件直接返回允许策略（状态码 `204`），**不进入控制器**
2. **实际跨域请求**：在控制器返回的响应上补充 `Access-Control-Allow-Origin` 等头

> 框架已对 `Kernel` 做了适配：未注册 OPTIONS 路由的预检请求不会被当作 404，而是经过全局中间件管道由 CORS 中间件接管。因此 **CORS 中间件必须注册为全局中间件**才能覆盖所有路由（含未显式声明 OPTIONS 的路由）。

## 配置

在 `config/` 目录下创建 `cors.php` 定义全局默认值：

```php
// config/cors.php
return [
    // 允许的源
    //   '*'                      - 允许所有源（不携带凭证时）
    //   ['https://a.com', ...]   - 白名单，精确匹配
    //   ['https://*.example.com']- 通配符，匹配所有子域
    'allowed_origins'     => ['*'],

    // 允许的 HTTP 方法
    'allowed_methods'     => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // 允许的请求头
    //   ['Content-Type', ...]    - 具体列表
    //   ['*']                    - 允许所有；携带凭证时回显请求头
    'allowed_headers'     => ['Content-Type', 'Authorization', 'X-Requested-With'],

    // 允许浏览器读取的自定义响应头
    'exposed_headers'     => [],

    // 是否允许携带凭证（Cookie）
    //   开启后 Allow-Origin 不能为 '*'，会回显具体源并附加 Vary: Origin
    'allowed_credentials' => false,

    // 预检结果缓存时间（秒），0 表示不发送该头
    'max_age'             => 86400,

    // 预检响应状态码
    'preflight_status'    => 204,
];
```

> 若不创建 `config/cors.php`，中间件将使用代码内置的默认值（允许所有源、常用方法与头，不携带凭证）。

## 使用方式

### 作为全局中间件（推荐）

在 `config/middleware.php` 中注册，对所有路由生效：

```php
// config/middleware.php
return [
    \Lychee\cors\CorsMiddleware::class,
];
```

### 挂载到控制器或方法

通过 `#[Middleware]` 注解挂载，作用于单个方法或整个控制器：

```php
use Lychee\cors\CorsMiddleware;
use Lychee\routing\Middleware;
use Lychee\routing\Route;

class UserController
{
    #[Route('POST', '/user/login')]
    #[Middleware(CorsMiddleware::class)]
    public function login() {}
}
```

> 注意：路由级挂载无法覆盖未注册 OPTIONS 路由的预检请求，跨域场景仍建议使用全局中间件。

### 自定义策略（子类化）

不同模块往往需要不同的跨域策略。通过继承 `CorsMiddleware` 并在构造函数中传入覆盖配置：

```php
// app/middleware/AdminCors.php
namespace App\middleware;

use Lychee\cors\CorsMiddleware;

class AdminCors extends CorsMiddleware
{
    public function __construct()
    {
        parent::__construct([
            'allowed_origins'     => ['https://admin.example.com'],
            'allowed_credentials' => true,
        ]);
    }
}
```

然后像普通中间件一样挂载：

```php
#[Route('GET', '/admin/dashboard')]
#[Middleware(\App\middleware\AdminCors::class)]
public function dashboard() {}
```

## 响应头说明

中间件会根据配置与请求自动写入以下响应头：

| 响应头 | 说明 |
| --- | --- |
| `Access-Control-Allow-Origin` | 允许的源；白名单或凭证模式下回显具体源并附带 `Vary: Origin` |
| `Access-Control-Allow-Methods` | 允许的 HTTP 方法（仅预检响应） |
| `Access-Control-Allow-Headers` | 允许的请求头；`['*']` + 凭证模式下回显 `Access-Control-Request-Headers`（仅预检响应） |
| `Access-Control-Expose-Headers` | 允许浏览器读取的自定义响应头 |
| `Access-Control-Allow-Credentials` | 是否允许携带凭证 |
| `Access-Control-Max-Age` | 预检结果缓存时间（秒） |
| `Vary` | 白名单或凭证模式下为 `Origin`，防止 CDN 缓存串头 |

## 注意事项

- **凭证与通配符互斥**：`allowed_credentials` 为 `true` 时，`Access-Control-Allow-Origin` 与 `Access-Control-Allow-Headers` 都不能为 `*`，中间件会自动回显请求中的具体值
- **未命中的源不主动 403**：源不在白名单时，中间件不添加任何 CORS 头直接放行，由浏览器拦截跨域读取；同源请求与服务端工具（curl、健康检查）不受影响
- **无 `Origin` 头直接放行**：同源请求与非浏览器客户端不会被添加任何 CORS 头
- **生产环境静态资源**：若前后端分离部署，静态文件由 Nginx 直接处理时，需在 Nginx 侧配置 CORS 头（PHP 中间件无法拦截未经过 PHP 的请求）
