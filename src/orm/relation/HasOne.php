<?php

declare(strict_types=1);

namespace Lychee\orm\relation;

use Lychee\orm\Model;

/**
 * 一对一关联（父模型持有外键的反向在子模型上）。
 *
 *   $this->hasOne(Related::class, 'parent_id', 'id');
 *
 * foreignKey：子表外键字段
 * localKey：父表本地键字段（通常是 id）
 *
 * @template TRelated of Model
 * @extends Relation<TRelated>
 */
class HasOne extends Relation
{
    public function getResults(): ?Model
    {
        $value = $this->parent->getAttr($this->localKey);
        if ($value === null) {
            return null;
        }

        return $this->query->where($this->foreignKey, $value)->find();
    }

    public function eagerLoad(array $models, string $name): void
    {
        $keys = $this->collectKeys($models, $this->localKey);
        if ($keys === []) {
            foreach ($models as $m) {
                $m->setRelation($name, null);
            }

            return;
        }

        /** @var array<int, Model> $results */
        $results = $this->query->whereIn($this->foreignKey, $keys)->select()->all();

        $index = [];
        foreach ($results as $r) {
            $k         = $r->getAttr($this->foreignKey);
            $index[$k] = $r;
        }

        foreach ($models as $m) {
            $k = $m->getAttr($this->localKey);
            $m->setRelation($name, $index[$k] ?? null);
        }
    }
}
