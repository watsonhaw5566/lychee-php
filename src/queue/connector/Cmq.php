<?php

declare(strict_types=1);

namespace Lychee\queue\connector;

use InvalidArgumentException;
use Lychee\queue\Connector;
use Lychee\queue\Job;
use Lychee\queue\job\Cmq as CmqJob;
use RuntimeException;
use Throwable;

/**
 * 腾讯云 CMQ 消息队列驱动（纯原生 curl 实现，无需额外 SDK）。
 *
 *   - size() -> 腾讯云 API v3（TC3-HMAC-SHA256 签名），通过
 *     tdmq.tencentcloudapi.com 的 DescribeCmqQueueDetail 查询可见消息数。
 *
 *   - push / pop / delete / release -> CMQ 原生 HTTP 接口（curl + HMAC-SHA1 签名），
 *     使用 SendMessage / ReceiveMessage / DeleteMessage，入口由控制台分配的 endpoint 提供。
 *
 * release 实现：先 DeleteMessage 删除原消息，再 SendMessage 重新投递
 * （payload 含最新 attempts），与 Redis/MNS 驱动一致。
 * CMQ 原生支持 delaySeconds 延迟投递，也支持在控制台为队列配置死信。
 */
class Cmq extends Connector
{
    protected string $secretId;

    protected string $secretKey;

    /**
     * 腾讯云 API v3 请求使用的地域，如 ap-guangzhou。
     */
    protected string $region;

    /**
     * CMQ 原生接口入口地址，如 https://cmq-gz.publicXXX.tencenttdmq.com。
     */
    protected string $endpoint;

    protected string $default;

