<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\log\LogManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class LogTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        // 清理测试日志文件
        $logDir = STUB_DIR . '/runtime/log';
        if (is_dir($logDir)) {
            foreach (glob($logDir . '/*.log') as $file) {
                @unlink($file);
            }
        }
    }

    public function test_log_manager_bound_to_container(): void
    {
        $this->assertTrue($this->app->container->bound('log'));
        $this->assertInstanceOf(LogManager::class, $this->app->container->get('log'));
    }

    public function test_psr3_logger_interface_bound(): void
    {
        $logger = $this->app->container->get(LoggerInterface::class);

        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function test_logger_writes_to_file(): void
    {
        $logger = $this->app->container->get('log')->channel();
        $logger->info('test info message', ['user' => 123]);

        $logFile = STUB_DIR . '/runtime/log/' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('INFO', $content);
        $this->assertStringContainsString('test info message', $content);
        $this->assertStringContainsString('"user":123', $content);
    }

    public function test_logger_supports_all_levels(): void
    {
        $logger = $this->app->container->get('log')->channel();

        $logger->emergency('emergency msg');
        $logger->alert('alert msg');
        $logger->critical('critical msg');
        $logger->error('error msg');
        $logger->warning('warning msg');
        $logger->notice('notice msg');
        $logger->info('info msg');
        $logger->debug('debug msg');

        $logFile = STUB_DIR . '/runtime/log/' . date('Y-m-d') . '.log';
        $content = file_get_contents($logFile);

        foreach (['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'] as $level) {
            $this->assertStringContainsString($level, $content);
        }
    }

    public function test_logger_filters_by_level(): void
    {
        // 配置 level 为 warning，低于 warning 的日志不应被记录
        $manager = $this->app->container->get(LogManager::class);

        // 临时设置一个 warning 级别的频道
        $reflection  = new ReflectionClass($manager);
        $loggersProp = $reflection->getProperty('loggers');
        $loggersProp->setAccessible(true);

        // 直接测试 Logger 类的级别过滤
        $logger = new \Lychee\log\Logger($manager, 'file', 'warning');
        $logger->info('should not appear');
        $logger->warning('should appear');

        $logFile = STUB_DIR . '/runtime/log/' . date('Y-m-d') . '.log';
        $content = file_get_contents($logFile);

        $this->assertStringNotContainsString('should not appear', $content);
        $this->assertStringContainsString('should appear', $content);
    }

    public function test_logger_helper_function(): void
    {
        logger()->info('helper info message', ['key' => 'value']);

        $logFile = STUB_DIR . '/runtime/log/' . date('Y-m-d') . '.log';
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('helper info message', $content);
        $this->assertStringContainsString('"key":"value"', $content);
    }

    public function test_logger_helper_with_channel(): void
    {
        logger('file')->warning('channel warning message');

        $logFile = STUB_DIR . '/runtime/log/' . date('Y-m-d') . '.log';
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('channel warning message', $content);
    }

    public function test_logger_helper_returns_instance(): void
    {
        $logger = logger();

        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function test_context_interpolation(): void
    {
        $logger = $this->app->container->get('log')->channel();
        $logger->info('User {user} logged in from {ip}', ['user' => 42, 'ip' => '127.0.0.1']);

        $logFile = STUB_DIR . '/runtime/log/' . date('Y-m-d') . '.log';
        $content = file_get_contents($logFile);

        $this->assertStringContainsString('User 42 logged in from 127.0.0.1', $content);
    }

    public function test_max_files_cleans_up_old_logs(): void
    {
        $logDir = sys_get_temp_dir() . '/lychee-test-maxfiles';

        // 清理可能残留的测试目录
        if (is_dir($logDir)) {
            array_map('unlink', glob($logDir . '/*.log'));
        } else {
            mkdir($logDir, 0755, true);
        }

        // 手动创建 5 个旧日志文件（模拟不同日期）
        for ($i = 0; $i < 5; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            file_put_contents($logDir . '/' . $date . '.log', "log content {$i}\n");
        }

        // 使用 max_files=2 的 File 驱动写入一条日志
        $driver = new \Lychee\log\driver\File(['path' => $logDir, 'max_files' => 2]);
        $driver->write("new log\n");

        // 现在目录下应该只有 2 个日志文件（最近 2 天）
        $remaining = glob($logDir . '/*.log');
        $this->assertCount(2, $remaining);

        // 最新的两个文件应保留
        $dates = array_map(fn($f) => basename($f, '.log'), $remaining);
        sort($dates);
        $this->assertEquals(date('Y-m-d'), end($dates));

        // 清理测试目录
        array_map('unlink', glob($logDir . '/*.log'));
        rmdir($logDir);
    }

    public function test_max_files_zero_keeps_all_files(): void
    {
        $logDir = sys_get_temp_dir() . '/lychee-test-nolimit';

        if (is_dir($logDir)) {
            array_map('unlink', glob($logDir . '/*.log'));
        } else {
            mkdir($logDir, 0755, true);
        }

        // 创建 3 个旧日志文件
        for ($i = 0; $i < 3; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            file_put_contents($logDir . '/' . $date . '.log', "log {$i}\n");
        }

        // max_files=0 表示不限制
        $driver = new \Lychee\log\driver\File(['path' => $logDir, 'max_files' => 0]);
        $driver->write("new log\n");

        // 所有文件都应保留
        $remaining = glob($logDir . '/*.log');
        $this->assertCount(3, $remaining);

        // 清理
        array_map('unlink', glob($logDir . '/*.log'));
        rmdir($logDir);
    }
}
