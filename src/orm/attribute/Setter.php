<?php

declare(strict_types=1);

namespace Lychee\orm\attribute;

use Attribute;

/**
 * 写入修改器方法 Attribute。
 *
 * 标注在模型方法上，声明该方法为某个字段的写入修改器，取代依赖
 * `setXxxAttr` 命名约定的魔术式检测。
 *
 * 推荐用法（方法名直接等于字段名，最简洁）：
 *   class User extends Model {
 *       #[Column(type: 'string')]
 *       protected string $password;
 *
 *       #[Setter]
 *       public function password($value, array $data): string {
 *           return password_hash((string) $value, PASSWORD_DEFAULT);
 *       }
 *   }
 *
 * 也支持 setXxx / setXxxAttr 形式自动推导，或显式指定字段名：
 *   #[Setter('password')]
 *   public function hashPassword($value, array $data): string { ... }
 *
 * 方法签名：
 *   - $value：待写入的原始值
 *   - $data：模型全部原始数据
 *   - 返回值：经修改器处理后的值（会再经过 writeTransform 类型转换）
 *
 * 未标注 `#[Setter]` 的 `setXxxAttr` 方法仍按命名约定生效（向后兼容）。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Setter
{
    /**
     * @param string|null $field 绑定的字段名；为 null 时从方法名自动推导。
     *                           支持：方法名即字段名 / setXxx / setXxxAttr。
     */
    public function __construct(
        public readonly ?string $field = null,
    ) {
    }
}
