# 快速开始

## 环境要求

- PHP >= 8.2
- Composer
- Node.js >= 18（仅用于运行文档站点）

## 安装

```bash
composer require watsonhaw/lychee-php
```

## 创建入口文件

### Web 入口 `public/index.php`

```php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Lychee\Application;

$app = new Application(
    basePath: dirname(__DIR__),
    controllerNamespace: 'App\\controller',
);

$app->run();
```

### 命令行入口 `lee`

```php
#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Lychee\console\Console;
use Lychee\Application;

$app = new Application(
    basePath: __DIR__,
    controllerNamespace: 'App\\controller',
);

$console = $app->container->get(Console::class);
$console->run();
```

```bash
chmod +x lee
```

## 目录结构

```
your-app/
├── app/
│   ├── controller/    # 控制器
│   ├── model/         # 模型
│   ├── middleware/    # 中间件
│   ├── job/           # 队列任务
│   ├── view/          # Twig 模板
│   └── common.php     # 应用公共文件（可选，定义全局辅助函数）
├── config/            # 配置文件（按需创建）
├── database/
│   ├── migrations/    # 迁移文件
│   └── seeders/       # 数据填充
├── public/
│   └── index.php      # Web 入口
├── runtime/           # 运行时缓存、日志
└── lee                # 命令行入口
```

## 应用公共文件 common.php

在 `app/common.php` 中定义的函数会在框架启动时自动加载，可在全局范围内使用，类似 ThinkPHP 的 `common.php`。文件不存在时自动跳过，不影响启动。

```php
// app/common.php
<?php

if (!function_exists('format_money')) {
    function format_money(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
```

加载时机在配置加载之后，因此 `common.php` 内可安全使用 `config()`、`app()`、`env()` 等框架辅助函数。建议用 `function_exists` 包裹，避免与框架内置函数重名冲突。

## 第一个控制器

```php
// app/controller/HelloController.php
namespace App\controller;

use Lychee\routing\Route;

class HelloController
{
    #[Route('GET', '/')]
    public function index()
    {
        return view('hello.html', ['name' => 'Lychee']);
    }
}
```

```twig
{# app/view/hello.html #}
<h1>Hello, {{ name }}!</h1>
```

## 按需加载

框架仅启动 `config/` 目录下存在配置文件的模块。例如：

- 创建 `config/database.php` → 启用数据库 ORM
- 创建 `config/queue.php` → 启用队列
- 创建 `config/session.php` → 启用会话

无需配置的模块不会被初始化。

## 运行

```bash
# 开发服务器
php lee run --port=8000

# 查看所有命令
php lee list
```

## 运行文档站点

```bash
cd docs
npm install
npm run docs:dev
```
