<?php

declare(strict_types=1);

namespace Lychee\orm\exception;

use RuntimeException;

/**
 * ORM 通用异常。
 *
 * 覆盖连接失败、SQL 执行错误、模型配置错误等场景。
 */
class DbException extends RuntimeException
{
}
