# 路由 Routing

基于注解的路由系统，自动扫描控制器目录。

## 定义路由

```php
// app/controller/UserController.php
namespace App\controller;

use Lychee\routing\Route;

class UserController
{
    #[Route('GET', '/users')]
    public function index()
    {
        return 'user list';
    }

    #[Route('GET', '/users/{id}')]
    public function show(int $id)
    {
        return "user {$id}";
    }

    #[Route('POST', '/users')]
    public function store()
    {
        return 'created';
    }
}
```

## 路由参数

```php
#[Route('GET', '/users/{id}/posts/{postId}')]
public function post(int $id, int $postId)
{
    // $id, $postId 自动从 URL 注入
}
```

## 路由中间件

```php
use Lychee\routing\Middleware;

#[Route('GET', '/admin')]
#[Middleware(AuthMiddleware::class)]
public function admin()
{
    // ...
}
```

## 资源路由

```php
// 自动注册 index/show/store/update/destroy 五个路由
#[Resource('/users')]
class UserController
{
    public function index() {}
    public function show($id) {}
    public function store() {}
    public function update($id) {}
    public function destroy($id) {}
}
```
