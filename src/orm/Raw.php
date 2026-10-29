<?php

declare(strict_types=1);

namespace Lychee\orm;

/**
 * SQL 原生表达式包装。
 *
 * 用于 where/order 等场景传入未参数化的片段：
 *   $query->whereRaw('score > ?', [100])
 *   $query->order(new Raw('FIELD(status, 1, 2, 3)'))
 *
 * 原生片段不会被引号包裹，调用方自行保证安全。
 */
final class Raw
{
    public function __construct(
        private readonly string $value,
        private readonly array $bindings = [],
    ) {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getBindings(): array
    {
        return $this->bindings;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
