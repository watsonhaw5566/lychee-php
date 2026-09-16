<?php

declare(strict_types=1);

namespace Lychee\migration\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\migration\MigrationManager;
use Throwable;

/**
 * 执行迁移命令。
 *
 * 用法：php lee migrate:run
 */
class MigrateRunCommand extends Command
{
    protected string $name = 'migrate:run';

    protected string $description = 'Run all pending database migrations';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
    }

    protected function execute(Input $input, Output $output): int
    {
        $manager = $this->getMigrationManager();

        if ($manager === null) {
            $output->writeln('<error>Database connection is not available. Please configure database first.</error>');

            return 1;
        }

        try {
            $count = $manager->run(function (string $message, string $style) use ($output): void {
                $output->writeln("<{$style}>{$message}</{$style}>");
            });

            $output->writeln("<info>Migrations completed ({$count}).</info>");

            return 0;
        } catch (Throwable $e) {
            $output->writeln("<error>Migration failed: {$e->getMessage()}</error>");

            return 1;
        }
    }

    private function getMigrationManager(): ?MigrationManager
    {
        if (!$this->app->bound(MigrationManager::class)) {
            return null;
        }

        /** @var MigrationManager $manager */
        $manager = $this->app->get(MigrationManager::class);

        return $manager;
    }
}
