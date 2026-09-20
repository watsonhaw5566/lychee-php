<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\cron\Scheduler;
use PHPUnit\Framework\TestCase;

class CronTest extends TestCase
{
    private Application $app;
    private Scheduler $scheduler;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->scheduler = $this->app->container->get(Scheduler::class);
    }

    public function test_scheduler_bound_to_container(): void
    {
        $this->assertTrue($this->app->container->bound('scheduler'));
        $this->assertInstanceOf(Scheduler::class, $this->app->container->get('scheduler'));
    }

    public function test_call_registers_task(): void
    {
        $this->scheduler->call('test-task', '* * * * *', fn () => 'done', 'Test task');

        $tasks = $this->scheduler->getTasks();
        $this->assertArrayHasKey('test-task', $tasks);
        $this->assertSame('Test task', $tasks['test-task']->getDescription());
        $this->assertSame('* * * * *', $tasks['test-task']->getCronExpression());
    }

    public function test_run_executes_due_tasks(): void
    {
        $executed = false;
        $this->scheduler->call('due-task', '* * * * *', function () use (&$executed) {
            $executed = true;

            return 'result';
        });

        $results = $this->scheduler->run();

        $this->assertTrue($executed);
        $this->assertArrayHasKey('due-task', $results);
        $this->assertSame('result', $results['due-task']);
    }

    public function test_run_skips_non_due_tasks(): void
    {
        $executed = false;
        // 2 月 31 日在日历上不存在，因此该表达式永不匹配，任务不会被执行
        $this->scheduler->call('future-task', '0 0 31 2 *', function () use (&$executed) {
            $executed = true;
        });

        $this->scheduler->run();

        $this->assertFalse($executed);
    }

    public function test_get_due_tasks_filters_correctly(): void
    {
        $this->scheduler->call('due', '* * * * *', fn () => null);
        // 2 月 31 日不存在，永不到期
        $this->scheduler->call('not-due', '0 0 31 2 *', fn () => null);

        $due = $this->scheduler->getDueTasks();

        $this->assertArrayHasKey('due', $due);
        $this->assertArrayNotHasKey('not-due', $due);
    }

    public function test_command_task_with_handle_method(): void
    {
        $command = new class () {
            public bool $handled = false;

            public function handle(): void
            {
                $this->handled = true;
            }
        };

        $this->scheduler->command('cmd-task', '* * * * *', $command);
        $this->scheduler->run();

        $this->assertTrue($command->handled);
    }

    public function test_load_tasks_from_config_array(): void
    {
        $command = new class () {
            public bool $handled = false;

            public function handle(): void
            {
                $this->handled = true;
            }
        };

        // 绑定命令类到容器，模拟配置加载
        $this->app->container->instance('test.cron.command', $command);

        $this->scheduler->load([
            [
                'name'        => 'loaded-task',
                'cron'        => '* * * * *',
                'command'     => 'test.cron.command',
                'description' => 'Loaded from config',
            ],
        ]);

        $this->assertArrayHasKey('loaded-task', $this->scheduler->getTasks());

        $this->scheduler->run();
        $this->assertTrue($command->handled);
    }

    public function test_cron_run_command_registered(): void
    {
        /** @var \Lychee\console\Application $console */
        $console = $this->app->container->get(\Lychee\console\Application::class);

        $this->assertTrue($console->has('cron:run'));
    }
}
