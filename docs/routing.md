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

中间件可标注在类上（对所有方法生效）或方法上（追加）。

### 挂载多个中间件

支持三种写法，效果相同：

```php
// 写法一：数组形式（推荐）
#[Middleware([AuthMiddleware::class, LogMiddleware::class])]
class UserController {}

// 写法二：重复注解
#[Middleware(AuthMiddleware::class)]
#[Middleware(LogMiddleware::class)]
class UserController {}

// 写法三：单个
#[Middleware(AuthMiddleware::class)]
class UserController {}
```

### 排除中间件

当类标注了中间件，个别方法（如登录、注册）无需鉴权时，
可在方法上使用 `#[WithoutMiddleware]` 排除类级中间件：

```php
use Lychee\routing\Middleware;
use Lychee\routing\WithoutMiddleware;

#[Middleware([AuthMiddleware::class, LogMiddleware::class])]
class UserController
{
    // 排除所有类级中间件
    #[WithoutMiddleware]
    public function login() {}

    // 排除指定的一个中间件
    #[WithoutMiddleware(AuthMiddleware::class)]
    public function register() {}

    // 排除指定的多个中间件
    #[WithoutMiddleware([AuthMiddleware::class, LogMiddleware::class])]
    public function guest() {}

    // 继承类级中间件，需要鉴权
    public function index() {}
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

> 如需零代码实现增删改查，可结合 [控制器 / ResourceController](./controller.md#资源控制器-resourcecontroller) 使用，
> 继承 `ResourceController` 并声明 `$model` 即可自动获得完整 CRUD 接口。
