<?php

declare(strict_types=1);

namespace Lychee\orm\attribute;

use Attribute;

/**
 * 字段列声明 Attribute，用于取代模型的 `$type` / `$json` 配置。
 *
 * 标注在模型属性上，声明该字段的类型转换规则。支持参数化类型与枚举类：
 *
 *   // 基础类型
 *   #[Column(type: 'int')]
 *   protected int $id;
 *
 *   // 精度小数（读取为 float，JSON 输出数字，兼容前端数字输入组件）
 *   #[Column(type: 'decimal:2')]
 *   protected float $balance;        // 读为 99.5
 *
 *   // 日期格式（读取为 DateTimeImmutable）
 *   #[Column(type: 'date:Y-m-d')]
 *   protected ?\DateTimeImmutable $birthday;
 *
 *   // 枚举类（读取为枚举实例）
 *   #[Column(type: UserStatus::class)]
 *   protected UserStatus $status;
 *
 *   // JSON 字段（快捷方式，等价 type: 'json'）
 *   #[Column(json: true)]
 *   protected array $extra;
 *
 *   // 默认值：INSERT 时若字段未被显式赋值，则自动填充
 *   #[Column(type: UserStatus::class, default: UserStatus::Active)]
 *   protected UserStatus $status;
 *
 *   #[Column(type: 'decimal:2', default: '0.00')]
 *   protected string $balance;
 *
 *   #[Column(json: true, default: [])]
 *   protected array $tags;
 *
 * 类型解析优先级：`#[Column]` > 模型 `$type` > 模型 `$json` > 数据库表结构自动推导。
 *
 * 支持的 type 值：
 *   - 基础：int / integer / float / double / bool / boolean / string / array / object / json / timestamp / datetime
 *   - 参数化：decimal:{scale}、date:{format}、datetime:{format}
 *   - 枚举：直接传枚举类名（如 UserStatus::class），需为 PHP 8.1+ Enum
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    /**
     * @param string|null $type    字段类型，支持参数化写法（decimal:2）与枚举类名
     * @param bool        $json    是否为 JSON 字段（等价 type='json' 的快捷方式）
     * @param mixed       $default INSERT 时的默认值；仅在字段未被显式赋值时生效，
     *                             会经过 writeTransform 转换（如枚举取 value、数组转 JSON）
     */
    public function __construct(
        public ?string $type = null,
        public bool $json = false,
        public mixed $default = null,
    ) {
    }
}
