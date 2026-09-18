<?php

declare(strict_types=1);

namespace Tests\stub\plugin;

use Lychee\container\Container;
use Lychee\plugin\PluginInterface;

/**
 * 测试用插件：在 register 阶段绑定一个服务到容器，供后续插件使用。
 */
class ServiceBindingPlugin implements PluginInterface
{
    public function register(Container $container): void
    {
        $container->instance('test.plugin.service', 'bound-by-plugin');
    }

    public function boot(Container $container): void
    {
    }
}
