<?php

declare(strict_types=1);

namespace Lychee\queue;

use Lychee\container\Container;

/**
 * 队列连接器抽象基类。
 *
 * 定义 push / later / pop 等核心操作，具体驱动（sync / redis）实现存储细节。
 */
abstract class Connector
{
    protected Container $app;

    protected string $connection = '';

    public function setApp(Container $app): static
    {
        $this->app = $app;

        return $this;
    }

    public function setConnection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    public function getConnection(): string
    {
        return $this->connection;
    }

    /**
     * 推送一个任务到队列。
     *
     * @param  object|string $job   任务类名或 "Class@method"
     * @param  mixed         $data  任务数据
     * @param  string|null   $queue 队列名
     * @return mixed 任务 ID
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue);
    }

    /**
     * 推送一个原始 payload 到队列。
     *
     * @param  string               $payload JSON 格式的任务负载
     * @param  string|null          $queue   队列名
     * @param  array<string, mixed> $options 选项（如 delay）
     * @return mixed
     */
    abstract public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed;

    /**
     * 延迟推送一个任务。
     *
     * @param  int           $delay 延迟秒数
     * @param  object|string $job   任务类名
     * @param  mixed         $data  任务数据
     * @param  string|null   $queue 队列名
     * @return mixed
     */
    public function later(int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->pushRaw($this->createPayload($job, $data), $queue, ['delay' => $delay]);
    }

    /**
     * 从队列取出下一个任务。
     *
     * @param  string|null $queue 队列名
     * @return Job|null
     */
    abstract public function pop(?string $queue = null): ?Job;

    /**
     * 获取队列长度。
     */
    abstract public function size(?string $queue = null): int;

    /**
     * 创建任务负载 JSON。
     *
     * @param  object|string $job
     * @param  mixed         $data
     */
    public function createPayload(object|string $job, mixed $data = ''): string
    {
        return json_encode($this->createPayloadArray($job, $data), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 创建任务负载数组。
     *
     * @param  object|string $job
     * @param  mixed         $data
     * @return array<string, mixed>
     */
    protected function createPayloadArray(object|string $job, mixed $data = ''): array
    {
        if (is_object($job)) {
            $job = get_class($job);
        }

        return [
            'job'  => $job,
            'data' => $data,
        ];
    }
}
