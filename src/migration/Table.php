<?php

declare(strict_types=1);

namespace Lychee\migration;

use PDO;

/**
 * 数据表构建器。
 *
 * 通过链式 API 生成 DDL SQL，支持建表、改表、加字段、加索引等操作。
 * 根据 PDO 驱动类型自动适配 MySQL / SQLite 语法。
 */
class Table
{
    /** @var array<int, array{name: string, type: string, options: array<string, mixed>}> */
    private array $columns = [];

    /** @var array<int, array{columns: array<int,string>, options: array<string, mixed>}> */
    private array $indexes = [];

    /** @var array<string, string> */
    private array $options = [
        'engine'      => 'InnoDB',
        'collation'   => 'utf8mb4_unicode_ci',
        'comment'     => '',
        'id'          => 'id',
        'primary_key' => 'id',
    ];

    /** @var array<string, mixed> */
    private array $pendingChanges = [];

    private readonly string $driverName;

    public function __construct(
        private readonly string $name,
        private readonly PDO    $pdo,
        array                   $options = [],
        private readonly string $prefix = '',
    ) {
        $this->driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->options    = array_merge($this->options, $options);
    }

    /**
     * 创建表。
     */
    public function create(): void
    {
        $columns   = [];
        $idColumn  = $this->options['id'] ?? null;
        $pkColumns = !empty($this->options['primary_key']) ? (array)$this->options['primary_key'] : [];

        if (!empty($idColumn)) {
            if ($this->isSqlite()) {
                // SQLite 自增主键必须使用 INTEGER PRIMARY KEY
                $columns[] = sprintf('`%s` INTEGER PRIMARY KEY', $idColumn);
            } else {
                $columns[] = sprintf(
                    '`%s` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                    $idColumn
                );
            }
        }

        foreach ($this->columns as $col) {
            $columns[] = $this->buildColumnDefinition($col['name'], $col['type'], $col['options']);
        }

        // SQLite 中若 id 列已是 INTEGER PRIMARY KEY，则无需再声明 PRIMARY KEY
        $addPrimaryKey = !empty($pkColumns);
        if ($this->isSqlite() && $idColumn && count($pkColumns) === 1 && $pkColumns[0] === $idColumn) {
            $addPrimaryKey = false;
        }

        if ($addPrimaryKey) {
            $columns[] = 'PRIMARY KEY (' . implode(', ', array_map(fn ($c) => "`{$c}`", $pkColumns)) . ')';
        }

        // 内联唯一约束（MySQL / SQLite 均支持 UNIQUE(col)）
        $regularIndexes = [];
        foreach ($this->indexes as $idx) {
            $type = strtoupper((string)($idx['options']['type'] ?? 'INDEX'));

            if ($type === 'UNIQUE') {
                $cols      = implode(', ', array_map(fn ($c) => "`{$c}`", $idx['columns']));
                $columns[] = "UNIQUE ({$cols})";
            } elseif ($type === 'PRIMARY') {
                // 已通过 primary_key 选项处理
                continue;
            } else {
                $regularIndexes[] = $idx;
            }
        }

        $sql = sprintf('CREATE TABLE IF NOT EXISTS `%s` (%s)', $this->getTableName(), implode(', ', $columns));

        if (!$this->isSqlite()) {
            $sql .= sprintf(
                ' ENGINE=%s DEFAULT CHARSET=utf8mb4 COLLATE=%s',
                $this->options['engine'],
                $this->options['collation']
            );

            if (!empty($this->options['comment'])) {
                $sql .= " COMMENT='" . addslashes((string)$this->options['comment']) . "'";
            }
        }

        $this->pdo->exec($sql);

        // 普通索引通过 CREATE INDEX 单独创建（MySQL / SQLite 通用）
        foreach ($regularIndexes as $idx) {
            $this->createIndex($idx['columns'], $idx['options']);
        }
    }

