<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\event\EventDispatcher;
use Lychee\event\facade\Event;
use PHPUnit\Framework\TestCase;

class EventDispatcherTest extends TestCase
{
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
    }

    // ── 基础监听与分发 ─────────────────────────────────────────────

    public function test_listen_and_dispatch(): void
    {
        $called = false;

        $this->dispatcher->listen('user.login', function (string $event, $user) use (&$called) {
            $called = true;
            $this->assertSame('user.login', $event);
            $this->assertSame('张三', $user);
        });

        $this->dispatcher->dispatch('user.login', ['张三']);
        $this->assertTrue($called);
    }

    public function test_dispatch_returns_responses(): void
    {
        $this->dispatcher->listen('order.created', fn () => 'logged');
        $this->dispatcher->listen('order.created', fn () => 'notified');

        $responses = $this->dispatcher->dispatch('order.created');

        $this->assertSame(['logged', 'notified'], $responses);
    }

    public function test_dispatch_without_listeners(): void
    {
        $responses = $this->dispatcher->dispatch('nothing.happened');

        $this->assertSame([], $responses);
    }

    // ── 多事件注册 ─────────────────────────────────────────────────

    public function test_listen_multiple_events(): void
    {
        $count = 0;

        $this->dispatcher->listen(['user.login', 'user.logout'], function () use (&$count) {
            $count++;
        });

        $this->dispatcher->dispatch('user.login');
        $this->dispatcher->dispatch('user.logout');

        $this->assertSame(2, $count);
    }

    // ── 优先级 ─────────────────────────────────────────────────────

    public function test_priority_ordering(): void
    {
        $order = [];

        $this->dispatcher->listen('boot', function () use (&$order) {
            $order[] = 'normal';
        }, 0);

        $this->dispatcher->listen('boot', function () use (&$order) {
            $order[] = 'high';
        }, 10);

        $this->dispatcher->listen('boot', function () use (&$order) {
            $order[] = 'low';
        }, -5);

        $this->dispatcher->dispatch('boot');

        $this->assertSame(['high', 'normal', 'low'], $order);
    }

    // ── 返回 false 中断 ───────────────────────────────────────────

    public function test_return_false_halts_propagation(): void
    {
        $reached = false;

        $this->dispatcher->listen('validate', fn () => false);
        $this->dispatcher->listen('validate', function () use (&$reached) {
            $reached = true;
        });

        $responses = $this->dispatcher->dispatch('validate');

        $this->assertFalse($reached);
        $this->assertSame([], $responses);
    }

    // ── 通配符 ─────────────────────────────────────────────────────

    public function test_wildcard_listener(): void
    {
        $events = [];

        $this->dispatcher->listen('order.*', function (string $event) use (&$events) {
            $events[] = $event;
        });

        $this->dispatcher->dispatch('order.created');
        $this->dispatcher->dispatch('order.paid');
        $this->dispatcher->dispatch('user.login');

        $this->assertSame(['order.created', 'order.paid'], $events);
    }

    public function test_wildcard_does_not_match_unrelated(): void
    {
        $called = false;

        $this->dispatcher->listen('user.*', function () use (&$called) {
            $called = true;
        });

        $this->dispatcher->dispatch('order.created');

        $this->assertFalse($called);
    }

    // ── hasListeners ───────────────────────────────────────────────

    public function test_has_listeners(): void
    {
        $this->assertFalse($this->dispatcher->hasListeners('user.login'));

        $this->dispatcher->listen('user.login', fn () => null);
        $this->assertTrue($this->dispatcher->hasListeners('user.login'));
    }

    public function test_has_listeners_with_wildcard(): void
    {
        $this->dispatcher->listen('user.*', fn () => null);

        $this->assertTrue($this->dispatcher->hasListeners('user.login'));
        $this->assertFalse($this->dispatcher->hasListeners('order.created'));
    }

    // ── forget ─────────────────────────────────────────────────────

    public function test_forget_removes_listeners(): void
    {
        $this->dispatcher->listen('user.login', fn () => null);
        $this->assertTrue($this->dispatcher->hasListeners('user.login'));

        $this->dispatcher->forget('user.login');
        $this->assertFalse($this->dispatcher->hasListeners('user.login'));
    }

    public function test_forget_wildcard(): void
    {
        $this->dispatcher->listen('order.*', fn () => null);
        $this->assertTrue($this->dispatcher->hasListeners('order.created'));

        $this->dispatcher->forget('order.*');
        $this->assertFalse($this->dispatcher->hasListeners('order.created'));
    }

    // ── subscribe ──────────────────────────────────────────────────

    public function test_subscriber(): void
    {
        $subscriber = new class () {
            public bool $loginCalled  = false;
            public bool $logoutCalled = false;

            public function subscribe(EventDispatcher $dispatcher): void
            {
                $dispatcher->listen('user.login', function () {
                    $this->loginCalled = true;
                });
                $dispatcher->listen('user.logout', function () {
                    $this->logoutCalled = true;
                });
            }
        };

        $this->dispatcher->subscribe($subscriber);

        $this->dispatcher->dispatch('user.login');
        $this->assertTrue($subscriber->loginCalled);
        $this->assertFalse($subscriber->logoutCalled);

        $this->dispatcher->dispatch('user.logout');
        $this->assertTrue($subscriber->logoutCalled);
    }

    // ── Facade ─────────────────────────────────────────────────────

    public function test_facade_delegates_to_dispatcher(): void
    {
        Event::setDispatcher($this->dispatcher);

        Event::listen('test.event', fn () => 'from_facade');

        $responses = Event::dispatch('test.event');

        $this->assertSame(['from_facade'], $responses);
    }
}
