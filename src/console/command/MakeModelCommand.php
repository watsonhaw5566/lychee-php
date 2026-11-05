<?php

declare(strict_types=1);

namespace Lychee\console\command;

use Lychee\console\Input;
use Lychee\console\input\Option;
use Lychee\console\ModelColumnBuilder;
use Lychee\console\Output;
use RuntimeException;

/**
 * 生成模型文件。
 *
 * 用法：
 *   php lee make:model User
 *   php lee make:model User --table=users          # 从数据库表结构反向生成 #[Column] 注解
 *   php lee make:model User --table=users --namespace=App
 */
class MakeModelCommand extends MakeBaseCommand
{
    use ModelColumnBuilder;

    protected function configure(): void
    {
        $this->setName('make:model')
            ->setDescription('生成模型文件，支持 --table 从数据库表结构反向生成 #[Column] 注解')
            ->addArgument('name', Option::VALUE_REQUIRED, '模型名称')
            ->addOption('table', 't', Option::VALUE_OPTIONAL, '从指定数据表读取字段生成 #[Column] 注解')
            ->addOption('namespace', null, Option::VALUE_OPTIONAL, '命名空间');
    }

    protected function execute(Input $input, Output $output): int
    {
        $name = trim((string) $input->getArgument('name'));

        if ($name === '') {
            $output->writeln('<error>Model name cannot be empty.</error>');

            return 1;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $output->writeln('<error>Model name may only contain letters, numbers and underscores.</error>');

            return 1;
        }

        $name      = ucfirst($name);
        $namespace = $input->getOption('namespace') ?: $this->app->getNamespace();
        $table     = $input->getOption('table');

        $modelPath = app_path('model') . $name . '.php';

        if (file_exists($modelPath)) {
            $output->writeln("<error>Model file already exists: {$name}.php</error>");

            return 1;
        }

        ['use' => $useStr, 'columns' => $columns] = $this->resolveColumns($table, $output);

        // 指定 --table 时，在模型中声明逻辑表名（无前缀，运行时由连接自动拼接 prefix）
        $tableName = $table ? "    protected \$name = '{$table}';" . "\n" : '';

        $replace = [
            '{{namespace}}' => $namespace,
            '{{model}}'     => $name,
            '{{tableName}}' => $tableName,
            '{{columnUse}}' => $useStr,
            '{{columns}}'   => $columns,
        ];

        $this->writeModelStub($modelPath, $replace);
        $output->writeln("<info>Created model:</info> {$name}.php");
        if ($table) {
            $output->writeln("<info>  from table:</info> {$table}");
        }

        return 0;
    }

    /**
     * 写入模型 stub。
     *
     * @param array<string, string> $replace
     */
    private function writeModelStub(string $path, array $replace): void
    {
        $stubPath = dirname(__DIR__) . '/stubs/model.stub';
        $content  = file_get_contents($stubPath);

        if ($content === false) {
            throw new RuntimeException("Failed to read stub file: {$stubPath}");
        }

        $content = strtr($content, $replace);

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Failed to create directory: {$directory}");
        }

        file_put_contents($path, $content);
    }
}
