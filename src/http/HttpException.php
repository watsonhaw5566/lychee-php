<?php

declare(strict_types=1);

namespace Lychee\http;

use RuntimeException;
use Throwable;

/**
 * 带 HTTP 状态码的异常。
 *
 * 所有具备 HTTP 语义的业务异常（未登录、权限不足、资源不存在、校验失败等）
 * 均应继承此类并在构造函数中固化 statusCode，以便全局异常处理器自动渲染为
 * 对应状态码的响应，无需在 handler 中逐个 instanceof 判断。
 *
 * 构造参数：
 *   - $statusCode HTTP 状态码（401/403/404/...）
 *   - $message    错误描述
 *   - $code       业务错误码（可选，区别于 HTTP 状态码）
 *   - $previous   上一个异常（用于异常链）
 *   - $headers    需要附加到响应的头信息（如 Retry-After、WWW-Authenticate）
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private readonly array $headers = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * 获取需要附加到响应的头信息。
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
