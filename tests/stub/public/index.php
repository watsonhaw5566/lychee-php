<?php

declare(strict_types=1);

/**
 * 示例应用 Web 入口。
 *
 * 作为 PHP 内置开发服务器的路由脚本，同时也适用于 Apache/Nginx。
 */

if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

// 示例应用位于框架 tests/stub，vendor 在框架根目录
$basePath = dirname(__DIR__);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lychee\Application;

$app = new Application(
    basePath: $basePath,
    controllerNamespace: 'Tests\\stub\\app\\controller',
);
$app->run();
