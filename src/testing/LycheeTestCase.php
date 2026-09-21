<?php

declare(strict_types=1);

namespace Lychee\testing;

use Lychee\Application;
use Lychee\http\Kernel;
use Lychee\http\Request;
use Lychee\http\Response;
use PHPUnit\Framework\TestCase;
use think\DbManager;

/**
 * Lychee 测试基类。
 *
 * 提供应用初始化、请求模拟与数据库事务回滚。
 *
 *   class UserControllerTest extends LycheeTestCase
 *   {
 *       protected string $basePath = __DIR__ . '/../';
 *
 *       protected function setUp(): void
 *       {
 *           parent::setUp();
 *           $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name VARCHAR(255))');
 *       }
 *
 *       public function test_index(): void
 *       {
 *           $resp = $this->get('/users');
 *           $this->assertSame(200, $resp->status);
 *       }
 *   }
 */
abstract class LycheeTestCase extends TestCase
{
    protected Application $app;
    protected Kernel      $kernel;
    protected DbManager   $db;

    /** 应用根目录（包含 app/、config/ 等），子类必须覆盖 */
    protected string $basePath = '';

    /** 控制器命名空间 */
    protected string $controllerNamespace = 'app\\controller';

    /** 是否启用事务回滚（默认开启） */
    protected bool $useTransactions = true;

    private ?int $actingAsUserId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Application(
            basePath: $this->resolveBasePath(),
            controllerNamespace: $this->controllerNamespace,
        );

        $this->kernel = $this->app->container->get(Kernel::class);
        /** @var DbManager $db */
        $db       = $this->app->container->get('db');
        $this->db = $db;

        if ($this->useTransactions) {
            $this->db->connect()->startTrans();
        }
    }

    protected function tearDown(): void
    {
        if ($this->useTransactions) {
            $this->db->connect()->rollback();
        }

        $this->actingAsUserId = null;

        parent::tearDown();
    }

    protected function resolveBasePath(): string
    {
        if ($this->basePath !== '') {
            return rtrim($this->basePath, DIRECTORY_SEPARATOR);
        }

        $cwd = getcwd();
        if ($cwd !== false && is_dir($cwd . '/app')) {
            return $cwd;
        }

        throw new \RuntimeException('请设置 protected string $basePath = ...');
    }

    // ── 请求模拟 ──

    public function get(string $path, array $query = [], array $headers = []): Response
    {
        return $this->request('GET', $path, $query, [], $headers);
    }

    public function post(string $path, array $body = [], array $headers = []): Response
    {
        return $this->request('POST', $path, [], $body, $headers);
    }

    public function put(string $path, array $body = [], array $headers = []): Response
    {
        return $this->request('PUT', $path, [], $body, $headers);
    }

    public function patch(string $path, array $body = [], array $headers = []): Response
    {
        return $this->request('PATCH', $path, [], $body, $headers);
    }

    public function delete(string $path, array $body = [], array $headers = []): Response
    {
        return $this->request('DELETE', $path, [], $body, $headers);
    }

    public function request(string $method, string $path, array $query = [], array $body = [], array $headers = []): Response
    {
        $request = new Request($method, $path, $query, $body, $headers);

        if ($this->actingAsUserId !== null) {
            $request->setLoginId($this->actingAsUserId);
        }

        // 同步到容器，确保控制器 $this->request 与 request() 助手函数拿到同一对象
        $this->app->container->instance(Request::class, $request);
        $this->app->container->instance('request', $request);

        return $this->kernel->handle($request);
    }

    // ── 辅助方法 ──

    /** 从容器解析实例（支持依赖注入），如 $this->make(CustomerService::class) */
    public function make(string $abstract): mixed
    {
        return $this->app->container->get($abstract);
    }

    /** 解析 JSON 响应为数组 */
    public function json(Response $response): array
    {
        return json_decode($response->content, true) ?: [];
    }

    /** 模拟指定用户登录 */
    public function actingAs(int $userId): static
    {
        $this->actingAsUserId = $userId;

        return $this;
    }
}
