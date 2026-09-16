# 认证 Auth (SaToken)

轻量级认证模块，支持登录态管理、权限校验。

## 配置

创建 `config/satoken.php`：

```php
return [
    'token_name'  => 'satoken',       // Token 名称
    'timeout'     => 2592000,         // 过期时间（秒），默认 30 天
    'active_timeout' => 0,            // 活跃超时，0 表示不限制
    'is_concurrent' => true,          // 是否允许同一账号多端登录
    'is_share'     => false,          // 多端是否共享 Token
    'token_style'  => 'uuid',         // Token 风格：uuid / simple-uuid / random
];
```

## 使用

```php
$auth = satoken();

// 登录
$token = $auth->login($userId);

// 获取当前登录 ID
$userId = $auth->getLoginId();

// 检查是否登录
if ($auth->isLogin()) {
    // ...
}

// 检查登录（未登录抛出异常）
$auth->checkLogin();

// 登出
$auth->logout();
```

## 辅助函数

```php
$token  = satoken()->login($userId);
$userId = satoken()->getLoginId();
satoken()->logout();
```
