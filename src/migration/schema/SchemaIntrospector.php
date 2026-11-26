<?php

declare(strict_types=1);

namespace Lychee\migration\schema;

use Lychee\migration\MigrationManager;
use PDO;

/**
 * 数据库结构内省器。
 *
 * 从现有数据库读取表、列、索引、外键信息，输出驱动无关的 {@see TableSchema} 中间结构，
 * 供 {@see MigrationCodeGenerator} 反向生成迁移文件。
 *
 * 支持 MySQL 与 SQLite；其他驱动退化为基于结果集元数据的列读取（无索引/外键信息）。
 */
class SchemaIntrospector
{
    private readonly string $driverName;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $prefix = '',
    ) {
        $this->driverName = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function isSqlite(): bool
    {
        return $this->driverName === 'sqlite';
    }

    public function driverName(): string
    {
        return $this->driverName;
    }

    /**
     * 将物理表名转换为逻辑表名（剥离配置前缀）。
     */
    public function logicalName(string $realName): string
    {
        return $this->stripPrefix($realName);
    }

    /**
     * 列出数据库中所有业务表的物理表名（含前缀）。
     *
     * 排除视图与框架内部的 migrations 记录表。
     *
     * @return list<string>
     */
    public function listTables(): array
    {
        if ($this->isSqlite()) {
            $stmt = $this->pdo->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            );
            $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        } else {
            $stmt = $this->pdo->query(
                "SELECT table_name FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
                 ORDER BY table_name"
            );
            $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        }

        $tables = array_values(array_filter(
            $tables,
            static fn (mixed $name): bool => is_string($name) && $name !== MigrationManager::MIGRATION_TABLE
        ));

        return $tables;
    }

    /**
     * 内省所有表的结构。
     *
     * @return list<TableSchema>
     */
    public function introspectAll(): array
    {
        $schemas = [];
        foreach ($this->listTables() as $table) {
            $schemas[] = $this->introspectTable($table);
        }

        return $schemas;
    }

    /**
     * 内省单张表的结构。
     */
    public function introspectTable(string $realName): TableSchema
    {
        if ($this->isSqlite()) {
            return $this->introspectSqlite($realName);
        }

        return $this->introspectMysql($realName);
    }

    private function introspectMysql(string $realName): TableSchema
    {
        $quoted = $this->quoteIdentifier($realName);

        // 表元信息
        $metaStmt = $this->pdo->prepare(
            "SELECT engine, table_collation AS collation, table_comment AS comment
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?"
        );
        $metaStmt->execute([$realName]);
        $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // 列
        $colStmt = $this->pdo->query("SHOW FULL COLUMNS FROM {$quoted}");
        $rows    = $colStmt ? $colStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $columns = [];
        foreach ($rows as $row) {
            $parsed  = ColumnTypeParser::parse((string) ($row['Type'] ?? ''));
            $extra   = strtolower((string) ($row['Extra'] ?? ''));
            $default = $row['Default'] ?? null;

            $columns[] = new ColumnSchema(
                name: (string) $row['Field'],
                type: $parsed['type'],
                length: $parsed['length'],
                precision: $parsed['precision'],
                scale: $parsed['scale'],
                unsigned: $parsed['unsigned'],
                values: $parsed['values'],
                nullable: strtolower((string) ($row['Null'] ?? 'YES')) !== 'no',
                default: $default,
                hasDefault: $default !== null,
                autoIncrement: str_contains($extra, 'auto_increment'),
                comment: (string) ($row['Comment'] ?? ''),
                onUpdateCurrentTimestamp: str_contains($extra, 'on update current_timestamp'),
            );
        }

        return new TableSchema(
            name: $this->stripPrefix($realName),
            realName: $realName,
            driver: $this->driverName,
            engine: (string) ($meta['engine'] ?? 'InnoDB'),
            collation: (string) ($meta['collation'] ?? ''),
            comment: (string) ($meta['comment'] ?? ''),
            columns: $columns,
            indexes: $this->introspectMysqlIndexes($quoted),
            foreignKeys: $this->introspectMysqlForeignKeys($realName),
        );
    }

    /**
     * @return list<IndexSchema>
     */
    private function introspectMysqlIndexes(string $quotedTable): array
    {
        $stmt = $this->pdo->query("SHOW INDEX FROM {$quotedTable}");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $groups = [];
        foreach ($rows as $row) {
            $key = (string) $row['Key_name'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'name'   => $key,
                    'unique' => (int) ($row['Non_unique'] ?? 1) === 0,
                    'cols'   => [],
                ];
            }
            $groups[$key]['cols'][(int) ($row['Seq_in_index'] ?? 0)] = (string) $row['Column_name'];
        }

        $indexes = [];
        foreach ($groups as $group) {
            ksort($group['cols']);
            $isPrimary = $group['name'] === 'PRIMARY';
            $type      = $isPrimary ? 'PRIMARY' : ($group['unique'] ? 'UNIQUE' : 'INDEX');
            $indexes[] = new IndexSchema($group['name'], array_values($group['cols']), $type);
        }

        return $indexes;
    }

    /**
     * @return list<ForeignKeySchema>
     */
    private function introspectMysqlForeignKeys(string $realName): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT k.constraint_name, k.column_name, k.referenced_table_name, k.referenced_column_name,
                    r.delete_rule, r.update_rule
             FROM information_schema.key_column_usage k
             JOIN information_schema.referential_constraints r
               ON k.constraint_name = r.constraint_name AND k.constraint_schema = r.constraint_schema
             WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.referenced_table_name IS NOT NULL
             ORDER BY k.constraint_name, k.ordinal_position"
        );
        $stmt->execute([$realName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $groups = [];
        foreach ($rows as $row) {
            $name = (string) ($row['constraint_name'] ?? '');
            if (!isset($groups[$name])) {
                $groups[$name] = [
                    'name'     => $name,
                    'refTable' => (string) ($row['referenced_table_name'] ?? ''),
                    'onDelete' => isset($row['delete_rule']) ? (string) $row['delete_rule'] : null,
                    'onUpdate' => isset($row['update_rule']) ? (string) $row['update_rule'] : null,
                    'cols'     => [],
                    'refCols'  => [],
                ];
            }
            $groups[$name]['cols'][]    = (string) ($row['column_name'] ?? '');
            $groups[$name]['refCols'][] = (string) ($row['referenced_column_name'] ?? '');
        }

        $foreignKeys = [];
        foreach ($groups as $group) {
            $foreignKeys[] = new ForeignKeySchema(
                name: $group['name'],
                columns: $group['cols'],
                refTable: $this->stripPrefix($group['refTable']),
                refColumns: $group['refCols'],
                onDelete: $group['onDelete'],
                onUpdate: $group['onUpdate'],
            );
        }

        return $foreignKeys;
    }

    private function introspectSqlite(string $realName): TableSchema
    {
        $quoted = $this->quoteIdentifier($realName);

        $createSql = '';
        $stmt      = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$realName]);
        $createSql = (string) ($stmt->fetchColumn() ?: '');

        $colStmt = $this->pdo->query("PRAGMA table_info({$quoted})");
        $rows    = $colStmt ? $colStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // SQLite 中，单列 INTEGER PRIMARY KEY 即为 rowid 别名（自增），
        // 无论是否显式声明 AUTOINCREMENT 关键字。
        $pkRows = array_values(array_filter(
            $rows,
            static fn (array $r): bool => (int) ($r['pk'] ?? 0) === 1
        ));
        $integerPkName = null;
        if (count($pkRows) === 1) {
            $pkType = strtolower(trim((string) ($pkRows[0]['type'] ?? '')));
            if (in_array($pkType, ['integer', 'int'], true)) {
                $integerPkName = (string) $pkRows[0]['name'];
            }
        }

        $hasAutoincKeyword = str_contains(strtoupper($createSql), 'AUTOINCREMENT');

        $columns = [];
        foreach ($rows as $row) {
            $rawType = trim((string) ($row['type'] ?? ''));
            $type    = $rawType !== '' ? $rawType : 'TEXT';
            $parsed  = ColumnTypeParser::parse($type);
            $name    = (string) $row['name'];

            $columns[] = new ColumnSchema(
                name: $name,
                type: $parsed['type'],
                length: $parsed['length'],
                precision: $parsed['precision'],
                scale: $parsed['scale'],
                unsigned: $parsed['unsigned'],
                values: $parsed['values'],
                nullable: (int) ($row['notnull'] ?? 0) !== 1,
                default: $this->parseSqliteDefault($row['dflt_value'] ?? null),
                hasDefault: ($row['dflt_value'] ?? null) !== null,
                autoIncrement: $hasAutoincKeyword
                    ? (int) ($row['pk'] ?? 0) === 1
                    : $integerPkName          === $name,
                comment: '',
                onUpdateCurrentTimestamp: false,
            );
        }

        // SQLite 的主键信息来自 PRAGMA table_info 的 pk 字段（index_list 中
        // origin='pk' 的自动索引已被跳过），这里重建一个 PRIMARY 索引供生成器识别。
        $pkCols = [];
        foreach ($rows as $row) {
            $pk = (int) ($row['pk'] ?? 0);
            if ($pk > 0) {
                $pkCols[$pk] = (string) $row['name'];
            }
        }
        ksort($pkCols);
        $pkIndex = $pkCols !== [] ? [new IndexSchema('PRIMARY', array_values($pkCols), 'PRIMARY')] : [];

        return new TableSchema(
            name: $this->stripPrefix($realName),
            realName: $realName,
            driver: $this->driverName,
            columns: $columns,
            indexes: array_merge($pkIndex, $this->introspectSqliteIndexes($realName)),
            foreignKeys: $this->introspectSqliteForeignKeys($quoted),
        );
    }

    /**
     * @return list<IndexSchema>
     */
    private function introspectSqliteIndexes(string $realName): array
    {
        $listStmt = $this->pdo->query("PRAGMA index_list({$this->quoteIdentifier($realName)})");
        $rows     = $listStmt ? $listStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $indexes = [];
        foreach ($rows as $row) {
            $origin = strtolower((string) ($row['origin'] ?? ''));
            // 主键的自动索引由主键逻辑处理，跳过
            if ($origin === 'pk') {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $infoStmt = $this->pdo->query("PRAGMA index_info({$this->quoteIdentifier($name)})");
            $infoRows = $infoStmt ? $infoStmt->fetchAll(PDO::FETCH_ASSOC) : [];

            $cols = [];
            foreach ($infoRows as $info) {
                $cols[(int) ($info['seqno'] ?? 0)] = (string) ($info['name'] ?? '');
            }
            ksort($cols);
            $cols = array_values(array_filter($cols, static fn (string $c): bool => $c !== ''));

            if ($cols === []) {
                continue;
            }

            $unique    = (int) ($row['unique'] ?? 0) === 1;
            $indexes[] = new IndexSchema($name, $cols, $unique ? 'UNIQUE' : 'INDEX');
        }

        return $indexes;
    }

    /**
     * @return list<ForeignKeySchema>
     */
    private function introspectSqliteForeignKeys(string $quotedTable): array
    {
        $stmt = $this->pdo->query("PRAGMA foreign_key_list({$quotedTable})");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $groups = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '0');
            if (!isset($groups[$id])) {
                $groups[$id] = [
                    'refTable' => (string) ($row['table'] ?? ''),
                    'onDelete' => isset($row['on_delete']) && $row['on_delete'] !== 'NO ACTION' ? (string) $row['on_delete'] : null,
                    'onUpdate' => isset($row['on_update']) && $row['on_update'] !== 'NO ACTION' ? (string) $row['on_update'] : null,
                    'cols'     => [],
                    'refCols'  => [],
                ];
            }
            $seq                          = (int) ($row['seq'] ?? 0);
            $groups[$id]['cols'][$seq]    = (string) ($row['from'] ?? '');
            $groups[$id]['refCols'][$seq] = (string) ($row['to'] ?? '');
        }

        $foreignKeys = [];
        foreach ($groups as $id => $group) {
            ksort($group['cols']);
            ksort($group['refCols']);
            $foreignKeys[] = new ForeignKeySchema(
                name: 'fk_' . $id,
                columns: array_values($group['cols']),
                refTable: $this->stripPrefix($group['refTable']),
                refColumns: array_values($group['refCols']),
                onDelete: $group['onDelete'],
                onUpdate: $group['onUpdate'],
            );
        }

        return $foreignKeys;
    }

    /**
     * 解析 SQLite 的 dflt_value：NULL / 'NULL' / 'text' / 0 / CURRENT_TIMESTAMP 等。
     */
    private function parseSqliteDefault(mixed $raw): mixed
    {
        if (!is_string($raw)) {
            return $raw;
        }

        if (strtoupper($raw) === 'NULL') {
            return null;
        }

        if (preg_match("/^'(.*)'$/s", $raw, $matches)) {
            return str_replace("''", "'", $matches[1]);
        }

        return $raw;
    }

    /**
     * 从物理表名中剥离配置的前缀，得到逻辑表名。
     */
    private function stripPrefix(string $name): string
    {
        if ($this->prefix !== '' && str_starts_with($name, $this->prefix)) {
            return substr($name, strlen($this->prefix));
        }

        return $name;
    }

    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
