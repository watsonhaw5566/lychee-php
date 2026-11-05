<?php

declare(strict_types=1);

namespace Lychee\orm\attribute;

use Attribute;

/**
 * 虚拟追加字段 Attribute，用于取代模型的 `$append` 配置。
 *
 * 支持两种声明形式：
 *
 * 形式一（简洁，推荐）：标注在方法上，方法名即字段名，方法本身即为访问器。
 *   class User extends Model {
 *       #[Append]
 *       public function fullName($value, array $data): string {
 *           return $data['first_name'] . ' ' . $data['last_name'];
 *       }
 *   }
 *
 * 形式二（显式）：标注在属性上，需配合 #[Getter] 或 getXxxAttr 提供值。
 *   class User extends Model {
 *       #[Append]
 *       protected string $fullName;
 *
 *       #[Getter]
 *       public function fullName($value, array $data): string { ... }
 *   }
 *
 * 指定输出字段名（如希望输出为下划线 full_name）：
 *   #[Append(name: 'full_name')]
 *   public function fullName($value, array $data): string { ... }
 *
 * 标注在关联方法上时，该关联会在查询时自动预加载并随 toArray() 输出：
 *   #[Append]
 *   public function posts(): HasMany { ... }
 *
 * 字段值在 `toArray()` / JSON 序列化时自动追加到输出中。
 *
 * 与 `$append` 的关系：
 *   - `#[Append]` 声明的字段始终随 `toArray()` 输出
 *   - 运行时 `$model->append(['extra'])` 仍可追加额外字段
 *   - 两者取并集
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
class Append
{
    /**
     * @param string|null $name 输出字段名；为 null 时使用属性/方法名。
     *                          例如方法 fullName 配合 name: 'full_name' 可输出下划线字段。
     */
    public function __construct(
        public readonly ?string $name = null,
    ) {
    }
}
