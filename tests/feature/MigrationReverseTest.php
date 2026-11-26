<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\console\Application as ConsoleApplication;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\migration\MigrationManager;
use Lychee\migration\schema\MigrationCodeGenerator;
use Lychee\migration\schema\SchemaIntrospector;
use PHPUnit\Framework\TestCase;
use PDO;

class MigrationReverseTest extends TestCase
{
    private string $tempDir;
    private string $migrationPath;

    protected function setUp(): void
    {
        $this->tempDir       = sys_get_temp_dir() . '/lychee_reverse_' . uniqid();
        $this->migrationPath = $this->tempDir . '/migrations';
        mkdir($this->migrationPath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_per_table_round_trip_with_foreign_key_and_ordering(): void
    {
        $src = $this->freshPdo();
        $src->exec('CREATE TABLE rt_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL)');
        $src->exec('CREATE TABLE rt_users (id INTEGER PRIMARY KEY AUTOINCREMENT, role_id INTEGER NOT NULL, email VARCHAR(255) NOT NULL, FOREIGN KEY (role_id) REFERENCES rt_roles(id) ON DELETE CASCADE)');
        $src->exec('CREATE UNIQUE INDEX uk_rt_users_email ON rt_users(email)');

        $generator = new MigrationCodeGenerator('20260101000000');
        $files     = $generator->generatePerTable((new SchemaIntrospector($src))->introspectAll());
        $written   = $this->writeFiles($files);

        // 外键依赖决定顺序：rt_roles 必须先于 rt_users
        $this->assertSame(['20260101000000_create_rt_roles_table.php', '20260101000001_create_rt_users_table.php'], array_keys($written));

        // 规范自增 id 主键：复用构建器默认，不显式声明 id 列
        $this->assertStringContainsString("\$this->table('rt_roles')", $written['20260101000000_create_rt_roles_table.php']);
        $this->assertStringNotContainsString("addColumn('id'", $written['20260101000000_create_rt_roles_table.php']);
        $this->assertStringNotContainsString("'id' => false", $written['20260101000000_create_rt_roles_table.php']);

        // 外键被还原
        $this->assertStringContainsString("->addForeignKey(['role_id'], 'rt_roles', ['id']", $written['20260101000001_create_rt_users_table.php']);
        $this->assertStringContainsString("'onDelete' => 'CASCADE'", $written['20260101000001_create_rt_users_table.php']);

        // 在全新库上执行生成的迁移，验证闭环
        $dst = $this->freshPdo();
        foreach ($files as $file) {
            require_once $this->migrationPath . '/' . $file['filename'];
            $class = $file['class'];
            (new $class($dst))->up();
        }

        $this->assertSame(['id', 'name'], $this->columnNames($dst, 'rt_roles'));
        $this->assertSame(['id', 'role_id', 'email'], $this->columnNames($dst, 'rt_users'));

        $fks = $dst->query('PRAGMA foreign_key_list(rt_users)')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $fks);
        $this->assertSame('rt_roles', $fks[0]['table']);

        // down() 逆序可执行
        foreach (array_reverse($files) as $file) {
            $class = $file['class'];
            (new $class($dst))->down();
        }
        $this->assertSame([], $dst->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function test_non_canonical_primary_key_is_rendered_explicitly(): void
    {
        $src = $this->freshPdo();
        $src->exec('CREATE TABLE kv_settings (key VARCHAR(50) NOT NULL PRIMARY KEY, value TEXT)');

        $generator = new MigrationCodeGenerator('20260101000000');
        $files     = $generator->generatePerTable((new SchemaIntrospector($src))->introspectAll());
        $this->writeFiles($files);

        $content = $files[0]['content'];
        $this->assertStringContainsString("'id' => false", $content);
        $this->assertStringContainsString("'primary_key' => ['key']", $content);
        $this->assertStringContainsString("->addColumn('key', 'string', ['length' => 50])", $content);

        $dst = $this->freshPdo();
        require_once $this->migrationPath . '/' . $files[0]['filename'];
        $class = $files[0]['class'];
        (new $class($dst))->up();

        $pk     = $dst->query("PRAGMA table_info(kv_settings)")->fetchAll(PDO::FETCH_ASSOC);
        $pkCols = array_values(array_filter(array_column($pk, 'pk'), static fn (mixed $v): bool => (int) $v === 1));
        $this->assertSame([1], $pkCols);
    }

    public function test_composite_primary_key_is_rendered(): void
    {
        $src = $this->freshPdo();
        $src->exec('CREATE TABLE tag_rels (post_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY (post_id, tag_id))');

        $generator = new MigrationCodeGenerator('20260101000000');
        $files     = $generator->generatePerTable((new SchemaIntrospector($src))->introspectAll());
        $this->writeFiles($files);

        $this->assertStringContainsString("'primary_key' => ['post_id', 'tag_id']", $files[0]['content']);

        $dst = $this->freshPdo();
        require_once $this->migrationPath . '/' . $files[0]['filename'];
        $class = $files[0]['class'];
        (new $class($dst))->up();

        $cols  = $dst->query("PRAGMA table_info(tag_rels)")->fetchAll(PDO::FETCH_ASSOC);
        $pkSeq = array_filter(array_column($cols, 'pk'), static fn (mixed $v): bool => (int) $v > 0);
        $this->assertCount(2, $pkSeq);
    }

    public function test_all_in_one_generation_and_down_reverse_order(): void
    {
        $src = $this->freshPdo();
        $src->exec('CREATE TABLE aio_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL)');
        $src->exec('CREATE TABLE aio_users (id INTEGER PRIMARY KEY AUTOINCREMENT, role_id INTEGER NOT NULL, FOREIGN KEY (role_id) REFERENCES aio_roles(id))');

        $generator = new MigrationCodeGenerator('20260101000000');
        $file      = $generator->generateAllInOne((new SchemaIntrospector($src))->introspectAll());
        $this->writeFiles([$file]);

        $this->assertSame('20260101000000_create_all_tables.php', $file['filename']);
        $this->assertStringContainsString("\$this->table('aio_roles')", $file['content']);
        $this->assertStringContainsString("\$this->table('aio_users')", $file['content']);

        $dst = $this->freshPdo();
        require_once $this->migrationPath . '/' . $file['filename'];
        $class = $file['class'];
        (new $class($dst))->up();

        $this->assertSame(['aio_roles', 'aio_users'], $this->tableNames($dst));

        (new $class($dst))->down();
        $this->assertSame([], $this->tableNames($dst));
    }

    public function test_table_prefix_is_stripped_and_reapplied(): void
    {
        $src = $this->freshPdo();
        $src->exec('CREATE TABLE pf_demo (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(100) NOT NULL)');

        $generator = new MigrationCodeGenerator('20260101000000');
        $files     = $generator->generatePerTable((new SchemaIntrospector($src, 'pf_'))->introspectAll());
        $this->writeFiles($files);

        // 生成的迁移使用逻辑表名 demo
        $this->assertStringContainsString("\$this->table('demo')", $files[0]['content']);

        // 执行时带前缀 pf_，应创建物理表 pf_demo
        $dst = $this->freshPdo();
        require_once $this->migrationPath . '/' . $files[0]['filename'];
        $class = $files[0]['class'];
        (new $class($dst, 'pf_'))->up();

        $this->assertSame(['pf_demo'], $this->tableNames($dst));
    }

    public function test_mark_as_ran_inserts_records_in_one_batch(): void
    {
        $pdo     = $this->freshPdo();
        $manager = new MigrationManager($pdo, $this->migrationPath, $this->tempDir . '/seeders');

        $manager->markAsRan(['20260101000000_create_demo_table.php', '20260101000001_create_post_table.php']);

        $rows = $pdo->query('SELECT migration, batch FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertSame('20260101000000_create_demo_table.php', $rows[0]['migration']);
        $this->assertSame('20260101000001_create_post_table.php', $rows[1]['migration']);
        $this->assertSame((int) $rows[0]['batch'], (int) $rows[1]['batch']);
    }

    public function test_command_end_to_end_with_record(): void
    {
        $dbFile = $this->tempDir . '/reverse.sqlite';
        $src    = new PDO('sqlite:' . $dbFile);
        $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $src->exec('CREATE TABLE cmd_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL)');
        $src->exec('CREATE TABLE cmd_users (id INTEGER PRIMARY KEY AUTOINCREMENT, role_id INTEGER NOT NULL, FOREIGN KEY (role_id) REFERENCES cmd_roles(id))');
        $src = null;

        $app     = $this->createAppWithSqlite($dbFile);
        $console = $app->container->get(ConsoleApplication::class);
        $code    = $console->run(new Input(['migrate:reverse', '--record']), new Output());

        $this->assertSame(0, $code);

        $migDir = $this->tempDir . '/database/migrations';
        $files  = glob($migDir . '/*.php');
        $this->assertCount(2, $files);

        $check = new PDO('sqlite:' . $dbFile);
        $rows  = $check->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $rows);
    }

    public function test_command_respects_table_filter_and_ignore(): void
    {
        $dbFile = $this->tempDir . '/filter.sqlite';
        $src    = new PDO('sqlite:' . $dbFile);
        $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $src->exec('CREATE TABLE keep_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL)');
        $src->exec('CREATE TABLE keep_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL)');
        $src->exec('CREATE TABLE ignore_me (id INTEGER PRIMARY KEY AUTOINCREMENT)');
        $src = null;

        $app     = $this->createAppWithSqlite($dbFile);
        $console = $app->container->get(ConsoleApplication::class);
        $code    = $console->run(new Input(['migrate:reverse', '-t', 'keep_roles,keep_users', '--ignore', 'ignore_me']), new Output());

        $this->assertSame(0, $code);

        $migDir = $this->tempDir . '/database/migrations';
        $files  = array_map('basename', glob($migDir . '/*.php'));
        $this->assertCount(2, $files);
        foreach ($files as $f) {
            $this->assertStringNotContainsString('ignore', $f);
        }
    }

    private function freshPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * @param list<array{filename: string, class: string, content: string}> $files
     * @return array<string, string> filename => content
     */
    private function writeFiles(array $files): array
    {
        $written = [];
        foreach ($files as $file) {
            file_put_contents($this->migrationPath . '/' . $file['filename'], $file['content']);
            $written[$file['filename']] = $file['content'];
        }

        return $written;
    }

    /**
     * @return list<string>
     */
    private function columnNames(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA table_info(`{$table}`)");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return array_values(array_column($rows, 'name'));
    }

    /**
     * @return list<string>
     */
    private function tableNames(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name != 'migrations' ORDER BY name");

        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    private function createApp(): Application
    {
        return new Application(basePath: $this->tempDir);
    }

    private function createAppWithSqlite(string $dbFile): Application
    {
        $configDir = $this->tempDir . '/config';
        if (!is_dir($configDir)) {
            mkdir($configDir, 0777, true);
        }

        file_put_contents(
            $configDir . '/database.php',
            '<?php return ' . var_export([
                'default'     => 'sqlite',
                'connections' => [
                    'sqlite' => ['type' => 'sqlite', 'database' => $dbFile, 'prefix' => ''],
                ],
            ], true) . ';'
        );

        return new Application(basePath: $this->tempDir);
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
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
