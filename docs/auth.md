# 认证 Auth (SaToken)

轻量级认证模块，基于缓存实现 Token 登录态管理，支持多端登录、滑动续期、强制踢人。

## 配置

创建 `config/satoken.php`：

```php
return [
    // Token 请求头名称，留空则从 Authorization: Bearer 读取
    'token_name'        => '',

    // Cookie 中的 token 字段名（使用 cookie 驱动时生效）
    'token_cookie_name' => 'satoken',

    // Token 读取驱动：header / cookie / chain / 自定义类名
    //   header  - 仅从 HTTP Header 读取（适合 SPA / API）
    //   cookie  - 仅从 Cookie 读取（适合传统服务端渲染页面）
    //   chain   - 先 Header 后 Cookie，兼顾两者（默认）
    'token_reader'      => 'chain',

    // 缓存驱动名，null 使用默认缓存
    'store'             => null,

    // Token 过期时间（秒），默认 7 天
    'timeout'           => 86400 * 7,

    // 是否开启滑动续期
    'auto_renew'        => true,

    // 剩余时间少于该值（秒）时自动续期，默认 1 小时
    'renew_before'      => 3600,

    // 同一账号最大同时在线数，超出自动踢掉最早的
    'max_login_count'   => 10,
];
```

## Token 读取驱动

SaToken 通过 `TokenReaderInterface` 解耦 token 的传递方式，内置三种驱动：

| 驱动 | 类名 | 说明 |
|------|------|------|
| `header` | `Lychee\auth\HeaderTokenReader` | 从 HTTP Header 读取：先 `token_name` 指定的 header，再 `Authorization: Bearer xxx` |
| `cookie` | `Lychee\auth\CookieTokenReader` | 从 `$_COOKIE[token_cookie_name]` 读取 |
| `chain` | `Lychee\auth\ChainTokenReader` | 依次尝试多个 reader，返回第一个非空 token（默认：Header → Cookie） |

### 选择驱动

- **API / SPA 项目**：用 `header`，前端在请求头中携带 token
- **传统后台管理（页面跳转）**：用 `cookie`，浏览器自动携带，登录时由后端 `Set-Cookie`
- **混合场景**：用 `chain`（默认），两种方式都支持

### 自定义驱动

实现 `Lychee\auth\TokenReaderInterface` 接口，然后在配置中指定类名：

```php
use Lychee\auth\TokenReaderInterface;

class QueryTokenReader implements TokenReaderInterface
{
    public function read(): ?string
    {
        return $_GET['token'] ?? null;
    }
}
```

```php
// config/satoken.php
return [
    'token_reader' => \App\auth\QueryTokenReader::class,
];
```

### Cookie 模式示例

传统后台推荐使用 cookie 模式，登录成功后由后端写入 cookie，后续页面跳转自动鉴权：

```php
public function login(Request $request): JsonResponse
{
    // ... 校验账号密码 ...
    $token = satoken()->login($user->id, ['username' => $user->username]);

    $name    = config('satoken.token_cookie_name', 'satoken');
    $minutes = (int) ceil(config('satoken.timeout', 86400 * 7) / 60);

    return json(['errno' => 0, 'code' => 200, 'msg' => 'success', 'data' => ['token' => $token]])
        ->cookie($name, $token, $minutes, '/', null, false, true, 'Lax');
}

public function logout(): JsonResponse
{
    satoken()->logout();
    $name = config('satoken.token_cookie_name', 'satoken');
    return json(['errno' => 0, 'code' => 200, 'msg' => '退出成功', 'data' => null])
        ->withoutCookie($name);
}
```

## 使用

```php
$auth = satoken();

// 登录，返回 Token
$token = $auth->login($userId);

// 登录时附带额外数据
$token = $auth->login($userId, ['role' => 'admin']);

// 获取当前登录 ID
$userId = $auth->getCurrentLoginId();

// 检查是否登录（返回 bool）
if ($auth->isLogin()) {
    // ...
}

// 校验登录（未登录抛出 NotLoginException）
$auth->checkLogin();

// 获取当前 Token 信息
$info = $auth->getTokenInfo();

// 读取 / 设置 Token 附带的额外数据
$extra = $auth->getExtra();
$auth->setExtra(['role' => 'editor']);

// 登出
$auth->logout();

// 强制踢人（按用户 ID）
$auth->kickout($userId);

// 强制踢人（按 Token）
$auth->kickoutByToken($token);
```

## 鉴权中间件

通过 `#[Middleware(SatokenMiddleware::class)]` 按需挂载到控制器或方法，未登录时抛出 `NotLoginException`，由全局异常处理器自动渲染为 401 响应（HTML 或 JSON 按请求自动判断）：

```php
use Lychee\auth\SatokenMiddleware;
use Lychee\routing\Middleware;
use Lychee\routing\Resource;
use Lychee\routing\WithoutMiddleware;

// 整个控制器需要登录
#[Resource('/users')]
#[Middleware(SatokenMiddleware::class)]
class UserController
{
    // 登录接口无需鉴权，排除类级中间件
    #[WithoutMiddleware]
    public function login() {}

    public function index() {}
    public function read($id) {}
}

// 仅单个方法需要登录
class PublicController
{
    #[Route('/profile')]
    #[Middleware(SatokenMiddleware::class)]
    public function profile() {}
}
```

