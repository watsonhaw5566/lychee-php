<?php

declare(strict_types=1);

namespace Lychee\console\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\routing\Router;

/**
 * 列出所有已注册的路由。
 */
class RouteListCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('route:list');
        $this->setDescription('List all registered routes');
    }

    protected function execute(Input $input, Output $output): int
    {
        $router = $this->app->get(Router::class);
        $routes = $router->getRoutes();

        if (empty($routes)) {
            $output->writeln('<comment>No routes registered.</comment>');

            return 0;
        }

        $methodWidth = 6;
        $pathWidth   = 4;
        $rows        = [];

        foreach ($routes as $route) {
            $method = str_pad($route['method'], $methodWidth, ' ');
            $path   = $route['path'];
            $action = $route['controller'] . '@' . $route['action'];

            $pathWidth = max($pathWidth, strlen($path));

            $rows[] = [$method, $path, $action];
        }

        $output->writeln('<info>Registered routes:</info> (' . count($routes) . ')');
        $output->writeln('');

        foreach ($rows as [$method, $path, $action]) {
            $output->writeln(sprintf(
                '  <comment>%s</comment>  %s  %s',
                $method,
                str_pad($path, $pathWidth, ' '),
                $action
            ));
        }

        $output->writeln('');

        return 0;
    }
}
