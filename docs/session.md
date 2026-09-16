# 会话 Session

基于驱动的会话管理，内置文件驱动。

## 配置

创建 `config/session.php`：

```php
return [
    'driver'      => 'file',
    'path'        => runtime_path('session'),  // 文件存储目录，缺省为 runtime/session
    'expire'      => 120,                      // 过期分钟数
    'name'        => 'PHPSESSID',              // Cookie 名称
    'cookie_path' => '/',                      // Cookie 生效路径，缺省为 /
];
```

> **注意**：`path` 是会话文件的存储目录，`cookie_path` 是 Session Cookie 的生效路径，二者用途不同。`cookie_path` 缺省为 `/`（整个站点生效），通常无需修改。

## 使用

```php
// 获取 session 实例
$session = session();

// 读写
session('user_id', 123);
$userId = session('user_id');

// 判断是否存在
session()->has('user_id');

// 删除
session()->remove('user_id');

// 清空
session()->flush();

// 取出并删除
$value = session()->pull('key');

// 重新生成 ID
session()->regenerate();

// 销毁会话
session()->invalidate();
```

## 会话中间件

挂载 `SessionMiddleware` 后，请求开始时自动启动会话，响应时自动保存并写入 Cookie：

```php
#[Route('GET', '/profile')]
#[Middleware(\Lychee\session\SessionMiddleware::class)]
public function profile() {}
```
