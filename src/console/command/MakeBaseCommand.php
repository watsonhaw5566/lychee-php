<?php

declare(strict_types=1);

namespace Lychee\console\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\console\input\Argument;
use RuntimeException;

/**
 * 创建基础控制器命令（不集成资源路由，方法体为空）。
 *
 * 用法：php lee make:base Index
 */
class MakeBaseCommand extends Command
{
    protected string $name = 'make:base';

    protected string $description = 'Create a new basic controller (without resource routing)';

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
        $this->addArgument('name', Argument::REQUIRED, 'The name of the controller (e.g. Index)');
    }

    protected function execute(Input $input, Output $output): int
    {
        $name = trim((string) $input->getArgument('name'));

        if ($name === '') {
            $output->writeln('<error>Controller name cannot be empty.</error>');

            return 1;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            $output->writeln('<error>Controller name may only contain letters, numbers and underscores.</error>');

            return 1;
        }

        // 首字母大写规范化：index → Index，indexController → IndexController
        $name = ucfirst($name);

        $appNamespace = $this->app->getNamespace();

        // 统一处理：输入 Index 或 IndexController 都生成 IndexController
        $modelName = preg_replace('/Controller$/', '', $name);
        $className = $modelName . 'Controller';
        // 路由路径保留用户输入的小写形式，不做复数化
        $resource = '/' . strtolower($modelName);

        $controllerPath = app_path('controller') . $className . '.php';

        // 控制器已存在则中止，避免覆盖业务代码
        if (file_exists($controllerPath)) {
            $output->writeln("<error>Controller file already exists: {$className}.php</error>");

            return 1;
        }

        $replace = [
            '{{namespace}}' => $appNamespace,
            '{{class}}'     => $className,
            '{{resource}}'  => $resource,
        ];

        $this->writeStub($controllerPath, 'base_controller.stub', $replace);
        $output->writeln("<info>Created controller:</info> {$className}.php");

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
