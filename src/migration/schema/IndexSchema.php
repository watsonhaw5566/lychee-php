<?php

declare(strict_types=1);

namespace Lychee\migration\schema;

/**
 * 索引元信息。
 */
final class IndexSchema
{
    /**
     * @param list<string> $columns 索引列（按顺序）
     * @param string       $type    PRIMARY / UNIQUE / INDEX
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly string $type,
    ) {
    }
}
