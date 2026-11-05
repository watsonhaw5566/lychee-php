<?php

declare(strict_types=1);

namespace Lychee\orm\exception;

use Lychee\http\HttpException;

/**
 * 查询单条记录未命中时抛出，对应 HTTP 404。
 *
 * 由 Query::findOrFail() / Model::findOrFail() 使用。
 */
class ModelNotFoundException extends HttpException
{
    public function __construct(string $model, string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            404,
            $message !== '' ? $message : "model data not found: {$model}",
            $code,
            $previous
        );
    }
}
