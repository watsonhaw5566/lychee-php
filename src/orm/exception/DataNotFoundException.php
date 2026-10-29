<?php

declare(strict_types=1);

namespace Lychee\orm\exception;

/**
 * 查询结果集为空且使用 failException 模式时抛出。
 */
class DataNotFoundException extends DbException
{
}
