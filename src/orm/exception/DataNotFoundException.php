<?php

declare(strict_types=1);

namespace Lychee\orm\exception;

use Lychee\http\HttpException;
use Throwable;

/**
 * 查询结果集为空且使用 failException 模式时抛出，对应 HTTP 404。
 */
class DataNotFoundException extends HttpException
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(404, $message, $code, $previous);
    }
}
