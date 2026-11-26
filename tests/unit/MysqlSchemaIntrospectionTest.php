<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\migration\schema\MigrationCodeGenerator;
use Lychee\migration\schema\SchemaIntrospector;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;

/**
 * 用 stub PDO 喂 MySQL 风格的结果集，验证内省器对 MySQL 的列/索引/外键解析。
 */
class MysqlSchemaIntrospectionTest extends TestCase
{
    public function test_introspect_mysql_table_columns_indexes_and_foreign_keys(): void
    {
        $columnRows = [
            ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => ''],
            ['Field' => 'role_id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => ''],
            ['Field' => 'name', 'Type' => 'varchar(100)', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => '姓名'],
            ['Field' => 'status', 'Type' => 'tinyint(1)', 'Null' => 'NO', 'Default' => '0', 'Extra' => '', 'Comment' => ''],
            ['Field' => 'email', 'Type' => 'varchar(255)', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Comment' => ''],
            ['Field' => 'profile', 'Type' => 'text', 'Null' => 'YES', 'Default' => null, 'Extra' => '', 'Comment' => ''],
        ];

        $indexRows = [
            ['Table' => 'my_users', 'Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'id'],
            ['Table' => 'my_users', 'Key_name' => 'uk_email', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'email'],
        ];

        $fkRows = [
            [
                'constraint_name'        => 'fk_role',
                'column_name'            => 'role_id',
                'referenced_table_name'  => 'my_roles',
                'referenced_column_name' => 'id',
                'delete_rule'            => 'CASCADE',
                'update_rule'            => 'RESTRICT',
            ],
        ];

        $pdo = $this->makeMysqlPdo(
            queryResults: [
                'SHOW FULL COLUMNS FROM' => $columnRows,
                'SHOW INDEX FROM'        => $indexRows,
            ],
            prepareResults: [
                'information_schema.tables' => ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci', 'comment' => '用户表'],
                'key_column_usage'          => $fkRows,
            ],
            listTables: ['my_users'],
        );

        $introspector = new SchemaIntrospector($pdo);

        $this->assertSame(['my_users'], $introspector->listTables());

        $schema = $introspector->introspectTable('my_users');
        $this->assertSame('my_users', $schema->name);
        $this->assertSame('InnoDB', $schema->engine);
        $this->assertSame('用户表', $schema->comment);

        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }

        $this->assertSame('integer', $columns['id']->type);
        $this->assertTrue($columns['id']->autoIncrement);
        $this->assertFalse($columns['id']->nullable);

        $this->assertSame('biginteger', $columns['role_id']->type);
        $this->assertTrue($columns['role_id']->unsigned);

        $this->assertSame('string', $columns['name']->type);
        $this->assertSame(100, $columns['name']->length);
        $this->assertSame('姓名', $columns['name']->comment);

        $this->assertSame('boolean', $columns['status']->type);
        $this->assertTrue($columns['status']->hasDefault);
        $this->assertSame('0', $columns['status']->default);

        $this->assertSame('text', $columns['profile']->type);
        $this->assertTrue($columns['profile']->nullable);

        $indexByType = [];
        foreach ($schema->indexes as $index) {
            $indexByType[$index->type] = $index;
        }
        $this->assertSame(['id'], $indexByType['PRIMARY']->columns);
        $this->assertSame(['email'], $indexByType['UNIQUE']->columns);
        $this->assertSame('uk_email', $indexByType['UNIQUE']->name);

        $this->assertCount(1, $schema->foreignKeys);
        $fk = $schema->foreignKeys[0];
        $this->assertSame('fk_role', $fk->name);
        $this->assertSame(['role_id'], $fk->columns);
        $this->assertSame('my_roles', $fk->refTable);
        $this->assertSame(['id'], $fk->refColumns);
        $this->assertSame('CASCADE', $fk->onDelete);
        $this->assertSame('RESTRICT', $fk->onUpdate);
    }

    public function test_prefix_is_stripped_from_table_and_foreign_key_reference(): void
    {
        $columnRows = [
            ['Field' => 'id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => ''],
        ];
        $indexRows = [
            ['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'id'],
        ];
        $fkRows = [
            [
                'constraint_name'        => 'fk_org',
                'column_name'            => 'org_id',
                'referenced_table_name'  => 'pre_orgs',
                'referenced_column_name' => 'id',
                'delete_rule'            => null,
                'update_rule'            => null,
            ],
        ];

        $pdo = $this->makeMysqlPdo(
            queryResults: [
                'SHOW FULL COLUMNS FROM' => $columnRows,
                'SHOW INDEX FROM'        => $indexRows,
            ],
            prepareResults: [
                'information_schema.tables' => ['engine' => 'InnoDB', 'collation' => '', 'comment' => ''],
                'key_column_usage'          => $fkRows,
            ],
            listTables: ['pre_users'],
        );

        $introspector = new SchemaIntrospector($pdo, 'pre_');
        $schema       = $introspector->introspectTable('pre_users');

        $this->assertSame('users', $schema->name);
        $this->assertSame('pre_users', $schema->realName);
        $this->assertSame('orgs', $schema->foreignKeys[0]->refTable);
    }

    public function test_mysql_int_auto_increment_id_is_emitted_explicitly_to_preserve_type(): void
    {
        $code = $this->generateMysqlTableCode(
            columnRows: [
                ['Field' => 'id', 'Type' => 'int(11)', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => ''],
                ['Field' => 'name', 'Type' => 'varchar(100)', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => ''],
            ],
            indexRows: [
                ['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'id'],
            ],
            table: 'legacy_users',
        );

        // MySQL 下 INT 主键不等于构建器默认的 BIGINT UNSIGNED，必须显式输出以免被默认类型覆盖
        $this->assertStringContainsString("'id' => false", $code);
        $this->assertStringContainsString("'primary_key' => ['id']", $code);
        $this->assertStringContainsString("->addColumn('id', 'integer'", $code);
    }

    public function test_mysql_bigint_unsigned_id_reuses_builder_default(): void
    {
        $code = $this->generateMysqlTableCode(
            columnRows: [
                ['Field' => 'id', 'Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment', 'Comment' => ''],
                ['Field' => 'name', 'Type' => 'varchar(100)', 'Null' => 'NO', 'Default' => null, 'Extra' => '', 'Comment' => ''],
            ],
            indexRows: [
                ['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1, 'Column_name' => 'id'],
            ],
            table: 'modern_users',
        );

        // 与构建器默认一致的主键复用默认行为，输出更整洁
        $this->assertStringNotContainsString("'id' => false", $code);
        $this->assertStringNotContainsString("addColumn('id'", $code);
    }

    /**
     * @param list<array<string, mixed>> $columnRows
     * @param list<array<string, mixed>> $indexRows
     */
    private function generateMysqlTableCode(array $columnRows, array $indexRows, string $table): string
    {
        $pdo = $this->makeMysqlPdo(
            queryResults: [
                'SHOW FULL COLUMNS FROM' => $columnRows,
                'SHOW INDEX FROM'        => $indexRows,
            ],
            prepareResults: [
                'information_schema.tables' => ['engine' => 'InnoDB', 'collation' => '', 'comment' => ''],
                'key_column_usage'          => [],
            ],
            listTables: [$table],
        );

        $schema = (new SchemaIntrospector($pdo))->introspectTable($table);
        $files  = (new MigrationCodeGenerator('20240101000000'))->generatePerTable([$schema]);

        return $files[0]['content'];
    }

    /**
     * @param array<string, list<array<string, mixed>>> $queryResults
     * @param array<string, array<string, mixed>|list<array<string, mixed>>> $prepareResults
     * @param list<string> $listTables
     */
    private function makeMysqlPdo(array $queryResults, array $prepareResults, array $listTables): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');

        $pdo->method('query')->willReturnCallback(function (string $sql) use ($queryResults, $listTables) {
            if (str_contains($sql, 'information_schema.tables') && str_contains($sql, 'table_type')) {
                return $this->makeStatement($listTables, 'column');
            }
            foreach ($queryResults as $pattern => $rows) {
                if (str_contains($sql, $pattern)) {
                    return $this->makeStatement($rows, 'assoc');
                }
            }

            return $this->makeStatement([], 'assoc');
        });

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($prepareResults) {
            foreach ($prepareResults as $pattern => $row) {
                if (str_contains($sql, $pattern)) {
                    // key_column_usage 返回多行（assoc），tables 元信息返回单行（fetch）
                    $mode = str_contains($sql, 'key_column_usage') ? 'assoc' : 'fetch';

                    return $this->makeStatement($row === [] ? [] : (is_array($row) && isset($row[0]) ? $row : [$row]), $mode);
                }
            }

            return $this->makeStatement([], 'fetch');
        });

        return $pdo;
    }

    /**
     * @param list<mixed> $rows
     */
    private function makeStatement(array $rows, string $mode): PDOStatement
    {
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        switch ($mode) {
            case 'column':
                $stmt->method('fetchAll')->willReturn($rows);
                break;
            case 'fetch':
                $stmt->method('fetch')->willReturn($rows[0] ?? false);
                $stmt->method('fetchColumn')->willReturn($rows[0] ? array_values($rows[0])[0] : false);
                break;
            default:
                $stmt->method('fetchAll')->willReturn($rows);
                $stmt->method('fetch')->willReturn($rows[0] ?? false);
                break;
        }

        return $stmt;
    }
}
