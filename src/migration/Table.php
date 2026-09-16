<?php

declare(strict_types=1);

namespace Lychee\migration;

use PDO;

/**
 * 数据表构建器。
 *
 * 通过链式 API 生成 DDL SQL，支持建表、改表、加字段、加索引等操作。
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

    public function __construct(
        private readonly string $name,
        private readonly PDO $pdo,
        array $options = [],
    ) {
        $this->options = array_merge($this->options, $options);
    }

    /**
     * 创建表。
     */
    public function create(): void
    {
        $columns = [];

        if (!empty($this->options['id'])) {
            $columns[] = sprintf(
                '`%s` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT',
                $this->options['id']
            );
        }

        foreach ($this->columns as $col) {
            $columns[] = $this->buildColumnDefinition($col['name'], $col['type'], $col['options']);
        }

        if (!empty($this->options['primary_key'])) {
            $pk        = (array) $this->options['primary_key'];
            $columns[] = 'PRIMARY KEY (' . implode(', ', array_map(fn ($c) => "`{$c}`", $pk)) . ')';
        }

        foreach ($this->indexes as $idx) {
            $columns[] = $this->buildIndexDefinition($idx['columns'], $idx['options']);
        }

        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (%s) ENGINE=%s DEFAULT CHARSET=utf8mb4 COLLATE=%s',
            $this->name,
            implode(', ', $columns),
            $this->options['engine'],
            $this->options['collation']
        );

        if (!empty($this->options['comment'])) {
            $sql .= " COMMENT='" . addslashes((string) $this->options['comment']) . "'";
        }

        $this->pdo->exec($sql);
    }

    /**
     * 删除表。
     */
    public function drop(): void
    {
        $this->pdo->exec("DROP TABLE IF EXISTS `{$this->name}`");
    }

    /**
     * 判断表是否存在。
     */
    public function exists(): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
        );
        $stmt->execute([$this->name]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * 添加列。
     *
     * @param  string               $name
     * @param  string               $type
     * @param  array<string, mixed> $options
     */
    public function addColumn(string $name, string $type, array $options = []): static
    {
        $this->columns[] = compact('name', 'type', 'options');

        return $this;
    }

    /**
     * 修改列。
     *
     * @param  string               $name
     * @param  string               $type
     * @param  array<string, mixed> $options
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
     * @param  array<int, string>|string $columns
     * @param  array<string, mixed>      $options
     */
    public function addIndex(array|string $columns, array $options = []): static
    {
        $this->indexes[] = [
            'columns' => (array) $columns,
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

        $this->pdo->exec(sprintf('ALTER TABLE `%s` %s', $this->name, implode(', ', $parts)));
    }

    /**
     * @param  array<string, mixed> $options
     */
    private function buildColumnDefinition(string $name, string $type, array $options): string
    {
        $sqlType = $this->mapType($type, $options);
        $def     = "`{$name}` {$sqlType}";

        if (!empty($options['unsigned'])) {
            $def .= ' UNSIGNED';
        }

        if (!empty($options['null'])) {
            $def .= ' NULL';
        } else {
            $def .= ' NOT NULL';
        }

        if (array_key_exists('default', $options)) {
            $default = $options['default'];
            if (strtoupper((string) $default) === 'CURRENT_TIMESTAMP') {
                $def .= ' DEFAULT CURRENT_TIMESTAMP';
            } elseif ($default === null) {
                $def .= ' DEFAULT NULL';
            } else {
                $def .= " DEFAULT '" . addslashes((string) $default) . "'";
            }
        }

        if (!empty($options['auto_increment'])) {
            $def .= ' AUTO_INCREMENT';
        }

        if (!empty($options['comment'])) {
            $def .= " COMMENT '" . addslashes((string) $options['comment']) . "'";
        }

        if (!empty($options['update']) && strtoupper((string) $options['update']) === 'CURRENT_TIMESTAMP') {
            $def .= ' ON UPDATE CURRENT_TIMESTAMP';
        }

        return $def;
    }

    /**
     * @param  array<string, mixed> $options
     */
    private function mapType(string $type, array $options): string
    {
        $length = $options['length'] ?? null;

        return match (strtolower($type)) {
            'varchar'                  => 'VARCHAR(' . ($length ?? 255) . ')',
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
            'enum'                     => "ENUM('" . implode("','", array_map('addslashes', (array) ($options['values'] ?? []))) . "')",
            'binary'                   => 'BLOB',
            'uuid'                     => 'CHAR(36)',
            default                    => strtoupper($type),
        };
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<string, mixed> $options
     */
    private function buildIndexDefinition(array $columns, array $options): string
    {
        $cols = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));

        $type = strtoupper((string) ($options['type'] ?? 'INDEX'));

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
}
