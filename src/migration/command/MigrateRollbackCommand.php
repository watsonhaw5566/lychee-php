<?php

declare(strict_types=1);

namespace Lychee\migration\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\migration\MigrationManager;
use Throwable;

/**
 * 回滚迁移命令。
 *
 * 用法：php lee migrate:rollback [--steps=1]
 */
class MigrateRollbackCommand extends Command
{
    protected string $name = 'migrate:rollback';

    protected string $description = 'Rollback the last database migration(s)';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
        $this->addOption('steps', 's', \Lychee\console\input\Option::VALUE_REQUIRED, 'Number of migrations to rollback (0 = all)', 0);
    }

    protected function execute(Input $input, Output $output): int
    {
        $manager = $this->getMigrationManager();

        if ($manager === null) {
            $output->writeln('<error>Database connection is not available. Please configure database first.</error>');

            return 1;
        }

        $steps = (int) ($input->getOption('steps') ?? 0);

        try {
            $count = $manager->rollback($steps, function (string $message, string $style) use ($output): void {
                $output->writeln("<{$style}>{$message}</{$style}>");
            });

            $output->writeln("<info>Rollback completed ({$count}).</info>");

            return 0;
        } catch (Throwable $e) {
            $output->writeln("<error>Rollback failed: {$e->getMessage()}</error>");

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
