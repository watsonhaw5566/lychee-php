<?php

declare(strict_types=1);

namespace Lychee\migration\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\console\input\Argument;
use Lychee\migration\MigrationManager;
use RuntimeException;

/**
 * 创建迁移模板文件命令。
 *
 * 用法：php lee migrate:create create_users_table
 */
class MigrateCreateCommand extends Command
{
    protected string $name = 'migrate:create';

    protected string $description = 'Create a new migration file';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
        $this->addArgument('name', Argument::REQUIRED, 'The name of the migration');
    }

    protected function execute(Input $input, Output $output): int
    {
        $name = trim((string) $input->getArgument('name'));

        if ($name === '') {
            $output->writeln('<error>Migration name cannot be empty.</error>');

            return 1;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/', $name)) {
            $output->writeln('<error>Migration name may only contain letters, numbers, underscores and dashes.</error>');

            return 1;
        }

        $timestamp = date('YmdHis');
        $filename  = "{$timestamp}_{$name}.php";
        $path      = base_path('database/migrations') . $filename;
        $class     = MigrationManager::toClassName($name);

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Failed to create directory: {$directory}");
        }

        if (file_exists($path)) {
            $output->writeln("<error>Migration file already exists: {$filename}</error>");

            return 1;
        }

        $content = $this->buildTemplate($class);

        file_put_contents($path, $content);

        $output->writeln("<info>Created migration:</info> {$filename}");
        $output->writeln("<comment>{$path}</comment>");

        return 0;
    }

    private function buildTemplate(string $class): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use Lychee\migration\Migration;

class {$class} extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
}

PHP;
    }
}
