<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\orm\Connection;
use Lychee\orm\exception\DbException;
use Lychee\orm\facade\Db;
use Lychee\orm\Query;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Tests\stub\app\model\User;

/**
 * 覆盖查询构造器的 upsert / firstOrCreate / updateOrCreate。
 *
 * - SQLite：使用 stub 的内存库做真实执行验证；
 * - MySQL：通过伪造 PDO 捕获生成的 SQL，验证 ON DUPLICATE KEY UPDATE 分支。
 */
class UpsertTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $db = $this->app->container->get('db');
        $db->connect()->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL UNIQUE,
            age INTEGER DEFAULT 0,
            create_time DATETIME,
            update_time DATETIME
        )');
    }

    // ── upsert ──────────────────────────────────────────────────────

    public function test_upsert_inserts_new_row(): void
    {
        $affected = Db::table('users')->upsert(
            [['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]],
            ['email']
        );

        $this->assertSame(1, $affected);
        $row = Db::table('users')->where('email', 'alice@example.com')->find();
        $this->assertSame('Alice', $row['name']);
        $this->assertSame(30, $row['age']);
    }

    public function test_upsert_updates_on_conflict(): void
    {
        Db::table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);

        $affected = Db::table('users')->upsert(
            [['name' => 'Alice New', 'email' => 'alice@example.com', 'age' => 31]],
            ['email']
        );

        $this->assertSame(1, $affected);
        $row = Db::table('users')->where('email', 'alice@example.com')->find();
        $this->assertSame('Alice New', $row['name']);
        $this->assertSame(31, $row['age']);
    }

    public function test_upsert_accepts_single_associative_row(): void
    {
        $affected = Db::table('users')->upsert(
            ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 25],
            ['email']
        );

        $this->assertSame(1, $affected);
        $this->assertSame(1, Db::table('users')->where('email', 'bob@example.com')->count());
    }

    public function test_upsert_only_updates_specified_columns(): void
    {
        Db::table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);

        Db::table('users')->upsert(
            [['name' => 'Should Change', 'email' => 'alice@example.com', 'age' => 99]],
            ['email'],
            ['name'] // 只更新 name，不更新 age
        );

        $row = Db::table('users')->where('email', 'alice@example.com')->find();
        $this->assertSame('Should Change', $row['name']);
        $this->assertSame(30, $row['age']); // age 未被更新
    }

    public function test_upsert_requires_unique_by_on_sqlite(): void
    {
        $this->expectException(DbException::class);

        Db::table('users')->upsert(
            [['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]],
            // 未传 $uniqueBy
        );
    }

    public function test_upsert_mysql_uses_on_duplicate_key_update(): void
    {
        $sql = $this->captureMysqlUpsertSql(
            [['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]],
            ['email']
        );

        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $sql);
        $this->assertStringNotContainsString('ON CONFLICT', $sql);
        $this->assertStringContainsString('VALUES(`name`)', $sql);
        $this->assertStringContainsString('VALUES(`age`)', $sql);
    }

    // ── firstOrCreate ───────────────────────────────────────────────

    public function test_first_or_create_creates_when_missing(): void
    {
        $row = Db::table('users')->firstOrCreate(
            ['email' => 'alice@example.com'],
            ['name' => 'Alice', 'age' => 30]
        );

        $this->assertSame('Alice', $row['name']);
        $this->assertSame(30, $row['age']);
        $this->assertSame(1, Db::table('users')->count());
    }

    public function test_first_or_create_returns_existing_without_modification(): void
    {
        Db::table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);

        $row = Db::table('users')->firstOrCreate(
            ['email' => 'alice@example.com'],
            ['name' => 'Bob', 'age' => 99] // 第二个参数在已存在时被忽略
        );

        $this->assertSame('Alice', $row['name']);
        $this->assertSame(30, $row['age']);
        $this->assertSame(1, Db::table('users')->count());
    }

    // ── updateOrCreate ──────────────────────────────────────────────

    public function test_update_or_create_updates_when_exists(): void
    {
        Db::table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);

        $row = Db::table('users')->updateOrCreate(
            ['email' => 'alice@example.com'],
            ['name' => 'Alice New', 'age' => 31]
        );

        $this->assertSame('Alice New', $row['name']);
        $this->assertSame(31, $row['age']);
        $this->assertSame(1, Db::table('users')->count());
    }

    public function test_update_or_create_creates_when_missing(): void
    {
        $row = Db::table('users')->updateOrCreate(
            ['email' => 'alice@example.com'],
            ['name' => 'Alice', 'age' => 30]
        );

        $this->assertSame('Alice', $row['name']);
        $this->assertSame(30, $row['age']);
        $this->assertSame(1, Db::table('users')->count());
    }

    // ── Model 级别 ──────────────────────────────────────────────────

    public function test_model_first_or_create(): void
    {
        $user = User::firstOrCreate(['email' => 'alice@example.com'], ['name' => 'Alice', 'age' => 30]);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('Alice', $user->getAttr('name'));
        $this->assertTrue($user->exists());
    }

    public function test_model_update_or_create(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);

        $user = User::updateOrCreate(['email' => 'alice@example.com'], ['name' => 'Alice New', 'age' => 31]);

        $this->assertSame('Alice New', $user->getAttr('name'));
        $this->assertSame(31, $user->getAttr('age'));
    }

    public function test_model_upsert(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);

        User::upsert(
            [['name' => 'Alice New', 'email' => 'alice@example.com', 'age' => 31]],
            ['email']
        );

        $row = Db::table('users')->where('email', 'alice@example.com')->find();
        $this->assertSame('Alice New', $row['name']);
        $this->assertSame(31, $row['age']);
    }

    // ── 辅助：捕获 MySQL 分支生成的 SQL ──────────────────────────────

    /**
     * 构造一个 type=mysql 的连接，其 PDO 为伪造对象，捕获 prepare 收到的 SQL。
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string>               $uniqueBy
     * @param array<int, string>               $update
     */
    private function captureMysqlUpsertSql(array $rows, array $uniqueBy = [], array $update = []): string
    {
        $pdo  = $this->createStub(PDO::class);
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(1);

        $captured = '';
        $pdo->method('prepare')->willReturnCallback(
            static function (string $sql) use ($stmt, &$captured): PDOStatement {
                $captured = $sql;

                return $stmt;
            }
        );

        $connection = new class ('mysql-spy', ['type' => 'mysql']) extends Connection {
            public PDO $spyPdo;

            public function getPdo(): PDO
            {
                return $this->spyPdo;
            }
        };
        $connection->spyPdo = $pdo;

        $query = new Query($connection);
        $query->table('users');
        $query->upsert($rows, $uniqueBy, $update);

        return $captured;
    }
}
