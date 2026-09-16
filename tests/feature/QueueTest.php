<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\queue\connector\Sync as SyncConnector;
use Lychee\queue\QueueManager;
use PHPUnit\Framework\TestCase;
use Tests\stub\app\job\TestJob;
use RuntimeException;

class QueueTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        TestJob::$lastData   = null;
        TestJob::$fireCount  = 0;
        TestJob::$shouldFail = false;
    }

    public function test_queue_manager_bound_to_container(): void
    {
        $this->assertTrue($this->app->container->bound('queue'));
        $this->assertInstanceOf(QueueManager::class, $this->app->container->get('queue'));
    }

    public function test_default_connection_is_sync(): void
    {
        $manager = $this->app->container->get(QueueManager::class);

        $this->assertSame('sync', $manager->getDefaultDriver());
    }

    public function test_sync_connector_executes_job_immediately(): void
    {
        $manager = $this->app->container->get(QueueManager::class);
        $sync    = $manager->connection('sync');

        $this->assertInstanceOf(SyncConnector::class, $sync);

        $sync->push(TestJob::class, ['name' => 'sync-test']);

        $this->assertSame(1, TestJob::$fireCount);
        $this->assertSame(['name' => 'sync-test'], TestJob::$lastData);
    }

    public function test_sync_connector_via_helper(): void
    {
        queue(TestJob::class, ['value' => 123]);

        $this->assertSame(1, TestJob::$fireCount);
        $this->assertSame(['value' => 123], TestJob::$lastData);
    }

    public function test_later_helper_dispatches_delayed_job(): void
    {
        // 链式调用 delay(0) 走 push 路径，任务立即执行
        queue(TestJob::class, ['delayed' => true])->delay(0);

        $this->assertSame(1, TestJob::$fireCount);
        $this->assertSame(['delayed' => true], TestJob::$lastData);
    }

    public function test_queue_helper_returns_pending_dispatch(): void
    {
        $pending = queue(TestJob::class, ['value' => 1]);

        $this->assertInstanceOf(\Lychee\queue\PendingDispatch::class, $pending);
    }

    public function test_delay_method_is_chainable(): void
    {
        $pending = queue(TestJob::class, ['value' => 1]);

        // delay(0) 避免析构时触发 Sync 的 sleep
        $this->assertSame($pending, $pending->delay(0));
    }

    public function test_sync_connector_later_executes_job(): void
    {
        $sync = $this->app->container->get(QueueManager::class)->connection('sync');

        // delay 为 0 时不 sleep，直接执行
        $sync->later(0, TestJob::class, ['via' => 'later']);

        $this->assertSame(1, TestJob::$fireCount);
        $this->assertSame(['via' => 'later'], TestJob::$lastData);
    }

    public function test_queue_helper_returns_connector_when_no_job(): void
    {
        $connector = queue();

        $this->assertInstanceOf(SyncConnector::class, $connector);
    }

    public function test_sync_connector_size_is_zero(): void
    {
        $this->assertSame(0, queue()->size());
    }

    public function test_sync_connector_pop_returns_null(): void
    {
        $this->assertNull(queue()->pop());
    }

    public function test_job_with_custom_method(): void
    {
        $manager = $this->app->container->get(QueueManager::class);
        $sync    = $manager->connection('sync');

        // 使用 Class@method 语法
        $sync->push(TestJob::class . '@fire', ['method' => 'fire']);

        $this->assertSame(1, TestJob::$fireCount);
    }

    public function test_failed_sync_job_throws(): void
    {
        TestJob::$shouldFail = true;

        $this->expectException(RuntimeException::class);

        queue(TestJob::class, ['fail' => true]);
    }

    public function test_queue_work_command_registered(): void
    {
        /** @var \Lychee\console\Application $console */
        $console = $this->app->container->get(\Lychee\console\Application::class);

        $this->assertTrue($console->has('queue:work'));
    }
}
