<?php

declare(strict_types=1);

namespace Lychee\migration\schema;

/**
 * 数据表元信息（驱动无关的中间结构）。
 *
 * name 为逻辑表名（已剥离前缀），realName 为物理表名（含前缀）。
 */
final class TableSchema
{
    /**
     * @param list<ColumnSchema>     $columns
     * @param list<IndexSchema>      $indexes
     * @param list<ForeignKeySchema> $foreignKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly string $realName,
        public readonly string $driver = '',
        public readonly string $engine = 'InnoDB',
        public readonly string $collation = '',
        public readonly string $comment = '',
        public readonly array $columns = [],
        public readonly array $indexes = [],
        public readonly array $foreignKeys = [],
    ) {
    }
}
