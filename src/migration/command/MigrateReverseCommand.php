<?php

declare(strict_types=1);

namespace Lychee\migration\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\console\input\Option;
use Lychee\migration\MigrationManager;
use Lychee\migration\schema\MigrationCodeGenerator;
use Lychee\migration\schema\SchemaIntrospector;
use Lychee\orm\ConnectionManager;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 从现有数据库结构反向生成迁移文件。
 *
 * 用法：
 *   php lee migrate:reverse                    # 每张表一个迁移文件
 *   php lee migrate:reverse -a                 # 所有表合并到一个文件
 *   php lee migrate:reverse -t users,posts     # 仅生成指定表（逗号分隔）
 *   php lee migrate:reverse -c production      # 使用指定数据库连接
 *   php lee migrate:reverse --ignore=cache     # 排除指定表
 *   php lee migrate:reverse --record           # 生成后标记为已执行（已有库接管）
 */
class MigrateReverseCommand extends Command
{
    protected string $name = 'migrate:reverse';

    protected string $description = 'Generate migration files from an existing database schema';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
        $this->addOption('all', 'a', Option::VALUE_NONE, 'Generate all tables into a single migration file');
        $this->addOption('table', 't', Option::VALUE_REQUIRED, 'Only generate specified tables (comma-separated)');
        $this->addOption('connection', 'c', Option::VALUE_REQUIRED, 'Database connection name to use');
        $this->addOption('ignore', null, Option::VALUE_REQUIRED, 'Tables to ignore (comma-separated)');
        $this->addOption('force', 'f', Option::VALUE_NONE, 'Overwrite existing migration files');
        $this->addOption('record', 'r', Option::VALUE_NONE, 'Mark generated migrations as already executed');
    }

    protected function execute(Input $input, Output $output): int
    {
        $connectionName = $input->getOption('connection');
        $connectionName = is_string($connectionName) && $connectionName !== '' ? $connectionName : null;

        try {
            [$pdo, $prefix] = $this->resolveConnection($connectionName);
        } catch (Throwable $e) {
            $output->writeln('<error>Database connection is not available: ' . $e->getMessage() . '</error>');

            return 1;
        }

        $introspector = new SchemaIntrospector($pdo, $prefix);

        $realTables = $introspector->listTables();
        if ($realTables === []) {
            $output->writeln('<comment>No tables found in database.</comment>');

            return 0;
        }

        $realByLogical = [];
        foreach ($realTables as $real) {
            $realByLogical[$introspector->logicalName($real)] = $real;
        }

        $ignoreMap = array_flip($this->parseList($input->getOption('ignore')));

        $tableFilter = $this->parseList($input->getOption('table'));
        if ($tableFilter !== []) {
            $selected = [];
            foreach ($tableFilter as $logical) {
                if (isset($realByLogical[$logical])) {
                    $selected[] = $realByLogical[$logical];
                } else {
                    $output->writeln("<comment>Table '{$logical}' not found, skipped.</comment>");
                }
            }
            $realTables = $selected;
        }

        $realTables = array_values(array_filter(
            $realTables,
            static fn (string $real): bool => !isset($ignoreMap[$introspector->logicalName($real)])
        ));

        if ($realTables === []) {
            $output->writeln('<comment>No tables to generate after filtering.</comment>');

            return 0;
        }

        $schemas = [];
        foreach ($realTables as $real) {
            $schemas[] = $introspector->introspectTable($real);
        }

        $generator = new MigrationCodeGenerator();
        $all       = (bool) $input->getOption('all');
        $files     = $all ? [$generator->generateAllInOne($schemas)] : $generator->generatePerTable($schemas);

        $dir = rtrim(base_path('database/migrations'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: {$dir}");
        }

        $force   = (bool) $input->getOption('force');
        $written = [];
        $skipped = [];

        foreach ($files as $file) {
            $path = $dir . $file['filename'];
            if (file_exists($path) && !$force) {
                $skipped[] = $file['filename'];
                $output->writeln("<comment>Skipped (exists, use --force): {$file['filename']}</comment>");

                continue;
            }

            file_put_contents($path, $file['content']);
            $written[] = $file['filename'];
            $output->writeln("<info>Created:</info> {$file['filename']}");
        }

        if ($written === []) {
            $output->writeln('<comment>No migration files written.</comment>');

            return 0;
        }

        if ((bool) $input->getOption('record')) {
            $manager = new MigrationManager(
                pdo: $pdo,
                migrationPath: rtrim(base_path('database/migrations'), DIRECTORY_SEPARATOR),
                seederPath: rtrim(base_path('database/seeders'), DIRECTORY_SEPARATOR),
                prefix: $prefix,
            );
            $manager->markAsRan($written);
            $output->writeln('<info>Marked as ran:</info> ' . implode(', ', $written));
        }

        $output->writeln(sprintf(
            '<info>Reverse migration completed (%d written, %d skipped).</info>',
            count($written),
            count($skipped)
        ));

        return 0;
    }

    /**
     * @return array{0: PDO, 1: string}
     */
    private function resolveConnection(?string $connectionName): array
    {
        if (!$this->app->bound(ConnectionManager::class) && !$this->app->bound('db')) {
            throw new RuntimeException('database is not configured');
        }

        /** @var ConnectionManager $manager */
        $manager = $this->app->bound(ConnectionManager::class)
            ? $this->app->get(ConnectionManager::class)
            : $this->app->get('db');

        $connection = $manager->connect($connectionName);

        return [$connection->getPdo(), (string) $connection->getConfig('prefix', '')];
    }

    /**
     * 解析逗号分隔的列表选项。
     *
     * @return list<string>
     */
    private function parseList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $items = array_map('trim', explode(',', $value));

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }
}
