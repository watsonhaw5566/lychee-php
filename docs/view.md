# 模板引擎 View

基于 Twig 的模板引擎，模板文件从 `app/view` 目录读取。

## 配置

创建 `config/view.php`（可选，不配置则使用默认值）：

```php
return [
    'view_path'  => app_path('view'),
    'cache_path' => runtime_path('twig'),
    'debug'      => false,
    'base_url'   => '',  // 静态资源基础 URL，如配置 CDN 可填 'https://cdn.example.com'
];
```

## 渲染模板

```php
// 控制器中
public function index()
{
    return view('user/profile.html', [
        'name' => 'Lychee',
        'user' => ['id' => 1, 'email' => 'a@b.com'],
    ]);
}

// 或通过容器
$html = app('view')->render('index.html', ['title' => 'Home']);
```

## 静态资源

静态文件（CSS、JS、图片等）存放在 `public/` 目录。模板中通过 `asset()` 函数生成资源 URL：

```twig
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
<script src="{{ asset('js/app.js') }}"></script>
<img src="{{ asset('images/logo.png') }}" alt="Logo">
```

也可在 PHP 代码中使用 `asset()` 辅助函数：

```php
$url = asset('css/app.css');  // '/css/app.css'
```

若配置了 `base_url`，则资源 URL 会自动拼接前缀：

```php
// config/view.php: 'base_url' => 'https://cdn.example.com'
asset('css/app.css');  // 'https://cdn.example.com/css/app.css'
```

## 开发服务器

使用内置开发服务器，静态文件直接从 `public/` 提供：

```bash
php lee run --host=127.0.0.1 --port=8000
```

## 模板语法

```twig
{# app/view/hello.html #}
<h1>Hello, {{ name }}!</h1>

{% if user %}
    <p>Email: {{ user.email }}</p>
{% endif %}

{% for item in items %}
    <li>{{ item }}</li>
{% endfor %}

{{ 'now'|date('Y-m-d') }}
```

## 扩展 Twig

```php
$twig = app('view')->getTwig();
$twig->addFunction(new \Twig\TwigFunction('avatar', fn ($id) => '/avatars/' . $id . '.png'));
```
