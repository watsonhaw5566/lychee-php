<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\migration\MigrationManager;
use Lychee\migration\Table;
use PHPUnit\Framework\TestCase;
use PDO;

class MigrationTest extends TestCase
{
    private string $tempDir;
    private string $migrationPath;
    private string $seederPath;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->tempDir       = sys_get_temp_dir() . '/lychee_migration_test_' . uniqid();
        $this->migrationPath = $this->tempDir . '/migrations';
        $this->seederPath    = $this->tempDir . '/seeders';

        mkdir($this->migrationPath, 0777, true);
        mkdir($this->seederPath, 0777, true);

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_get_migrations_discovers_php_files(): void
    {
        $this->createMigrationFile('20240101000000_create_users_table.php', 'CreateUsersTableMigration');
        $this->createMigrationFile('20240102000000_create_posts_table.php', 'CreatePostsTableMigration');

        $pdo     = $this->createStub(PDO::class);
        $manager = new MigrationManager($pdo, $this->migrationPath, $this->seederPath);

        $migrations = $manager->getMigrations();

        $this->assertCount(2, $migrations);
        $this->assertArrayHasKey('20240101000000', $migrations);
        $this->assertArrayHasKey('20240102000000', $migrations);
        $this->assertSame('CreateUsersTableMigration', $migrations['20240101000000']['class']);
        $this->assertSame('CreatePostsTableMigration', $migrations['20240102000000']['class']);
    }

    public function test_get_migrations_ignores_non_standard_files(): void
    {
        file_put_contents($this->migrationPath . '/invalid.php', '<?php echo "not a migration";');
        $this->createMigrationFile('20240101000000_create_users_table.php', 'CreateUsersTable');

        $pdo     = $this->createStub(PDO::class);
        $manager = new MigrationManager($pdo, $this->migrationPath, $this->seederPath);

        $migrations = $manager->getMigrations();

        $this->assertCount(1, $migrations);
    }

    public function test_get_seeders_discovers_php_files(): void
    {
        $this->createSeederFile('UserSeeder.php', 'UserSeeder');
        $this->createSeederFile('PostSeeder.php', 'PostSeeder');

        $pdo     = $this->createStub(PDO::class);
        $manager = new MigrationManager($pdo, $this->migrationPath, $this->seederPath);

        $seeders = $manager->getSeeders();

        $this->assertCount(2, $seeders);
    }

    public function test_get_migrations_sorted_by_version(): void
    {
        $this->createMigrationFile('20240103000000_third.php', 'Third');
        $this->createMigrationFile('20240101000000_first.php', 'First');
        $this->createMigrationFile('20240102000000_second.php', 'Second');

        $pdo     = $this->createStub(PDO::class);
        $manager = new MigrationManager($pdo, $this->migrationPath, $this->seederPath);

        $versions = array_map('strval', array_keys($manager->getMigrations()));

        $this->assertSame(['20240101000000', '20240102000000', '20240103000000'], $versions);
    }

    private function createMigrationFile(string $filename, string $className): void
    {
        $content = <<<PHP
<?php

use Lychee\migration\Migration;

class {$className} extends Migration
{
    public function up(): void {}
    public function down(): void {}
}
PHP;
        file_put_contents($this->migrationPath . '/' . $filename, $content);
    }

    private function createSeederFile(string $filename, string $className): void
    {
        $content = <<<PHP
<?php

use Lychee\migration\Seeder;

class {$className} extends Seeder
{
    public function run(): void {}
}
PHP;
        file_put_contents($this->seederPath . '/' . $filename, $content);
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

    /**
     * 获取表的列信息。
     *
     * @return array<string, array{name: string, type: string, notnull: int, dflt_value: string|null}>
     */
    private function getColumns(string $table): array
    {
        $stmt = $this->pdo->query(sprintf('PRAGMA table_info(`%s`)', $table));
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $columns = [];
        foreach ($rows as $row) {
            $columns[$row['name']] = [
                'name'       => $row['name'],
                'type'       => $row['type'],
                'notnull'    => (int)$row['notnull'],
                'dflt_value' => $row['dflt_value'],
            ];
        }

        return $columns;
    }

    public function test_add_datetimes_creates_create_and_update_time_as_datetime(): void
    {
        $table = new Table('test_datetimes', $this->pdo);
        $table->addDatetimes()->create();

        $columns = $this->getColumns('test_datetimes');

        $this->assertArrayHasKey('create_time', $columns);
        $this->assertArrayHasKey('update_time', $columns);
        $this->assertSame('DATETIME', strtoupper($columns['create_time']['type']));
        $this->assertSame('DATETIME', strtoupper($columns['update_time']['type']));
    }

    public function test_add_datetimes_create_time_is_not_null(): void
    {
        $table = new Table('test_datetimes_notnull', $this->pdo);
        $table->addDatetimes()->create();

        $columns = $this->getColumns('test_datetimes_notnull');

        $this->assertSame(1, $columns['create_time']['notnull']);
    }

    public function test_add_datetimes_update_time_is_nullable(): void
    {
        $table = new Table('test_datetimes_nullable', $this->pdo);
        $table->addDatetimes()->create();

        $columns = $this->getColumns('test_datetimes_nullable');

        $this->assertSame(0, $columns['update_time']['notnull']);
    }

    public function test_add_datetimes_supports_custom_field_names(): void
    {
        $table = new Table('test_datetimes_custom', $this->pdo);
        $table->addDatetimes('created_at', 'updated_at')->create();

        $columns = $this->getColumns('test_datetimes_custom');

        $this->assertArrayHasKey('created_at', $columns);
        $this->assertArrayHasKey('updated_at', $columns);
        $this->assertArrayNotHasKey('create_time', $columns);
        $this->assertArrayNotHasKey('update_time', $columns);
    }

    public function test_add_timestamps_uses_timestamp_type(): void
    {
        $table = new Table('test_timestamps', $this->pdo);
        $table->addTimestamps()->create();

        $columns = $this->getColumns('test_timestamps');

        $this->assertSame('TIMESTAMP', strtoupper($columns['create_time']['type']));
        $this->assertSame('TIMESTAMP', strtoupper($columns['update_time']['type']));
    }

    public function test_add_soft_delete_creates_nullable_delete_time(): void
    {
        $table = new Table('test_soft_delete', $this->pdo);
        $table->addSoftDelete()->create();

        $columns = $this->getColumns('test_soft_delete');

        $this->assertArrayHasKey('delete_time', $columns);
        $this->assertSame(0, $columns['delete_time']['notnull']);
    }

    public function test_add_soft_delete_supports_custom_name(): void
    {
        $table = new Table('test_soft_delete_custom', $this->pdo);
        $table->addSoftDelete('deleted_at')->create();

        $columns = $this->getColumns('test_soft_delete_custom');

        $this->assertArrayHasKey('deleted_at', $columns);
        $this->assertArrayNotHasKey('delete_time', $columns);
    }
}