    /**
     * 删除表。
     */
    public function drop(): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS `{$this->getTableName()}`");
    }

    /**
     * 判断表是否存在。
     */
    public function exists(): bool
    {
        if ($this->isSqlite()) {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = ?"
            );
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
            );
        }

        $stmt->execute([$this->getTableName()]);

        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * 添加列。
     *
     * @param string $name
     * @param string $type
     * @param array<string, mixed> $options
     */
    public function addColumn(string $name, string $type, array $options = []): static
    {
        $this->columns[] = compact('name', 'type', 'options');

        return $this;
    }

    /**
     * 修改列。
     *
     * @param string $name
     * @param string $type
     * @param array<string, mixed> $options
     */
    public function changeColumn(string $name, string $type, array $options = []): static
    {
        $this->pendingChanges[] = ['type' => 'change', 'name' => $name, 'columnType' => $type, 'options' => $options];

        return $this;
    }

    /**
     * 删除列。
     */
    public function removeColumn(string $name): static
    {
        $this->pendingChanges[] = ['type' => 'drop', 'name' => $name];

        return $this;
    }

    /**
     * 重命名列。
     */
    public function renameColumn(string $from, string $to): static
    {
        $this->pendingChanges[] = ['type' => 'rename', 'from' => $from, 'to' => $to];

        return $this;
    }

    /**
     * 添加索引。
     *
     * @param array<int, string>|string $columns
     * @param array<string, mixed> $options
     */
    public function addIndex(array|string $columns, array $options = []): static
    {
        $this->indexes[] = [
            'columns' => (array)$columns,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * 删除索引。
     */
    public function removeIndex(string $name): static
    {
        $this->pendingChanges[] = ['type' => 'drop_index', 'name' => $name];

        return $this;
    }

    /**
     * 添加 create_time / update_time 时间戳字段。
     */
    public function addTimestamps(string $createTime = 'create_time', string $updateTime = 'update_time'): static
    {
        $this->addColumn($createTime, 'timestamp', ['null' => false, 'default' => 'CURRENT_TIMESTAMP']);
        $this->addColumn($updateTime, 'timestamp', [
            'null'    => true,
            'default' => 'CURRENT_TIMESTAMP',
            'update'  => 'CURRENT_TIMESTAMP',
        ]);

        return $this;
    }

    /**
     * 添加软删除字段 delete_time。
     */
    public function addSoftDelete(string $name = 'delete_time'): static
    {
        $this->addColumn($name, 'timestamp', ['null' => true]);

        return $this;
    }

    /**
     * 设置表引擎。
     */
    public function setEngine(string $engine): static
    {
        $this->options['engine'] = $engine;

        return $this;
    }

    /**
     * 设置表注释。
     */
    public function setComment(string $comment): static
    {
        $this->options['comment'] = $comment;

        return $this;
    }

    /**
     * 设置主键字段（设为 false 则不自动创建 id 主键）。
     */
    public function setPrimaryKey(string|false $key): static
    {
        if ($key === false) {
            $this->options['id']          = null;
            $this->options['primary_key'] = null;
        } else {
            $this->options['id']          = $key;
            $this->options['primary_key'] = $key;
        }

        return $this;
    }

    /**
     * 提交所有挂起的变更（针对已存在的表）。
     */
    public function update(): void
    {
        if ($this->isSqlite()) {
            $this->updateSqlite();

            return;
        }

        $parts = [];

        foreach ($this->pendingChanges as $change) {
            $parts[] = match ($change['type']) {
                'change'     => sprintf('CHANGE `%s` %s', $change['name'], $this->buildColumnDefinition($change['name'], $change['columnType'], $change['options'])),
                'drop'       => sprintf('DROP `%s`', $change['name']),
                'rename'     => sprintf('CHANGE `%s` `%s`', $change['from'], $change['to']),
                'drop_index' => sprintf('DROP INDEX `%s`', $change['name']),
                default      => '',
            };
        }

        foreach ($this->columns as $col) {
            $parts[] = 'ADD ' . $this->buildColumnDefinition($col['name'], $col['type'], $col['options']);
        }

        foreach ($this->indexes as $idx) {
            $parts[] = 'ADD ' . $this->buildIndexDefinition($idx['columns'], $idx['options']);
        }

        if (empty($parts)) {
            return;
        }

        $this->pdo->exec(sprintf('ALTER TABLE `%s` %s', $this->getTableName(), implode(', ', $parts)));
    }

    /**
     * SQLite 下的表结构更新。
     *
     * SQLite 的 ALTER TABLE 仅支持 RENAME / ADD COLUMN / DROP COLUMN / RENAME COLUMN，
     * 修改列类型（change）需要通过"新建表-拷贝数据-替换"的方式完成。
     */
    private function updateSqlite(): void
    {
        $hasChange = false;
        foreach ($this->pendingChanges as $change) {
            if ($change['type'] === 'change') {
                $hasChange = true;

                break;
            }
        }

        if ($hasChange) {
            $this->recreateTableForSqlite();
        } else {
            foreach ($this->pendingChanges as $change) {
                match ($change['type']) {
                    'drop'       => $this->pdo->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $this->getTableName(), $change['name'])),
                    'rename'     => $this->pdo->exec(sprintf('ALTER TABLE `%s` RENAME COLUMN `%s` TO `%s`', $this->getTableName(), $change['from'], $change['to'])),
                    'drop_index' => $this->pdo->exec(sprintf('DROP INDEX IF EXISTS `%s`', $change['name'])),
                    default      => null,
                };
            }

            foreach ($this->columns as $col) {
                $this->pdo->exec(sprintf(
                    'ALTER TABLE `%s` ADD COLUMN %s',
                    $this->getTableName(),
                    $this->buildColumnDefinition($col['name'], $col['type'], $col['options'])
                ));
            }
        }

        foreach ($this->indexes as $idx) {
            $this->createIndex($idx['columns'], $idx['options']);
        }
    }

    /**
     * SQLite 修改列时通过重建表实现。
     */
    private function recreateTableForSqlite(): void
    {
        $tableName   = $this->getTableName();
        $stmt        = $this->pdo->query(sprintf('PRAGMA table_info(`%s`)', $tableName));
        $currentCols = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // 收集各类挂起变更
        $drops   = [];
        $renames = [];
        $changes = [];
        foreach ($this->pendingChanges as $change) {
            match ($change['type']) {
                'drop'   => $drops[$change['name']]   = true,
                'rename' => $renames[$change['from']] = $change['to'],
                'change' => $changes[$change['name']] = $change,
                default  => null,
            };
        }

        $newColumns    = []; // 新表的全部列定义
        $targetColumns = []; // INSERT 目标列名（新表中的列名）
        $sourceColumns = []; // SELECT 源列名（旧表中的列名）

        foreach ($currentCols as $col) {
            $name = $col['name'];

            // 被删除的列跳过
            if (isset($drops[$name])) {
                continue;
            }

            // 确定新列名（可能被重命名）
            $newName = $renames[$name] ?? $name;

            if (isset($changes[$name])) {
                $newColumns[] = $this->buildColumnDefinition($newName, $changes[$name]['columnType'], $changes[$name]['options']);
            } else {
                $type         = $col['type'] ?: 'TEXT';
                $notNull      = (int)$col['notnull'] === 1 ? 'NOT NULL' : '';
                $default      = $col['dflt_value'] !== null ? 'DEFAULT ' . $col['dflt_value'] : '';
                $newColumns[] = trim("`{$newName}` {$type} {$notNull} {$default}");
            }

            $targetColumns[] = "`{$newName}`";
            $sourceColumns[] = "`{$name}`";
        }

        // 追加新增的列（旧表中不存在，不参与数据拷贝，由默认值兜底）
        $existingNames = array_column($currentCols, 'name');
        foreach ($this->columns as $col) {
            if (in_array($col['name'], $existingNames, true)) {
                continue;
            }
            $newColumns[] = $this->buildColumnDefinition($col['name'], $col['type'], $col['options']);
        }

        $tempName = $tableName . '_temp_' . time();

        $this->pdo->exec(sprintf('CREATE TABLE `%s` (%s)', $tempName, implode(', ', $newColumns)));
        $this->pdo->exec(sprintf(
            'INSERT INTO `%s` (%s) SELECT %s FROM `%s`',
            $tempName,
            implode(', ', $targetColumns),
            implode(', ', $sourceColumns),
            $tableName
        ));
        $this->pdo->exec(sprintf('DROP TABLE `%s`', $tableName));
        $this->pdo->exec(sprintf('ALTER TABLE `%s` RENAME TO `%s`', $tempName, $tableName));
    }

    /**
     * 创建索引（MySQL / SQLite 通用）。
     *
     * @param array<int, string> $columns
     * @param array<string, mixed> $options
     */
    private function createIndex(array $columns, array $options): void
    {
        $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
        $type = strtoupper((string)($options['type'] ?? 'INDEX'));
        $name = $options['name'] ?? implode('_', $columns) . '_index';

        $unique      = $type === 'UNIQUE' ? 'UNIQUE ' : '';
        $ifNotExists = $this->isSqlite() ? 'IF NOT EXISTS ' : '';

        $this->pdo->exec(sprintf(
            'CREATE %sINDEX %s`%s` ON `%s` (%s)',
            $unique,
            $ifNotExists,
            $name,
            $this->getTableName(),
            $cols
        ));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildColumnDefinition(string $name, string $type, array $options): string
    {
        $sqlType = $this->mapType($type, $options);
        $def     = "`{$name}` {$sqlType}";

        if (!$this->isSqlite() && !empty($options['unsigned'])) {
            $def .= ' UNSIGNED';
        }

        if (!empty($options['null'])) {
            $def .= ' NULL';
        } else {
            $def .= ' NOT NULL';
        }

        if (array_key_exists('default', $options)) {
            $default = $options['default'];
            if (strtoupper((string)$default) === 'CURRENT_TIMESTAMP') {
                $def .= ' DEFAULT CURRENT_TIMESTAMP';
            } elseif ($default === null) {
                $def .= ' DEFAULT NULL';
            } else {
                $def .= " DEFAULT '" . addslashes((string)$default) . "'";
            }
        }

        if (!$this->isSqlite() && !empty($options['auto_increment'])) {
            $def .= ' AUTO_INCREMENT';
        }

        if (!$this->isSqlite() && !empty($options['comment'])) {
            $def .= " COMMENT '" . addslashes((string)$options['comment']) . "'";
        }

        if (!$this->isSqlite() && !empty($options['update']) && strtoupper((string)$options['update']) === 'CURRENT_TIMESTAMP') {
            $def .= ' ON UPDATE CURRENT_TIMESTAMP';
        }

        return $def;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function mapType(string $type, array $options): string
    {
        $length = $options['length'] ?? null;
        $lower  = strtolower($type);

        if ($this->isSqlite()) {
            return match ($lower) {
                'string', 'varchar'                                                                            => 'VARCHAR(' . ($length ?? 255) . ')',
                'char'                                                                                         => 'CHAR(' . ($length ?? 255) . ')',
                'text', 'mediumtext', 'longtext'                                                               => 'TEXT',
                'integer', 'int', 'biginteger', 'bigint', 'smallinteger', 'smallint', 'tinyinteger', 'tinyint' => 'INTEGER',
                'float', 'double'                                                                              => 'REAL',
                'decimal'                                                                                      => 'NUMERIC',
                'boolean', 'bool'                                                                              => 'INTEGER',
                'date', 'datetime', 'timestamp', 'time'                                                        => strtoupper($lower),
                'json'                                                                                         => 'TEXT',
                'enum'                                                                                         => 'TEXT',
                'binary'                                                                                       => 'BLOB',
                'uuid'                                                                                         => 'CHAR(36)',
                default                                                                                        => strtoupper($type),
            };
        }

        return match ($lower) {
            'string', 'varchar'        => 'VARCHAR(' . ($length ?? 255) . ')',
            'char'                     => 'CHAR(' . ($length ?? 255) . ')',
            'text'                     => 'TEXT',
            'mediumtext'               => 'MEDIUMTEXT',
            'longtext'                 => 'LONGTEXT',
            'integer', 'int'           => 'INT' . ($length ? "({$length})" : ''),
            'biginteger', 'bigint'     => 'BIGINT' . ($length ? "({$length})" : ''),
            'smallinteger', 'smallint' => 'SMALLINT' . ($length ? "({$length})" : ''),
            'tinyinteger', 'tinyint'   => 'TINYINT' . ($length ? "({$length})" : ''),
            'float'                    => 'FLOAT' . ($length ? "({$length})" : ''),
            'double'                   => 'DOUBLE' . ($length ? "({$length})" : ''),
            'decimal'                  => sprintf('DECIMAL(%d,%d)', $options['precision'] ?? 10, $options['scale'] ?? 2),
            'boolean', 'bool'          => 'TINYINT(1)',
            'date'                     => 'DATE',
            'datetime'                 => 'DATETIME' . ($length ? "({$length})" : ''),
            'timestamp'                => 'TIMESTAMP' . ($length ? "({$length})" : ''),
            'time'                     => 'TIME',
            'json'                     => 'JSON',
            'enum'                     => "ENUM('" . implode("','", array_map('addslashes', (array)($options['values'] ?? []))) . "')",
            'binary'                   => 'BLOB',
            'uuid'                     => 'CHAR(36)',
            default                    => strtoupper($type),
        };
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, mixed> $options
     */
    private function buildIndexDefinition(array $columns, array $options): string
    {
        $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));

        $type = strtoupper((string)($options['type'] ?? 'INDEX'));

        if ($type === 'PRIMARY') {
            return "PRIMARY KEY ({$cols})";
        }

        if ($type === 'UNIQUE') {
            $name = $options['name'] ?? implode('_', $columns) . '_unique';

            return "UNIQUE KEY `{$name}` ({$cols})";
        }

        $name = $options['name'] ?? implode('_', $columns) . '_index';

        return "KEY `{$name}` ({$cols})";
    }

    private function isSqlite(): bool
    {
        return $this->driverName === 'sqlite';
    }

    /**
     * 获取带前缀的表名。
     */
    private function getTableName(): string
    {
        return $this->prefix . $this->name;
    }
}
