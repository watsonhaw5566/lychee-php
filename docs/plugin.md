# 插件 Plugin

框架提供了轻量的插件系统，允许通过 composer 安装扩展包来增强应用功能，而无需修改框架核心代码。插件是实现 `Lychee\plugin\PluginInterface` 的入口类，在应用启动时由框架自动发现并加载。

> 插件系统的定位是**框架的扩展能力**，本身不绑定任何具体业务。具体的功能插件（如 lychee-admin 后台管理）作为独立 composer 包发布，按需安装。

## 工作原理

插件的生命周期分为两个阶段，由 `Lychee\plugin\PluginManager` 统一管理：

| 阶段 | 方法 | 时机 | 可做的事 |
| --- | --- | --- | --- |
| 注册 | `register()` | 核心服务绑定之后、可选模块启动之后 | 绑定容器服务、合并默认配置 |
| 启动 | `boot()` | 所有核心模块（路由、视图、控制台等）就绪之后 | 注册路由、视图命名空间、命令、中间件 |

执行顺序：

```
Application 构造
  → loadEnvironment()
  → registerBindings()      // 容器、路由、请求、控制台等核心服务
  → bootConfig()            // 加载 config/*.php
  → bootOptionalModules()   // 按需启动 log/database/cache/view 等模块
  → bootPlugins()           // 插件 register() + boot()
  → run()                   // 处理请求
```

> `register()` 阶段不应依赖其他插件的服务，因为此时其他插件可能尚未注册。插件间的依赖应在 `boot()` 阶段处理。

## 创建插件

每个插件包提供一个入口类（通常命名为 `XxxServiceProvider`），实现 `PluginInterface`：

```php
// src/MyPluginServiceProvider.php
namespace MyPlugin;

use Lychee\container\Container;
use Lychee\plugin\PluginInterface;

class MyPluginServiceProvider implements PluginInterface
{
    public function register(Container $container): void
    {
        // 绑定服务到容器
        $container->singleton(MyService::class, fn () => new MyService());
    }

    public function boot(Container $container): void
    {
        // 注册路由、视图、命令等
    }
}
```

## 注册插件

在应用的 `config/plugin.php` 中声明需要启动的插件：

```php
// config/plugin.php
return [
    'providers' => [
        \MyPlugin\MyPluginServiceProvider::class,
        src\AdminServiceProvider::class,
        // ... 其他插件
    ],
];
```

框架会按数组顺序依次调用每个插件的 `register()`，全部完成后再依次调用 `boot()`。

> 未在 `providers` 中声明的插件不会被加载。插件的启用/禁用完全由该配置控制。

## 插件能做什么

在 `boot()` 方法中，插件可以向框架注入各种功能。以下是常用的注入方式：

### 注册路由

```php
use Lychee\routing\Router;

public function boot(Container $container): void
{
    $router = $container->get(Router::class);
    $router->registerDirectory(
        __DIR__ . '/controller',   // 插件的控制器目录
        'MyPlugin\\controller'     // 对应的命名空间
    );
}
```

### 注册视图命名空间

```php
use Lychee\view\View;

public function boot(Container $container): void
{
    $view = $container->get(View::class);
    // 以 'myplugin' 命名空间注册模板目录，模板中可通过 @myplugin/xxx 引用
    $view->getTwig()->getLoader()->addPath(__DIR__ . '/view', 'myplugin');
}
```

### 注册控制台命令

```php
use Lychee\console\Application as ConsoleApplication;

public function boot(Container $container): void
{
    $console = $container->get(ConsoleApplication::class);
    $console->addCommand(\MyPlugin\command\MyCommand::class);
}
```

### 注册全局中间件

```php
public function boot(Container $container): void
{
    $config = $container->get('config');
    $existing = (array) $config->get('middleware', []);
    $existing[] = \MyPlugin\middleware\MyMiddleware::class;
    $config->set(['middleware' => $existing]);
}
```

### 合并默认配置

```php
public function register(Container $container): void
{
    $config = $container->get('config');
    // 仅当用户未配置时加载插件的默认配置
    if (!$config->has('myplugin')) {
        $config->load(__DIR__ . '/config/myplugin.php', 'myplugin');
    }
}
```

### 绑定容器服务

```php
public function register(Container $container): void
{
    $container->singleton(MyService::class, function (Container $c) {
        return new MyService($c->get('config'));
    });
}
```

## 完整示例

以下是一个最小可用的插件包结构：

```
my-plugin/
├── composer.json
├── src/
│   ├── MyPluginServiceProvider.php   # 插件入口
│   ├── controller/
│   │   └── HelloController.php       # 插件控制器
│   ├── view/
│   │   └── hello.twig                # 插件模板
│   └── config/
│       └── myplugin.php              # 默认配置
```

`MyPluginServiceProvider.php`：

```php
<?php

declare(strict_types=1);

namespace MyPlugin;

use Lychee\container\Container;
use Lychee\plugin\PluginInterface;
use Lychee\routing\Router;
use Lychee\view\View;

class MyPluginServiceProvider implements PluginInterface
{
    public function register(Container $container): void
    {
        $config = $container->get('config');
        if (!$config->has('myplugin')) {
            $config->load(__DIR__ . '/config/myplugin.php', 'myplugin');
        }
    }

    public function boot(Container $container): void
    {
        $container->get(Router::class)->registerDirectory(
            __DIR__ . '/controller',
            'MyPlugin\\controller'
        );

        $container->get(View::class)->getTwig()->getLoader()
            ->addPath(__DIR__ . '/view', 'myplugin');
    }
}
```

`controller/HelloController.php`：

```php
<?php

declare(strict_types=1);

namespace MyPlugin\controller;

use Lychee\http\Controller;
use Lychee\routing\Route;

class HelloController extends Controller
{
    #[Route('GET', '/hello')]
    public function index(): string
    {
        return view('@myplugin/hello', ['name' => 'Lychee']);
    }
}
```

在应用中启用：

```php
// config/plugin.php
return [
    'providers' => [
        \MyPlugin\MyPluginServiceProvider::class,
    ],
];
```

访问 `/hello` 即可看到插件渲染的页面。

## 插件管理 API

通过容器获取 `PluginManager` 实例，可在运行时查询已注册的插件：

```php
use Lychee\plugin\PluginManager;

$manager = app(PluginManager::class);
// 或 $manager = app('plugins');

$manager->has(MyPluginServiceProvider::class);   // 是否已注册
$manager->get(MyPluginServiceProvider::class);   // 获取插件实例
$manager->getPlugins();                           // 获取所有已注册插件
```

## 注意事项

1. **插件顺序**：`providers` 数组中的顺序决定了插件的启动顺序。如果插件 B 依赖插件 A 在 `register()` 阶段绑定的服务，应将 A 放在 B 之前。

2. **register 与 boot 的职责**：`register()` 只做容器绑定和配置合并，不触发路由/视图等需要核心服务就绪的操作；`boot()` 才做功能注入。

3. **幂等性**：同一个插件类重复注册会被忽略（`register()` 只执行一次），`boot()` 也只会执行一次。

4. **静态资源**：插件的前端静态资源（CSS/JS/图片）不通过框架处理。开发环境可通过 `plugin:publish` 命令发布到项目 `public/` 目录，生产环境由 Nginx/Apache 直接 serve。
