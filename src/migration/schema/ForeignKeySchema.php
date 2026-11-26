<?php

declare(strict_types=1);

namespace Lychee\migration\schema;

/**
 * 外键元信息。引用表名已剥离前缀，为逻辑表名。
 */
final class ForeignKeySchema
{
    /**
     * @param list<string> $columns    本表外键列（按顺序）
     * @param list<string> $refColumns 被引用列（按顺序）
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $refTable,
        public readonly array $refColumns,
        public readonly ?string $onDelete = null,
        public readonly ?string $onUpdate = null,
    ) {
    }
}
