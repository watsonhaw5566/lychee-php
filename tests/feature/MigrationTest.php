<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\migration\MigrationManager;
use PHPUnit\Framework\TestCase;
use PDO;

class MigrationTest extends TestCase
{
    private string $tempDir;
    private string $migrationPath;
    private string $seederPath;

    protected function setUp(): void
    {
        $this->tempDir       = sys_get_temp_dir() . '/lychee_migration_test_' . uniqid();
        $this->migrationPath = $this->tempDir . '/migrations';
        $this->seederPath    = $this->tempDir . '/seeders';

        mkdir($this->migrationPath, 0777, true);
        mkdir($this->seederPath, 0777, true);
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
}
