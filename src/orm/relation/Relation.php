<?php

declare(strict_types=1);

namespace Lychee\orm\relation;

use Lychee\orm\Collection;
use Lychee\orm\Model;
use Lychee\orm\Query;

/**
 * 关联定义基类。
 *
 * 关联对象既是「关联描述」也是「查询构造器」：
 *   $user->orders()->where(...)->order(...)->select()
 *
 * 子类（HasOne / HasMany / BelongsTo）负责定义：
 * - getResults()：lazy 加载时取结果
 * - eagerLoad()：预加载批量匹配
 *
 * @template TRelated of Model
 */
abstract class Relation
{
    protected Query $query;

    protected Model $relatedInstance;

    public function __construct(
        protected Model $parent,
        protected string $related,
        protected string $foreignKey,
        protected string $localKey,
    ) {
        /** @var class-string<Model> $related */
        $this->relatedInstance = new $this->related();
        $this->query           = $this->relatedInstance->newQuery();
    }

    public function getQuery(): Query
    {
        return $this->query;
    }

    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    public function getLocalKey(): string
    {
        return $this->localKey;
    }

    public function getRelatedInstance(): Model
    {
        return $this->relatedInstance;
    }

    /**
     * 关联查询包含已软删除的记录。
     *
     * 用法：$this->belongsTo(DrawLots::class)->withTrashed()
     */
    public function withTrashed(): static
    {
        $this->query->withTrashed();

        return $this;
    }

    /**
     * 关联查询仅包含已软删除的记录。
     */
    public function onlyTrashed(): static
    {
        $this->query->onlyTrashed();

        return $this;
    }

    /**
     * 把关联对象的链式调用全部转发给内部 Query。
     */
    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->query->{$method}(...$arguments);

        // 链式方法返回 Query 自身时，转回当前 Relation
        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }

    /**
     * lazy 加载当前关联。
     *
     * @return TRelated|Collection<array-key, TRelated>|null
     */
    abstract public function getResults(): mixed;

    /**
     * 预加载批量关联。
     *
     * @param array<int, Model> $models 父模型列表
     * @param string            $name   关联在父模型上的属性名
     */
    abstract public function eagerLoad(array $models, string $name): void;

    /**
     * 从父模型列表抽出关联键集合。
     *
     * @param array<int, Model> $models
     * @return array<int, mixed>
     */
    protected function collectKeys(array $models, string $key): array
    {
        $values = [];
        foreach ($models as $m) {
            $v = $m->getAttr($key);
            if ($v !== null) {
                $values[] = $v;
            }
        }

        return array_values(array_unique($values, SORT_REGULAR));
    }
}
