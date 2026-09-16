<?php

declare(strict_types=1);

namespace Lychee\cron;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use think\Container;

/**
 * 定时任务调度器。
 *
 * 管理一组 Task，按 cron 表达式判断到期并执行。
 * 支持通过 call() 注册闭包任务，或通过 command() 注册命令类任务。
 */
class Scheduler
{
    /** @var array<string, Task> */
    private array $tasks = [];

    public function __construct(private readonly Container $app)
    {
    }

    /**
     * 注册一个闭包定时任务。
     *
     * @param  string   $name        任务名称
     * @param  string   $cron        cron 表达式
     * @param  Closure  $callback    任务回调
     * @param  string   $description 任务描述
     * @return $this
     */
    public function call(string $name, string $cron, Closure $callback, string $description = ''): static
    {
        $this->tasks[$name] = new Task($name, $cron, $callback, $description);

        return $this;
    }

    /**
     * 注册一个命令类定时任务。
     *
     * 命令类需实现 public function handle(): void 方法，依赖由容器注入。
     *
     * @param  string               $name        任务名称
     * @param  string               $cron        cron 表达式
     * @param  class-string|object  $command     命令类名或实例
     * @param  string               $description 任务描述
     * @return $this
     */
    public function command(string $name, string $cron, string|object $command, string $description = ''): static
    {
        $callback = function () use ($command): mixed {
            if (is_string($command)) {
                $command = $this->app->make($command);
            }

            if (method_exists($command, 'handle')) {
                return $this->app->invoke([$command, 'handle']);
            }

            if (is_callable($command)) {
                return $command();
            }

            return null;
        };

        $this->tasks[$name] = new Task($name, $cron, $callback, $description);

        return $this;
    }

    /**
     * 从配置加载任务。
     *
     * 配置格式：
     *   'tasks' => [
     *       ['name' => 'foo', 'cron' => '* * * * *', 'command' => 'App\\cron\\Foo', 'description' => '...'],
     *   ]
     *
     * @param array<int, array{name: string, cron: string, command?: class-string, description?: string}> $tasks
     */
    public function load(array $tasks): static
    {
        foreach ($tasks as $task) {
            if (!isset($task['name'], $task['cron'])) {
                continue;
            }

            if (isset($task['command'])) {
                $this->command($task['name'], $task['cron'], $task['command'], $task['description'] ?? '');
            }
        }

        return $this;
    }

    /**
     * 获取所有已注册的任务。
     *
     * @return array<string, Task>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * 获取指定时间到期的任务。
     *
     * @return array<string, Task>
     */
    public function getDueTasks(?DateTimeInterface $time = null): array
    {
        $time ??= new DateTimeImmutable();

        return array_filter(
            $this->tasks,
            static fn (Task $task): bool => $task->isDue($time)
        );
    }

    /**
     * 执行所有到期的任务。
     *
     * @param  DateTimeInterface|null $time 参考时间，默认为当前时间
     * @return array<string, mixed>           各任务名称及其返回值
     */
    public function run(?DateTimeInterface $time = null): array
    {
        $time ??= new DateTimeImmutable();
        $results = [];

        foreach ($this->getDueTasks($time) as $name => $task) {
            $results[$name] = $task->run();
        }

        return $results;
    }
}
