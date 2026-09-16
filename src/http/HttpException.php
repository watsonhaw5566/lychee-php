<?php

declare(strict_types=1);

namespace Lychee\http;

use RuntimeException;
use Throwable;

/**
 * 带 HTTP 状态码的异常。
 */
class HttpException extends RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
