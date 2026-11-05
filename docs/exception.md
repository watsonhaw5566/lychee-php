# 异常处理 Exception

Lychee PHP 提供了统一的异常处理体系：所有具备 HTTP 语义的异常均继承自 [Lychee\http\HttpException](file:///Users/wangjue/PhpstormProjects/ant/lychee-php/src/http/HttpException.php)，由全局异常处理器 [Lychee\http\ExceptionHandler](file:///Users/wangjue/PhpstormProjects/ant/lychee-php/src/http/ExceptionHandler.php) 自动捕获并渲染为对应状态码的响应，无需在控制器或中间件中逐个 `try/catch`。

## 处理流程

```
请求 → Kernel → 路由/中间件/控制器
                ↓ 抛出异常
        ExceptionHandler::report()   （按需记录日志）
        ExceptionHandler::render()   （渲染为响应）
                ↓
        HTML 异常页 或 JSON 错误响应
```

- **路由匹配阶段**、**中间件管道**、**控制器调用**中抛出的未捕获异常，均由 [Kernel](file:///Users/wangjue/PhpstormProjects/ant/lychee-php/src/http/Kernel.php) 的 `exceptionResponse()` 统一交给 `ExceptionHandler` 处理。
- **致命错误**（`E_ERROR`、`E_PARSE` 等）由 [Application::registerErrorHandling()](file:///Users/wangjue/PhpstormProjects/ant/lychee-php/src/Application.php) 注册的 shutdown 函数兜底，同样委托给 `ExceptionHandler` 渲染——因此自定义处理器、`exception_render` 配置（JSON 模式）与日志记录对致命错误同样生效。
- **PHP Warning/Notice** 被转换为 `ErrorException`，同样进入统一处理流程。

## 异常继承体系

```
RuntimeException
  └─ Lychee\http\HttpException          ← 带 HTTP 状态码的基类
       ├─ ValidateException             (400)  数据校验失败
       ├─ RouteNotFoundException        (404)  路由未找到
       ├─ NotLoginException             (401)  未登录
       ├─ TokenInvalidException         (401)  Token 无效
       ├─ NoPermissionException         (403)  权限不足
       ├─ CaptchaException              (400)  验证码错误
       ├─ ModelNotFoundException        (404)  模型未找到
       └─ DataNotFoundException         (404)  数据未找到

RuntimeException（其他）
  ├─ Lychee\orm\exception\DbException   (500)  数据库内部错误
  └─ Lychee\http\client\RequestException       HTTP 客户端请求失败
```

继承 `HttpException` 的异常会被自动渲染为对应状态码的响应，且默认不写入错误日志（属于预期内的业务错误）。直接继承 `RuntimeException` 的异常（如 `DbException`）则统一按 500 处理并记录日志。

## 内置异常类

| 异常类 | 状态码 | 触发场景 |
| --- | --- | --- |
| `Lychee\validate\ValidateException` | 400 | 数据校验失败 |
| `Lychee\captcha\exception\CaptchaException` | 400 | 验证码生成或校验失败 |
| `Lychee\auth\exception\NotLoginException` | 401 | 未登录 |
| `Lychee\auth\exception\TokenInvalidException` | 401 | Token 格式无效或已过期 |
| `Lychee\auth\exception\NoPermissionException` | 403 | 无权限访问 |
| `Lychee\routing\RouteNotFoundException` | 404 | 路由未匹配 |
| `Lychee\orm\exception\ModelNotFoundException` | 404 | `findOrFail()` 未命中 |
| `Lychee\orm\exception\DataNotFoundException` | 404 | 查询结果集为空（`failException` 模式） |
| `Lychee\orm\exception\DbException` | 500 | 数据库连接失败、SQL 执行错误等 |
| `Lychee\http\client\RequestException` | 远端状态码 | HTTP 客户端请求返回 4xx/5xx（显式调用 `throw()` 时） |

## HttpException 基类

[Lychee\http\HttpException](file:///Users/wangjue/PhpstormProjects/ant/lychee-php/src/http/HttpException.php) 是所有 HTTP 语义异常的基类，构造函数签名：

```php
public function __construct(
    int $statusCode,             // HTTP 状态码（401/403/404/...）
    string $message = '',        // 错误描述
    int $code = 0,               // 业务错误码（区别于 HTTP 状态码）
    ?Throwable $previous = null, // 上一个异常（异常链）
    array $headers = [],         // 需要附加到响应的头信息
)
```

### 常用方法

| 方法 | 返回值 | 说明 |
| --- | --- | --- |
| `getStatusCode()` | `int` | 获取 HTTP 状态码 |
| `getHeaders()` | `array` | 获取需要附加到响应的头信息 |
| `getMessage()` | `string` | 错误描述（继承自 `Exception`） |
| `getCode()` | `int` | 业务错误码（继承自 `Exception`） |

### 附加响应头

某些场景需要在错误响应中附加额外的 HTTP 头，例如限流响应的 `Retry-After`、未授权响应的 `WWW-Authenticate`：

```php
use Lychee\http\HttpException;

// 限流：告知客户端 60 秒后重试
throw new HttpException(429, '请求过于频繁', 0, null, [
    'Retry-After' => '60',
]);

// 未授权：提示认证方式
throw new HttpException(401, '需要登录', 0, null, [
    'WWW-Authenticate' => 'Bearer realm="api"',
]);
```

## 抛出异常

在控制器、中间件或服务类中直接 `throw` 即可，框架会自动捕获并渲染：

```php
use Lychee\http\HttpException;

public function show(int $id): JsonResponse
{
    $user = User::find($id);

    if ($user === null) {
        throw new HttpException(404, '用户不存在');
    }

    return json(['code' => 0, 'msg' => 'ok', 'data' => $user]);
}
```

也可以使用内置的语义化异常类，状态码已固化：

```php
use Lychee\auth\exception\NotLoginException;
use Lychee\auth\exception\NoPermissionException;
use Lychee\orm\exception\ModelNotFoundException;

throw new NotLoginException('请先登录');        // → 401
throw new NoPermissionException('无权访问');     // → 403
throw new ModelNotFoundException(User::class);   // → 404
```

::: tip
无需在控制器中 `try/catch` 这些异常。让它们向上抛出，由全局处理器统一渲染，才能保证响应格式一致。
:::

## 响应格式

### JSON 响应

非 HTML 请求（`Accept: application/json` 或 `exception_render = 'json'`）返回统一的 JSON 结构：

```json
{
    "code": 404,
    "msg": "用户不存在",
    "data": null
}
```

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `code` | `int` | HTTP 状态码 |
| `msg` | `string` | 错误描述（生产环境受 `show_error_msg` 控制） |
| `data` | `null` | 统一占位，便于前端解析 |

#### 调试模式

`APP_DEBUG=true` 时，JSON 响应会额外包含异常详情，便于排查：

```json
{
    "code": 404,
    "msg": "用户不存在",
    "data": null,
    "type": "Lychee\\http\\HttpException",
    "file": "/path/to/controller/UserController.php",
    "line": 42,
    "trace": ["#0 ...", "#1 ..."]
}
```

#### 校验异常的字段级错误

`ValidateException` 会在响应中额外返回 `errors` 字段，保留字段到错误信息的映射，方便前端做表单字段高亮：

```json
{
    "code": 400,
    "msg": "用户名必填；邮箱格式不正确",
    "data": null,
    "errors": {
        "name": "用户名必填",
        "email": "邮箱格式不正确"
    }
}
```

### HTML 响应

浏览器请求（`Accept: text/html` 或 `exception_render = 'html'`）渲染 HTML 异常页：

- **调试模式**：展示异常详情页，包含异常类、消息、文件、行号、完整堆栈、请求方法与 URL。
- **生产模式**：展示通用错误页，仅包含状态码与 `error_message` 配置的文案，不暴露异常详情。

#### auto 模式的 Accept 解析

当 `exception_render = 'auto'`（默认）时，框架会解析 `Accept` 头的 **q 值**来判断渲染格式：

- 比较 `text/html` 与 `application/json` 的 q 值，优先级高者胜出。
- 未指定 `Accept` 或仅包含通配符时默认渲染 HTML。
- 仅声明 `application/json` 时返回 JSON。

```
Accept: text/html;q=0.9,application/json;q=1.0   → JSON（json 优先级更高）
Accept: text/html,application/json;q=0.8          → HTML（html 默认 q=1.0）
Accept: application/json                           → JSON
Accept: */*                                        → HTML
```

## 自定义业务异常

当内置异常类无法满足业务需求时，继承 `HttpException` 并固化状态码即可：

```php
// app/exception/OrderException.php
namespace App\exception;

use Lychee\http\HttpException;

/**
 * 订单异常，对应 HTTP 400。
 */
class OrderException extends HttpException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, $code, $previous);
    }
}
```

使用时直接抛出，框架自动渲染为 400 响应：

```php
use App\exception\OrderException;

throw new OrderException('订单已支付，无法取消', 10001);
```

`getCode()` 返回业务错误码 `10001`，可用于前端区分具体业务错误类型。

::: tip
如果自定义异常代表系统内部错误（应返回 500 并记录日志），则继承 `RuntimeException` 而非 `HttpException`。
:::

## 异常日志

`ExceptionHandler::report()` 负责将需要记录的异常写入日志（通过 `logger()->error()`）。默认记录的上下文字段：

| 字段 | 说明 |
| --- | --- |
| `exception` | 异常类名 |
| `file` / `line` | 异常抛出位置 |
| `trace` | 完整堆栈字符串 |
| `previous` | 上一个异常（类名 + 消息，仅当存在异常链时） |
| `url` | 请求 URL |
| `method` | 请求方法 |
| `ip` | 客户端 IP |
| `login_id` | 当前登录 ID（仅当已设置登录态时） |

日志消息本身为异常的 `getMessage()`。`HttpException` 及其子类默认不记录日志（见下方 `ignoreReport`），`RuntimeException` 等系统异常会被记录。

::: tip
请求上下文仅在请求已注册到容器时附带；若异常发生在请求注册之前（如启动阶段），则只记录异常本身的信息。
:::

## 自定义异常处理器

默认的 [ExceptionHandler](file:///Users/wangjue/PhpstormProjects/ant/lychee-php/src/http/ExceptionHandler.php) 已能处理绝大多数场景。如需自定义日志上报、响应格式或特定异常的渲染逻辑，可继承它并覆盖对应方法。

### 配置自定义处理器

```php
// config/app.php
'exception_handler' => \App\exception\Handler::class,
```

### 覆盖 report() — 自定义日志

```php
// app/exception/Handler.php
namespace App\exception;

use Lychee\http\ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    public function report(Throwable $e): void
    {
        parent::report($e);

        // 上报到监控平台（如 Sentry）
        if ($this->shouldReport($e) && app()->has('sentry')) {
            app('sentry')->captureException($e);
        }
    }
}
```

### 覆盖 render() — 自定义响应

```php
public function render(\Lychee\http\Request $request, Throwable $e): \Lychee\http\Response
{
    // 业务异常统一返回特定结构
    if ($e instanceof \App\exception\BusinessException) {
        return json([
            'code' => $e->getCode(),
            'msg'  => $e->getMessage(),
            'data' => null,
        ], $e->getStatusCode());
    }

    return parent::render($request, $e);
}
```

### 控制日志记录 — ignoreReport

`$ignoreReport` 数组中的异常类不会被记录日志（因为它们属于预期内的业务错误）。默认包含：

```php
protected array $ignoreReport = [
    \Lychee\http\HttpException::class,
    \Lychee\routing\RouteNotFoundException::class,
    \Lychee\validate\ValidateException::class,
];
```

由于所有内置业务异常都继承自 `HttpException`，它们默认都不写日志。如需让某类异常记录日志，从 `$ignoreReport` 中移除对应类，或在子类中调整：

```php
protected array $ignoreReport = [
    \Lychee\routing\RouteNotFoundException::class,
    \Lychee\validate\ValidateException::class,
    // 移除 HttpException，使所有业务异常都记录日志
];
```

## 调试模式 vs 生产模式

| 模式 | 环境变量 | HTML 响应 | JSON 响应 | 日志 |
| --- | --- | --- | --- | --- |
| 调试 | `APP_DEBUG=true` | 异常详情页（堆栈/文件/行号） | 完整异常信息（`type`/`file`/`line`/`trace`） | 不变 |
| 生产 | `APP_DEBUG=false` | 通用错误页（仅状态码+文案） | `code`+`msg`（受 `show_error_msg` 控制） | 不变 |

上述规则对**致命错误**（`E_ERROR`、`E_PARSE` 等）同样适用——致命错误也由 `ExceptionHandler` 统一渲染，因此纯 API 应用（`exception_render = 'json'`）的致命错误会返回 JSON 而非 HTML。

::: warning
生产环境务必设置 `APP_DEBUG=false` 且 `show_error_msg=false`，避免泄露服务器内部路径与堆栈信息。
:::

## 相关配置

异常处理行为受 `config/app.php` 中以下配置项控制，详见 [应用配置 App](./app)：

| 配置项 | 说明 |
| --- | --- |
| `exception_handler` | 自定义异常处理器类名 |
| `exception_render` | 渲染模式：`auto` / `html` / `json` |
| `error_message` | 生产环境通用错误文案 |
| `show_error_msg` | 生产环境是否展示真实异常信息 |
| `app.debug` / `APP_DEBUG` | 调试模式开关 |
