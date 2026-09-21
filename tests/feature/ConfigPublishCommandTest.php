<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\console\Input;
use Lychee\console\Output;
use PHPUnit\Framework\TestCase;

class ConfigPublishCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lychee_config_publish_' . uniqid();
        mkdir($this->tempDir . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_command_is_registered(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $this->assertTrue($console->has('config:publish'));
    }

    public function test_publish_single_module(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['config:publish', 'cache']), new Output());

        $this->assertSame(0, $code);

        $configPath = $this->tempDir . '/config/cache.php';
        $this->assertFileExists($configPath);

        $content = file_get_contents($configPath);
        $this->assertStringContainsString("'default' => 'file'", $content);
        $this->assertStringContainsString('runtime_path()', $content);
    }

    public function test_publish_all_modules(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['config:publish']), new Output());

        $this->assertSame(0, $code);

        // 所有模块的配置文件都应已生成
        $modules = [
            'app', 'cache', 'captcha', 'cron', 'database', 'filesystem',
            'i18n', 'log', 'plugin', 'queue', 'satoken', 'session', 'view', 'websocket',
        ];

        foreach ($modules as $module) {
            $this->assertFileExists(
                $this->tempDir . "/config/{$module}.php",
                "Config file for module '{$module}' should exist"
            );
        }
    }

    public function test_publish_skips_existing_without_force(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        // 先创建一个已存在的配置文件
        file_put_contents($this->tempDir . '/config/cache.php', '<?php return [];');

        $code = $console->run(new Input(['config:publish', 'cache']), new Output());

        $this->assertSame(0, $code);

        // 文件内容应保持不变（未被覆盖）
        $content = file_get_contents($this->tempDir . '/config/cache.php');
        $this->assertSame('<?php return [];', $content);
    }

    public function test_publish_overwrites_with_force(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        file_put_contents($this->tempDir . '/config/cache.php', '<?php return [];');

        $code = $console->run(new Input(['config:publish', 'cache', '--force']), new Output());

        $this->assertSame(0, $code);

        $content = file_get_contents($this->tempDir . '/config/cache.php');
        $this->assertStringContainsString("'default' => 'file'", $content);
        $this->assertStringNotContainsString('return [];', $content);
    }

    public function test_publish_unknown_module_fails(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['config:publish', 'unknown_module']), new Output());

        $this->assertSame(1, $code);
    }

    public function test_publish_database_uses_mysql_as_default(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['config:publish', 'database']), new Output());

        $this->assertSame(0, $code);

        $content = file_get_contents($this->tempDir . '/config/database.php');
        $this->assertStringContainsString("'default'     => 'mysql'", $content);
        $this->assertStringContainsString("'fields_strict'   => true", $content);
    }

    public function test_publish_app_config(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['config:publish', 'app']), new Output());

        $this->assertSame(0, $code);

        $content = file_get_contents($this->tempDir . '/config/app.php');
        $this->assertStringContainsString("'default_timezone'  => 'Asia/Shanghai'", $content);
        $this->assertStringContainsString("'exception_render'  => 'auto'", $content);
    }

    public function test_publish_satoken_config(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['config:publish', 'satoken']), new Output());

        $this->assertSame(0, $code);

        $content = file_get_contents($this->tempDir . '/config/satoken.php');
        $this->assertStringContainsString("'timeout'         => 86400 * 7", $content);
    }

    private function createApp(): Application
    {
        return new Application(
            basePath: $this->tempDir,
            controllerNamespace: 'App\\controller',
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
