<?php

declare(strict_types=1);

namespace Lychee\orm;

use ArrayAccess;
use JsonSerializable;

/**
 * 分页结果对象。
 *
 * 同时支持语义化方法与数组访问（ArrayAccess）：
 *   $paginator->items()       // 当前页数据数组（自动 ->all()）
 *   $paginator->total()       // 总记录数
 *   $paginator->perPage()     // 每页条数
 *   $paginator->currentPage() // 当前页码
 *   $paginator->lastPage()    // 最后一页
 *   $paginator->isEmpty()     // 是否为空
 *
 *   $paginator['list']        // 数组访问（兼容 ThinkPHP 写法）
 *   $paginator['total']
 *   $paginator['current']     // 当前页
 *   $paginator['pageSize']    // 每页条数
 */
class Paginator implements ArrayAccess, JsonSerializable
{
    protected int $total;

    /** @var Collection<array-key, Model>|array<int, array<string, mixed>> */
    protected Collection|array $list;

    protected int $perPage;

    protected int $currentPage;

    protected int $lastPage;

    /**
     * @param int                                               $total       总记录数
     * @param Collection<array-key, Model>|array<int, array>    $list        当前页数据
     * @param int                                               $perPage     每页条数
     * @param int                                               $currentPage 当前页码
     */
    public function __construct(int $total, Collection|array $list, int $perPage, int $currentPage)
    {
        $this->total       = $total;
        $this->list        = $list;
        $this->perPage     = $perPage;
        $this->currentPage = $currentPage;
        $this->lastPage    = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
    }

    /**
     * 当前页数据（数组形式）。
     *
     * 若 list 为 Collection，自动调用 ->all()，省去每次手写。
     *
     * @return array<array-key, mixed>
     */
    public function items(): array
    {
        return $this->list instanceof Collection ? $this->list->all() : $this->list;
    }

    /**
     * 原始 list（可能是 Collection 或数组）。
     *
     * @return Collection<array-key, Model>|array<int, array<string, mixed>>
     */
    public function list(): Collection|array
    {
        return $this->list;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        return $this->lastPage;
    }

    public function isEmpty(): bool
    {
        $count = is_array($this->list) ? count($this->list) : $this->list->count();

        return $count === 0;
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    public function count(): int
    {
        return is_array($this->list) ? count($this->list) : $this->list->count();
    }

    // ── ArrayAccess ────────────────────────────────────────────────

    /**
     * 支持键：total / list / current / pageSize / current_page / per_page / last_page。
     *
     * @param mixed $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['total', 'list', 'current', 'pageSize', 'current_page', 'per_page', 'last_page'], true);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'total'        => $this->total,
            'list'         => $this->list,
            'current'      => $this->currentPage,
            'pageSize'     => $this->perPage,
            'current_page' => $this->currentPage,
            'per_page'     => $this->perPage,
            'last_page'    => $this->lastPage,
            default        => null,
        };
    }

    /**
     * 分页结果只读，禁止写入。
     *
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        // no-op
    }

    /**
     * 分页结果只读，禁止删除。
     *
     * @param mixed $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        // no-op
    }

    // ── 序列化 ─────────────────────────────────────────────────────

    /**
     * 同时输出驼峰（current / pageSize）与下划线（current_page / per_page）两套键名，
     * 兼容 ThinkPHP 风格与前端驼峰习惯。
     *
     * @return array{total:int, list:array, current:int, pageSize:int, current_page:int, per_page:int, last_page:int}
     */
    public function jsonSerialize(): array
    {
        return [
            'total'        => $this->total,
            'list'         => $this->list instanceof Collection ? $this->list->toArray() : $this->list,
            'current'      => $this->currentPage,
            'pageSize'     => $this->perPage,
            'current_page' => $this->currentPage,
            'per_page'     => $this->perPage,
            'last_page'    => $this->lastPage,
        ];
    }

    /**
     * @return array{total:int, list:array, current:int, pageSize:int, current_page:int, per_page:int, last_page:int}
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
