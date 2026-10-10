<?php

declare(strict_types=1);

namespace Lychee\queue\job;

use Lychee\container\Container;
use Lychee\queue\Job;
use Lychee\queue\connector\Cmq as CmqConnector;

/**
 * 腾讯云 CMQ 队列任务。
 *
 * 封装从 CMQ 取出的消息，delete/release 通过 ReceiptHandle 操作远端消息。
 */
class Cmq extends Job
{
    /**
     * @param CmqConnector $cmq           CMQ 连接器
     * @param string       $job           消息原文（JSON payload）
     * @param string       $receiptHandle 消息句柄，用于删除/重投
     * @param string       $connection    连接名
     * @param string       $queue         队列名
     */
    public function __construct(
        Container $app,
        protected CmqConnector $cmq,
        protected string $job,
        protected string $receiptHandle,
        string $connection,
        string $queue,
    ) {
        $this->app        = $app;
        $this->connection = $connection;
        $this->queue      = $queue;
    }

    public function getJobId(): mixed
    {
        return $this->payload('id');
    }

    public function attempts(): int
    {
        // CMQ 不会在服务端累计尝试次数，attempts 记录在 payload 中，
        // pop 后当前执行次数为 payload 值 +1
        return (int) $this->payload('attempts', 0) + 1;
    }

    public function getRawBody(): string
    {
        return $this->job;
    }

    public function delete(): void
    {
        parent::delete();

        $this->cmq->deleteMessage($this->queue, $this->receiptHandle);
    }

    public function release(int $delay = 0): void
    {
        parent::release();

        // 将当前尝试次数写回 payload，以便下一任 worker 感知累计 attempts，
        // 使 maxTries / failed 机制正常工作
        $payload = $this->payload();
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload['attempts'] = $this->attempts();

        $newPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($newPayload === false) {
            $newPayload = $this->job;
        }

        $this->cmq->releaseMessage($this->queue, $this->receiptHandle, $delay, $newPayload);
    }

    public function getReceiptHandle(): string
    {
        return $this->receiptHandle;
    }
}
