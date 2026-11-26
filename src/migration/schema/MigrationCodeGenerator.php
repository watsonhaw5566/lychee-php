<?php

declare(strict_types=1);

namespace Lychee\migration\schema;

use Lychee\migration\MigrationManager;
use RuntimeException;

/**
 * 将 {@see TableSchema} 列表渲染为迁移文件的 PHP 源码。
 *
 * 职责：
 *  - 按外键依赖做拓扑排序，保证建表顺序正确（down() 逆序删表）；
 *  - 识别规范自增主键 id，复用构建器默认行为以保持输出整洁；
 *  - 将列 / 索引 / 外键映射为 {@see \Lychee\migration\Table} 构建器的链式调用。
 */
final class MigrationCodeGenerator
{
    public function __construct(
        private readonly string $baseTimestamp = '',
    ) {
    }

    /**
     * 每张表生成一个迁移文件。
     *
     * @param  list<TableSchema> $tables
     * @return list<array{filename: string, class: string, content: string}>
     */
    public function generatePerTable(array $tables): array
    {
        $sorted  = $this->topoSort($tables);
        $baseInt = (int) ($this->baseTimestamp !== '' ? $this->baseTimestamp : date('YmdHis'));

        $files = [];
        foreach ($sorted as $i => $table) {
            $version  = sprintf('%014d', $baseInt + $i);
            $name     = 'create_' . $table->name . '_table';
            $class    = MigrationManager::toMigrationClassName($name);
            $filename = $version . '_' . $name . '.php';
            $files[]  = [
                'filename' => $filename,
                'class'    => $class,
                'content'  => $this->renderSingleTableClass($class, $table),
            ];
        }

        return $files;
    }

    /**
     * 所有表合并生成到单个迁移文件中。
     *
     * @param  list<TableSchema> $tables
     * @return array{filename: string, class: string, content: string}
     */
    public function generateAllInOne(array $tables): array
    {
        $sorted   = $this->topoSort($tables);
        $version  = $this->baseTimestamp !== '' ? $this->baseTimestamp : date('YmdHis');
        $name     = 'create_all_tables';
        $class    = MigrationManager::toMigrationClassName($name);
        $filename = $version . '_' . $name . '.php';

        return [
            'filename' => $filename,
            'class'    => $class,
            'content'  => $this->renderAllInOneClass($class, $sorted),
        ];
    }

    private function renderSingleTableClass(string $class, TableSchema $table): string
    {
        $up   = $this->indent($this->renderTableCreate($table), 8);
        $down = $this->indent(
            '$this->table(' . var_export($table->name, true) . ')->drop();',
            8
        );

        return <<<PHP
<?php

declare(strict_types=1);

use Lychee\migration\Migration;

class {$class} extends Migration
{
    public function up(): void
    {
{$up}
    }

    public function down(): void
    {
{$down}
    }
}

PHP;
    }

    /**
     * @param list<TableSchema> $sorted
     */
    private function renderAllInOneClass(string $class, array $sorted): string
    {
        $upLines = [];
        foreach ($sorted as $table) {
            $upLines[] = $this->renderTableCreate($table);
        }
        $up = $this->indent(implode("\n\n", $upLines), 8);

        $downLines = [];
        foreach (array_reverse($sorted) as $table) {
            $downLines[] = '$this->table(' . var_export($table->name, true) . ')->drop();';
        }
        $down = $this->indent(implode("\n", $downLines), 8);

        return <<<PHP
<?php

declare(strict_types=1);

use Lychee\migration\Migration;

class {$class} extends Migration
{
    public function up(): void
    {
{$up}
    }

    public function down(): void
    {
{$down}
    }
}

PHP;
    }

