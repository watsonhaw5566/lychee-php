<?php

declare(strict_types=1);

namespace Lychee\orm\exception;

/**
 * 查询单条记录未命中时抛出。
 *
 * 由 Query::findOrFail() / Model::findOrFail() 使用。
 */
class ModelNotFoundException extends DbException
{
    public function __construct(string $model, string $message = '')
    {
        parent::__construct($message !== '' ? $message : "model data not found: {$model}");
    }
}
