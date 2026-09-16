<?php

declare(strict_types=1);

namespace Lychee\migration\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\migration\MigrationManager;
use Throwable;

/**
 * 数据填充命令。
 *
 * 用法：php lee seed:run
 */
class SeedRunCommand extends Command
{
    protected string $name = 'seed:run';

    protected string $description = 'Run database seeders';

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
            $manager->seed(function (string $message, string $style) use ($output): void {
                $output->writeln("<{$style}>{$message}</{$style}>");
            });

            $output->writeln('<info>Seeding completed.</info>');

            return 0;
        } catch (Throwable $e) {
            $output->writeln("<error>Seeding failed: {$e->getMessage()}</error>");

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
