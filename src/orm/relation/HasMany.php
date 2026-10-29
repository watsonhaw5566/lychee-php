<?php

declare(strict_types=1);

namespace Lychee\orm\relation;

use Lychee\orm\Collection;
use Lychee\orm\Model;

/**
 * 一对多关联。
 *
 *   $this->hasMany(Related::class, 'parent_id', 'id');
 *
 * @template TRelated of Model
 * @extends Relation<TRelated>
 */
class HasMany extends Relation
{
    public function getResults(): Collection
    {
        $value = $this->parent->getAttr($this->localKey);
        if ($value === null) {
            return new Collection();
        }

        /** @var Collection<array-key, Model> $result */
        $result = $this->query->where($this->foreignKey, $value)->select();
        if (is_array($result)) {
            $result = new Collection($result);
        }

        return $result;
    }

    public function eagerLoad(array $models, string $name): void
    {
        $keys = $this->collectKeys($models, $this->localKey);
        if ($keys === []) {
            foreach ($models as $m) {
                $m->setRelation($name, new Collection());
            }

            return;
        }

        /** @var array<int, Model> $results */
        $results = $this->query->whereIn($this->foreignKey, $keys)->select()->all();

        $grouped = [];
        foreach ($results as $r) {
            $k = $r->getAttr($this->foreignKey);
            $grouped[$k] ??= [];
            $grouped[$k][] = $r;
        }

        foreach ($models as $m) {
            $k = $m->getAttr($this->localKey);
            $m->setRelation($name, new Collection($grouped[$k] ?? []));
        }
    }
}
