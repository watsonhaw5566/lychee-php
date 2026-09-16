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

标注 `#[Resource]` 的控制器会自动注册资源动作路由，无需再为每个方法声明 `#[Route]`。
仅当方法存在且为 `public` 时才会注册。

| 方法 | 路由 | 说明 |
| --- | --- | --- |
| `index()` | `GET /path` | 列表 |
| `save()` | `POST /path` | 新建 |
| `read($id)` | `GET /path/{id}` | 详情 |
| `update($id)` | `PUT /path/{id}`（同时支持 `PATCH`） | 更新 |
| `delete($id)` | `DELETE /path/{id}` | 删除单条 |
| `batch_delete()` | `DELETE /path` | 批量删除 |

```php
use Lychee\routing\Resource;

#[Resource('/users')]
class UserController
{
    public function index() {}           // GET    /users
    public function save() {}            // POST   /users
    public function read($id) {}         // GET    /users/{id}
    public function update($id) {}       // PUT    /users/{id}
    public function delete($id) {}       // DELETE /users/{id}
    public function batch_delete() {}    // DELETE /users
}
```

若某个资源方法已显式声明 `#[Route]`，则以显式声明为准，自动注册会跳过该方法。
`#[Resource]` 同样作为路径前缀作用于控制器内所有显式 `#[Route]` 方法。
