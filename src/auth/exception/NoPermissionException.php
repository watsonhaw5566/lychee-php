<?php

declare(strict_types=1);

namespace Lychee\auth\exception;

use Lychee\http\HttpException;
use Throwable;

/**
 * 权限不足异常，对应 HTTP 403。
 */
class NoPermissionException extends HttpException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(403, $message, $code, $previous);
    }
}
