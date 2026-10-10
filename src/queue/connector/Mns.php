<?php

declare(strict_types=1);

namespace Lychee\queue\connector;

use AliyunMNS\Client;
use AliyunMNS\Requests\SendMessageRequest;
use InvalidArgumentException;
use Lychee\queue\Connector;
use Lychee\queue\Job;
use Lychee\queue\job\Mns as MnsJob;
use RuntimeException;
use Throwable;

/**
 * 阿里云轻量消息队列 MNS 队列模型驱动。
 *
 * 依赖（需用户项目自行安装）：aliyun/aliyun-mns-php-sdk (>=1.0.0)
 * 参考文档：https://help.aliyun.com/zh/mns/developer-reference/queue-queue
 *
 * release 实现：先 deleteMessage 删除原消息，再 SendMessage 重新投递一条
 * （payload 中已写入最新的 attempts），与 Redis 驱动保持一致。
 *
 * MNS 原生支持 DelaySeconds 延迟投递与死信队列（在控制台配置死信策略）。
 */
class Mns extends Connector
{
    /**
     * MNS 异常基类名（用字符串引用，避免未安装 SDK 时类加载失败）。
     */
    private const MNS_EXCEPTION = 'AliyunMNS\\Exception\\MnsException';

    protected Client $client;

    protected string $default;

    /**
     * ReceiveMessage 等待时长（秒），用于长轮询。0 表示短轮询。
     */
    protected int $waitSeconds;

    /**
     * @param array<string, mixed> $config 连接配置
     * @param Client|null          $client 可选，注入的 MNS 客户端（用于测试）
     */
    public function __construct(array $config = [], ?Client $client = null)
    {
        if ($client === null) {
            $accessId  = (string) ($config['access_id'] ?? '');
            $accessKey = (string) ($config['access_key'] ?? '');
            $endpoint  = (string) ($config['endpoint'] ?? '');

            if ($accessId === '' || $accessKey === '' || $endpoint === '') {
                throw new InvalidArgumentException(
                    'MNS 连接配置不完整：必须提供 access_id、access_key 与 endpoint。'
                );
            }

            if (!class_exists(Client::class)) {
                throw new RuntimeException(
                    'MNS SDK 未安装：请执行 composer require aliyun/aliyun-mns-php-sdk (>=1.0.0)。'
                );
            }

            $client = new Client(rtrim($endpoint, '/'), $accessId, $accessKey);
        }

        $this->client      = $client;
        $this->default     = (string) ($config['queue'] ?? 'default');
        $this->waitSeconds = max(0, (int) ($config['wait_seconds'] ?? 0));
    }

    /**
     * 获取 MNS 客户端实例。
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * 获取指定队列引用（关闭 base64，直接收发 JSON 原文）。
     *
     * @return mixed \AliyunMNS\Queue
     */
    public function getQueueRef(?string $queue = null)
    {
        return $this->client->getQueueRef($queue ?? $this->default, false);
    }

    /**
     * 查询队列中可见消息数（近似值）。属性不可读或队列不存在时返回 0。
     */
    public function size(?string $queue = null): int
    {
        $queueRef = $this->getQueueRef($queue);

        try {
            $attributes = $queueRef->getAttribute()->getQueueAttributes();
        } catch (Throwable $e) {
            if (self::isRecoverableMnsError($e)) {
                return 0;
            }

            throw $e;
        }

        if (!is_object($attributes)) {
            return 0;
        }

        $active = $attributes->getActiveMessages();

        return $active === null ? 0 : (int) $active;
    }

    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        $delaySeconds = max(0, (int) ($options['delay'] ?? 0));

        try {
            $res = $this->getQueueRef($queue)->sendMessage(new SendMessageRequest($payload, $delaySeconds));
        } catch (Throwable $e) {
            if (is_a($e, self::MNS_EXCEPTION)) {
                throw new RuntimeException(
                    '[MNS SendMessage] ' . $e->getMessage(),
                    (int) $e->getCode() ?: 500,
                    $e
                );
            }

            throw $e;
        }

        return $res->getMessageId();
    }

    /**
     * 从 MNS 拉取一条消息。队列为空（MessageNotExist）时返回 null。
     */
    public function pop(?string $queue = null): ?Job
    {
        try {
            $res = $this->getQueueRef($queue)->receiveMessage($this->waitSeconds);
        } catch (Throwable $e) {
            if (self::isRecoverableMnsError($e)) {
                return null;
            }

            if (is_a($e, self::MNS_EXCEPTION)) {
                throw new RuntimeException(
                    '[MNS ReceiveMessage] ' . $e->getMessage(),
                    (int) $e->getCode() ?: 500,
                    $e
                );
            }

            throw $e;
        }

        $messageBody = $res->getMessageBody();
        if (!is_string($messageBody) || $messageBody === '') {
            return null;
        }

        return new MnsJob(
            $this->app,
            $this,
            $messageBody,
            (string) $res->getReceiptHandle(),
            $this->connection,
            $queue ?? $this->default,
        );
    }

    /**
     * 根据 ReceiptHandle 删除已消费成功的消息。
     */
    public function deleteMessage(string $queue, string $receiptHandle): void
    {
        if ($receiptHandle === '') {
            return;
        }

        try {
            $this->getQueueRef($queue)->deleteMessage($receiptHandle);
        } catch (Throwable $e) {
            if (self::isRecoverableMnsError($e)) {
                return;
            }

            if (is_a($e, self::MNS_EXCEPTION)) {
                throw new RuntimeException(
                    '[MNS DeleteMessage] ' . $e->getMessage(),
                    (int) $e->getCode() ?: 500,
                    $e
                );
            }

            throw $e;
        }
    }

    /**
     * 重新投递消息：先删除原消息，再投递一条新消息（payload 含最新 attempts），
     * 可带 DelaySeconds 实现延迟重试。newPayload 为空时仅删除。
     */
    public function releaseMessage(
        string $queue,
        string $receiptHandle,
        int $delay = 0,
        string $newPayload = '',
    ): void {
        if ($receiptHandle === '') {
            return;
        }

        try {
            $this->getQueueRef($queue)->deleteMessage($receiptHandle);
        } catch (Throwable $e) {
            if (!self::isRecoverableMnsError($e)) {
                throw $e;
            }
        }

        if ($newPayload === '') {
            return;
        }

        try {
            $this->getQueueRef($queue)->sendMessage(new SendMessageRequest($newPayload, max(0, $delay)));
        } catch (Throwable $e) {
            if (is_a($e, self::MNS_EXCEPTION)) {
                throw new RuntimeException(
                    '[MNS SendMessage] ' . $e->getMessage(),
                    (int) $e->getCode() ?: 500,
                    $e
                );
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function createPayloadArray(object|string $job, mixed $data = ''): array
    {
        return array_merge(parent::createPayloadArray($job, $data), [
            'id'       => $this->getRandomId(),
            'attempts' => 0,
        ]);
    }

    protected function getRandomId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable) {
            return uniqid('', true);
        }
    }

    /**
     * 判断异常是否为可忽略的业务级错误（消息/队列不存在等）。
     */
    public static function isRecoverableMnsError(Throwable $e): bool
    {
        if (str_contains(strtolower($e::class), 'messagenotexist')
            || str_contains(strtolower($e::class), 'queuenotexist')) {
            return true;
        }

        $message = strtolower($e->getMessage());

        foreach (['not found', 'not exist', 'no message', 'message not exist', 'queue not exist'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
