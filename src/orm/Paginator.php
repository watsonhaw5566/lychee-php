<?php

declare(strict_types=1);

namespace Lychee\orm;

use JsonSerializable;

/**
 * 分页结果对象。
 *
 * 通过语义化方法访问分页数据：
 *   $paginator->items()       // 当前页数据数组（自动 ->all()）
 *   $paginator->total()       // 总记录数
 *   $paginator->perPage()     // 每页条数
 *   $paginator->currentPage() // 当前页码
 *   $paginator->lastPage()    // 最后一页
 *   $paginator->isEmpty()     // 是否为空
 *
 * 避免每次都写 `$paginator['list']->all()` 的样板代码。
 */
class Paginator implements JsonSerializable
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

    // ── 序列化 ─────────────────────────────────────────────────────

    /**
     * @return array{total:int, list:array, per_page:int, current_page:int, last_page:int}
     */
    public function jsonSerialize(): array
    {
        return [
            'total'        => $this->total,
            'list'         => $this->list instanceof Collection ? $this->list->toArray() : $this->list,
            'per_page'     => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page'    => $this->lastPage,
        ];
    }

    /**
     * @return array{total:int, list:array, per_page:int, current_page:int, last_page:int}
     */
    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
