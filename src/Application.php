<?php

declare(strict_types=1);

namespace Lychee;

use Lychee\auth\SaToken;
use Lychee\config\Config;
use Lychee\console\Application as ConsoleApplication;
use Lychee\container\Container;
use Lychee\cron\command\CronRunCommand;
use Lychee\cron\Scheduler;
use Lychee\filesystem\FilesystemManager;
use Lychee\http\Kernel;
use Lychee\http\MiddlewarePipeline;
use Lychee\http\Request;
use Lychee\log\LogManager;
use Lychee\migration\command\MigrateRollbackCommand;
use Lychee\migration\command\MigrateRunCommand;
use Lychee\migration\command\SeedRunCommand;
use Lychee\migration\MigrationManager;
use Lychee\queue\command\WorkCommand;
use Lychee\queue\QueueManager;
use Lychee\routing\Router;
use Lychee\session\driver\File as SessionFileDriver;
use Lychee\session\Session;
use Lychee\view\View;
use Psr\Log\LoggerInterface;
use think\CacheManager;
use think\DbManager;
use think\Model as ThinkModel;
use think\Validate;
use PDO;
use Throwable;

/**
 * 应用引导器。
 *
 * 负责组装容器、配置、ORM、缓存、日志、队列、定时任务、文件系统、认证，
 * 并运行 HTTP 内核。
 */
class Application
{
    public readonly Container $container;