    /**
     * 渲染单张表的建表链式调用（不含缩进与 up() 包裹）。
     */
    private function renderTableCreate(TableSchema $table): string
    {
        [$pkColumns, $canonicalId] = $this->resolvePrimaryKey($table);

        $lines = [];

        $tableCall = '$this->table(' . var_export($table->name, true);
        $opts      = $this->renderTableOptions($table, $pkColumns, $canonicalId);
        if ($opts !== '') {
            $tableCall .= ', [' . $opts . ']';
        }
        $tableCall .= ')';
        $lines[] = $tableCall;

        foreach ($table->columns as $column) {
            if ($canonicalId && $column->name === 'id') {
                continue;
            }
            $lines[] = $this->renderAddColumn($column);
        }

        foreach ($table->indexes as $index) {
            if ($index->type === 'PRIMARY') {
                continue;
            }
            $lines[] = $this->renderAddIndex($index);
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $lines[] = $this->renderAddForeignKey($foreignKey);
        }

        if ($table->comment !== '') {
            $lines[] = '->setComment(' . var_export($table->comment, true) . ')';
        }

        $lines[] = '->create();';

        $first = array_shift($lines);

        return $first . ($lines !== [] ? "\n    " . implode("\n    ", $lines) : '');
    }

    /**
     * @return array{0: list<string>, 1: bool} [主键列列表, 是否为规范自增 id 主键]
     */
    private function resolvePrimaryKey(TableSchema $table): array
    {
        $pkColumns = [];
        foreach ($table->indexes as $index) {
            if ($index->type === 'PRIMARY') {
                $pkColumns = $index->columns;

                break;
            }
        }

        $canonicalId = false;
        if ($pkColumns === ['id']) {
            foreach ($table->columns as $column) {
                if ($column->name === 'id' && $column->autoIncrement && $this->matchesDefaultId($table, $column)) {
                    $canonicalId = true;

                    break;
                }
            }
        }

        return [$pkColumns, $canonicalId];
    }

    /**
     * 仅当 id 列与构建器默认主键行为一致时才复用默认（不重复输出 id 列与 primary_key 选项）：
     * - MySQL 默认：BIGINT UNSIGNED
     * - SQLite 默认：INTEGER
     * 否则显式输出该列以保留原始定义的类型（如 MySQL 下的 INT、有符号 BIGINT 等）。
     */
    private function matchesDefaultId(TableSchema $table, ColumnSchema $column): bool
    {
        if ($table->driver === 'sqlite') {
            return $column->type === 'integer';
        }

        return $column->type === 'biginteger' && $column->unsigned;
    }

    /**
     * @param list<string> $pkColumns
     */
    private function renderTableOptions(TableSchema $table, array $pkColumns, bool $canonicalId): string
    {
        $options = [];

        if (!$canonicalId) {
            $options[] = "'id' => false";
            if ($pkColumns !== []) {
                $cols      = array_map(static fn (string $c): string => var_export($c, true), $pkColumns);
                $options[] = "'primary_key' => [" . implode(', ', $cols) . "]";
            }
        }

        if ($table->engine !== '' && strtoupper($table->engine) !== 'INNODB') {
            $options[] = "'engine' => " . var_export($table->engine, true);
        }

        if ($table->collation !== '' && $table->collation !== 'utf8mb4_unicode_ci') {
            $options[] = "'collation' => " . var_export($table->collation, true);
        }

        return implode(', ', $options);
    }

    private function renderAddColumn(ColumnSchema $column): string
    {
        $options = $this->columnOptions($column);
        $name    = var_export($column->name, true);
        $type    = var_export($column->type, true);

        if ($options === []) {
            return "->addColumn({$name}, {$type})";
        }

        return "->addColumn({$name}, {$type}, [" . implode(', ', $options) . "])";
    }

