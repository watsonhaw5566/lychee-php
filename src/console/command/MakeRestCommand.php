<?php

declare(strict_types=1);

namespace Lychee\console\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\console\input\Argument;
use RuntimeException;

/**
 * 创建资源控制器命令（同时生成 Model 与 Validate）。
 *
 * 用法：php lee make:rest User
 */
class MakeRestCommand extends Command
{
    protected string $name = 'make:rest';

    protected string $description = 'Create a new resource controller (with Model & Validate)';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
        $this->addArgument('name', Argument::REQUIRED, 'The name of the resource (e.g. User)');
    }

    protected function execute(Input $input, Output $output): int
    {
        $name = trim((string) $input->getArgument('name'));

        if ($name === '') {
            $output->writeln('<error>Resource name cannot be empty.</error>');

            return 1;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $output->writeln('<error>Resource name may only contain letters, numbers and underscores.</error>');

            return 1;
        }

        // 首字母大写规范化：user → User，userController → UserController
        $name = ucfirst($name);

        $appNamespace = $this->app->getNamespace();

        // 统一处理：输入 User 或 UserController 都生成 UserController
        $modelName = preg_replace('/Controller$/', '', $name);
        $className = $modelName . 'Controller';
        // 资源路径保留用户输入的小写形式，不做复数化
        $resource = '/' . strtolower($modelName);

        $controllerPath = app_path('controller') . $className . '.php';
        $modelPath      = app_path('model') . $modelName . '.php';
        $validateName   = $modelName . 'Validate';
        $validatePath   = app_path('validate') . $validateName . '.php';

        // 控制器已存在则中止，避免覆盖业务代码
        if (file_exists($controllerPath)) {
            $output->writeln("<error>Controller file already exists: {$className}.php</error>");

            return 1;
        }

        $replace = [
            '{{namespace}}' => $appNamespace,
            '{{class}}'     => $className,
            '{{model}}'     => $modelName,
            '{{resource}}'  => $resource,
        ];

        $this->writeStub($controllerPath, 'controller.stub', $replace);
        $output->writeln("<info>Created controller:</info> {$className}.php");

        // Model 已存在则跳过，仅在不存在时创建
        if (file_exists($modelPath)) {
            $output->writeln("<comment>Skipped model:</comment>      {$modelName}.php (already exists)");
        } else {
            $this->writeStub($modelPath, 'model.stub', $replace);
            $output->writeln("<info>Created model:</info>      {$modelName}.php");
        }

        // Validate 已存在则跳过，仅在不存在时创建
        if (file_exists($validatePath)) {
            $output->writeln("<comment>Skipped validate:</comment>   {$validateName}.php (already exists)");
        } else {
            $this->writeStub($validatePath, 'validate.stub', $replace);
            $output->writeln("<info>Created validate:</info>   {$validateName}.php");
        }

        return 0;
    }

    /**
     * 读取 stub 模板并替换占位符后写入目标文件。
     *
     * @param array<string, string> $replace
     */
    private function writeStub(string $path, string $stub, array $replace): void
    {
        $stubPath = dirname(__DIR__) . '/stubs/' . $stub;
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
