# 验证码 Captcha

框架内置了一个轻量级验证码模块 `Lychee\captcha\Captcha`，支持**图形验证码**和**短信验证码**两种类型，共享同一套"生成-存储-过期-校验-作废"内核。

核心实现位于 [Captcha.php](file:///Users/wangjue/PhpstormProjects/ant/lychee-php/src/captcha/Captcha.php)。

## 启用模块

Captcha 是按需加载的可选模块。在 `config/` 目录下创建 `captcha.php` 配置文件即可自动启用：

```php
// config/captcha.php
return [
    // 缓存 store，留空使用默认缓存驱动
    'store'        => null,

    // 验证码有效期（秒）
    'expire'       => 300,

    // 最大验证失败次数，超限自动作废
    'max_attempts' => 5,

    // 缓存键前缀
    'prefix'       => 'captcha:',

    // 图形验证码配置
    'image' => [
        'chars'     => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', // 去掉易混淆的 0/O/1/I/L
        'length'    => 4,
        'width'     => 120,
        'height'    => 40,
        'font_size' => 20,
    ],

    // 短信验证码配置
    'sms' => [
        'length' => 6,
        // 发送器类名，需实现 send(string $code, string $phone): bool
        // 留空则不实际发送（开发调试用）
        'send'   => null,
    ],

    // 邮箱验证码配置
    'email' => [
        'length'  => 6,
        'subject' => '您的验证码',
        // 发送器类名，需实现 send(string $code, string $email, string $subject): bool
        // 留空则不实际发送（开发调试用）
        'send'    => null,
    ],
];
```

## 核心方法

| 方法 | 说明 |
| --- | --- |
| `generate(string $type, string $key): mixed` | 生成验证码，`$type` 为 `image`/`sms`/`email`，`$key` 为关联标识 |
| `verify(string $type, string $key, string $code): bool` | 校验验证码，成功后立即作废（防重放） |
| `clear(string $type, string $key): void` | 手动作废验证码 |

## 图形验证码

图形验证码使用 `src/captcha/assets/` 下的资源文件生成：
- `bgs/` — 背景图片（jpg），每次随机选取一张
- `ttfs/` — TTF 字体文件，每次随机选取一个，字符支持随机倾斜

如需自定义背景或字体，直接替换对应目录下的文件即可。

### 配置项

| 配置 | 默认值 | 说明 |
| --- | --- | --- |
| `chars` | `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` | 字符集 |
| `length` | `4` | 验证码长度 |
| `width` | `120` | 图片宽度 |
| `height` | `40` | 图片高度 |
| `font_size` | `20` | 字体大小 |

### 生成图片

```php
use Lychee\captcha\Captcha;
use Lychee\http\Response;
use Lychee\routing\Route;

class CaptchaController
{
    #[Route('GET', '/captcha')]
    public function image(Captcha $captcha): Response
    {
        $id  = bin2hex(random_bytes(8));
        $png = $captcha->generate('image', $id);

        return new Response($png, 200, [
            'Content-Type' => 'image/png',
            'Set-Cookie'   => "captcha_id={$id}; Path=/; HttpOnly",
        ]);
    }
}
```

前端 `<img src="/captcha">` 即可显示，验证码 ID 通过 Cookie 回传。

### 校验

```php
#[Route('POST', '/login')]
public function login(Request $request, Captcha $captcha): JsonResponse
{
    $id   = $request->cookie('captcha_id');
    $code = (string) $request->post('captcha', '');

    if (!$captcha->verify('image', $id, $code)) {
        return new JsonResponse(['code' => 400, 'msg' => '验证码错误'], 400);
    }

    // 校验成功，验证码已自动作废，继续登录逻辑...
}
```

## 短信验证码

### 配置短信发送器

创建发送器类，实现 `send(string $code, string $phone): bool` 方法：

```php
// app/service/SmsSender.php
namespace App\service;

class SmsSender
{
    public function send(string $code, string $phone): bool
    {
        // 调用阿里云 / 腾讯云 / 自建短信网关
        // $client = new AliyunSmsClient(...);
        // return $client->send($phone, 'SMS_123456', ['code' => $code]);
        return true;
    }
}
```

在 `config/captcha.php` 的 `sms.send` 中指定发送器类名：

```php
return [
    'sms' => [
        'length' => 6,
        'send'   => \App\service\SmsSender::class,
    ],
];
```

> 发送器类由容器实例化，构造函数支持依赖注入。留空（`null`）则不实际发送，用于开发调试。

### 发送与校验

```php
#[Route('POST', '/sms/send')]
public function sendSms(Request $request, Captcha $captcha): JsonResponse
{
    $phone = (string) $request->post('phone', '');

    $ok = $captcha->generate('sms', $phone);

    return $ok
        ? new JsonResponse(['code' => 0, 'msg' => '验证码已发送'])
        : new JsonResponse(['code' => 500, 'msg' => '发送失败'], 500);
}

#[Route('POST', '/register')]
public function register(Request $request, Captcha $captcha): JsonResponse
{
    $phone = (string) $request->post('phone', '');
    $code  = (string) $request->post('code', '');

    if (!$captcha->verify('sms', $phone, $code)) {
        return new JsonResponse(['code' => 400, 'msg' => '验证码错误或已过期'], 400);
    }

    // 注册逻辑...
}
```

## 邮箱验证码

### 配置邮件发送器

创建发送器类，实现 `send(string $code, string $email, string $subject): bool` 方法：

```php
// app/service/EmailSender.php
namespace App\service;

class EmailSender
{
    public function send(string $code, string $email, string $subject): bool
    {
        // 使用 PHPMailer / Symfony Mailer / 阿里云邮件推送等
        // $mail = new PHPMailer(true);
        // $mail->addAddress($email);
        // $mail->Subject = $subject;
        // $mail->Body = "您的验证码是：{$code}，5 分钟内有效。";
        // return $mail->send();
        return true;
    }
}
```

在 `config/captcha.php` 的 `email.send` 中指定发送器类名：

```php
return [
    'email' => [
        'length'  => 6,
        'subject' => '您的验证码',
        'send'    => \App\service\EmailSender::class,
    ],
];
```

> 发送器类由容器实例化，构造函数支持依赖注入。留空（`null`）则不实际发送，用于开发调试。

### 发送与校验

```php
#[Route('POST', '/email/send')]
public function sendEmail(Request $request, Captcha $captcha): JsonResponse
{
    $email = (string) $request->post('email', '');

    $ok = $captcha->generate('email', $email);

    return $ok
        ? new JsonResponse(['code' => 0, 'msg' => '验证码已发送至邮箱'])
        : new JsonResponse(['code' => 500, 'msg' => '发送失败'], 500);
}

#[Route('POST', '/register')]
public function register(Request $request, Captcha $captcha): JsonResponse
{
    $email = (string) $request->post('email', '');
    $code  = (string) $request->post('code', '');

    if (!$captcha->verify('email', $email, $code)) {
        return new JsonResponse(['code' => 400, 'msg' => '验证码错误或已过期'], 400);
    }

    // 注册逻辑...
}
```

## 安全机制

1. **一次性使用**：`verify()` 成功后立即从缓存删除，防止重放攻击
2. **失败次数限制**：连续失败达到 `max_attempts`（默认 5 次）后验证码自动作废
3. **不回显明文**：验证码明文仅存储在服务端缓存，响应中绝不返回
4. **大小写不敏感**：图形验证码校验时自动忽略大小写（`strcasecmp`）
5. **自动过期**：验证码带 TTL，过期后自动失效

## 防刷建议

短信发送接口应配合 [Throttle 中间件](./throttle) 限流，按手机号维度限制发送频率：

```php
// app/middleware/SmsThrottle.php
namespace App\middleware;

use Lychee\throttle\ThrottleMiddleware;
use think\CacheManager;

class SmsThrottle extends ThrottleMiddleware
{
    public function __construct(CacheManager $cache)
    {
        parent::__construct($cache, [
            'max_attempts'  => 1,
            'decay_seconds' => 60,
            'key_type'      => 'ip',
        ]);
    }
}
```

```php
#[Route('POST', '/sms/send')]
#[Middleware(\App\middleware\SmsThrottle::class)]
public function sendSms(...) { ... }
```

## 助手函数

框架提供 `captcha()` 全局助手函数，等价于 `app('captcha')`：

```php
captcha()->generate('image', $id);
captcha()->verify('sms', $phone, $code);
captcha()->clear('image', $id);
```
