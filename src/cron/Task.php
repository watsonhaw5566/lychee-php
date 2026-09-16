<?php

declare(strict_types=1);

namespace Lychee\cron;

use Closure;
use DateTimeInterface;

/**
 * 定时任务定义。
 *
 * 封装任务名称、cron 表达式和执行回调，供 Scheduler 调度。
 */
class Task
{
    private readonly CronExpression $expression;

    public function __construct(
        private readonly string $name,
        string $cron,
        private readonly Closure $callback,
        private readonly string $description = '',
    ) {
        $this->expression = new CronExpression($cron);
    }

    /**
     * 获取任务名称。
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取任务描述。
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * 获取 cron 表达式。
     */
    public function getCronExpression(): string
    {
        return $this->expression->getExpression();
    }

    /**
     * 判断任务在给定时间是否应该执行。
     */
    public function isDue(DateTimeInterface $time): bool
    {
        return $this->expression->isDue($time);
    }

    /**
     * 执行任务回调。
     *
     * @return mixed 回调返回值
     */
    public function run(): mixed
    {
        return ($this->callback)();
    }
}
