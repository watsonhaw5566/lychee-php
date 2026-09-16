# 中间件 Middleware

中间件可在请求到达控制器前、响应返回前进行处理。

## 定义中间件

```php
// app/middleware/AuthMiddleware.php
namespace App\middleware;

use Lychee\http\MiddlewareInterface;
use Lychee\http\Request;
use Lychee\http\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
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

在 `config/app.php` 中配置全局中间件列表（需创建该配置文件）。
