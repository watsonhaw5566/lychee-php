<?php

declare(strict_types=1);

namespace Lychee;

use Lychee\auth\SaToken;
use Lychee\captcha\Captcha;
use Lychee\config\Config;
use Lychee\config\Env;
use Lychee\console\Application as ConsoleApplication;
use Lychee\container\Container;
use Lychee\cron\command\CronListCommand;
use Lychee\cron\command\CronRunCommand;
use Lychee\cron\command\CronScheduleCommand;
use Lychee\cron\Scheduler;
use Lychee\filesystem\FilesystemManager;
use Lychee\http\Kernel;
use Lychee\http\ExceptionHandler;
use Lychee\http\MiddlewarePipeline;
use Lychee\http\Request;
use Lychee\i18n\I18n;
use Lychee\log\LogManager;
use Lychee\migration\command\MigrateCreateCommand;
use Lychee\plugin\PluginManager;
use Lychee\migration\command\MigrateRollbackCommand;
use Lychee\migration\command\MigrateRunCommand;
use Lychee\migration\command\SeedCreateCommand;
use Lychee\migration\command\SeedRunCommand;
use Lychee\migration\MigrationManager;
use Lychee\queue\command\WorkCommand;
use Lychee\queue\QueueManager;
use Lychee\routing\Router;
use Lychee\session\driver\File as SessionFileDriver;
use Lychee\session\Session;
use Lychee\view\ExceptionRenderer;
use Lychee\view\View;
use Lychee\websocket\command\ServerCommand;
use Lychee\websocket\WebSocketServer;
use Psr\Log\LoggerInterface;
use think\CacheManager;
use think\DbManager;
use think\Model as ThinkModel;
use think\Validate;
use ErrorException;
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

        $this->loadEnvironment();
        $this->registerBindings();
        $this->bootConfig();
        $this->bootTimezone();
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
            'log'        => $this->bootLog(...),
            'database'   => $this->bootOrm(...),
            'cache'      => $this->bootCache(...),
            'filesystem' => $this->bootFilesystem(...),
            'satoken'    => $this->bootSaToken(...),
            'captcha'    => $this->bootCaptcha(...),
            'cron'       => $this->bootCron(...),
            'queue'      => $this->bootQueue(...),
            'session'    => $this->bootSession(...),
            'i18n'       => $this->bootI18n(...),
            'websocket'  => $this->bootWebSocket(...),
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

        // 校验器语言：注册 maker 闭包，使所有 Validate 实例默认中文（含全局 validate() 函数）
        $this->bootValidateLang();

        // 插件系统：在所有核心模块就绪后，加载并启动已配置的插件
        $this->bootPlugins();
    }

    /**
     * 加载并启动插件。
     *
     * 插件列表从 config/plugin.php 的 providers 配置读取，按声明顺序注册并启动。
     * 此时框架核心服务（Router、View、Console、Config 等）均已就绪，
     * 插件可在 boot() 中安全地注册路由、视图、命令、中间件等。
     */
    private function bootPlugins(): void
    {
        /** @var Config $config */
        $config = $this->container->get('config');

        $manager = new PluginManager($this->container);
        $this->container->instance(PluginManager::class, $manager);
        $this->container->instance('plugins', $manager);

        $providers = (array) $config->get('plugin.providers', []);

        foreach ($providers as $provider) {
            $manager->register((string) $provider);
        }

        $manager->boot();
    }

    /**
     * 加载项目根目录下的 .env 文件。
     *
     * 必须在 registerBindings / bootConfig 之前执行，
     * 以便配置文件与 env() 辅助函数能读取到环境变量。
     */
    private function loadEnvironment(): void
    {
        Env::load($this->basePath . '/.env');
    }

    private function registerBindings(): void
    {
        \think\Container::setInstance($this->container);

        $this->container->instance(Container::class, $this->container);
        $this->container->instance('app', $this->container);

        $this->container->instance('path.base', $this->basePath . DIRECTORY_SEPARATOR);
        $this->container->instance('path.app', $this->basePath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR);
        $this->container->instance('path.runtime', $this->basePath . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR);
        $this->container->instance('path.public', $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

        $this->container->singleton(Router::class, function (): Router {
            $routePrefix = (string) config('app.route_prefix', '');
            $router      = new Router($routePrefix);
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
            $console->addCommand(\Lychee\console\command\RunCommand::class);
            $console->addCommand(\Lychee\console\command\RouteListCommand::class);
            $console->addCommand(\Lychee\console\command\MakeRestCommand::class);
            $console->addCommand(\Lychee\console\command\MakeBaseCommand::class);

            return $console;
        });
        $this->container->singleton('console', fn () => $this->container->get(ConsoleApplication::class));

        $this->container->singleton(Validate::class, fn (): Validate => new Validate());

        $this->container->singleton(
            ExceptionHandler::class,
            function (Container $c): ExceptionHandler {
                $handlerClass = (string) config('app.exception_handler', ExceptionHandler::class);

                if (class_exists($handlerClass) && is_subclass_of($handlerClass, ExceptionHandler::class)) {
                    return $c->make($handlerClass);
                }

                return new ExceptionHandler();
            }
        );

        $this->container->singleton(
            MiddlewarePipeline::class,
            fn (Container $c): MiddlewarePipeline => new MiddlewarePipeline($c)
        );

        $this->container->singleton(Kernel::class, function (Container $c): Kernel {
            return new Kernel(
                router: $c->get(Router::class),
                container: $c,
                pipeline: $c->get(MiddlewarePipeline::class),
                exceptionHandler: $c->get(ExceptionHandler::class),
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

    /**
     * 根据配置设置默认时区。
     *
     * 读取 app.default_timezone，缺省为 Asia/Shanghai。
     */
    private function bootTimezone(): void
    {
        $timezone = (string) config('app.default_timezone', 'Asia/Shanghai');

        if ($timezone !== '') {
            date_default_timezone_set($timezone);
        }
    }

    private function bootOrm(): void
    {
        /** @var Config $config */
        $config   = $this->container->get('config');
        $dbConfig = $config->get('database', []);

        $dbManager = new DbManager();
        $dbManager->setConfig($dbConfig);
        ThinkModel::setDb($dbManager);

        // 接入 SQL 日志：当日志模块已启动时，将 ORM 的 SQL 监听输出到日志通道。
        // 优先使用 'sql' 频道（用户可在 config/log.php 中单独配置），否则回退到默认频道。
        // DbManager::log() 传入的类型为 'sql'，非 PSR-3 标准级别，
        // 故用 Closure 适配为 debug 级别，避免被级别过滤。
        if ($this->container->bound(LogManager::class)) {
            $logManager = $this->container->get(LogManager::class);
            $channels   = $logManager->getConfig('channels', []);
            $channel    = array_key_exists('sql', $channels) ? 'sql' : null;
            $logger     = $logManager->channel($channel);

            $dbManager->setLog(static function (string $type, string $message) use ($logger): void {
                $logger->debug($message);
            });
        }

        // 全局时间字段配置：think-orm 原生支持 auto_timestamp 与 datetime_format，
        // 但不支持 datetime_field 全局配置。这里通过 Model::maker() 闭包，
        // 将 'create_time,update_time' 格式的配置应用到所有模型实例。
        $datetimeField = (string) ($dbConfig['datetime_field'] ?? '');
        if ($datetimeField !== '') {
            $parts = array_map('trim', explode(',', $datetimeField));
            if (count($parts) >= 2) {
                [$createTime, $updateTime] = $parts;
                ThinkModel::maker(static function ($model) use ($createTime, $updateTime): void {
                    $model->setTimeField($createTime, $updateTime);
                });
            }
        }

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

    private function bootCaptcha(): void
    {
        $captcha = new Captcha($this->container);

        $this->container->instance(Captcha::class, $captcha);
        $this->container->instance('captcha', $captcha);
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
        $console->addCommand(CronListCommand::class);
        $console->addCommand(CronScheduleCommand::class);
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

        $path = $sessionConfig['path'] ?? runtime_path('session');

        $sessionDriver = new SessionFileDriver(
            path: $path,
            expireMinutes: (int) ($sessionConfig['expire'] ?? 120),
        );

        $session = new Session($sessionDriver, $sessionConfig);

        $this->container->instance(Session::class, $session);
        $this->container->instance('session', $session);
    }

    private function bootI18n(): void
    {
        /** @var Config $config */
        $config     = $this->container->get('config');
        $i18nConfig = $config->get('i18n', []);

        // 翻译文件目录默认为 basePath/app/lang
        if (!isset($i18nConfig['path']) || $i18nConfig['path'] === '') {
            $i18nConfig['path'] = $this->basePath . '/app/lang';
        }

        $i18n = new I18n($i18nConfig);

        $this->container->instance(I18n::class, $i18n);
        $this->container->instance('i18n', $i18n);
    }

    /**
     * 注册校验器语言闭包。
     *
     * 通过 think\Validate::maker() 注册构造钩子，使所有 Validate 实例
     * （包括全局 validate() 函数创建的）默认使用内置中文提示。
     *
     * 可通过 config/app.php 的 validate_lang 配置切换：
     * - 'zh'（默认，不配置即为中文）：中文提示
     * - 'en'：英文提示（think-validate 默认）
     */
    private function bootValidateLang(): void
    {
        /** @var Config $config */
        $config = $this->container->get('config');
        $lang   = strtolower((string) $config->get('app.validate_lang', 'zh'));

        Validate::maker(function (Validate $v) use ($lang): void {
            if ($lang === 'zh') {
                $v->useZh();
            }
        });
    }

    private function bootWebSocket(): void
    {
        /** @var Config $config */
        $config          = $this->container->get('config');
        $websocketConfig = $config->get('websocket', []);

        $server = new WebSocketServer($this->container, $websocketConfig);

        $this->container->instance(WebSocketServer::class, $server);
        $this->container->instance('websocket', $server);

        /** @var ConsoleApplication $console */
        $console = $this->container->get(ConsoleApplication::class);
        $console->addCommand(ServerCommand::class);
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
        $console->addCommand(MigrateCreateCommand::class);
        $console->addCommand(SeedRunCommand::class);
        $console->addCommand(SeedCreateCommand::class);
    }

    /**
     * 解析迁移模块使用的 PDO 连接。
     *
     * 仅使用用户配置的数据库；若未配置数据库或连接失败则返回 null，
     * 由迁移命令提示用户先配置数据库连接。
     */
    private function resolveMigrationPdo(): ?PDO
    {
        if (!$this->container->bound('db')) {
            return null;
        }

        /** @var DbManager $dbManager */
        $dbManager = $this->container->get('db');

        try {
            /** @var \think\db\PDOConnection $connection */
            $connection = $dbManager->connect();
            // connect() 会主动建立连接并返回 PDO；连接失败时抛出异常
            $pdo = $connection->connect();

            return $pdo instanceof PDO ? $pdo : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function bootView(): void
    {
        /** @var Config $config */
        $config     = $this->container->get('config');
        $viewConfig = $config->get('view', []);

        $viewPath   = (string) ($viewConfig['view_path'] ?? ($this->basePath . '/app/view'));
        $cachePath  = (string) ($viewConfig['cache_path'] ?? ($this->container->runtimePath . 'twig'));
        $debug      = (bool) ($viewConfig['debug'] ?? false);
        $baseUrl    = (string) ($viewConfig['base_url'] ?? '');
        $extensions = (array) ($viewConfig['extensions'] ?? ['.twig', '.html']);

        if (!is_dir($viewPath)) {
            @mkdir($viewPath, 0777, true);
        }

        $view = new View(
            viewPath: $viewPath,
            cachePath: $cachePath,
            debug: $debug,
            baseUrl: $baseUrl,
            extensions: $extensions,
        );

        $this->container->instance(View::class, $view);
        $this->container->instance('view', $view);
    }

    public function run(): void
    {
        $this->registerErrorHandling();

        $request  = Request::capture();
        $kernel   = $this->container->get(Kernel::class);
        $response = $kernel->handle($request);
        $response->send();
    }

    /**
     * 注册全局错误处理：将 PHP Warning/Notice 等转为 ErrorException，
     * 使其能被 Kernel 的 try/catch 捕获并渲染统一异常页；
     * 致命错误通过 shutdown 函数兜底渲染。
     */
    private function registerErrorHandling(): void
    {
        // 禁止 PHP 直接输出错误，交由框架统一处理
        ini_set('display_errors', '0');

        set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            // 被 @ 抑制的错误不抛出
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error === null) {
                return;
            }

            $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
            if (($error['type'] & $fatal) === 0) {
                return;
            }

            // 致命错误无法抛出，直接渲染异常页
            $this->renderFatalError($error);
        });
    }

    /**
     * 渲染致命错误页面。
     *
     * @param array{type:int, message:string, file:string, line:int} $error
     */
    private function renderFatalError(array $error): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        // 若已有输出（headers 已发送），则无法再发送完整响应
        if (headers_sent()) {
            return;
        }

        $debug        = (bool) config('app.debug', env('APP_DEBUG', false));
        $errorMessage = error_message();
        $showErrorMsg = (bool) config('app.show_error_msg', false);

        $e      = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        $status = 500;

        try {
            $renderer = new ExceptionRenderer(
                cachePath: (string) $this->container->runtimePath . 'twig',
            );

            if ($debug) {
                $html = $renderer->render(
                    status: $status,
                    e: $e,
                    method: $_SERVER['REQUEST_METHOD'] ?? 'GET',
                    url: $_SERVER['REQUEST_URI']       ?? '/',
                );
            } else {
                $message = $showErrorMsg ? $error['message'] : $errorMessage;
                $html    = $renderer->renderError($status, $message);
            }

            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            echo $html;
        } catch (Throwable) {
            // 渲染失败时输出最小化错误信息
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Server Error';
        }
    }
}
