<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\http\Request;
use Lychee\http\Response;
use Lychee\session\driver\File as FileDriver;
use Lychee\session\Session;
use PHPUnit\Framework\TestCase;

class SessionTest extends TestCase
{
    private string $sessionDir;

    protected function setUp(): void
    {
        $this->sessionDir = sys_get_temp_dir() . '/lychee-session-test-' . uniqid();
        mkdir($this->sessionDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->sessionDir . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->rmDir($file) : unlink($file);
        }
        rmdir($this->sessionDir);
    }

    public function test_session_starts_with_new_id_when_no_cookie(): void
    {
        $driver  = new FileDriver($this->sessionDir);
        $session = new Session($driver, ['name' => 'SESSID']);

        $request = new Request('GET', '/', [], [], [], []);

        $session->start($request);

        $this->assertTrue($session->isStarted());
        $this->assertNotEmpty($session->getId());
        $this->assertSame(32, strlen($session->getId()));
    }

    public function test_session_reuses_id_from_cookie(): void
    {
        $driver  = new FileDriver($this->sessionDir);
        $session = new Session($driver, ['name' => 'SESSID']);

        $existingId = str_repeat('a', 32);
        $request    = new Request('GET', '/', [], [], [], ['SESSID' => $existingId]);

        $session->start($request);

        $this->assertSame($existingId, $session->getId());
    }

    public function test_session_set_and_get(): void
    {
        $driver  = new FileDriver($this->sessionDir);
        $session = new Session($driver, ['name' => 'SESSID']);

        $request = new Request('GET', '/', [], [], [], []);
        $session->start($request);

        $session->set('user_id', 42);
        $session->set('user', ['name' => 'Alice']);

        $this->assertSame(42, $session->get('user_id'));
        $this->assertSame(['name' => 'Alice'], $session->get('user'));
        $this->assertTrue($session->has('user_id'));
        $this->assertNull($session->get('missing'));
        $this->assertSame('default', $session->get('missing', 'default'));
    }

    public function test_session_pull_removes_value(): void
    {
        $driver  = new FileDriver($this->sessionDir);
        $session = new Session($driver, ['name' => 'SESSID']);

        $request = new Request('GET', '/', [], [], [], []);
        $session->start($request);

        $session->set('flash', 'hello');
        $this->assertSame('hello', $session->pull('flash'));
        $this->assertFalse($session->has('flash'));
    }

    public function test_session_persists_across_instances(): void
    {
        $driver  = new FileDriver($this->sessionDir);
        $session = new Session($driver, ['name' => 'SESSID']);

        $request = new Request('GET', '/', [], [], [], []);
        $session->start($request);
        $session->set('key', 'value');

        $response = new Response();
        $session->save($response);

        $cookies = $response->getCookies();
        $this->assertArrayHasKey('SESSID', $cookies);

        $sessionId = $cookies['SESSID']->value;

        // 模拟下一次请求
        $driver2  = new FileDriver($this->sessionDir);
        $session2 = new Session($driver2, ['name' => 'SESSID']);
        $request2 = new Request('GET', '/', [], [], [], ['SESSID' => $sessionId]);
        $session2->start($request2);

        $this->assertSame('value', $session2->get('key'));
    }

    public function test_session_regenerate_changes_id(): void
    {
        $driver  = new FileDriver($this->sessionDir);
        $session = new Session($driver, ['name' => 'SESSID']);

        $request = new Request('GET', '/', [], [], [], []);
        $session->start($request);

        $oldId = $session->getId();
        $session->set('keep', 'data');

        $newId = $session->regenerate();

        $this->assertNotSame($oldId, $newId);
        $this->assertSame($newId, $session->getId());
        $this->assertSame('data', $session->get('keep'));
    }

    public function test_session_flush_clears_all(): void
    {
        $driver  = new FileDriver($this->sessionDir);
        $session = new Session($driver, ['name' => 'SESSID']);

        $request = new Request('GET', '/', [], [], [], []);
        $session->start($request);

        $session->set('a', 1);
        $session->set('b', 2);
        $session->flush();

        $this->assertSame([], $session->all());
    }

    private function rmDir(string $dir): void
    {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->rmDir($file) : unlink($file);
        }
        rmdir($dir);
    }
}
