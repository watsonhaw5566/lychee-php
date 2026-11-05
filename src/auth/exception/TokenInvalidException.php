<?php

declare(strict_types=1);

namespace Lychee\auth\exception;

use Lychee\http\HttpException;
use Throwable;

/**
 * Token 无效异常，对应 HTTP 401。
 */
class TokenInvalidException extends HttpException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(401, $message, $code, $previous);
    }
}
