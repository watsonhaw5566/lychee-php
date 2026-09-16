<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\view\View;
use PHPUnit\Framework\TestCase;

class ViewTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        $app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->view = $app->container->get(View::class);
    }

    public function test_view_instance_resolved(): void
    {
        $this->assertInstanceOf(View::class, $this->view);
    }

    public function test_render_template_with_data(): void
    {
        $html = $this->view->render('hello.html', ['name' => 'Lychee']);

        $this->assertStringContainsString('Hello, Lychee!', $html);
    }

    public function test_exists_returns_true_for_existing_template(): void
    {
        $this->assertTrue($this->view->exists('hello.html'));
    }

    public function test_exists_returns_false_for_missing_template(): void
    {
        $this->assertFalse($this->view->exists('nonexistent.html'));
    }

    public function test_view_helper_function(): void
    {
        $html = view('hello.html', ['name' => 'World']);

        $this->assertStringContainsString('Hello, World!', $html);
    }
}
