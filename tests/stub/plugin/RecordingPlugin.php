<?php

declare(strict_types=1);

namespace Tests\stub\plugin;

use Lychee\container\Container;
use Lychee\plugin\PluginInterface;

/**
 * 测试用插件：记录 register/boot 调用顺序与次数。
 */
class RecordingPlugin implements PluginInterface
{
    /** @var array<int, string> 调用记录，值为 'register' 或 'boot' */
    public static array $calls = [];

    public function register(Container $container): void
    {
        self::$calls[] = 'register';
    }

    public function boot(Container $container): void
    {
        self::$calls[] = 'boot';
    }

    public static function reset(): void
    {
        self::$calls = [];
    }
}