    public function __construct(
        private readonly string $basePath,
        private readonly string $controllerNamespace = 'App\\controller',
    ) {
        $this->container = new Container();

        $this->container->rootPath    = rtrim($this->basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->container->appPath     = $this->basePath . '/app/';
        $this->container->runtimePath = $this->basePath . '/runtime/';
        $this->container->configPath  = $this->basePath . '/config/';

        $appNamespace = rtrim(str_replace('\\controller', '', $this->controllerNamespace), '\\');
        $this->container->setNamespace($appNamespace);

        $this->registerBindings();
        $this->bootConfig();
        $this->bootOptionalModules();
    }

    /**
     * 根据用户配置按需启动可选模块。
     *
     * 仅当 config 目录下存在对应配置文件时才启动该模块，
     * 避免在未使用时无谓地初始化资源。
     */
    private function bootOptionalModules(): void
    {
        /** @var Config $config */
        $config = $this->container->get('config');

        $modules = [
            'database'   => $this->bootOrm(...),
            'cache'      => $this->bootCache(...),
            'log'        => $this->bootLog(...),
            'filesystem' => $this->bootFilesystem(...),
            'satoken'    => $this->bootSaToken(...),
            'cron'       => $this->bootCron(...),
            'queue'      => $this->bootQueue(...),
            'session'    => $this->bootSession(...),
        ];

        foreach ($modules as $key => $boot) {
            if ($config->has($key)) {
                $boot();
            }
        }

        // 视图模块：存在 view 配置或 app/view 目录时启动
        if ($config->has('view') || is_dir($this->basePath . '/app/view')) {
            $this->bootView();
        }

        // 迁移模块：始终注册命令，数据库连接可用时创建管理器
        $this->bootMigration();
    }

    private function registerBindings(): void
    {
        \think\Container::setInstance($this->container);

        $this->container->instance(Container::class, $this->container);
        $this->container->instance('app', $this->container);

        $this->container->instance('path.base', $this->basePath . DIRECTORY_SEPARATOR);
        $this->container->instance('path.runtime', $this->basePath . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
        $this->container->instance('path.public', $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

        $this->container->singleton(Router::class, function (): Router {
            $router = new Router();
            $router->registerDirectory(
                $this->basePath . '/app/controller',
                $this->controllerNamespace
            );

            return $router;
        });
        $this->container->singleton('route', fn () => $this->container->get(Router::class));

        $this->container->singleton(Request::class, fn (): Request => Request::capture());
        $this->container->singleton('request', fn () => $this->container->get(Request::class));

        $this->container->singleton(ConsoleApplication::class, function (): ConsoleApplication {
            $console = new ConsoleApplication($this->container);
            $console->addCommand(\Lychee\console\command\ServeCommand::class);

            return $console;
        });
        $this->container->singleton('console', fn () => $this->container->get(ConsoleApplication::class));

        $this->container->singleton(Validate::class, fn (): Validate => new Validate());

        $this->container->singleton(
            MiddlewarePipeline::class,
            fn (Container $c): MiddlewarePipeline => new MiddlewarePipeline($c)
        );

        $this->container->singleton(Kernel::class, function (Container $c): Kernel {
            return new Kernel(
                router: $c->get(Router::class),
                container: $c,
                pipeline: $c->get(MiddlewarePipeline::class),
                validator: $c->get(Validate::class),
            );
        });
    }

    private function bootConfig(): void
    {
        $configPath = $this->basePath . '/config';
        $config     = new Config($configPath);

        if (is_dir($configPath)) {
            foreach (glob($configPath . '/*.php') as $file) {
                $config->load($file, basename($file, '.php'));
            }
        }

        $this->container->instance('config', $config);
        $this->container->instance(Config::class, $config);
    }

    private function bootOrm(): void
    {
        /** @var Config $config */
        $config   = $this->container->get('config');
        $dbConfig = $config->get('database', []);

        $dbManager = new DbManager();
        $dbManager->setConfig($dbConfig);
        ThinkModel::setDb($dbManager);

        $this->container->instance('db', $dbManager);
        $this->container->instance(DbManager::class, $dbManager);
        $this->container->instance('think\DbManager', $dbManager);
    }

    private function bootCache(): void
    {
        /** @var Config $config */
        $config      = $this->container->get('config');
        $cacheConfig = $config->get('cache', []);

        $cacheManager = new CacheManager();
        $cacheManager->config($cacheConfig);

        $this->container->instance(CacheManager::class, $cacheManager);
        $this->container->instance('cache', $cacheManager);
        $this->container->instance('think\CacheManager', $cacheManager);
    }

    private function bootLog(): void
    {
        $logManager = new LogManager($this->container);

        $this->container->instance(LogManager::class, $logManager);
        $this->container->instance('log', $logManager);

        $this->container->singleton(
            LoggerInterface::class,
            fn (): LoggerInterface => $logManager->channel()
        );
    }

    private function bootFilesystem(): void
    {
        $filesystemManager = new FilesystemManager($this->container);

        $this->container->instance(FilesystemManager::class, $filesystemManager);
        $this->container->instance('filesystem', $filesystemManager);
    }

    private function bootSaToken(): void
    {
        $saToken = new SaToken($this->container);

        $this->container->instance(SaToken::class, $saToken);
        $this->container->instance('satoken', $saToken);
    }

    private function bootCron(): void
    {
        /** @var Config $config */
        $config     = $this->container->get('config');
        $cronConfig = $config->get('cron', []);

        $scheduler = new Scheduler($this->container);

        if (!empty($cronConfig['tasks']) && is_array($cronConfig['tasks'])) {
            $scheduler->load($cronConfig['tasks']);
        }

        $this->container->instance(Scheduler::class, $scheduler);
        $this->container->instance('scheduler', $scheduler);

        /** @var ConsoleApplication $console */
        $console = $this->container->get(ConsoleApplication::class);
        $console->addCommand(CronRunCommand::class);
    }

    private function bootQueue(): void
    {
        $queueManager = new QueueManager($this->container);

        $this->container->instance(QueueManager::class, $queueManager);
        $this->container->instance('queue', $queueManager);

        /** @var ConsoleApplication $console */
        $console = $this->container->get(ConsoleApplication::class);
        $console->addCommand(WorkCommand::class);
    }

    private function bootSession(): void
    {
        /** @var Config $config */
        $config        = $this->container->get('config');
        $sessionConfig = $config->get('session', []);

        $driver = $sessionConfig['driver'] ?? 'file';

        if ($driver === 'file') {
            $path          = $sessionConfig['path'] ?? ($this->container->runtimePath . 'session');
            $sessionDriver = new SessionFileDriver(
                path: (string) $path,
                expireMinutes: (int) ($sessionConfig['expire'] ?? 120),
            );
        } else {
            $sessionDriver = new SessionFileDriver(
                path: $this->container->runtimePath . 'session',
            );
        }

        $session = new Session($sessionDriver, $sessionConfig);

        $this->container->instance(Session::class, $session);
        $this->container->instance('session', $session);
    }

    private function bootMigration(): void
    {
        $migrationPath = $this->basePath . '/database/migrations';
        $seederPath    = $this->basePath . '/database/seeders';

        $pdo = $this->resolveMigrationPdo();

        if ($pdo instanceof PDO) {
            $manager = new MigrationManager(
                pdo: $pdo,
                migrationPath: $migrationPath,
                seederPath: $seederPath,
            );

            $this->container->instance(MigrationManager::class, $manager);
            $this->container->instance('migration', $manager);
        }

        /** @var ConsoleApplication $console */
        $console = $this->container->get(ConsoleApplication::class);
        $console->addCommand(MigrateRunCommand::class);
        $console->addCommand(MigrateRollbackCommand::class);
        $console->addCommand(SeedRunCommand::class);
    }

    /**
     * 解析迁移模块使用的 PDO 连接。
     *
     * 优先使用用户配置的数据库；连接失败时兜底到 SQLite；
     * 若 SQLite 扩展不可用则返回 null。
     */
    private function resolveMigrationPdo(): ?PDO
    {
        // 优先使用用户配置的数据库
        if ($this->container->bound('db')) {
            /** @var DbManager $dbManager */
            $dbManager = $this->container->get('db');

            try {
                /** @var \think\db\PDOConnection $connection */
                $connection = $dbManager->connect();
                $pdo        = $connection->getPdo();

                if ($pdo instanceof PDO) {
                    return $pdo;
                }
            } catch (Throwable) {
                // 配置的数据库不可用，尝试兜底到 SQLite
            }
        }

        // 兜底：使用 SQLite 文件数据库
        if (!extension_loaded('pdo_sqlite')) {
            return null;
        }

        $sqliteDir = $this->container->runtimePath;
        if (!is_dir($sqliteDir)) {
            @mkdir($sqliteDir, 0777, true);
        }

        try {
            return new PDO('sqlite:' . $sqliteDir . 'migration.sqlite');
        } catch (Throwable) {
            return null;
        }
    }

    private function bootView(): void
    {
        /** @var Config $config */
        $config     = $this->container->get('config');
        $viewConfig = $config->get('view', []);

        $viewPath  = (string) ($viewConfig['view_path'] ?? ($this->basePath . '/app/view'));
        $cachePath = (string) ($viewConfig['cache_path'] ?? ($this->container->runtimePath . 'twig'));
        $debug     = (bool) ($viewConfig['debug'] ?? false);
        $baseUrl   = (string) ($viewConfig['base_url'] ?? '');

        if (!is_dir($viewPath)) {
            @mkdir($viewPath, 0777, true);
        }

        $view = new View(
            viewPath: $viewPath,
            cachePath: $cachePath,
            debug: $debug,
            baseUrl: $baseUrl,
        );

        $this->container->instance(View::class, $view);
        $this->container->instance('view', $view);
    }

    public function run(): void
    {
        $request  = Request::capture();
        $kernel   = $this->container->get(Kernel::class);
        $response = $kernel->handle($request);
        $response->send();
    }
}
