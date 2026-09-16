# Cookie

框架提供了完整的 Cookie 读写能力，内置 `Cookie` 值对象封装 `Set-Cookie` 头的所有属性，支持 Secure / HttpOnly / SameSite 等安全选项。

## 读取 Cookie

通过 `Request` 对象读取：

```php
use Lychee\http\Request;

$request = request();

// 读取单个 Cookie
$name = $request->cookie('name');

// 带默认值
$theme = $request->cookie('theme', 'light');

// 读取全部
$cookies = $request->cookie();
```

也可以使用全局辅助函数（不传值时为读取）：

```php
$name = cookie('name');
```

## 设置 Cookie

### 通过 Response 设置

```php
use Lychee\http\Response;

return response('OK')->cookie('name', 'value', minutes: 60);
```

`cookie()` 方法参数：

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| `$name` | string | — | Cookie 名称 |
| `$value` | string | `''` | Cookie 值 |
| `$minutes` | int | `0` | 过期分钟数，0 表示会话 Cookie（关闭浏览器即失效） |
| `$path` | string | `'/'` | 路径 |
| `$domain` | string\|null | `null` | 域名 |
| `$secure` | bool | `false` | 仅通过 HTTPS 传输 |
| `$httpOnly` | bool | `true` | 禁止 JavaScript 访问 |
| `$sameSite` | string\|null | `Cookie::SAME_SITE_LAX` | SameSite 策略 |

### 通过全局辅助函数设置

```php
// 返回一个 Response 实例，需在控制器中 return
return cookie('name', 'value', minutes: 60);

// 带额外选项
return cookie('name', 'value', minutes: 60, [
    'secure'   => true,
    'httpOnly' => true,
    'sameSite' => Cookie::SAME_SITE_STRICT,
]);
```

## 删除 Cookie

```php
// 通过 Response
return response('OK')->withoutCookie('name');

// 指定路径和域名
return response('OK')->withoutCookie('name', path: '/', domain: 'example.com');

// 使用 Cookie::forget 创建过期 Cookie
use Lychee\http\Cookie;

return response()->withCookie(Cookie::forget('name'));
```

## SameSite 策略

`Cookie` 类提供三个常量：

```php
use Lychee\http\Cookie;

Cookie::SAME_SITE_LAX;    // 'Lax'（默认）
Cookie::SAME_SITE_STRICT; // 'Strict'
Cookie::SAME_SITE_NONE;   // 'None'（必须配合 Secure）
```

示例：

```php
// 跨站场景，必须设置 Secure
return response('OK')->cookie(
    name:     'token',
    value:    'xxx',
    minutes:  60,
    secure:   true,
    sameSite: Cookie::SAME_SITE_NONE,
);
```

## 使用 Cookie 值对象

需要精细控制时，可直接构造 `Cookie` 实例并通过 `withCookie()` 添加到响应：

```php
use Lychee\http\Cookie;
use Lychee\http\Response;

$cookie = new Cookie(
    name:      'token',
    value:     'abc123',
    expiresAt: new \DateTimeImmutable('+1 hour'),
    path:      '/',
    secure:    true,
    httpOnly:  true,
    sameSite:  Cookie::SAME_SITE_STRICT,
);

return response('OK')->withCookie($cookie);
```

也可使用 `Cookie::create()` 工厂方法（以分钟为单位）：

```php
$cookie = Cookie::create('name', 'value', minutes: 30);
```

## 实际应用：I18n 语言持久化

框架内置的 I18n 中间件即通过 Cookie 持久化用户语言偏好：

```php
// 设置语言 Cookie（30 天）
$response->cookie('lang', $locale, minutes: 60 * 24 * 30);

// 读取语言 Cookie
$locale = $request->cookie('lang');
```
