<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\console\Input;
use Lychee\console\Output;
use PHPUnit\Framework\TestCase;

class MakeControllerCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/lychee_make_ctrl_' . uniqid();
        mkdir($this->tempDir . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function test_command_is_registered(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $this->assertTrue($console->has('make:controller'));
    }

    public function test_make_controller_creates_controller_model_and_validate(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $input  = new Input(['make:controller', 'User']);
        $output = new Output();

        $code = $console->run($input, $output);

        $this->assertSame(0, $code);

        $appNamespace = $app->container->getNamespace();

        $controllerPath = $this->tempDir . '/app/controller/UserController.php';
        $modelPath      = $this->tempDir . '/app/model/User.php';
        $validatePath   = $this->tempDir . '/app/validate/UserValidate.php';

        $this->assertFileExists($controllerPath);
        $this->assertFileExists($modelPath);
        $this->assertFileExists($validatePath);

        $controller = file_get_contents($controllerPath);
        $this->assertStringContainsString("namespace {$appNamespace}\\controller;", $controller);
        $this->assertStringContainsString('class UserController extends ResourceController', $controller);
        $this->assertStringContainsString("#[Resource('/user')]", $controller);
        $this->assertStringContainsString('protected string $model = User::class;', $controller);
        $this->assertStringContainsString('protected string $validate = UserValidate::class;', $controller);
        $this->assertStringContainsString("use {$appNamespace}\\model\\User;", $controller);
        $this->assertStringContainsString("use {$appNamespace}\\validate\\UserValidate;", $controller);
        $this->assertStringContainsString('public function index(): JsonResponse', $controller);
        $this->assertStringContainsString('return $this->baseIndex($where);', $controller);
        $this->assertStringContainsString('public function save(): JsonResponse', $controller);
        $this->assertStringContainsString('return $this->baseSave($this->request->post());', $controller);
        $this->assertStringContainsString('public function read(int $id): JsonResponse', $controller);
        $this->assertStringContainsString('return $this->baseRead($id);', $controller);
        $this->assertStringContainsString('public function update(int $id): JsonResponse', $controller);
        $this->assertStringContainsString('return $this->baseUpdate($id, $this->request->post());', $controller);
        $this->assertStringContainsString('public function delete(int $id): JsonResponse', $controller);
        $this->assertStringContainsString('return $this->baseDelete($id);', $controller);
        $this->assertStringContainsString('public function batch_delete(): JsonResponse', $controller);
        $this->assertStringContainsString('return $this->baseBatchDelete($ids);', $controller);

        $model = file_get_contents($modelPath);
        $this->assertStringContainsString("namespace {$appNamespace}\\model;", $model);
        $this->assertStringContainsString('class User extends Model', $model);
        $this->assertStringNotContainsString('protected $name', $model);

        $validate = file_get_contents($validatePath);
        $this->assertStringContainsString("namespace {$appNamespace}\\validate;", $validate);
        $this->assertStringContainsString('class UserValidate extends Validate', $validate);
    }

    public function test_make_controller_accepts_controller_suffix(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['make:controller', 'PostController']), new Output());

        $this->assertSame(0, $code);
        $this->assertFileExists($this->tempDir . '/app/controller/PostController.php');
        $this->assertFileExists($this->tempDir . '/app/model/Post.php');
        $this->assertFileExists($this->tempDir . '/app/validate/PostValidate.php');
    }

    public function test_make_controller_capitalizes_first_letter(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['make:controller', 'user']), new Output());

        $this->assertSame(0, $code);
        $this->assertFileExists($this->tempDir . '/app/controller/UserController.php');
        $this->assertFileExists($this->tempDir . '/app/model/User.php');
        $this->assertFileExists($this->tempDir . '/app/validate/UserValidate.php');

        $controller = file_get_contents($this->tempDir . '/app/controller/UserController.php');
        $this->assertStringContainsString('class UserController extends ResourceController', $controller);
        $this->assertStringContainsString("#[Resource('/user')]", $controller);
    }

    public function test_make_controller_fails_when_controller_exists(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        mkdir($this->tempDir . '/app/controller', 0777, true);
        file_put_contents($this->tempDir . '/app/controller/UserController.php', '<?php');

        $code = $console->run(new Input(['make:controller', 'User']), new Output());

        $this->assertSame(1, $code);
    }

    public function test_make_controller_skips_existing_model_and_validate(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        // 预先创建 Model 和 Validate
        mkdir($this->tempDir . '/app/model', 0777, true);
        mkdir($this->tempDir . '/app/validate', 0777, true);
        file_put_contents($this->tempDir . '/app/model/User.php', '<?php // existing model');
        file_put_contents($this->tempDir . '/app/validate/UserValidate.php', '<?php // existing validate');

        $code = $console->run(new Input(['make:controller', 'User']), new Output());

        $this->assertSame(0, $code);

        // 控制器被创建
        $this->assertFileExists($this->tempDir . '/app/controller/UserController.php');

        // Model 和 Validate 保持原样，未被覆盖
        $this->assertSame('<?php // existing model', file_get_contents($this->tempDir . '/app/model/User.php'));
        $this->assertSame('<?php // existing validate', file_get_contents($this->tempDir . '/app/validate/UserValidate.php'));
    }

    public function test_make_controller_rejects_invalid_name(): void
    {
        $app     = $this->createApp();
        $console = $app->container->get(\Lychee\console\Application::class);

        $code = $console->run(new Input(['make:controller', '123Invalid']), new Output());

        $this->assertSame(1, $code);
    }

    private function createApp(): Application
    {
        return new Application(
            basePath: $this->tempDir,
            controllerNamespace: 'App\\controller',
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
