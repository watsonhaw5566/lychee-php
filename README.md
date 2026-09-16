# Lychee PHP

轻量级、IDE 友好的 PHP Web 框架，PHP ^8.2。

## 特性

- 基于 PSR-11 的轻量容器
- 注解路由 + 中间件管道
- 基于 Twig 的模板引擎
- 数据库迁移与数据填充
- 队列、定时任务、会话、认证、文件系统
- 按需加载：仅启动已配置的模块

## 环境要求

- PHP >= 8.2
- Composer

## 安装

```bash
composer require watsonhaw/lychee-php
```

## 快速开始

```php
// public/index.php
require __DIR__ . '/../vendor/autoload.php';

use Lychee\Application;

$app = new Application(
    basePath: dirname(__DIR__),
    controllerNamespace: 'App\\controller',
);
$app->run();
```

## 目录结构

```
your-app/
├── app/
│   ├── controller/    # 控制器
│   ├── model/         # 模型
│   ├── middleware/    # 中间件
│   └── view/          # Twig 模板
├── config/            # 配置文件（按需创建）
├── database/
│   ├── migrations/    # 迁移文件
│   └── seeders/       # 数据填充
├── public/
│   └── index.php      # Web 入口
├── runtime/           # 运行时缓存、日志
└── lee                # 命令行入口
```

## 命令行

```bash
php lee list              # 查看所有命令
php lee migrate:run       # 执行迁移
php lee migrate:rollback  # 回滚迁移
php lee seed:run          # 数据填充
php lee cron:run          # 执行定时任务
php lee queue:work        # 队列消费
```

## 按需加载

框架仅启动 `config/` 目录下存在配置文件的模块。例如创建 `config/queue.php` 即启用队列模块。

## 文档

完整文档请访问 [lychee-php](https://lychee-php.watsonhaw.top)