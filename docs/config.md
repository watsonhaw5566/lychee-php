# 配置 Config

从 `config/` 目录加载 PHP 配置文件，支持点号分隔的多级读取。

## 配置文件

```php
// config/database.php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'hostname' => '127.0.0.1',
            'database' => 'app',
        ],
    ],
];
```

## 读取配置

```php
use Lychee\config\Config;

$config = app('config');

$value = $config->get('database.default');           // 'mysql'
$value = $config->get('database.connections.mysql');  // array
$value = $config->get('database.undefined', '默认值');

// 辅助函数
config('database.default');
config('app.debug', false);
```

## 判断配置是否存在

```php
$config->has('database');  // true
$config->has('redis');     // false
```

## 按需加载

框架仅启动 `config/` 下存在配置文件的模块。创建配置文件即启用对应模块。
