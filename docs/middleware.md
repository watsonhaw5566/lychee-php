# 中间件 Middleware

中间件可在请求到达控制器前、响应返回前进行处理。

## 定义中间件

```php
// app/middleware/AuthMiddleware.php
namespace App\middleware;

use Closure;
use Lychee\http\MiddlewareInterface;
use Lychee\http\Request;
use Lychee\http\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        // 前置处理
        if (!session('user_id')) {
            return new Response('Unauthorized', 401);
        }

        // 继续管道
        $response = $next($request);

        // 后置处理
        $response->withHeader('X-Frame-Options', 'DENY');

        return $response;
    }
}
```

## 注册中间件

### 路由级

```php
#[Route('GET', '/admin')]
#[Middleware(AuthMiddleware::class)]
public function admin() {}
```

### 控制器级（作用于该控制器所有方法）

```php
#[Middleware(AuthMiddleware::class)]
class AdminController
{
    #[Route('GET', '/admin/dashboard')]
    public function dashboard() {}
}
```

## 全局中间件

在 `config/middleware.php` 中配置全局中间件列表，其中间件会在**所有路由**的控制器级 / 路由级中间件**之前**执行。

```php
// config/middleware.php
return [
    // 按数组顺序依次执行
    \App\middleware\SessionMiddleware::class,
    \App\middleware\I18nMiddleware::class,
    \App\middleware\LogMiddleware::class,
];
```

执行顺序为：**全局中间件 → 控制器级中间件 → 路由级中间件 → 控制器方法**。

若未创建 `config/middleware.php`，则不会加载任何全局中间件。
