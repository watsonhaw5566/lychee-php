<?php

declare(strict_types=1);

namespace Lychee\migration;

use Closure;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 迁移管理器。
 *
 * 负责发现迁移文件、执行/回滚迁移，并在数据库中记录已执行的迁移版本。
 */
class MigrationManager
{
    public const MIGRATION_TABLE = 'migrations';

    /** @var array<int, string> 迁移目录路径列表 */
    private array $migrationPaths;

    /** @var array<int, string> Seeder 目录路径列表 */
    private array $seederPaths;

    public function __construct(
        private readonly PDO $pdo,
        string $migrationPath,
        string $seederPath,
        private readonly string $prefix = '',
    ) {
        $this->migrationPaths = [$migrationPath];
        $this->seederPaths    = [$seederPath];
    }

    /**
     * 追加迁移目录（供插件注册自身的迁移文件）。
     */
    public function addMigrationPath(string $path): void
    {
        if (!in_array($path, $this->migrationPaths, true)) {
            $this->migrationPaths[] = $path;
        }
    }

    /**
     * 追加 Seeder 目录（供插件注册自身的 seed 文件）。
     */
    public function addSeederPath(string $path): void
    {
        if (!in_array($path, $this->seederPaths, true)) {
            $this->seederPaths[] = $path;
        }
    }

    /**
     * 执行所有未执行的迁移。
     *
     * @param  Closure(string, string):void|null $output 输出回调 (message, style)
     * @return int 执行的迁移数量
     */
    public function run(?Closure $output = null): int
    {
        $this->ensureMigrationTable();

        $migrations = $this->getMigrations();
        $ran        = $this->getRanMigrations();
        $pending    = array_diff_key($migrations, $ran);

        if (empty($pending)) {
            $this->writeln($output, 'Nothing to migrate.');

            return 0;
        }

        $count = 0;

        foreach ($pending as $version => $migration) {
            $this->writeln($output, "Migrating: {$migration['name']}");

            $instance = $this->instantiateMigration($migration['file'], $migration['class']);

            try {
                $this->pdo->beginTransaction();
                $instance->up();
                $this->recordMigration((string) $version, $migration['name']);
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw new RuntimeException(
                    "Migration {$migration['name']} failed: {$e->getMessage()}",
                    (int) $e->getCode(),
                    $e
                );
            }

            $this->writeln($output, "Migrated:  {$migration['name']}");
            $count++;
        }

        return $count;
    }

    /**
     * 回滚最近一批迁移。
     *
     * @param  int                           $steps  回滚步数（0 表示回滚全部）
     * @param  Closure(string, string):void|null $output
     * @return int 回滚的迁移数量
     */
    public function rollback(int $steps = 0, ?Closure $output = null): int
    {
        $this->ensureMigrationTable();

        $migrations = $this->getMigrations();
        $ran        = $this->getRanMigrations();

        $executed = array_intersect_key($migrations, $ran);
        krsort($executed);

        if (empty($executed)) {
            $this->writeln($output, 'Nothing to rollback.');

            return 0;
        }

        $toRollback = $steps > 0 ? array_slice($executed, 0, $steps, true) : $executed;

        $count = 0;

        foreach ($toRollback as $version => $migration) {
            $this->writeln($output, "Rolling back: {$migration['name']}");

            $instance = $this->instantiateMigration($migration['file'], $migration['class']);

            try {
                $this->pdo->beginTransaction();
                $instance->down();
                $this->deleteMigration((string) $version);
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw new RuntimeException(
                    "Rollback of {$migration['name']} failed: {$e->getMessage()}",
                    (int) $e->getCode(),
                    $e
                );
            }

            $this->writeln($output, "Rolled back:  {$migration['name']}");
            $count++;
        }

        return $count;
    }

    /**
     * 执行所有 Seeder。
     *
     * @param  Closure(string, string):void|null $output
     */
    public function seed(?Closure $output = null): void
    {
        $seeders = $this->getSeeders();

        if (empty($seeders)) {
            $this->writeln($output, 'No seeders found.');

            return;
        }

        foreach ($seeders as $seeder) {
            $this->writeln($output, "Seeding: {$seeder['name']}");

            require_once $seeder['file'];

            if (!class_exists($seeder['class'])) {
                throw new InvalidArgumentException(
                    "Could not find class '{$seeder['class']}' in file '{$seeder['file']}'"
                );
            }

            /** @var Seeder $instance */
            $instance = new $seeder['class']($this->pdo, $this->prefix);

            try {
                $this->pdo->beginTransaction();
                $instance->run();
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw new RuntimeException(
                    "Seeder {$seeder['name']} failed: {$e->getMessage()}",
                    (int) $e->getCode(),
                    $e
                );
            }

            $this->writeln($output, "Seeded:  {$seeder['name']}");
        }
    }

