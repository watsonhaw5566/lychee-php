# 模板引擎 View

基于 [Twig](https://twig.symfony.com/) 的模板引擎，模板文件默认从 `app/view` 目录读取，编译缓存写入 `runtime/twig`。

## 配置

创建 `config/view.php`（可选，不配置则使用默认值）：

```php
return [
    // 模板驱动：twig（默认）或 liquid（需 composer require liquid/liquid）
    'driver'     => 'twig',

    // 模板根目录
    'view_path'  => app_path('view'),

    // 编译缓存目录
    'cache_path' => runtime_path('twig'),

    // 是否开启调试模式（开启后可使用 dump() 等调试函数）
    'debug'      => false,

    // 静态资源基础 URL，如配置 CDN 可填 'https://cdn.example.com'
    'base_url'   => '',

    // 允许的模板后缀，按查找优先级排序
    'extensions' => ['.twig', '.html'],
];
```

### 配置项说明

| 配置项       | 类型     | 默认值                  | 说明                                   |
| ------------ | -------- | ----------------------- | -------------------------------------- |
| `driver`     | string   | `'twig'`                | 模板引擎驱动，可选 `twig` 或 `liquid`  |
| `view_path`  | string   | `app_path('view')`      | 模板文件所在目录                       |
| `cache_path` | string   | `runtime_path('twig')`  | 模板编译缓存目录                       |
| `debug`      | bool     | `false`                 | 是否开启调试模式                       |
| `base_url`   | string   | `''`                    | 静态资源 URL 前缀，用于 `asset()`/`url()` |
| `extensions` | string[] | `['.twig', '.html']`    | 允许的模板后缀，按查找优先级排序（liquid 驱动默认为 `['.liquid']`） |

## 模板后缀

支持 `.twig` 和 `.html` 两种后缀（可通过 `extensions` 配置自定义）。

- **指定后缀**：`view('user/profile.twig')`、`view('user/profile.html')` —— 直接使用对应文件
- **省略后缀**：`view('user/profile')` —— 按 `extensions` 配置的顺序依次查找，默认优先 `.twig`，找不到再回退 `.html`

推荐使用 `.twig` 后缀，这样 IDE 能提供 Twig 语法高亮和自动补全提示；同时 `.html` 仍然完全兼容。

## 渲染模板

在控制器中使用 `view()` 辅助函数：

```php
public function index()
{
    return view('user/profile', [
        'name' => 'Lychee',
        'user' => ['id' => 1, 'email' => 'a@b.com'],
    ]);
}
```

也可通过容器获取 View 实例：

```php
$html = app('view')->render('index', ['title' => 'Home']);

// 判断模板是否存在
if (app('view')->exists('user/profile')) {
    // ...
}
```

## 内置函数

框架内置了以下 Twig 函数，可直接在模板中使用：

### `asset(path)`

生成 `public/` 目录下静态资源的 URL。

```twig
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
<script src="{{ asset('js/app.js') }}"></script>
<img src="{{ asset('images/logo.png') }}" alt="Logo">
```

若配置了 `base_url`，URL 会自动拼接前缀：

```php
// config/view.php: 'base_url' => 'https://cdn.example.com'
// 模板中 {{ asset('css/app.css') }} 输出 'https://cdn.example.com/css/app.css'
```

PHP 代码中也可使用全局 `asset()` 辅助函数：

```php
$url = asset('css/app.css');  // '/css/app.css'
```

### `url(path)`

生成站点 URL，自动拼接 `base_url` 前缀。

```twig
<a href="{{ url('user/profile') }}">个人中心</a>
{{ url() }}  {# 输出站点根 URL #}
```

## 模板语法

```twig
{# app/view/hello.twig #}
<h1>Hello, {{ name }}!</h1>

{% if user %}
    <p>Email: {{ user.email }}</p>
{% endif %}

{% for item in items %}
    <li>{{ item }}</li>
{% endfor %}

{{ 'now'|date('Y-m-d') }}
```

### 模板继承

使用 `extends` 和 `block` 实现模板继承：

```twig
{# app/view/layout.twig #}
<!DOCTYPE html>
<html>
<head>
    <title>{% block title %}默认标题{% endblock %}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    {% block content %}{% endblock %}
    <script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
```

```twig
{# app/view/user/profile.twig #}
{% extends 'layout.twig' %}

{% block title %}{{ user.name }} 的个人中心{% endblock %}

{% block content %}
    <h1>{{ user.name }}</h1>
    <p>Email: {{ user.email }}</p>
{% endblock %}
```

## 扩展 Twig

通过 `getTwig()` 获取 Twig 环境实例，注册自定义函数、过滤器等：

```php
$twig = app('view')->getTwig();

// 注册自定义函数
$twig->addFunction(new \Twig\TwigFunction('avatar', fn ($id) => '/avatars/' . $id . '.png'));

// 注册自定义过滤器
$twig->addFilter(new \Twig\TwigFilter('money', fn ($value) => number_format((float) $value, 2)));
```

在模板中使用：

```twig
<img src="{{ avatar(user.id) }}" alt="Avatar">
<p>余额：{{ balance|money }} 元</p>
```

## 开发服务器

使用内置开发服务器时，静态文件直接从 `public/` 目录提供：

```bash
php lee run --host=127.0.0.1 --port=8000
```

## 切换到 Liquid 驱动

框架内置了基于 [liquid/liquid](https://github.com/kalimatas/php-liquid) 的 Liquid 驱动，可通过配置一键切换，无需编写适配代码。

### 1. 安装依赖

```bash
composer require liquid/liquid
```

### 2. 修改配置

在 `config/view.php` 中将 `driver` 设为 `liquid`：

```php
return [
    'driver'     => 'liquid',
    'view_path'  => app_path('view'),
    'cache_path' => runtime_path('liquid'),
    'extensions' => ['.liquid'],
    // ...
];
```

切换后，`view()`、`asset()` 辅助函数及 `app('view')` 自动使用 Liquid 驱动，控制器代码无需改动：

```php
public function index()
{
    return view('user/profile', ['name' => 'Lychee']);
}
```

### Liquid 模板示例

```liquid
{# app/view/user/profile.liquid #}
<h1>Hello, {{ name }}!</h1>

{% if user %}
    <p>Email: {{ user.email }}</p>
{% endif %}

{% for item in items %}
    <li>{{ item }}</li>
{% endfor %}
```

> **注意**：框架内置的异常页面（调试模式的堆栈页、生产环境的通用错误页）始终使用 Twig 渲染，不受驱动切换影响，确保异常情况下也能正常展示错误信息。

## 自定义模板驱动

如需使用 Twig、Liquid 之外的其他模板引擎（如 Blade、Smarty），可实现 `Lychee\view\ViewInterface` 接口并通过容器绑定覆盖。

### 1. 实现 ViewInterface

```php
<?php
// app/view/CustomDriver.php

namespace App\view;

use Lychee\view\ViewInterface;

class CustomDriver implements ViewInterface
{
    public function __construct(
        protected string $viewPath,
        protected string $baseUrl = '',
    ) {}

    public function render(string $template, array $data = []): string
    {
        // 你的渲染逻辑
    }

    public function exists(string $template): bool
    {
        return is_file($this->viewPath . '/' . $template);
    }

    public function asset(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }
}
```

### 2. 注册到容器

在 `app/common.php` 中绑定（加载时机早于视图模块初始化）：

```php
app()->instance(\Lychee\view\ViewInterface::class, new \App\view\CustomDriver(
    viewPath: app_path('view'),
    baseUrl:  config('view.base_url', ''),
));
```

移除绑定即可恢复框架默认的 Twig 驱动。

