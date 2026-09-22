<?php

declare(strict_types=1);

namespace Lychee\console\command;

use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\routing\Router;

/**
 * 生成路由表缓存文件。
 *
 * 用于生产环境：跳过每请求的控制器目录扫描与注解反射，
 * 直接加载已收集的路由数据。命令执行时输出路由收集耗时，
 * 便于评估实时解析路由的开销。
 *
 * 动态路由参数（如 /users/{id}）不受影响：缓存保存的是已编译的
 * 正则表达式（命名捕获组），匹配与参数提取行为完全一致。
 */
class RouteCacheCommand extends \Lychee\console\Command
{
    protected function configure(): void
    {
        $this->setName('route:cache');
        $this->setDescription('Cache the route table for production');
    }

    protected function execute(Input $input, Output $output): int
    {
        $cacheFile = self::cacheFilePath($this->app->runtimePath);

        // 先删除旧缓存：否则解析 Router 时会从旧缓存加载，无法反映最新路由
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }

        // 计时：路由收集（目录扫描 + 注解反射 + 插件注册）
        $start       = microtime(true);
        $router      = $this->app->get(Router::class);
        $collectTime = (microtime(true) - $start) * 1000;

        $routes = $router->getRoutes();

        if (empty($routes)) {
            $output->writeln('<error>No routes found, route cache was not generated.</error>');

            return 1;
        }

        if (!is_dir(dirname($cacheFile))) {
            @mkdir(dirname($cacheFile), 0777, true);
        }

        $payload = $this->renderPayload($routes, $router->getNamedRoutes());

        // 原子写入：临时文件 + rename，避免并发请求读到半写入文件
        $tmpFile = $cacheFile . '.tmp';
        if (file_put_contents($tmpFile, $payload) === false) {
            $output->writeln('<error>Failed to write route cache file.</error>');

            return 1;
        }

        if (!@rename($tmpFile, $cacheFile)) {
            @unlink($tmpFile);
            $output->writeln('<error>Failed to move route cache file into place.</error>');

            return 1;
        }

        $output->writeln('<info>Route cache generated successfully.</info>');
        $output->writeln('');
        $output->writeln('  Routes:       ' . count($routes));
        $output->writeln(sprintf('  Collect time: %.2f ms', $collectTime));
        $output->writeln('  Cache file:   ' . $cacheFile);
        $output->writeln('');
        $output->writeln('<comment>Re-run this command after any route change, delete the file to disable.</comment>');

        return 0;
    }

    /**
     * 解析路由缓存文件路径。
     */
    public static function cacheFilePath(string $runtimePath): string
    {
        return rtrim($runtimePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'route_cache.php';
    }

    /**
     * @param array<int, array<string, mixed>> $routes
     * @param array<string, string> $namedRoutes
     */
    private function renderPayload(array $routes, array $namedRoutes): string
    {
        $export = var_export([
            'routes'       => $routes,
            'named_routes' => $namedRoutes,
        ], true);

        return <<<PHP
<?php

declare(strict_types=1);

// 路由缓存文件，由 `php lee route:cache` 自动生成，请勿手动修改。
// 修改路由后需重新执行生成命令；删除本文件即恢复实时扫描。

return {$export};

PHP;
    }
}
