<?php

declare(strict_types=1);

namespace Lychee\console\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\console\input\Argument;
use Lychee\console\input\Option;
use RuntimeException;

/**
 * 发布模块配置文件命令。
 *
 * 用法：
 *   php lee config:publish              # 发布所有模块配置
 *   php lee config:publish cache        # 发布指定模块配置
 *   php lee config:publish cache --force # 覆盖已存在的配置文件
 */
class ConfigPublishCommand extends Command
{
    protected string $name = 'config:publish';

    protected string $description = 'Publish module configuration files';

    /**
     * 可发布的模块列表。
     *
     * 键为模块名（对应 config 目录下的文件名），值为说明。
     *
     * @var array<string, string>
     */
    protected array $modules = [
        'app'        => '应用基础配置（时区、路由前缀、异常渲染等）',
        'cache'      => '缓存驱动配置（file / redis）',
        'captcha'    => '验证码配置（图形、短信、邮箱）',
        'cron'       => '定时任务配置',
        'database'   => '数据库连接配置（mysql / sqlite）',
        'filesystem' => '文件系统磁盘配置',
        'i18n'       => '国际化多语言配置',
        'log'        => '日志频道配置',
        'plugin'     => '插件注册配置',
        'queue'      => '队列连接配置（sync / redis）',
        'satoken'    => 'SaToken 认证配置',
        'session'    => '会话 Session 配置',
        'view'       => 'Twig 模板引擎配置',
        'websocket'  => 'WebSocket 服务配置',
    ];

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
        $this->addArgument(
            'module',
            Argument::OPTIONAL,
            'The module name to publish config for (e.g. cache, database). Omit to publish all.'
        );
        $this->addOption(
            'force',
            'f',
            Option::VALUE_NONE,
            'Overwrite existing config files'
        );
    }

    protected function execute(Input $input, Output $output): int
    {
        $module = $input->getArgument('module');
        $force  = (bool) $input->getOption('force');

        if ($module !== null) {
            $module = trim((string) $module);

            if ($module === '') {
                $output->writeln('<error>Module name cannot be empty.</error>');

                return 1;
            }

            if (!isset($this->modules[$module])) {
                $output->writeln("<error>Unknown module: {$module}</error>");
                $output->writeln('<comment>Available modules:</comment> ' . implode(', ', array_keys($this->modules)));

                return 1;
            }

            return $this->publishOne($module, $force, $output);
        }

        return $this->publishAll($force, $output);
    }

    /**
     * 发布单个模块的配置文件。
     */
    private function publishOne(string $module, bool $force, Output $output): int
    {
        $configPath = $this->app->getConfigPath() . $module . '.php';
        $stubPath   = dirname(__DIR__) . '/stubs/config/' . $module . '.stub';

        if (!is_file($stubPath)) {
            $output->writeln("<error>Stub file not found for module: {$module}</error>");

            return 1;
        }

        if (is_file($configPath) && !$force) {
            $output->writeln("<comment>Skipped:</comment> config/{$module}.php (already exists, use --force to overwrite)");

            return 0;
        }

        $this->writeStub($configPath, $stubPath);

        $action = (is_file($configPath) && $force) ? 'Overwrote' : 'Created';
        $output->writeln("<info>{$action} config:</info> config/{$module}.php");

        return 0;
    }

    /**
     * 发布所有模块的配置文件。
     */
    private function publishAll(bool $force, Output $output): int
    {
        $output->writeln('<info>Publishing all module configs...</info>');
        $output->writeln('');

        $created = 0;
        $skipped = 0;

        foreach (array_keys($this->modules) as $module) {
            $configPath = $this->app->getConfigPath() . $module . '.php';
            $existed    = is_file($configPath);

            $code = $this->publishOne($module, $force, $output);

            if ($code !== 0) {
                return $code;
            }

            if ($existed && !$force) {
                $skipped++;
            } else {
                $created++;
            }
        }

        $output->writeln('');
        $output->writeln("<info>Done:</info> {$created} created, {$skipped} skipped.");

        return 0;
    }

    /**
     * 读取 stub 模板并写入目标文件。
     */
    private function writeStub(string $path, string $stubPath): void
    {
        $content = file_get_contents($stubPath);

        if ($content === false) {
            throw new RuntimeException("Failed to read stub file: {$stubPath}");
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Failed to create directory: {$directory}");
        }

        file_put_contents($path, $content);
    }
}
