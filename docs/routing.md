# 路由 Routing

基于注解的路由系统，自动扫描 `app/controller` 目录下的控制器。

## 定义路由

使用 `#[Route]` 注解标注控制器方法，构造函数签名为：

```php
public function __construct(
    public string $path,              // 路由路径
    public string $method = 'GET',    // HTTP 方法，默认 GET
    public string $name = '',         // 路由名称（可选）
)
```

第一个参数为**路径**，第二个参数为 **HTTP 方法**（可省略，默认 `GET`）。

```php
// app/controller/UserController.php
namespace App\controller;

use Lychee\routing\Route;

class UserController
{
    #[Route('/users')]                 // GET  /users（method 省略时默认为 GET）
    public function index()
    {
        return 'user list';
    }

    #[Route('/users/{id}')]            // GET  /users/{id}
    public function show(int $id)
    {
        return "user {$id}";
    }

    #[Route('/users', 'POST')]         // POST /users
    public function store()
    {
        return 'created';
    }
}
```

## 类级路由前缀

在控制器类上标注 `#[Route]` 或 `#[Resource]`，可作为该控制器所有方法路由的路径前缀：

```php
use Lychee\routing\Route;

#[Route('/admin')]
class AdminController
{
    #[Route('/users')]       // 实际路径：GET  /admin/users
    public function index() {}

    #[Route('/users/{id}')]  // 实际路径：GET  /admin/users/{id}
    public function show(int $id) {}
}
```

> 若类上同时标注 `#[Resource]` 和 `#[Route]`，以 `#[Resource]` 的路径为准。

## 路由参数

路由路径中的 `{param}` 占位符会自动注入到控制器方法的同名参数中：

```php
#[Route('/users/{id}/posts/{postId}')]
public function post(int $id, int $postId)
{
    // $id, $postId 自动从 URL 注入
}
```

### 参数类型自动转换

路由参数会根据方法参数的类型声明自动转换：

| 参数类型 | 转换方式 |
| --- | --- |
| `int` | `(int) $value` |
| `float` | `(float) $value` |
| `bool` | `filter_var` 布尔过滤 |
| `string` | `(string) $value` |
| `array` | `(array) $value` |

```php
#[Route('/users/{id}')]
public function show(int $id)  // URL 中的字符串自动转为 int
{
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
> 继承 `ResourceController` 并声明 `$modelClass` 即可自动获得完整 CRUD 接口。

## 控制器方法参数注入

控制器方法的参数会按以下优先级自动解析注入：

1. **类型提示为类**：
   - `Request` 或其子类 → 注入当前请求对象
   - 容器中已注册的服务 → 从容器解析
2. **路由参数**：与 `{param}` 同名的参数从 URL 注入
3. **请求参数**：从 GET/POST 数据中获取同名参数
4. **默认值**：参数有默认值时使用默认值
5. **可空参数**：允许 `null` 的参数返回 `null`

```php
use Lychee\http\Request;

#[Route('/users/{id}')]
public function update(int $id, Request $request)  // $id 来自路由，$request 自动注入
{
    $data = $request->param();
}
```

## 控制器返回值

控制器方法的返回值处理规则：

- 返回 `Response` 实例 → 直接输出
- 返回其他类型（数组、字符串等）→ 自动包装为 `JsonResponse`

```php
#[Route('/users')]
public function index()
{
    return ['data' => []];  // 自动转为 JSON 响应
}
```

## 路由命名

`#[Route]` 第三个参数可指定路由名称，便于后续通过名称生成 URL：

```php
#[Route('/users/{id}', 'GET', 'user.show')]
public function show(int $id) {}
```

## 全局路由前缀

在 `config/app.php` 中设置 `route_prefix`，可为所有路由统一添加前缀，API 开发时尤为有用：

```php
// config/app.php
return [
    'route_prefix' => 'api',  // 所有路由自动加上 /api 前缀
];
```

设置后，`#[Resource('/users')]` 的实际访问路径变为 `/api/users`，`#[Route('/')]` 变为 `/api`。
前缀会自动去除首尾斜杠，`'api'`、`'/api'`、`'api/'` 等效。留空或不配置则不添加前缀。

### 跳过或覆盖全局前缀

某些接口（如健康检查、第三方回调）不需要全局前缀，可通过 `#[Route]` 或 `#[Resource]` 的 `prefix` 参数覆盖：

| `prefix` 值 | 效果 |
| --- | --- |
| 不设置（默认 `null`） | 使用全局 `route_prefix` |
| `''`（空字符串） | 不使用任何前缀 |
| `'admin'` 等非空字符串 | 用该值替代全局前缀 |

方法级 `#[Route]` 的 `prefix` 优先级高于类级 `#[Resource]` / `#[Route]` 的 `prefix`。

```php
use Lychee\routing\Route;
use Lychee\routing\Resource;

// 假设全局 route_prefix 为 'api'

// 1. 方法级跳过全局前缀
class HealthController
{
    #[Route('/health', prefix: '')]      // GET /health（无 /api 前缀）
    public function health() {}

    #[Route('/ping')]                    // GET /api/ping（使用全局前缀）
    public function ping() {}
}

// 2. 类级跳过全局前缀（作用于控制器所有路由）
#[Resource('orders', prefix: '')]        // 所有资源路由都不带 /api 前缀
class OrderController
{
    public function index() {}           // GET /orders
    public function read($id) {}         // GET /orders/{id}
}

// 3. 自定义前缀替代全局前缀
#[Route('/admin', prefix: 'manage')]      // 用 manage 替代 api
class AdminController
{
    #[Route('/users')]                    // GET /manage/admin/users
    public function users() {}
}
```

## 查看路由列表

使用 `route:list` 命令查看所有已注册的路由：

```bash
php lee route:list
```

输出示例：

```
Registered routes: (7)

  GET     /api/users          App\controller\UserController@index
  POST    /api/users          App\controller\UserController@save
  GET     /api/users/{id}     App\controller\UserController@read
  PUT     /api/users/{id}     App\controller\UserController@update
  PATCH   /api/users/{id}     App\controller\UserController@update
  DELETE  /api/users/{id}     App\controller\UserController@delete
  DELETE  /api/users          App\controller\UserController@batch_delete
```
