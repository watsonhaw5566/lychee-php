# 应用配置 App

`config/app.php` 是框架的核心应用配置，控制时区、调试模式与异常页面展示策略。

## 配置项

```php
// config/app.php
return [
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 错误显示信息，非调试模式有效
    'error_message'    => '页面错误，请稍后再试~',

    // 是否显示错误信息（非调试模式下是否暴露真实异常信息）
    'show_error_msg'   => false,

    // 自定义异常处理器类名，需继承 Lychee\http\ExceptionHandler
    'exception_handler' => '',

    // 校验提示语言：'zh'（默认）中文 | 'en' 英文
    'validate_lang' => 'zh',
];
```

## 配置说明

### default_timezone

应用默认时区，框架启动时调用 `date_default_timezone_set()` 生效。影响所有日期/时间函数（`date()`、`time()`、ORM 时间字段等）。

```php
date_default_timezone_set((string) config('app.default_timezone', 'Asia/Shanghai'));
```

### error_message

非调试模式下展示给用户的通用错误文案，同时作用于 HTML 页面与 JSON 响应的 `msg` 字段。默认值为 `页面错误，请稍后再试~`。

该配置支持多语言：当 i18n 模块已启用时，框架会将此配置值作为翻译键去查找翻译；若存在对应翻译则使用翻译值，否则原样返回该配置值。

你可以直接硬编码中文字符串作为默认文案，也可以将其设为任意翻译键以支持多语言。

#### 用法一：硬编码中文（默认）

```php
// config/app.php
'error_message' => '页面错误，请稍后再试~',
```

不配置 i18n 时直接使用该文案；配置了 i18n 但找不到对应翻译时也原样返回。

#### 用法二：使用翻译键支持多语言

```php
// config/app.php
'error_message' => 'errors.server_error',
```

在各语言目录下创建对应的翻译文件：

```php
// app/lang/zh-CN/errors.php
return [
    'server_error' => '页面错误，请稍后再试~',
];

// app/lang/en/errors.php
return [
    'server_error' => 'Page error, please try again later~',
];
```

请求会根据当前语言（由 `I18nMiddleware` 自动检测）返回对应翻译；若当前语言未定义该键，则回退到 `fallback_locale` 的翻译。

详见 [国际化 i18n](./i18n)。

### show_error_msg

非调试模式下是否展示真实异常信息。

- `false`（默认）：展示 `error_message` 通用文案，不暴露异常详情，适合生产环境。
- `true`：展示真实异常消息（`$e->getMessage()`），便于线上排查但可能泄露敏感信息。

```php
$message = $debug || $showErrorMsg
    ? ($e->getMessage() ?: $statusText)
    : $errorMessage;
```

### exception_handler

自定义异常处理器类名，需继承 `Lychee\http\ExceptionHandler`。留空或不配置时使用框架默认处理器。

需填写完整类名：

```php
// config/app.php
'exception_handler' => \App\exception\Handler::class,
```

#### 自定义异常处理器

框架默认的 `ExceptionHandler` 已处理以下异常类型：

| 异常类 | HTTP 状态码 | 说明 |
| --- | --- | --- |
| `think\exception\ValidateException` | 400 | 数据验证失败 |
| `Lychee\routing\RouteNotFoundException` | 404 | 路由未找到 |
| `Lychee\http\HttpException` | 自定义 | HTTP 异常 |
| 其他异常 | 500 | 服务器内部错误 |

如需完全自定义异常处理逻辑，可继承 `ExceptionHandler` 并覆盖 `report()` 和/或 `render()` 方法：

```php
// app/exception/Handler.php
namespace App\exception;

use Lychee\http\ExceptionHandler;
use Lychee\http\Request;
use Lychee\http\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * 不需要记录日志的异常类列表。
     *
     * @var array<class-string<Throwable>>
     */
    protected array $ignoreReport = [
        \Lychee\http\HttpException::class,
        \Lychee\routing\RouteNotFoundException::class,
        \think\exception\ValidateException::class,
    ];

    /**
     * 记录异常日志。
     */
    public function report(Throwable $e): void
    {
        parent::report($e);

        // 可在此处上报到监控平台
    }

    /**
     * 渲染异常响应。
     */
    public function render(Request $request, Throwable $e): Response
    {
        // 自定义业务异常处理
        if ($e instanceof \App\exception\BusinessException) {
            return json(['code' => $e->getCode(), 'msg' => $e->getMessage()], 400);
        }

        // 其余交给父类处理
        return parent::render($request, $e);
    }
}
```

### validate_lang

校验失败提示的默认语言，作用于所有通过 `think\Validate` 进行的校验（包括全局 `validate()` 函数、`ResourceController` 的 `validate()` 方法、验证器类等）。

| 值 | 说明 |
| --- | --- |
| `'zh'`（默认，不配置即为中文） | 使用 think-validate 内置中文提示 |
| `'en'` | 使用 think-validate 内置英文提示 |

不配置该项时默认为中文，开箱即用。如需切换为英文，在 `config/app.php` 中添加：

```php
'validate_lang' => 'en',
```

::: tip
该配置仅控制校验框架的默认提示语言。若需要更精细的多语言校验提示（如按请求语言动态切换），可在验证器中通过 `$message` 数组自定义，或结合 i18n 模块自行实现。
:::

## 调试模式

`app.debug` 控制是否进入调试模式，优先读取 `.env` 中的 `APP_DEBUG`：

```php
$debug = (bool) config('app.debug', env('APP_DEBUG', false));
```

| 模式 | HTML 响应 | JSON 响应 |
| --- | --- | --- |
| 调试模式 (`debug=true`) | 渲染异常详情页（含堆栈、文件、行号） | 返回完整异常信息（`type`、`file`、`line`、`trace`） |
| 非调试模式 (`debug=false`) | 渲染通用错误页（仅状态码 + `error_message`） | 返回 `code` + `msg`（受 `show_error_msg` 控制） |

::: tip
生产环境务必设置 `APP_DEBUG=false` 且 `show_error_msg=false`，避免泄露服务器内部信息。
:::
