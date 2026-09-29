<?php

declare(strict_types=1);

namespace Lychee\migration\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\console\input\Argument;
use Lychee\console\input\Option;
use Lychee\migration\SqlSeederRunner;
use Throwable;

/**
 * 导入本地裸 SQL 种子脚本命令。
 *
 * 用法：
 *   php lee seed:sql                  执行 database/sql 下所有未导入的脚本
 *   php lee seed:sql cod_regions.sql  仅执行指定文件
 *   php lee seed:sql --force          已导入过的文件也重新执行
 */
class SeedSqlCommand extends Command
{
    protected string $name = 'seed:sql';

    protected string $description = 'Import raw SQL seed scripts from database/sql';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
        $this->addArgument('filename', Argument::OPTIONAL, 'Only import the given file (e.g. cod_regions.sql)');
        $this->addOption('force', 'f', Option::VALUE_NONE, 'Re-run files even if they were already imported');
    }

    protected function execute(Input $input, Output $output): int
    {
        if (!$this->app->bound(SqlSeederRunner::class)) {
            $output->writeln('<error>Database connection is not available. Please configure database first.</error>');

            return 1;
        }

        /** @var SqlSeederRunner $runner */
        $runner = $this->app->get(SqlSeederRunner::class);

        $filename = $input->getArgument('filename');
        $force    = (bool) $input->getOption('force');

        try {
            $count = $runner->run(
                $filename !== null ? trim((string) $filename) : null,
                $force,
                function (string $message, string $style) use ($output): void {
                    $output->writeln("<{$style}>{$message}</{$style}>");
                }
            );

            $output->writeln("<info>SQL import completed ({$count}).</info>");

            return 0;
        } catch (Throwable $e) {
            $output->writeln("<error>SQL import failed: {$e->getMessage()}</error>");
            $output->writeln('<comment>Tip: fix the database state manually (e.g. DROP the partial table), then re-run with --force on an idempotent script.</comment>');

            return 1;
        }
    }
}
