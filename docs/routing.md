# 路由 Routing

基于注解的路由系统，自动扫描 `app/controller` 目录下的控制器。

## 定义路由

使用 `#[Route]` 注解标注控制器方法，构造函数签名为：

```php
public function __construct(
    public string $path,              // 路由路径
    public string $method = 'GET',    // HTTP 方法，默认 GET
    public string $name = '',         // 路由名称（可选）
    public ?string $prefix = null,    // 覆盖全局 route_prefix（null=使用全局，''=无前缀）
    public ?int $cache = null,        // 路由缓存 TTL（秒），null 表示不缓存
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

`#[Resource]` 构造函数签名：

```php
public function __construct(
    public string $path,              // 资源路径
    public ?string $prefix = null,    // 覆盖全局 route_prefix（null=使用全局，''=无前缀）
    public ?int $cache = null,        // 资源动作缓存 TTL（秒），null 表示不缓存
)
```

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

## 路由缓存

通过 `#[Route]` 或 `#[Resource]` 的 `cache` 参数，可启用路由级响应缓存。缓存基于框架内置的 `cache` 模块（支持 file / redis 等驱动），将响应内容序列化后存入缓存，后续相同请求直接返回缓存结果，跳过控制器执行。

### 基本用法

```php
use Lychee\routing\Route;

class ArticleController
{
    // 缓存 1 小时（3600 秒）
    #[Route('/articles', cache: 3600)]
    public function index()
    {
        return ['articles' => [...]];
    }

    // 不缓存（默认）
    #[Route('/articles/{id}')]
    public function show(int $id) {}
}
```

### 资源路由缓存

在 `#[Resource]` 上设置 `cache`，对所有资源动作生效：

```php
use Lychee\routing\Resource;

// 所有资源动作缓存 5 分钟
#[Resource('/posts', cache: 300)]
class PostController
{
    public function index() {}     // GET /posts      → 缓存 300 秒
    public function read($id) {}   // GET /posts/{id} → 缓存 300 秒
    public function save() {}      // POST /posts     → 不缓存（写操作）
}
```

方法上的 `#[Route(cache: xxx)]` 可覆盖类级配置：

```php
#[Resource('/posts', cache: 300)]
class PostController
{
    // 覆盖资源级缓存，仅缓存 60 秒
    #[Route('/posts/hot', cache: 60)]
    public function hot() {}
}
```

### 工作原理

- **仅缓存 GET 请求**：POST / PUT / DELETE 等写操作不受影响
- **仅缓存 200 响应**：404 / 500 等错误响应不写入缓存
- **缓存键**：基于 `控制器@动作 + HTTP 方法 + 路径 + 查询参数哈希`，不同查询条件对应不同缓存条目
- **缓存模块未启用时自动跳过**：若项目未配置 `cache` 模块，路由缓存不生效但不影响正常请求
- **命中缓存时跳过中间件与控制器**：直接返回缓存的响应内容、状态码与响应头

### 缓存键构成

缓存键格式为：

```
route:{Controller}@{action}:{METHOD}:{path}:{query_hash}
```

其中 `query_hash` 是对查询参数按 key 排序后做 JSON 编码再 MD5 的结果，确保 `?page=1&size=10` 与 `?size=10&page=1` 命中同一条缓存。

### 缓存失效与清理

路由缓存依赖框架的 `cache` 模块（见 [缓存 Cache](./cache.md)），可通过以下方式失效：

1. **自然过期**：到达 `cache` 参数指定的 TTL 后自动失效
2. **手动清理**：调用 `cache()` 助手清除指定键或全部缓存

```php
// 清除全部缓存
cache()->clear();

// 清除指定缓存键（需自行构造与内核一致的键名）
cache()->delete('route:App\controller\ArticleController@index:GET:/articles:' . md5('[]'));
```

> 对于数据变更后需要立即刷新缓存的场景，建议在 `save` / `update` / `delete` 等写操作完成后
> 调用 `cache()->clear()` 或针对性删除相关缓存键。

### 前置条件

路由缓存需要 `cache` 模块已配置。若项目根目录下不存在 `config/cache.php`，
可通过 `php lee config:publish cache` 生成配置文件，详见 [缓存 Cache](./cache.md)。

> **使用建议**：路由缓存命中时会跳过中间件管道与控制器执行，因此不适用于需要鉴权、
> 会话、限流等动态处理的接口。建议仅用于公开、内容相对稳定的接口（如文章列表、
> 商品详情、配置项等）。

## 路由表缓存（生产环境）

> 注意与上文的[路由缓存](#路由缓存)区分：**路由缓存**缓存的是响应内容（跳过控制器执行）；
> 本节的**路由表缓存**缓存的是路由定义本身（跳过控制器目录扫描与注解反射），用于降低生产环境的路由解析开销。

默认情况下，框架每个请求都会扫描 `app/controller` 目录、反射所有控制器并解析注解。
在生产环境可通过 `route:cache` 命令将收集好的路由表生成为缓存文件，之后直接加载，不再进行扫描与反射。
命令执行时会输出路由收集耗时，便于评估实时解析路由的开销：

```bash
php lee route:cache
```

输出示例：

```
Route cache generated successfully.

  Routes:       14
  Collect time: 1.01 ms
  Cache file:   /var/www/runtime/route_cache.php

Re-run this command after any route change, delete the file to disable.
```

缓存文件位于 `runtime/route_cache.php`。文件存在时框架自动使用；删除该文件即恢复实时扫描，无需修改任何配置。

### 动态路由参数不受影响

`{id}` 等动态路由完全不受缓存影响。缓存保存的是注册阶段已编译好的正则表达式，
`/users/{id}` 在缓存文件中仍为命名捕获组：

```
#^/users/(?P<id>[^/]+)$#
```

因此路由匹配、`{param}` 参数提取、参数类型自动转换等行为与实时扫描完全一致。

### 注意事项

1. **修改路由后必须重新生成**：新增控制器、修改 `#[Route]` / `#[Resource]` 注解、调整中间件后，
   需重新执行 `php lee route:cache`，否则生效的仍是旧路由表。命令会先删除旧缓存再重建。
2. **插件路由会一并缓存**：命令在插件 `boot()` 完成后收集路由，插件注册的路由也会写入缓存；
   缓存生效后插件对路由表的重复注册会被自动跳过。
3. **建议仅用于生产环境**：开发环境保留实时扫描，改完代码立即生效。
4. 缓存文件采用原子写入（临时文件 + 重命名），生成过程中不会影响正在处理的请求。

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
