<?php

declare(strict_types=1);

namespace Lychee\plugin;

use Lychee\container\Container;
use RuntimeException;

/**
 * 插件管理器。
 *
 * 负责插件的注册与启动生命周期管理：
 *   1. register(): 实例化插件入口类并调用其 register() 方法（绑定服务、合并配置）
 *   2. boot():     依次调用所有已注册插件的 boot() 方法（注册路由、视图、命令等）
 *
 * 插件按注册顺序启动，后注册的插件可依赖先注册插件在 register 阶段绑定的服务。
 */
class PluginManager
{
    /** @var array<class-string<PluginInterface>, PluginInterface> 已注册的插件实例 */
    protected array $plugins = [];

    /** @var array<class-string<PluginInterface>, true> 已完成 boot 的插件 */
    protected array $booted = [];

    public function __construct(
        protected readonly Container $container,
    ) {
    }

    /**
     * 注册一个插件。
     *
     * 实例化插件入口类并立即调用其 register() 方法。
     * 同一个插件类重复注册会被忽略（不会重复执行 register）。
     *
     * @param class-string<PluginInterface> $pluginClass 插件入口类名
     */
    public function register(string $pluginClass): void
    {
        if (isset($this->plugins[$pluginClass])) {
            return;
        }

        if (!is_subclass_of($pluginClass, PluginInterface::class)) {
            throw new RuntimeException(
                "插件 [{$pluginClass}] 必须实现 " . PluginInterface::class . ' 接口'
            );
        }

        /** @var PluginInterface $plugin */
        $plugin = $this->container->make($pluginClass);
        $plugin->register($this->container);

        $this->plugins[$pluginClass] = $plugin;
    }

    /**
     * 启动所有已注册的插件。
     *
     * 按注册顺序依次调用每个插件的 boot() 方法。
     * 已 boot 过的插件不会重复执行。
     */
    public function boot(): void
    {
        foreach ($this->plugins as $class => $plugin) {
            if (isset($this->booted[$class])) {
                continue;
            }

            $plugin->boot($this->container);
            $this->booted[$class] = true;
        }
    }

    /**
     * 获取所有已注册的插件实例。
     *
     * @return array<class-string<PluginInterface>, PluginInterface>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * 判断指定插件是否已注册。
     *
     * @param class-string<PluginInterface> $pluginClass
     */
    public function has(string $pluginClass): bool
    {
        return isset($this->plugins[$pluginClass]);
    }

    /**
     * 获取指定插件实例。
     *
     * @param class-string<PluginInterface> $pluginClass
     */
    public function get(string $pluginClass): ?PluginInterface
    {
        return $this->plugins[$pluginClass] ?? null;
    }
}
