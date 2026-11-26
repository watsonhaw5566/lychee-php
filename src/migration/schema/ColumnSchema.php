<?php

declare(strict_types=1);

namespace Lychee\migration\schema;

/**
 * 列元信息（驱动无关的中间结构）。
 *
 * 类型字段已归一化为 {@see \Lychee\migration\Table} 构建器可识别的逻辑类型
 * （如 string / integer / biginteger / decimal / datetime / enum ...）。
 */
final class ColumnSchema
{
    /**
     * @param list<string> $values enum / set 的取值列表
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $unsigned = false,
        public readonly array $values = [],
        public readonly bool $nullable = true,
        public readonly mixed $default = null,
        public readonly bool $hasDefault = false,
        public readonly bool $autoIncrement = false,
        public readonly string $comment = '',
        public readonly bool $onUpdateCurrentTimestamp = false,
    ) {
    }
}