## 辅助函数

```php
// 获取 SaToken 实例
$auth = satoken();

// 链式调用
$token  = satoken()->login($userId);
$userId = satoken()->getCurrentLoginId();
satoken()->logout();
```

## 功能权限 Permission

基于 `#[Permission]` Attribute + `PermissionMiddleware` 实现操作级别的功能权限拦截。

### 核心概念

| 组件 | 说明 |
|------|------|
| `Permission` | PHP 8 Attribute，声明方法或控制器所需权限 |
| `PermissionMiddleware` | 中间件，读取 Attribute 并调用 SaToken 校验 |
| `PermissionResolverInterface` | 权限解析器接口，业务层实现，查询用户权限列表 |
| `NullPermissionResolver` | 默认解析器，全部放行（渐进式迁移友好） |

### 1. 实现权限解析器

框架默认使用 `NullPermissionResolver`（全部放行）。业务层需实现 `PermissionResolverInterface`，从数据库查询用户的权限列表：

```php
namespace App\auth;

use Lychee\auth\PermissionResolverInterface;

class DbPermissionResolver implements PermissionResolverInterface
{
    public function getPermissions(int $loginId): array
    {
        // 从数据库查询用户角色关联的权限标识列表
        // 返回如 ['user.list', 'user.edit', 'order.delete']
        return UserRole::getPermissionsByUserId($loginId);
    }

    public function hasPermission(int $loginId, string $permission): bool
    {
        return in_array($permission, $this->getPermissions($loginId), true);
    }
}
```

在 `ServiceProvider` 或启动文件中绑定到容器：

```php
$container->bind(
    \Lychee\auth\PermissionResolverInterface::class,
    \App\auth\DbPermissionResolver::class,
);
```

### 2. 注册中间件

将 `PermissionMiddleware` 注册为全局中间件，放在 `SatokenMiddleware` **之后**：

```php
// config/middleware.php
return [
    \Lychee\auth\SatokenMiddleware::class,
    \Lychee\auth\PermissionMiddleware::class,
];
```

也可以只在需要权限控制的控制器上挂载：

```php
#[Middleware(SatokenMiddleware::class)]
#[Middleware(PermissionMiddleware::class)]
class OrderController extends ResourceController
{
    // ...
}
```

### 3. 声明权限

#### 方法级

```php
use Lychee\auth\Permission;

class OrderController extends ResourceController
{
    #[Permission('order.export')]
    public function export(): JsonResponse
    {
        // 仅拥有 order.export 权限的用户可访问
    }
}
```

#### 多权限（OR 逻辑）

```php
// 拥有 order.edit 或 order.admin 任意一个即可
#[Permission('order.edit', 'order.admin')]
public function update(Request $request, int $id): JsonResponse
{
    // ...
}
```

#### 类级

```php
// 该控制器所有方法都需要 report.view 权限
#[Permission('report.view')]
class ReportController extends Controller
{
    public function daily() {}
    public function monthly() {}

    // 方法级声明会覆盖类级
    #[Permission('report.admin')]
    public function delete(int $id) {}
}
```

### 4. ResourceController 自动推断

继承 `ResourceController` 的控制器，CRUD 方法无需手动标注。`PermissionMiddleware` 会根据控制器名和方法名自动推断权限：

| 方法 | 推断权限 | 示例（BillCustomerController） |
|------|---------|------|
| `index` | `{module}.list` | `bill_customer.list` |
| `save` | `{module}.create` | `bill_customer.create` |
| `read` | `{module}.read` | `bill_customer.read` |
| `update` | `{module}.edit` | `bill_customer.edit` |
| `delete` | `{module}.delete` | `bill_customer.delete` |

模块名从控制器短类名推断：去掉 `Controller` 后缀，PascalCase 转 snake_case。

**优先级**：方法级 `#[Permission]` > 类级 `#[Permission]` > 自动推断。手动标注会覆盖自动推断。

**未标注的非 CRUD 方法默认放行**，支持渐进式迁移。

### 5. 直接调用权限 API

除了中间件自动拦截，也可以在业务代码中手动校验：

```php
$auth = satoken();

// 判断是否有权限（返回 bool）
if ($auth->hasPermission('order.export')) {
    // ...
}

// 判断是否拥有任意一个权限（OR 逻辑）
$auth->hasAnyPermission('order.edit', 'order.admin');

// 校验权限，不通过抛出 NoPermissionException
$auth->checkPermission('order.delete');

// 校验任意一个权限，全部不通过才抛异常
$auth->checkAnyPermission('order.edit', 'order.admin');
```

### 权限不足响应

权限校验不通过时，`PermissionMiddleware` 抛出 `NoPermissionException`，由全局异常处理器统一渲染为 403 响应：

```json
{
    "code": 403,
    "msg": "无权限：order.delete",
    "data": null
}
```

> `NotLoginException`、`TokenInvalidException`、`NoPermissionException` 均继承自 `Lychee\http\HttpException`，状态码分别为 401、401、403。完整的异常体系与响应格式详见 [异常处理 Exception](./exception)。
