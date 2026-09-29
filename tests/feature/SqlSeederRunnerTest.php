<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\migration\SqlSeederRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SqlSeederRunnerTest extends TestCase
{
    private string $tempDir;
    private string $sqlPath;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lychee_sql_seed_test_' . uniqid();
        $this->sqlPath = $this->tempDir . '/sql';

        mkdir($this->sqlPath, 0777, true);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_run_executes_files_and_records_them(): void
    {
        file_put_contents($this->sqlPath . '/001_init.sql', <<<SQL
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    name VARCHAR(50)
);

INSERT INTO users (id, name) VALUES (1, 'alice');
INSERT INTO users (id, name) VALUES (2, 'bob');
SQL);

        $runner = new SqlSeederRunner($this->pdo, $this->sqlPath);

        $count = $runner->run();

        $this->assertSame(1, $count);

        $rows = $this->pdo->query('SELECT name FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['alice', 'bob'], $rows);

        $recorded = $this->pdo->query('SELECT filename FROM sql_seeds')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['001_init.sql'], $recorded);

        // 再次执行应全部跳过
        $this->assertSame(0, $runner->run());
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_semicolon_inside_string_is_not_split(): void
    {
        file_put_contents($this->sqlPath . '/001.sql', <<<SQL
CREATE TABLE t (id INTEGER PRIMARY KEY, v VARCHAR(100));
INSERT INTO t (id, v) VALUES (1, 'a;b;c');
INSERT INTO t (id, v) VALUES (2, 'it''s ok; right');
SQL);

        $runner = new SqlSeederRunner($this->pdo, $this->sqlPath);
        $runner->run();

        $rows = $this->pdo->query('SELECT v FROM t ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['a;b;c', "it's ok; right"], $rows);
    }

    public function test_semicolon_in_comments_is_ignored(): void
    {
        file_put_contents($this->sqlPath . '/001.sql', <<<'SQL'
-- header; not a statement
/* block; comment; */ CREATE TABLE t (id INTEGER PRIMARY KEY);
-- INSERT INTO t VALUES (99);
INSERT INTO t VALUES (1);
SQL);

        $runner = new SqlSeederRunner($this->pdo, $this->sqlPath);
        $runner->run();

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function test_force_re_runs_imported_file(): void
    {
        file_put_contents($this->sqlPath . '/001.sql', 'CREATE TABLE t (id INTEGER PRIMARY KEY);');

        $runner = new SqlSeederRunner($this->pdo, $this->sqlPath);

        $this->assertSame(1, $runner->run());
        // CREATE TABLE IF NOT EXISTS 语义通过 force + 已存在表验证不重复记账
        file_put_contents($this->sqlPath . '/001.sql', 'CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY);');

        $this->assertSame(1, $runner->run(force: true));

        $recorded = $this->pdo->query('SELECT COUNT(*) FROM sql_seeds')->fetchColumn();
        $this->assertSame(1, (int) $recorded);
    }

    public function test_only_runs_named_file(): void
    {
        file_put_contents($this->sqlPath . '/001_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY);');
        file_put_contents($this->sqlPath . '/002_b.sql', 'CREATE TABLE b (id INTEGER PRIMARY KEY);');

        $runner = new SqlSeederRunner($this->pdo, $this->sqlPath);
        $runner->run('002_b.sql');

        $recorded = $this->pdo->query('SELECT filename FROM sql_seeds')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['002_b.sql'], $recorded);

        $this->expectException(RuntimeException::class);
        $runner->run('missing.sql');
    }

    public function test_files_run_in_name_order(): void
    {
        // 若先执行 002 会因缺少 t 表而失败
        file_put_contents($this->sqlPath . '/002_insert.sql', 'INSERT INTO t VALUES (1);');
        file_put_contents($this->sqlPath . '/001_create.sql', 'CREATE TABLE t (id INTEGER PRIMARY KEY);');

        $runner = new SqlSeederRunner($this->pdo, $this->sqlPath);

        $this->assertSame(2, $runner->run());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function test_failure_rolls_back_batch_and_does_not_record(): void
    {
        file_put_contents($this->sqlPath . '/001_bad.sql', <<<SQL
CREATE TABLE t (id INTEGER PRIMARY KEY);
INSERT INTO t VALUES (1);
INSERT INTO t BROKEN SYNTAX;
SQL);

        $runner = new SqlSeederRunner($this->pdo, $this->sqlPath);

        try {
            $runner->run();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            // 坏语句位于第 4 行（INSERT 成功语句之后）
            $this->assertStringContainsString("'001_bad.sql'", $e->getMessage());
            $this->assertStringContainsString('statement #3', $e->getMessage());
            $this->assertStringContainsString('line 3', $e->getMessage());
        }

        // DDL 已生效，但同批次 DML 整体回滚
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
        // 失败文件不记账，允许修复后重跑
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM sql_seeds')->fetchColumn());
    }

    public function test_statement_spanning_chunk_boundary(): void
    {
        // 让闭合引号恰好落在第一块尾部的 4 字节保留区内（全局偏移 65534）
        $ddl    = 'CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT);';
        $prefix = 'INSERT INTO t (v) VALUES (\'';
        $filler = 65534 - strlen($ddl) - strlen($prefix);

        file_put_contents(
            $this->sqlPath . '/001_long.sql',
            $ddl . $prefix . str_repeat('a', $filler) . "');"
        );

        $runner = new SqlSeederRunner($this->pdo, $this->sqlPath);
        $runner->run();

        $value = (string) $this->pdo->query('SELECT v FROM t LIMIT 1')->fetchColumn();
        $this->assertSame($filler, strlen($value));
    }

    public function test_missing_directory_is_noop(): void
    {
        $runner = new SqlSeederRunner($this->pdo, $this->tempDir . '/does-not-exist');

        $this->assertSame(0, $runner->run());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
