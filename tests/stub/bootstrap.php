<?php

declare(strict_types=1);

/**
 * 应用引导文件。
 *
 * 加载 composer 自动加载器，并定义示例应用的根目录常量。
 * 由 phpunit 作为测试引导加载，也可供入口脚本复用。
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

define('STUB_DIR', __DIR__);