    /**
     * 获取所有迁移文件（扫描所有已注册的迁移目录）。
     *
     * @return array<string, array{name: string, class: string, file: string}>
     */
    public function getMigrations(): array
    {
        $migrations = [];

        foreach ($this->migrationPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . DIRECTORY_SEPARATOR . '*.php') ?: [];

            foreach ($files as $file) {
                $basename = basename($file, '.php');

                if (!preg_match('/^(\d{14})_(.+)$/', $basename, $matches)) {
                    continue;
                }

                $version = $matches[1];
                $name    = $matches[2];
                $class   = self::toMigrationClassName($name);

                $migrations[$version] = [
                    'name'  => $basename,
                    'class' => $class,
                    'file'  => $file,
                ];
            }
        }

        ksort($migrations);

        return $migrations;
    }

    /**
     * 获取所有 Seeder 文件（扫描所有已注册的 Seeder 目录）。
     *
     * @return array<int, array{name: string, class: string, file: string}>
     */
    public function getSeeders(): array
    {
        $seeders = [];

        foreach ($this->seederPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . DIRECTORY_SEPARATOR . '*.php') ?: [];

            foreach ($files as $file) {
                $basename = basename($file, '.php');
                $class    = self::toSeederClassName($basename);

                $seeders[] = [
                    'name'  => $basename,
                    'class' => $class,
                    'file'  => $file,
                ];
            }
        }

        return $seeders;
    }

    private function instantiateMigration(string $file, string $class): Migration
    {
        require_once $file;

        if (!class_exists($class)) {
            throw new InvalidArgumentException(
                "Could not find class '{$class}' in file '{$file}'"
            );
        }

        /** @var Migration $instance */
        $instance = new $class($this->pdo, $this->prefix);

        if (!$instance instanceof Migration) {
            throw new InvalidArgumentException(
                "The class '{$class}' must extend " . Migration::class
            );
        }

        return $instance;
    }

    private function ensureMigrationTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$this->table()}` (
                    `id` INTEGER PRIMARY KEY,
                    `migration` VARCHAR(255) NOT NULL,
                    `batch` INTEGER NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )"
            );

            return;
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table()}` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(255) NOT NULL,
                `batch` INT UNSIGNED NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /**
     * @return array<string, string>
     */
    private function getRanMigrations(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM `{$this->table()}`");

        if (!$stmt) {
            return [];
        }

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (preg_match('/^(\d{14})_/', (string) $name, $m)) {
                $result[$m[1]] = $name;
            }
        }

        return $result;
    }

    private function recordMigration(string $version, string $name): void
    {
        $batch = $this->getNextBatch();

        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->table()}` (migration, batch) VALUES (?, ?)"
        );
        $stmt->execute([$name, $batch]);
    }

    private function deleteMigration(string $version): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM `{$this->table()}` WHERE migration LIKE ?"
        );
        $stmt->execute(["{$version}_%"]);
    }

    private function getNextBatch(): int
    {
        $stmt = $this->pdo->query("SELECT MAX(batch) FROM `{$this->table()}`");
        $max  = $stmt ? (int) $stmt->fetchColumn() : 0;

        return $max + 1;
    }

    /**
     * 将下划线/短横线分隔的名称转为 PascalCase 类名。
     */
    public static function toClassName(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $name)));
    }

    /**
     * 生成迁移类名，自动追加 Migration 后缀（若尚未存在）。
     */
    public static function toMigrationClassName(string $name): string
    {
        $class = self::toClassName($name);

        return str_ends_with($class, 'Migration') ? $class : $class . 'Migration';
    }

    /**
     * 生成 Seeder 类名，自动追加 Seeder 后缀（若尚未存在）。
     */
    public static function toSeederClassName(string $name): string
    {
        $class = self::toClassName($name);

        return str_ends_with($class, 'Seeder') ? $class : $class . 'Seeder';
    }

    private function table(): string
    {
        return self::MIGRATION_TABLE;
    }

    /**
     * @param  Closure(string, string):void|null $output
     */
    private function writeln(?Closure $output, string $message): void
    {
        if ($output) {
            $output($message, 'info');
        }
    }
}
