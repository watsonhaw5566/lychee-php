<?php

declare(strict_types=1);

namespace Lychee\queue\job;

use Lychee\container\Container;
use Lychee\queue\Job;
use Lychee\queue\connector\Mns as MnsConnector;

/**
 * 阿里云 MNS 队列任务。
 *
 * 封装从 MNS 取出的消息，delete/release 通过 ReceiptHandle 操作远端消息。
 */
class Mns extends Job
{
    /**
     * @param MnsConnector $mns           MNS 连接器
     * @param string       $job           消息原文（JSON payload）
     * @param string       $receiptHandle 消息句柄，用于删除/重投
     * @param string       $connection    连接名
     * @param string       $queue         队列名
     */
    public function __construct(
        Container $app,
        protected MnsConnector $mns,
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
        // MNS 不会在服务端累计尝试次数，attempts 记录在 payload 中，
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

        $this->mns->deleteMessage($this->queue, $this->receiptHandle);
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

        $this->mns->releaseMessage($this->queue, $this->receiptHandle, $delay, $newPayload);
    }

    public function getReceiptHandle(): string
    {
        return $this->receiptHandle;
    }
}
