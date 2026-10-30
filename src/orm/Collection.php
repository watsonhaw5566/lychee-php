<?php

declare(strict_types=1);

namespace Lychee\orm;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * 模型集合。
 *
 * 封装一组 {@see Model} 实例，提供常用的集合操作：toArray / pluck /
 * column / groupBy / keyBy / map / filter / each / first / isEmpty。
 * 结果集可直接当数组使用（ArrayAccess / Iterator / Countable）。
 *
 * @template TKey of array-key
 * @template TModel of Model
 * @implements ArrayAccess<TKey, TModel>
 * @implements IteratorAggregate<TKey, TModel>
 * @phpstan-consistent-constructor
 */
class Collection implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    /** @var array<array-key, TModel> */
    protected array $items;

    /**
     * @param array<array-key, TModel> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * @return array<array-key, TModel>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /**
     * @return TModel|null
     */
    public function first(): ?Model
    {
        foreach ($this->items as $item) {
            return $item;
        }

        return null;
    }

    /**
     * @return TModel|null
     */
    public function last(): ?Model
    {
        if ($this->items === []) {
            return null;
        }

        return $this->items[array_key_last($this->items)];
    }

    /**
     * 转换为纯数组（递归调用每个模型的 toArray）。
     *
     * @return array<array-key, array<string, mixed>>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->items as $key => $item) {
            $result[$key] = $item instanceof Model ? $item->toArray() : $item;
        }

        return $result;
    }

    /**
     * 转换为 JSON 字符串。
     */
    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return (string) json_encode($this->toArray(), $options);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * 提取某个字段形成一维数组；传入 $key 时以其为键。
     *
     * @return array<int|string, mixed>
     */
    public function column(string $field, ?string $key = null): array
    {
        $result = [];
        foreach ($this->items as $index => $item) {
            $value = $item instanceof Model ? $item->getAttr($field) : ($item[$field] ?? null);
            if ($key !== null) {
                $k          = $item instanceof Model ? $item->getAttr($key) : ($item[$key] ?? $index);
                $result[$k] = $value;
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * {@see column} 的别名，兼容 think-orm。
     *
     * @return array<int|string, mixed>
     */
    public function pluck(string $field, ?string $key = null): array
    {
        return $this->column($field, $key);
    }

    /**
     * 以某字段作为键重新索引集合。
     *
     * @return static<array-key, TModel>
     */
    public function keyBy(string $field): static
    {
        $result = [];
        foreach ($this->items as $item) {
            $value          = $item instanceof Model ? $item->getAttr($field) : ($item[$field] ?? null);
            $result[$value] = $item;
        }

        return new static($result);
    }

    /**
     * 按某字段分组。
     *
     * @return array<array-key, static<array-key, TModel>>
     */
    public function groupBy(string $field): array
    {
        $groups = [];
        foreach ($this->items as $item) {
            $value            = $item instanceof Model ? $item->getAttr($field) : ($item[$field] ?? null);
            $groups[$value][] = $item;
        }

        $result = [];
        foreach ($groups as $k => $list) {
            $result[$k] = new static($list);
        }

        return $result;
    }

    /**
     * @param callable(TModel, array-key): mixed $callback
     * @return static<array-key, mixed>
     */
    public function map(callable $callback): static
    {
        $result = [];
        foreach ($this->items as $key => $item) {
            $result[$key] = $callback($item, $key);
        }

        return new static($result);
    }

    /**
     * @param callable(TModel, array-key): bool $callback
     * @return static<array-key, TModel>
     */
    public function filter(callable $callback): static
    {
        $result = [];
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key)) {
                $result[$key] = $item;
            }
        }

        return new static($result);
    }

    /**
     * 批量删除集合中的所有模型。
     */
    public function delete(): int
    {
        $count = 0;
        foreach ($this->items as $item) {
            if ($item instanceof Model && $item->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param callable(TModel, array-key): void $callback
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * 归并集合为单个值。
     *
     * @param callable(mixed, TModel, array-key): mixed $callback
     * @param mixed $initial
     * @return mixed
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $result = $initial;
        foreach ($this->items as $key => $item) {
            $result = $callback($result, $item, $key);
        }

        return $result;
    }

    /**
     * 对集合中某项的指定字段求和；$field 为 null 时直接对项求和。
     *
     * @param string|null $field
     * @return int|float
     */
    public function sum(?string $field = null): int|float
    {
        $result = 0;
        foreach ($this->items as $item) {
            $value = $field === null
                ? $item
                : ($item instanceof Model ? $item->getAttr($field) : ($item[$field] ?? 0));
            $result += is_numeric($value) ? $value + 0 : 0;
        }

        return $result;
    }

    /**
     * 对集合中某项的指定字段求平均；空集合返回 0。
     *
     * @param string|null $field
     */
    public function avg(?string $field = null): float
    {
        if ($this->items === []) {
            return 0.0;
        }

        return $this->sum($field) / count($this->items);
    }

    /**
     * 返回集合中指定字段的最小值。
     *
     * @param string|null $field
     */
    public function min(?string $field = null): mixed
    {
        $values = [];
        foreach ($this->items as $item) {
            $values[] = $field === null
                ? $item
                : ($item instanceof Model ? $item->getAttr($field) : ($item[$field] ?? null));
        }

        return $values === [] ? null : min($values);
    }

    /**
     * 返回集合中指定字段的最大值。
     *
     * @param string|null $field
     */
    public function max(?string $field = null): mixed
    {
        $values = [];
        foreach ($this->items as $item) {
            $values[] = $field === null
                ? $item
                : ($item instanceof Model ? $item->getAttr($field) : ($item[$field] ?? null));
        }

        return $values === [] ? null : max($values);
    }

    /**
     * 判断集合是否包含满足条件的项。
     *
     * $key 为字符串时按字段值是否等于 $value 判断；
     * 为 callable 时按回调返回值判断。
     *
     * @param string|callable(TModel, array-key): bool $key
     * @param mixed $value
     */
    public function contains(string|callable $key, mixed $value = null): bool
    {
        if (is_callable($key)) {
            foreach ($this->items as $k => $item) {
                if ($key($item, $k)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($this->items as $item) {
            $v = $item instanceof Model ? $item->getAttr($key) : ($item[$key] ?? null);
            if ($v === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * 按指定字段或回调排序，返回新的集合（保持键）。
     *
     * @param string|callable(TModel): mixed $callback
     * @param int $flags SORT_REGULAR / SORT_NUMERIC / SORT_STRING 等
     * @return static<array-key, TModel>
     */
    public function sortBy(string|callable $callback, int $flags = SORT_REGULAR): static
    {
        $results = [];
        foreach ($this->items as $key => $item) {
            $results[$key] = is_callable($callback)
                ? $callback($item)
                : ($item instanceof Model ? $item->getAttr($callback) : ($item[$callback] ?? null));
        }

        asort($results, $flags);

        $sorted = [];
        foreach (array_keys($results) as $key) {
            $sorted[$key] = $this->items[$key];
        }

        return new static($sorted);
    }

    /**
     * 按指定字段或回调去重，返回新的集合。
     *
     * @param string|callable(TModel): mixed|null $key
     * @return static<array-key, TModel>
     */
    public function unique(string|callable|null $key = null): static
    {
        $exists = [];
        $result = [];
        foreach ($this->items as $index => $item) {
            $value = $key === null
                ? $item
                : (is_callable($key)
                    ? $key($item)
                    : ($item instanceof Model ? $item->getAttr($key) : ($item[$key] ?? null)));

            $hash = is_object($value) ? spl_object_hash($value) : (is_array($value) ? md5(serialize($value)) : (string) $value);
            if (!isset($exists[$hash])) {
                $exists[$hash]  = true;
                $result[$index] = $item;
            }
        }

        return new static($result);
    }

    /**
     * 将集合（项为数组）扁平化一层。
     *
     * @return static<array-key, mixed>
     */
    public function flatten(): static
    {
        $result = [];
        foreach ($this->items as $item) {
            if (is_array($item) || $item instanceof self) {
                $list = $item instanceof self ? $item->all() : $item;
                foreach ($list as $v) {
                    $result[] = $v;
                }
            } else {
                $result[] = $item;
            }
        }

        return new static($result);
    }

    /**
     * {@see flatten} 的别名，用于项为集合的场景。
     *
     * @return static<array-key, mixed>
     */
    public function collapse(): static
    {
        return $this->flatten();
    }

    // ── 条件过滤（集合内按字段筛选）─────────────────────────────────

    /**
     * 按字段值过滤集合。
     *
     * @return static<array-key, TModel>
     */
    public function where(string $field, mixed $value): static
    {
        return $this->filter(fn ($item) => $this->itemValue($item, $field) === $value);
    }

    /**
     * 按字段值在给定集合内过滤。
     *
     * @param array<int, mixed> $values
     * @return static<array-key, TModel>
     */
    public function whereIn(string $field, array $values): static
    {
        return $this->filter(fn ($item) => in_array($this->itemValue($item, $field), $values, true));
    }

    /**
     * 按字段值不在给定集合内过滤。
     *
     * @param array<int, mixed> $values
     * @return static<array-key, TModel>
     */
    public function whereNotIn(string $field, array $values): static
    {
        return $this->filter(fn ($item) => !in_array($this->itemValue($item, $field), $values, true));
    }

    // ── 集合运算 ────────────────────────────────────────────────────

    /**
     * 差集：返回当前集合中不在 $items 里的项。
     *
     * 模型按主键比较，数组按值比较，对象按实例比较。
     *
     * @param Collection<array-key, TModel>|array<array-key, mixed> $items
     * @return static<array-key, TModel>
     */
    public function diff(Collection|array $items): static
    {
        $other = $items instanceof Collection ? $items->all() : $items;
        $keys  = [];
        foreach ($other as $item) {
            $keys[$this->itemIdentity($item)] = true;
        }

        $result = [];
        foreach ($this->items as $key => $item) {
            if (!isset($keys[$this->itemIdentity($item)])) {
                $result[$key] = $item;
            }
        }

        return new static($result);
    }

    /**
     * 交集：返回当前集合中同时存在于 $items 里的项。
     *
     * @param Collection<array-key, TModel>|array<array-key, mixed> $items
     * @return static<array-key, TModel>
     */
    public function intersect(Collection|array $items): static
    {
        $other = $items instanceof Collection ? $items->all() : $items;
        $keys  = [];
        foreach ($other as $item) {
            $keys[$this->itemIdentity($item)] = true;
        }

        $result = [];
        foreach ($this->items as $key => $item) {
            if (isset($keys[$this->itemIdentity($item)])) {
                $result[$key] = $item;
            }
        }

        return new static($result);
    }

    /**
     * 仅保留指定键的项。
     *
     * @param array<int, array-key> $keys
     * @return static<array-key, TModel>
     */
    public function only(array $keys): static
    {
        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    /**
     * 排除指定键的项。
     *
     * @param array<int, array-key> $keys
     * @return static<array-key, TModel>
     */
    public function except(array $keys): static
    {
        return new static(array_diff_key($this->items, array_flip($keys)));
    }

    // ── 集合内分页（客户端分页）─────────────────────────────────────

    /**
     * 对已加载到内存的集合做客户端分页，返回 {@see Paginator}。
     *
     * 常用于统计结果集等已在内存中的数据分页。
     *
     * @param int $page     当前页码（最小 1）
     * @param int $pageSize 每页条数（自动限制在 1~200）
     */
    public function paginate(int $page = 1, int $pageSize = 15): Paginator
    {
        $page     = max(1, $page);
        $pageSize = max(1, min(200, $pageSize));

        $total  = count($this->items);
        $offset = max(0, ($page - 1) * $pageSize);
        $slice  = array_slice($this->items, $offset, $pageSize, true);

        return new Paginator($total, new static($slice), $pageSize, $page);
    }

    // ── 内部工具 ────────────────────────────────────────────────────

    /**
     * 读取集合项的某个字段值。
     */
    protected function itemValue(mixed $item, string $field): mixed
    {
        if ($item instanceof Model) {
            return $item->getAttr($field);
        }

        if (is_array($item)) {
            return $item[$field] ?? null;
        }

        return null;
    }

    /**
     * 生成集合项的可比较标识，用于 diff / intersect。
     *
     * 模型优先用主键，否则用对象哈希；数组用序列化哈希；标量用字符串值。
     */
    protected function itemIdentity(mixed $item): string
    {
        if ($item instanceof Model) {
            $key = $item->getKey();

            return $key !== null ? 'm:' . $key : 'o:' . spl_object_hash($item);
        }

        if (is_object($item)) {
            return 'o:' . spl_object_hash($item);
        }

        if (is_array($item)) {
            return 'a:' . md5(serialize($item));
        }

        return 's:' . (string) $item;
    }

    /**
     * @return static<array-key, TModel>
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