    /**
     * ReceiveMessage 等待时长（秒），用于长轮询。0 表示短轮询。
     */
    protected int $pollingWaitSeconds;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('curl 扩展未安装，CMQ 驱动依赖 curl 调用腾讯云接口。');
        }

        $this->secretId           = (string) ($config['secret_id'] ?? '');
        $this->secretKey          = (string) ($config['secret_key'] ?? '');
        $this->region             = (string) ($config['region'] ?? '');
        $this->endpoint           = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $this->default            = (string) ($config['queue'] ?? 'default');
        $this->pollingWaitSeconds = max(0, (int) ($config['polling_wait_seconds'] ?? 0));

        if (in_array('', [$this->secretId, $this->secretKey, $this->region, $this->endpoint], true)) {
            throw new InvalidArgumentException(
                'CMQ 连接配置不完整：必须提供 secret_id、secret_key、region 与 endpoint。'
            );
        }
    }

    /**
     * 查询队列中可见消息数。接口异常或字段缺失时返回 0。
     */
    public function size(?string $queue = null): int
    {
        try {
            $resp = $this->callTencentCloudApi('tdmq', '2020-02-17', 'DescribeCmqQueueDetail', [
                'QueueName' => $queue ?? $this->default,
            ]);
        } catch (Throwable $e) {
            if (self::isRecoverableCmqError($e)) {
                return 0;
            }

            throw $e;
        }

        if (!is_array($resp)) {
            return 0;
        }

        $activeMsgNum = $resp['ActiveMsgNum']
            ?? $resp['QueueDescribe']['ActiveMsgNum']
            ?? $resp['QueueSet'][0]['ActiveMsgNum']
            ?? null;

        return $activeMsgNum === null ? 0 : (int) $activeMsgNum;
    }

    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        $resp = $this->callCmqApi('SendMessage', [
            'queueName'    => $queue ?? $this->default,
            'msgBody'      => $payload,
            'delaySeconds' => max(0, (int) ($options['delay'] ?? 0)),
        ]);

        return $resp['msgId'] ?? null;
    }

    /**
     * 从 CMQ 拉取一条消息。空队列（code=10200 no message）静默为 null。
     */
    public function pop(?string $queue = null): ?Job
    {
        try {
            $resp = $this->callCmqApi('ReceiveMessage', [
                'queueName'          => $queue ?? $this->default,
                'pollingWaitSeconds' => $this->pollingWaitSeconds,
            ]);
        } catch (Throwable $e) {
            if (self::isRecoverableCmqError($e)) {
                return null;
            }

            throw $e;
        }

        $msg = $resp['msgBody'] ?? null;
        if (!is_string($msg)) {
            return null;
        }

        return new CmqJob(
            $this->app,
            $this,
            $msg,
            (string) ($resp['receiptHandle'] ?? ''),
            $this->connection,
            $queue ?? $this->default,
        );
    }

    public function deleteMessage(string $queue, string $receiptHandle): void
    {
        if ($receiptHandle === '') {
            return;
        }

        $this->callCmqApi('DeleteMessage', [
            'queueName'     => $queue,
            'receiptHandle' => $receiptHandle,
        ]);
    }

    /**
     * 重新投递消息：先删除原消息，再投递新消息（payload 含最新 attempts）。
     * newPayload 为空时仅删除。
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

        $this->callCmqApi('DeleteMessage', [
            'queueName'     => $queue,
            'receiptHandle' => $receiptHandle,
        ]);

        if ($newPayload === '') {
            return;
        }

        $this->callCmqApi('SendMessage', [
            'queueName'    => $queue,
            'msgBody'      => $newPayload,
            'delaySeconds' => max(0, $delay),
        ]);
    }

    /**
     * 调用 CMQ 原生 HTTP 接口（HMAC-SHA1 签名）。
     *
     * @param array<string, mixed> $params 业务参数（不含签名）
     * @return array<string, mixed>
     * @throws RuntimeException 当 HTTP 失败或接口返回 code != 0
     */
    private function callCmqApi(string $action, array $params): array
    {
        $params['Action']          = $action;
        $params['SecretId']        = $this->secretId;
        $params['Timestamp']       = time();
        $params['Nonce']           = random_int(100000, 9999999);
        $params['SignatureMethod'] = 'HmacSHA1';

        ksort($params, SORT_STRING);

        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);

        $signString          = 'POST' . $host . '/v2/index.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature           = base64_encode(hash_hmac('sha1', $signString, $this->secretKey, true));
        $params['Signature'] = $signature;

        $data = json_decode($this->doHttpPost($this->endpoint . '/v2/index.php', $params), true);

        if (!is_array($data)) {
            throw new RuntimeException('CMQ 接口返回数据格式异常', 500);
        }

        $code = (int) ($data['code'] ?? 0);
        if ($code !== 0) {
            throw new RuntimeException(
                sprintf(
                    '[CMQ %s] %s (code=%d)',
                    $action,
                    $data['message'] ?? ($data['Message'] ?? '未知错误'),
                    $code
                ),
                $code
            );
        }

        return $data;
    }

    /**
     * 执行一次 form-urlencoded POST 请求。
     *
     * protected 便于单元测试覆写，避免发送真实网络请求。
     *
     * @param array<string, mixed> $params
     */
    protected function doHttpPost(string $url, array $params): string
    {
        $ch = curl_init($url);

        try {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($params, '', '&'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $result = curl_exec($ch);

            if ($result === false) {
                throw new RuntimeException('CMQ HTTP 请求失败: ' . curl_error($ch), 500);
            }

            return (string) $result;
        } finally {
            curl_close($ch);
        }
    }

    /**
     * 调用腾讯云 API v3（TC3-HMAC-SHA256 签名）。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function callTencentCloudApi(
        string $service,
        string $version,
        string $action,
        array $payload,
    ): array {
        $host      = $service . '.tencentcloudapi.com';
        $url       = 'https://' . $host . '/';
        $timestamp = time();
        $body      = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = [
            'Authorization: ' . $this->signTencentCloudApi($service, $host, $body, $timestamp),
            'Content-Type: application/json; charset=utf-8',
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: ' . $version,
            'X-TC-Region: ' . $this->region,
        ];

        $data = json_decode($this->doHttpPostWithHeaders($url, $body, $headers), true);

        if (!is_array($data)) {
            throw new RuntimeException('云 API 返回数据格式异常', 500);
        }

        if (isset($data['Response']['Error'])) {
            throw new RuntimeException(
                sprintf(
                    '[%s] %s (%s)',
                    $action,
                    $data['Response']['Error']['Message'] ?? '未知错误',
                    $data['Response']['Error']['Code']    ?? 'UNKNOWN',
                ),
                500,
            );
        }

        return $data['Response'] ?? $data;
    }

    /**
     * 执行带自定义请求头的 JSON POST 请求。protected 便于单元测试覆写。
     *
     * @param array<string> $headers
     */
    protected function doHttpPostWithHeaders(string $url, string $body, array $headers): string
    {
        $ch = curl_init($url);

        try {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $result = curl_exec($ch);

            if ($result === false) {
                throw new RuntimeException('云 API HTTP 请求失败: ' . curl_error($ch), 500);
            }

            return (string) $result;
        } finally {
            curl_close($ch);
        }
    }

    /**
     * 计算腾讯云 API v3 的 TC3-HMAC-SHA256 签名。
     * 参考：https://cloud.tencent.com/document/api/1179/55910
     */
    private function signTencentCloudApi(
        string $service,
        string $host,
        string $body,
        int $timestamp,
    ): string {
        $date        = gmdate('Y-m-d', $timestamp);
        $algorithm   = 'TC3-HMAC-SHA256';
        $contentType = 'application/json; charset=utf-8';

        $canonicalRequest = implode("\n", [
            'POST',
            '/',
            '',
            "content-type:{$contentType}\nhost:{$host}\n",
            'content-type;host',
            hash('sha256', $body),
        ]);

        $credentialScope = "{$date}/{$service}/tc3_request";
        $stringToSign    = implode("\n", [
            $algorithm,
            $timestamp,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $secretDate    = hash_hmac('sha256', $date, 'TC3' . $this->secretKey, true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature     = hash_hmac('sha256', $stringToSign, $secretSigning, false);

        return "{$algorithm} Credential={$this->secretId}/{$credentialScope}, SignedHeaders=content-type;host, Signature={$signature}";
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
     * 判断异常是否为可忽略的业务级错误（空队列、队列不存在等）。
     */
    public static function isRecoverableCmqError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (['not found', 'no message', 'not exist', 'empty'] as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
