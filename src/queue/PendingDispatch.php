<?php

declare(strict_types=1);

namespace Lychee\queue;

/**
 * 待分发任务。
 *
 * 提供链式 delay() 配置延迟秒数，对象销毁时自动分发到队列。
 *
 * 用法：
 *   queue(SendEmail::class, $data);              // 立即推送
 *   queue(SendEmail::class, $data)->delay(60);   // 延迟 60 秒推送
 */
class PendingDispatch
{
    protected int $delay = 0;

    protected bool $dispatched = false;

    public function __construct(
        protected Connector $connector,
        protected object|string $job,
        protected mixed $data = '',
        protected ?string $queue = null,
    ) {
    }

    /**
     * 设置延迟秒数。
     */
    public function delay(int $seconds): static
    {
        $this->delay = $seconds;

        return $this;
    }

    /**
     * 分发任务到队列。
     *
     * @return mixed 任务 ID（部分驱动返回 null）
     */
    public function dispatch(): mixed
    {
        if ($this->dispatched) {
            return null;
        }

        $this->dispatched = true;

        if ($this->delay > 0) {
            return $this->connector->later($this->delay, $this->job, $this->data, $this->queue);
        }

        return $this->connector->push($this->job, $this->data, $this->queue);
    }

    /**
     * 析构时自动分发，保证 queue() 调用后无需显式 dispatch()。
     */
    public function __destruct()
    {
        $this->dispatch();
    }
}
