# 应用配置 App

`config/app.php` 是框架的核心应用配置，控制时区、调试模式与异常页面展示策略。

## 配置项

```php
// config/app.php
return [
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 错误显示信息，非调试模式有效
    'error_message'    => '页面错误！请稍后再试~',

    // 是否显示错误信息（非调试模式下是否暴露真实异常信息）
    'show_error_msg'   => false,
];
```

## 配置说明

### default_timezone

应用默认时区，框架启动时调用 `date_default_timezone_set()` 生效。影响所有日期/时间函数（`date()`、`time()`、ORM 时间字段等）。

```php
date_default_timezone_set((string) config('app.default_timezone', 'Asia/Shanghai'));
```

### error_message

非调试模式下展示给用户的通用错误文案，同时作用于 HTML 页面与 JSON 响应的 `msg` 字段。默认值为 `页面错误,请稍后再试~`。

### show_error_msg

非调试模式下是否展示真实异常信息。

- `false`（默认）：展示 `error_message` 通用文案，不暴露异常详情，适合生产环境。
- `true`：展示真实异常消息（`$e->getMessage()`），便于线上排查但可能泄露敏感信息。

```php
$message = $debug || $showErrorMsg
    ? ($e->getMessage() ?: $statusText)
    : $errorMessage;
```

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
