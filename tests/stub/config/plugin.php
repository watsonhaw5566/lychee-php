<?php

declare(strict_types=1);

/**
 * 插件配置。
 *
 * providers 数组声明需要启动的插件入口类（需实现 PluginInterface）。
 * 按数组顺序依次注册并启动。
 */
return [
    'providers' => [
        \Tests\stub\plugin\RecordingPlugin::class,
        \Tests\stub\plugin\ServiceBindingPlugin::class,
    ],
];
