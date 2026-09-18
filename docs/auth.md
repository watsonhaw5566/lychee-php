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

    return $this->success(['token' => $token])
        ->cookie($name, $token, $minutes, '/', null, false, true, 'Lax');
}

public function logout(): JsonResponse
{
    satoken()->logout();
    $name = config('satoken.token_cookie_name', 'satoken');
    return $this->success(null, '退出成功')->withoutCookie($name);
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

通过 `#[Middleware(SatokenMiddleware::class)]` 按需挂载到控制器或方法，未登录时自动返回 401 JSON 响应：

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
