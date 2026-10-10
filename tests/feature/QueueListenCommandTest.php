<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\console\Application as ConsoleApplication;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\queue\command\ListenCommand;
use Lychee\queue\command\WorkCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class QueueListenCommandTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );
    }

    private function console(): ConsoleApplication
    {
        return $this->app->container->get(ConsoleApplication::class);
    }

    public function test_listen_command_registered(): void
    {
        $this->assertTrue($this->console()->has('queue:listen'));
    }

    public function test_work_once_returns_empty_exit_code_when_queue_is_empty(): void
    {
        $command = $this->console()->getCommand('queue:work');

        $code = $command->run(
            new Input(['--once', '--connection=sync']),
            new Output(Output::VERBOSITY_QUIET)
        );

        $this->assertSame(WorkCommand::EXIT_EMPTY, $code);
    }

    public function test_build_command_targets_fresh_once_worker(): void
    {
        /** @var ListenCommand $command */
        $command = $this->console()->getCommand('queue:listen');

        $method = new ReflectionMethod($command, 'buildCommand');
        $method->setAccessible(true);

        /** @var list<string> $withQueue */
        $withQueue = $method->invoke($command, 'redis', 'emails', 3);

        $this->assertSame(PHP_BINARY, $withQueue[0]);
        $this->assertFileExists($withQueue[1]);
        $this->assertSame('queue:work', $withQueue[2]);
        $this->assertContains('--once', $withQueue);
        $this->assertContains('--connection=redis', $withQueue);
        $this->assertContains('--tries=3', $withQueue);
        $this->assertContains('--queue=emails', $withQueue);

        /** @var list<string> $withoutQueue */
        $withoutQueue = $method->invoke($command, 'redis', null, 1);

        $this->assertEmpty(array_filter(
            $withoutQueue,
            static fn (string $token): bool => str_starts_with($token, '--queue=')
        ));
    }

    public function test_listener_spawns_worker_subprocess(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled in this PHP environment.');
        }

        /** @var ListenCommand $command */
        $command = $this->console()->getCommand('queue:listen');

        $method = new ReflectionMethod($command, 'runWorker');
        $method->setAccessible(true);

        /** @var array{code: int, timeout: bool} $result */
        $result = $method->invoke($command, [
            PHP_BINARY,
            STUB_DIR . '/lee',
            'queue:work',
            '--once',
            '--connection=sync',
        ], 0);

        $this->assertSame(WorkCommand::EXIT_EMPTY, $result['code']);
        $this->assertFalse($result['timeout']);
    }

    public function test_listen_loop_stops_after_stop_flag(): void
    {
        $command = new ControlledListenCommand();
        $command->setApp($this->app->container);
        $command->callConfigure();

        $code = $command->run(
            new Input(['--sleep=0']),
            new Output(Output::VERBOSITY_QUIET)
        );

        $this->assertSame(0, $code);
        $this->assertSame(2, $command->cycles);
    }
}

/**
 * 受控监听命令：只跑两个轮次，且不真正拉起子进程。
 */
class ControlledListenCommand extends ListenCommand
{
    public int $cycles = 0;

    protected function shouldContinue(): bool
    {
        return $this->cycles < 2;
    }

    protected function runWorker(array $command, int $timeout): array
    {
        $this->cycles++;

        return ['code' => WorkCommand::EXIT_EMPTY, 'timeout' => false];
    }

    protected function entryScript(): string
    {
        return STUB_DIR . '/lee';
    }
}
