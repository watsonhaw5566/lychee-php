# 认证 Auth (SaToken)

轻量级认证模块，基于缓存实现 Token 登录态管理，支持多端登录、滑动续期、强制踢人。

## 配置

创建 `config/satoken.php`：

```php
return [
    'token_name'      => '',            // Token 请求头名称，留空则从 Authorization: Bearer 读取
    'store'           => null,          // 缓存驱动名，null 使用默认缓存
    'timeout'         => 86400 * 7,     // Token 过期时间（秒），默认 7 天
    'auto_renew'      => true,          // 是否开启滑动续期
    'renew_before'    => 3600,          // 剩余时间少于该值（秒）时自动续期，默认 1 小时
    'max_login_count' => 10,            // 同一账号最大同时在线数，超出自动踢掉最早的
];
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

// 整个控制器需要登录
#[Resource('/users')]
#[Middleware(SatokenMiddleware::class)]
class UserController
{
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
