<?php

declare(strict_types=1);

namespace Lychee\plugin;

use Lychee\container\Container;

/**
 * 插件接口。
 *
 * 每个插件包提供一个实现本接口的入口类（通常命名为 XxxServiceProvider），
 * 由框架在启动时自动发现并调用其生命周期方法。
 *
 * 生命周期分两阶段：
 *   - register(): 绑定容器服务、合并默认配置。此阶段不应依赖其他插件的服务。
 *   - boot():     注册路由、视图、命令、中间件等。此阶段所有框架核心服务均已就绪。
 */
interface PluginInterface
{
    /**
     * 注册阶段：绑定服务到容器、合并配置。
     *
     * 仅做容器绑定与配置合并，不触发路由/视图等需要核心服务就绪的操作。
     */
    public function register(Container $container): void;

    /**
     * 启动阶段：注册路由、视图命名空间、控制台命令、中间件等。
     *
     * 此时框架核心服务（Router、View、Console、Config 等）均已就绪，
     * 可安全地向框架注入插件的功能。
     */
    public function boot(Container $container): void;
}
