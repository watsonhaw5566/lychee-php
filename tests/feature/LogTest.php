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
}
