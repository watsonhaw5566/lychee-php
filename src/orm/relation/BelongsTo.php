<?php

declare(strict_types=1);

namespace Lychee\orm\relation;

use Lychee\orm\Model;

/**
 * 反向一对一（父模型持有外键）。
 *
 *   $this->belongsTo(Related::class, 'owner_id', 'id');
 *
 * foreignKey：当前模型（父）上的外键字段
 * ownerKey：目标模型（related）的本地键字段（通常是 id）
 *
 * @template TRelated of Model
 * @extends Relation<TRelated>
 */
class BelongsTo extends Relation
{
    public function getResults(): ?Model
    {
        $value = $this->parent->getAttr($this->foreignKey);
        if ($value === null) {
            return null;
        }

        return $this->query->where($this->localKey, $value)->find();
    }

    public function eagerLoad(array $models, string $name): void
    {
        $keys = $this->collectKeys($models, $this->foreignKey);
        if ($keys === []) {
            foreach ($models as $m) {
                $m->setRelation($name, null);
            }

            return;
        }

        /** @var array<int, Model> $results */
        $results = $this->query->whereIn($this->localKey, $keys)->select()->all();

        $index = [];
        foreach ($results as $r) {
            $k         = $r->getAttr($this->localKey);
            $index[$k] = $r;
        }

        foreach ($models as $m) {
            $k = $m->getAttr($this->foreignKey);
            $m->setRelation($name, $index[$k] ?? null);
        }
    }
}