    /**
     * @return list<string>
     */
    private function columnOptions(ColumnSchema $column): array
    {
        $options = [];

        if ($column->length !== null) {
            $options[] = "'length' => {$column->length}";
        }
        if ($column->unsigned) {
            $options[] = "'unsigned' => true";
        }
        if ($column->precision !== null) {
            $options[] = "'precision' => {$column->precision}";
        }
        if ($column->scale !== null) {
            $options[] = "'scale' => {$column->scale}";
        }
        if ($column->values !== []) {
            $values    = array_map(static fn (string $v): string => var_export($v, true), $column->values);
            $options[] = "'values' => [" . implode(', ', $values) . "]";
        }
        if ($column->nullable) {
            $options[] = "'null' => true";
        }
        if ($column->hasDefault) {
            $options[] = "'default' => " . $this->renderDefault($column->default);
        }
        if ($column->autoIncrement) {
            $options[] = "'auto_increment' => true";
        }
        if ($column->comment !== '') {
            $options[] = "'comment' => " . var_export($column->comment, true);
        }
        if ($column->onUpdateCurrentTimestamp) {
            $options[] = "'update' => 'CURRENT_TIMESTAMP'";
        }

        return $options;
    }

    private function renderAddIndex(IndexSchema $index): string
    {
        $columns = array_map(static fn (string $c): string => var_export($c, true), $index->columns);
        $options = [];

        if ($index->type !== 'INDEX') {
            $options[] = "'type' => " . var_export($index->type, true);
        }
        $options[] = "'name' => " . var_export($index->name, true);

        return "->addIndex([" . implode(', ', $columns) . "], [" . implode(', ', $options) . "])";
    }

    private function renderAddForeignKey(ForeignKeySchema $foreignKey): string
    {
        $columns   = array_map(static fn (string $c): string => var_export($c, true), $foreignKey->columns);
        $refCols   = array_map(static fn (string $c): string => var_export($c, true), $foreignKey->refColumns);
        $refTable  = var_export($foreignKey->refTable, true);
        $options   = [];
        $options[] = "'name' => " . var_export($foreignKey->name, true);
        if ($foreignKey->onDelete !== null) {
            $options[] = "'onDelete' => " . var_export($foreignKey->onDelete, true);
        }
        if ($foreignKey->onUpdate !== null) {
            $options[] = "'onUpdate' => " . var_export($foreignKey->onUpdate, true);
        }

        $cols = '[' . implode(', ', $columns) . ']';
        $refs = '[' . implode(', ', $refCols) . ']';

        return "->addForeignKey({$cols}, {$refTable}, {$refs}, [" . implode(', ', $options) . "])";
    }

    private function renderDefault(mixed $default): string
    {
        if (is_string($default) && strtoupper($default) === 'CURRENT_TIMESTAMP') {
            return "'CURRENT_TIMESTAMP'";
        }

        return var_export($default, true);
    }

    private function indent(string $content, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);

        return $pad . str_replace("\n", "\n" . $pad, $content);
    }

    /**
     * 按外键依赖拓扑排序：被引用的表排在前面。
     *
     * @param  list<TableSchema> $tables
     * @return list<TableSchema>
     */
    private function topoSort(array $tables): array
    {
        $byName = [];
        foreach ($tables as $table) {
            $byName[$table->name] = $table;
        }

        /** @var array<string, array<string, string>> $deps */
        $deps = [];
        foreach ($tables as $table) {
            $deps[$table->name] = [];
            foreach ($table->foreignKeys as $foreignKey) {
                if ($foreignKey->refTable !== $table->name && isset($byName[$foreignKey->refTable])) {
                    $deps[$table->name][$foreignKey->refTable] = $foreignKey->refTable;
                }
            }
        }

        $visited = [];
        foreach ($tables as $table) {
            $visited[$table->name] = 0;
        }

        $sorted = [];
        $visit  = function (string $name) use (&$visit, &$sorted, &$visited, $deps, $byName): void {
            if ($visited[$name] === 2) {
                return;
            }
            if ($visited[$name] === 1) {
                throw new RuntimeException("Circular foreign key dependency detected at table '{$name}'.");
            }

            $visited[$name] = 1;
            foreach ($deps[$name] as $dependency) {
                $visit($dependency);
            }
            $visited[$name] = 2;
            $sorted[]       = $byName[$name];
        };

        foreach ($tables as $table) {
            if ($visited[$table->name] === 0) {
                $visit($table->name);
            }
        }

        return $sorted;
    }
}
