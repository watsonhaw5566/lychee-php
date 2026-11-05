<?php

declare(strict_types=1);

namespace Lychee\orm\attribute;

use Attribute;

/**
 * 读取访问器方法 Attribute。
 *
 * 标注在模型方法上，声明该方法为某个字段的读取访问器，取代依赖
 * `getXxxAttr` 命名约定的魔术式检测，使 IDE 可搜索、可显式绑定字段。
 *
 * 推荐用法（方法名直接等于字段名，最简洁）：
 *   class User extends Model {
 *       #[Append]
 *       protected string $fullName;
 *
 *       #[Getter]
 *       public function fullName($value, array $data): string {
 *           return $data['first_name'] . ' ' . $data['last_name'];
 *       }
 *   }
 *
 * 也支持 getXxx / getXxxAttr 形式自动推导，或显式指定字段名：
 *   #[Getter('fullName')]
 *   public function computeFullName($value, array $data): string { ... }
 *
 * 方法签名：
 *   - $value：字段原始值（已经过 readTransform 类型转换）
 *   - $data：模型全部原始数据
 *   - 返回值：访问器计算结果
 *
 * 未标注 `#[Getter]` 的 `getXxxAttr` 方法仍按命名约定生效（向后兼容）。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Getter
{
    /**
     * @param string|null $field 绑定的字段名；为 null 时从方法名自动推导。
     *                           支持：方法名即字段名 / getXxx / getXxxAttr。
     */
    public function __construct(
        public readonly ?string $field = null,
    ) {
    }
}
