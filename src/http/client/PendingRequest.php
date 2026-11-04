<?php

declare(strict_types=1);

namespace Lychee\http\client;

use CurlHandle;

/**
 * 待发送的 HTTP 请求构建器。
 *
 * 通过链式调用配置请求参数，最终调用 get/post/put/patch/delete 发送请求。
 *
 *   $response = (new PendingRequest())
 *       ->baseUrl('https://api.example.com')
 *       ->withToken('xxx')
 *       ->timeout(10)
 *       ->post('/users', ['name' => 'test']);
 */
class PendingRequest
{
    protected string $baseUrl = '';

    /** @var array<string, string> */
    protected array $headers = [];

    /** @var array<string, string> */
    protected array $query = [];

    protected int $timeout = 30;

    protected int $connectTimeout = 10;

    protected int $retries = 0;

    protected int $retryDelay = 100;

    protected bool $verify = true;

    protected string $bodyFormat = 'json';

    /** @var array<string, mixed> */
    protected array $options = [];

    /**
     * 设置基础 URL，所有相对路径请求会拼接此前缀。
     */
    public function baseUrl(string $url): static
    {
        $this->baseUrl = rtrim($url, '/');

        return $this;
    }

    /**
     * 设置请求头。
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /**
     * 设置单个请求头。
     */
    public function withHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * 设置 Bearer Token。
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withHeader('Authorization', "{$type} {$token}");
    }

    /**
     * 设置 Basic Auth。
     */
    public function withBasicAuth(string $username, string $password): static
    {
        return $this->withHeader(
            'Authorization',
            'Basic ' . base64_encode("{$username}:{$password}"),
        );
    }

    /**
     * 设置 URL 查询参数（附加到所有请求）。
     *
     * @param array<string, string> $query
     */
    public function withQuery(array $query): static
    {
        $this->query = array_merge($this->query, $query);

        return $this;
    }

    /**
     * 设置请求超时（秒）。
     */
    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * 设置连接超时（秒）。
     */
    public function connectTimeout(int $seconds): static
    {
        $this->connectTimeout = $seconds;

        return $this;
    }

    /**
     * 设置失败重试次数及间隔。
     *
     * @param int $times    最大重试次数
     * @param int $delayMs  每次重试间隔（毫秒）
     */
    public function retry(int $times, int $delayMs = 100): static
    {
        $this->retries    = $times;
        $this->retryDelay = $delayMs;

        return $this;
    }

    /**
     * 禁用 SSL 证书验证（仅用于开发/测试环境）。
     */
    public function withoutVerifying(): static
    {
        $this->verify = false;

        return $this;
    }

    /**
     * 以 application/x-www-form-urlencoded 格式发送请求体。
     */
    public function asForm(): static
    {
        $this->bodyFormat = 'form';

        return $this;
    }

    /**
     * 以 application/json 格式发送请求体（默认）。
     */
    public function asJson(): static
    {
        $this->bodyFormat = 'json';

        return $this;
    }

    /**
     * 以 multipart/form-data 格式发送（用于文件上传）。
     */
    public function asMultipart(): static
    {
        $this->bodyFormat = 'multipart';

        return $this;
    }

    /**
     * 发送 GET 请求。
     *
     * @param array<string, mixed> $query 查询参数
     */
    public function get(string $url, array $query = []): ClientResponse
    {
        return $this->send('GET', $url, query: $query);
    }

    /**
     * 发送 POST 请求。
     *
     * @param array<string, mixed> $data 请求体数据
     */
    public function post(string $url, array $data = []): ClientResponse
    {
        return $this->send('POST', $url, data: $data);
    }

    /**
     * 发送 PUT 请求。
     *
     * @param array<string, mixed> $data 请求体数据
     */
    public function put(string $url, array $data = []): ClientResponse
    {
        return $this->send('PUT', $url, data: $data);
    }

    /**
     * 发送 PATCH 请求。
     *
     * @param array<string, mixed> $data 请求体数据
     */
    public function patch(string $url, array $data = []): ClientResponse
    {
        return $this->send('PATCH', $url, data: $data);
    }

    /**
     * 发送 DELETE 请求。
     *
     * @param array<string, mixed> $data 请求体数据
     */
    public function delete(string $url, array $data = []): ClientResponse
    {
        return $this->send('DELETE', $url, data: $data);
    }

    /**
     * 发送 HEAD 请求。
     */
    public function head(string $url): ClientResponse
    {
        return $this->send('HEAD', $url);
    }

    /**
     * 发送 HTTP 请求（核心方法）。
     *
     * @param array<string, mixed> $data  请求体数据
     * @param array<string, mixed> $query 额外查询参数
     *
     * @throws RequestException 连接失败或 cURL 错误时抛出
     */
    public function send(
        string $method,
        string $url,
        array $data = [],
        array $query = [],
    ): ClientResponse {
        $url     = $this->buildUrl($url, $query);
        $attempt = 0;

        do {
            if ($attempt > 0) {
                usleep($this->retryDelay * 1000);
            }

            $response = $this->execute($method, $url, $data);

            if ($response->ok() || !$this->shouldRetry($response)) {
                return $response;
            }

            $attempt++;
        } while ($attempt <= $this->retries);

        return $response;
    }

    /**
     * 判断是否应该重试（仅对服务端错误和连接超时重试）。
     */
    protected function shouldRetry(ClientResponse $response): bool
    {
        return $response->serverError() || $response->status() === 429;
    }

    /**
     * 执行单次 cURL 请求。
     *
     * @param array<string, mixed> $data
     */
    protected function execute(
        string $method,
        string $url,
        array $data,
    ): ClientResponse {
        $ch = curl_init();

        $this->configureCurl($ch, $method, $url, $data);

        $responseHeaders = [];
        curl_setopt(
            $ch,
            CURLOPT_HEADERFUNCTION,
            function (CurlHandle $ch, string $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return strlen($line);
            },
        );

        $body       = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno      = curl_errno($ch);
        $error      = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RequestException(
                new ClientResponse($error, 0),
            );
        }

        return new ClientResponse(
            (string) $body,
            (int) $statusCode,
            $responseHeaders,
        );
    }

    /**
     * 配置 cURL 句柄。
     *
     * @param array<string, mixed> $data
     */
    protected function configureCurl(
        CurlHandle $ch,
        string $method,
        string $url,
        array $data,
    ): void {
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_SSL_VERIFYPEER => $this->verify,
            CURLOPT_SSL_VERIFYHOST => $this->verify ? 2 : 0,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        $headers = $this->headers;

        if ($data !== [] && !in_array($method, ['GET', 'HEAD'], true)) {
            $this->applyBody($ch, $data, $headers);
        }

        if ($headers !== []) {
            $formatted = [];
            foreach ($headers as $name => $value) {
                $formatted[] = "{$name}: {$value}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formatted);
        }
    }

    /**
     * 根据 bodyFormat 将数据写入 cURL 请求体。
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> &$headers
     */
    protected function applyBody(
        CurlHandle $ch,
        array $data,
        array &$headers,
    ): void {
        match ($this->bodyFormat) {
            'json'      => (function () use ($ch, $data, &$headers) {
                $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                $headers['Content-Type']   ??= 'application/json; charset=utf-8';
                $headers['Content-Length'] ??= (string) strlen($json);
            })(),
            'form'      => (function () use ($ch, $data, &$headers) {
                $body = http_build_query($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
            })(),
            'multipart' => (function () use ($ch, $data) {
                // 传数组给 CURLOPT_POSTFIELDS 时 cURL 自动设置 multipart Content-Type
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            })(),
        };
    }

    /**
     * 拼接完整 URL。
     *
     * @param array<string, mixed> $query
     */
    protected function buildUrl(string $url, array $query = []): string
    {
        // 相对路径拼接 baseUrl
        if ($this->baseUrl !== '' && !str_contains($url, '://')) {
            $url = $this->baseUrl . '/' . ltrim($url, '/');
        }

        $mergedQuery = array_merge($this->query, $query);

        if ($mergedQuery !== []) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($mergedQuery);
        }

        return $url;
    }
}
