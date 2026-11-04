<?php

declare(strict_types=1);

namespace Lychee\http\client;

/**
 * HTTP 客户端入口。
 *
 * 提供静态快捷方法和工厂方法，零配置即可发起请求：
 *
 *   // 快捷用法
 *   $response = HttpClient::get('https://api.example.com/users');
 *   $data = $response->json();
 *
 *   // 链式配置
 *   $response = HttpClient::create()
 *       ->baseUrl('https://api.example.com')
 *       ->withToken('xxx')
 *       ->timeout(10)
 *       ->post('/users', ['name' => 'test']);
 *
 *   // 全局辅助函数
 *   $response = http()->get('https://...');
 */
class HttpClient
{
    /**
     * 创建一个新的请求构建器。
     */
    public static function create(): PendingRequest
    {
        return new PendingRequest();
    }

    /**
     * 快捷发送 GET 请求。
     *
     * @param array<string, mixed> $query
     */
    public static function get(string $url, array $query = []): ClientResponse
    {
        return static::create()->get($url, $query);
    }

    /**
     * 快捷发送 POST 请求（JSON 格式）。
     *
     * @param array<string, mixed> $data
     */
    public static function post(string $url, array $data = []): ClientResponse
    {
        return static::create()->post($url, $data);
    }

    /**
     * 快捷发送 PUT 请求。
     *
     * @param array<string, mixed> $data
     */
    public static function put(string $url, array $data = []): ClientResponse
    {
        return static::create()->put($url, $data);
    }

    /**
     * 快捷发送 PATCH 请求。
     *
     * @param array<string, mixed> $data
     */
    public static function patch(string $url, array $data = []): ClientResponse
    {
        return static::create()->patch($url, $data);
    }

    /**
     * 快捷发送 DELETE 请求。
     *
     * @param array<string, mixed> $data
     */
    public static function delete(string $url, array $data = []): ClientResponse
    {
        return static::create()->delete($url, $data);
    }
}
