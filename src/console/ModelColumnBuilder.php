<?php

declare(strict_types=1);

namespace Lychee\console;

use Lychee\orm\ConnectionManager;
use Throwable;
use DateTimeImmutable;

/**
 * 从数据库表结构生成模型 `#[Column]` 属性声明的辅助 trait。
 *
 * 被 MakeModelCommand 与 MakeRestCommand 复用，实现
 * `php lee make:model User --table=users` 这类反向生成能力。
 */
trait ModelColumnBuilder
{
    /**
     * 根据数据库字段元信息生成 `#[Column(...)]` 标注的属性声明代码。
     *
     * @param array<string, array{name:string,type:string,notnull:bool,default:mixed,primary:bool,autoinc:bool}> $fields
     * @return array{use: string, columns: string}
     */
    protected function buildColumnDeclarations(array $fields): array
    {
        $lines      = [];
        $needColumn = false;

        // 约定俗成的通用字段：由 ORM 自动管理（主键、时间戳、软删除），
        // 无需在每个模型中重复声明 #[Column]。
        $skipFields = ['create_time', 'update_time', 'delete_time'];

        foreach ($fields as $field) {
            // 跳过自增主键（由 ORM 自动管理，无需声明）
            if (!empty($field['autoinc'])) {
                continue;
            }

            $name = $field['name'];

            // 跳过约定俗成的时间戳与软删除字段
            if (in_array($name, $skipFields, true)) {
                continue;
            }

            $dbType  = strtolower(trim((string) ($field['type'] ?? '')));
            $notnull = (bool) ($field['notnull'] ?? true);
            $default = $field['default'] ?? null;

            $mapping = $this->mapDbTypeToColumn($dbType);
            $phpType = $mapping['phpType'];
            $colType = $mapping['columnType'];  // null 表示无需 #[Column] 注解

            if ($colType !== null) {
                $needColumn = true;
            }

            $nullable    = $notnull ? '' : '?';
            $phpTypeHint = $nullable . $phpType;

            $annotation = '';
            if ($colType !== null) {
                $args = ["type: {$colType}"];
                if ($default !== null && $default !== 'CURRENT_TIMESTAMP') {
                    $args[] = 'default: ' . $this->renderDefaultValue($colType, $default);
                }
                $annotation = "    #[Column(" . implode(', ', $args) . ")]\n";
            }

            $lines[] = $annotation . "    protected {$phpTypeHint} \${$name};";
        }

        return [
            'use'     => $needColumn ? "use Lychee\\orm\\attribute\\Column;" : '',
            'columns' => $lines === [] ? '' : implode("\n\n", $lines),
        ];
    }

    /**
     * 数据库类型 → PHP 属性类型 + #[Column] type 字符串。
     *
     * @return array{phpType: string, columnType: ?string} columnType 为 null 表示无需注解
     */
    protected function mapDbTypeToColumn(string $dbType): array
    {
        // 去除括号及之后的内容用于匹配基础类型，但保留原始串提取精度
        $base = preg_replace('/\(.*/', '', $dbType);

        // 布尔：tinyint(1)
        if (preg_match('/^tinyint\(1\)/', $dbType)) {
            return ['phpType' => 'bool', 'columnType' => "'bool'"];
        }

        // 整数类
        if (in_array($base, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true)) {
            return ['phpType' => 'int', 'columnType' => "'int'"];
        }

        // 浮点
        if (in_array($base, ['float', 'double', 'real'], true)) {
            return ['phpType' => 'float', 'columnType' => "'float'"];
        }

        // 定点小数：decimal(10,2) → decimal:2
        if (str_starts_with($base, 'decimal') || str_starts_with($base, 'numeric')) {
            $scale = $this->extractDecimalScale($dbType);

            return ['phpType' => 'float', 'columnType' => "'decimal:{$scale}'"];
        }

        // JSON
        if ($base === 'json') {
            return ['phpType' => 'array', 'columnType' => "'json'"];
        }

        // 日期
        if ($base === 'date') {
            return ['phpType' => '\\' . DateTimeImmutable::class, 'columnType' => "'date:Y-m-d'"];
        }

        // 日期时间
        if (in_array($base, ['datetime', 'timestamp'], true)) {
            return ['phpType' => '\\' . DateTimeImmutable::class, 'columnType' => "'datetime'"];
        }

        // 其余字符串类（varchar/char/text/enum/blob/time 等）无需 #[Column] 注解
        return ['phpType' => 'string', 'columnType' => null];
    }

    /**
     * 从 decimal(10,2) 中提取小数位数，默认 2。
     */
    protected function extractDecimalScale(string $dbType): int
    {
        if (preg_match('/\(\s*\d+\s*,\s*(\d+)\s*\)/', $dbType, $m)) {
            return (int) $m[1];
        }

        return 2;
    }

    /**
     * 将数据库默认值渲染为 #[Column] 的 PHP 表达式。
     */
    protected function renderDefaultValue(string $columnType, mixed $default): string
    {
        // SQLite 的 dflt_value 对字符串/数值默认值会带单引号（如 '0.00'、'active'），
        // MySQL 的 Default 则不带引号。统一去除首尾单引号以兼容两种数据库。
        $default = trim((string) $default);
        if (strlen($default) >= 2 && $default[0] === "'" && $default[strlen($default) - 1] === "'") {
            $default = substr($default, 1, -1);
        }

        // JSON 默认值
        if ($columnType === "'json'") {
            $trimmed = trim($default);
            if ($trimmed === '[]' || $trimmed === '') {
                return '[]';
            }
            if ($trimmed === '{}') {
                return '(object) []';
            }

            return '[]';
        }

        // 整型：转为 int
        if ($columnType === "'int'") {
            return var_export((int) $default, true);
        }

        // 布尔：转为 bool
        if ($columnType === "'bool'") {
            return var_export((bool) $default, true);
        }

        // decimal: 保留字符串形式
        if (str_starts_with($columnType, "'decimal:")) {
            return var_export((string) $default, true);
        }

        // float
        if ($columnType === "'float'") {
            return var_export((float) $default, true);
        }

        // date / datetime / 字符串
        return var_export((string) $default, true);
    }

    /**
     * 根据 --table 选项解析 #[Column] 声明。
     *
     * 会自动拼接连接配置的 prefix（如 `ly_`），因此用户传入的是逻辑表名（无前缀）。
     * 读取失败时降级为无注解生成，不阻断命令。
     *
     * @return array{use:string, columns:string}
     */
    protected function resolveColumns(?string $table, Output $output): array
    {
        if ($table === null || $table === false || $table === '') {
            return ['use' => '', 'columns' => ''];
        }

        try {
            /** @var ConnectionManager $manager */
            $manager    = $this->app->get('db');
            $connection = $manager->connect();

            // 自动拼接连接配置的表前缀，用户只需传逻辑表名
            $prefix    = (string) $connection->getConfig('prefix', '');
            $realTable = $prefix . $table;

            $fields = $connection->getFields($realTable);
        } catch (Throwable $e) {
            $output->writeln("<comment>Warning: cannot read table '{$table}' ({$e->getMessage()}). Model created without #[Column] annotations.</comment>");

            return ['use' => '', 'columns' => ''];
        }

        return $this->buildColumnDeclarations($fields);
    }
}
