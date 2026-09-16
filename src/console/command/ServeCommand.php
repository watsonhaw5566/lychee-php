<?php

declare(strict_types=1);

namespace Lychee\console\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\input\Option;
use Lychee\console\Output;

/**
 * 启动 PHP 内置开发服务器。
 *
 * 静态文件从 public/ 目录直接提供，其余请求交由应用处理。
 */
class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('run');
        $this->setDescription('Start the PHP built-in development server');
        $this->addOption('host', 'H', Option::VALUE_OPTIONAL, 'The host address', '127.0.0.1');
        $this->addOption('port', 'p', Option::VALUE_OPTIONAL, 'The port number', '8000');
    }

    protected function execute(Input $input, Output $output): int
    {
        $host = (string) $input->getOption('host');
        $port = (string) $input->getOption('port');

        $basePath = (string) $this->app->get('path.base');
        $public   = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'public';

        if (!is_dir($public)) {
            @mkdir($public, 0777, true);
        }

        $router = $this->createRouter($basePath);

        $command = sprintf(
            '%s -S %s:%s -t %s %s',
            PHP_BINARY,
            $host,
            $port,
            escapeshellarg($public),
            escapeshellarg($router),
        );

        $output->writeln("<info>Lychee development server started:</info>");
        $output->writeln("  <comment>http://{$host}:{$port}</comment>");
        $output->writeln("  Document root: {$public}");
        $output->writeln("  Press Ctrl+C to stop.");

        passthru($command, $code);

        return $code;
    }

    /**
     * 创建临时路由脚本。
     *
     * 静态文件存在则直接返回 false 由 PHP 内置服务器处理，
     * 否则交给应用入口处理。
     */
    private function createRouter(string $basePath): string
    {
        $publicPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'public';
        $router     = tempnam(sys_get_temp_dir(), 'lychee_router_');

        $script = '<?php' . PHP_EOL
            . '$uri = parse_url($_SERVER[\'REQUEST_URI\'], PHP_URL_PATH) ?? \'/\';' . PHP_EOL
            . PHP_EOL
            . '// 静态文件直接交由 PHP 内置服务器处理' . PHP_EOL
            . 'if ($uri !== \'/\' && file_exists(' . var_export($publicPath, true) . ' . $uri)) {' . PHP_EOL
            . '    return false;' . PHP_EOL
            . '}' . PHP_EOL
            . PHP_EOL
            . '// 其余请求交由应用处理' . PHP_EOL
            . 'require ' . var_export($publicPath . DIRECTORY_SEPARATOR . 'index.php', true) . ';' . PHP_EOL;

        file_put_contents($router, $script);

        return $router;
    }
}
