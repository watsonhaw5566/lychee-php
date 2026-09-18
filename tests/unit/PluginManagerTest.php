<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\Application;
use Lychee\container\Container;
use Lychee\plugin\PluginManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\stub\plugin\RecordingPlugin;
use Tests\stub\plugin\ServiceBindingPlugin;

class PluginManagerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        RecordingPlugin::reset();
    }

    public function test_register_calls_plugin_register_method(): void
    {
        $manager = new PluginManager($this->container);

        $manager->register(RecordingPlugin::class);

        $this->assertSame(['register'], RecordingPlugin::$calls);
        $this->assertTrue($manager->has(RecordingPlugin::class));
    }

    public function test_boot_calls_plugin_boot_method(): void
    {
        $manager = new PluginManager($this->container);

        $manager->register(RecordingPlugin::class);
        $manager->boot();

        $this->assertSame(['register', 'boot'], RecordingPlugin::$calls);
    }

    public function test_duplicate_register_is_ignored(): void
    {
        $manager = new PluginManager($this->container);

        $manager->register(RecordingPlugin::class);
        $manager->register(RecordingPlugin::class);

        // register 只执行一次
        $this->assertSame(['register'], RecordingPlugin::$calls);
    }

    public function test_duplicate_boot_is_ignored(): void
    {
        $manager = new PluginManager($this->container);

        $manager->register(RecordingPlugin::class);
        $manager->boot();
        $manager->boot();

        // boot 只执行一次
        $this->assertSame(['register', 'boot'], RecordingPlugin::$calls);
    }

    public function test_register_non_interface_class_throws(): void
    {
        $manager = new PluginManager($this->container);

        $this->expectException(RuntimeException::class);
        $manager->register(\stdClass::class);
    }

    public function test_plugins_booted_in_registration_order(): void
    {
        $manager = new PluginManager($this->container);

        $manager->register(RecordingPlugin::class);
        $manager->register(ServiceBindingPlugin::class);
        $manager->boot();

        // RecordingPlugin 的 register 和 boot 都应先于 ServiceBindingPlugin 执行
        $this->assertSame(['register', 'boot'], RecordingPlugin::$calls);
    }

    public function test_service_binding_available_after_register(): void
    {
        $manager = new PluginManager($this->container);

        $manager->register(ServiceBindingPlugin::class);

        $this->assertSame('bound-by-plugin', $this->container->get('test.plugin.service'));
    }

    public function test_get_returns_plugin_instance(): void
    {
        $manager = new PluginManager($this->container);

        $manager->register(RecordingPlugin::class);

        $this->assertInstanceOf(RecordingPlugin::class, $manager->get(RecordingPlugin::class));
    }

    public function test_get_returns_null_for_unregistered_plugin(): void
    {
        $manager = new PluginManager($this->container);

        $this->assertNull($manager->get(RecordingPlugin::class));
    }

    public function test_get_plugins_returns_all_registered(): void
    {
        $manager = new PluginManager($this->container);

        $manager->register(RecordingPlugin::class);
        $manager->register(ServiceBindingPlugin::class);

        $plugins = $manager->getPlugins();

        $this->assertCount(2, $plugins);
        $this->assertArrayHasKey(RecordingPlugin::class, $plugins);
        $this->assertArrayHasKey(ServiceBindingPlugin::class, $plugins);
    }

    public function test_application_loads_plugins_from_config(): void
    {
        $app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $manager = $app->container->get(PluginManager::class);

        $this->assertTrue($manager->has(RecordingPlugin::class));
        // boot 已由 Application 自动调用
        $this->assertContains('boot', RecordingPlugin::$calls);
    }

    public function test_application_binds_plugin_manager_to_container(): void
    {
        $app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->assertInstanceOf(
            PluginManager::class,
            $app->container->get(PluginManager::class)
        );
        $this->assertInstanceOf(
            PluginManager::class,
            $app->container->get('plugins')
        );
    }
}
