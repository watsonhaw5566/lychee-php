<?php

declare(strict_types=1);

namespace Lychee\event;

/**
 * 事件调度器。
 *
 * 提供轻量级的事件注册与分发能力，支持通配符监听、优先级排序，
 * 以及监听器返回 false 中断后续执行。
 *
 * 典型用法：
 *   $dispatcher = new EventDispatcher();
 *   $dispatcher->listen('user.login', function ($user) { ... });
 *   $dispatcher->dispatch('user.login', [$user]);
 */
class EventDispatcher
{
    /**
     * 已注册的监听器。
     *
     * 结构：[事件名 => [[callable, 优先级], ...]]
     *
     * @var array<string, list<array{0: callable, 1: int}>>
     */
    protected array $listeners = [];

    /**
     * 排序后的监听器缓存。
     *
     * @var array<string, list<callable>>
     */
    protected array $sorted = [];

    /**
     * 通配符监听器。
     *
     * @var array<string, list<array{0: callable, 1: int}>>
     */
    protected array $wildcards = [];

    /**
     * 排序后的通配符缓存。
     *
     * @var array<string, list<callable>>
     */
    protected array $wildcardsSorted = [];

    /**
     * 注册事件监听器。
     *
     * 事件名包含 `*` 时自动作为通配符监听器注册。
     * 优先级越大越先执行，默认为 0。
     *
     * @param string|array<string> $events   事件名或事件名数组
     * @param callable             $listener 监听器
     * @param int                  $priority 优先级（越大越先执行）
     */
    public function listen(string|array $events, callable $listener, int $priority = 0): void
    {
        foreach ((array) $events as $event) {
            if (str_contains($event, '*')) {
                $this->wildcards[$event][] = [$listener, $priority];
                unset($this->wildcardsSorted[$event]);
            } else {
                $this->listeners[$event][] = [$listener, $priority];
                unset($this->sorted[$event]);
            }
        }
    }

    /**
     * 判断事件是否有监听器（含通配符匹配）。
     */
    public function hasListeners(string $event): bool
    {
        if (!empty($this->listeners[$event])) {
            return true;
        }

        foreach ($this->wildcards as $pattern => $_) {
            if ($this->matchWildcard($pattern, $event)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 分发事件。
     *
     * 按优先级依次调用监听器，若任一监听器显式返回 `false` 则中断后续执行。
     *
     * @param string $event   事件名
     * @param array  $payload 传递给监听器的参数
     * @return array 所有监听器的返回值
     */
    public function dispatch(string $event, array $payload = []): array
    {
        $responses = [];

        foreach ($this->getListeners($event) as $listener) {
            $response = $listener($event, ...$payload);

            // 监听器返回 false 中断后续执行
            if ($response === false) {
                break;
            }

            $responses[] = $response;
        }

        return $responses;
    }

    /**
     * 获取事件的所有监听器（含通配符），按优先级排序。
     *
     * @return list<callable>
     */
    public function getListeners(string $event): array
    {
        $listeners = $this->getSortedListeners($event);

        // 合并匹配的通配符监听器
        foreach ($this->wildcards as $pattern => $_) {
            if ($this->matchWildcard($pattern, $event)) {
                $listeners = array_merge(
                    $listeners,
                    $this->getSortedWildcardListeners($pattern)
                );
            }
        }

        return $listeners;
    }

    /**
     * 移除事件的所有监听器。
     *
     * @param string $event 事件名（支持通配符模式）
     */
    public function forget(string $event): void
    {
        if (str_contains($event, '*')) {
            unset($this->wildcards[$event], $this->wildcardsSorted[$event]);
        } else {
            unset($this->listeners[$event], $this->sorted[$event]);
        }
    }

    /**
     * 订阅器注册：批量注册一个类中的多个监听。
     *
     * 订阅器类需实现 subscribe(EventDispatcher $dispatcher) 方法。
     */
    public function subscribe(object $subscriber): void
    {
        $subscriber->subscribe($this);
    }

    /**
     * 按优先级降序排列普通监听器并缓存。
     *
     * @return list<callable>
     */
    protected function getSortedListeners(string $event): array
    {
        if (!isset($this->sorted[$event])) {
            $this->sorted[$event] = $this->sortListeners(
                $this->listeners[$event] ?? []
            );
        }

        return $this->sorted[$event];
    }

    /**
     * 按优先级降序排列通配符监听器并缓存。
     *
     * @return list<callable>
     */
    protected function getSortedWildcardListeners(string $pattern): array
    {
        if (!isset($this->wildcardsSorted[$pattern])) {
            $this->wildcardsSorted[$pattern] = $this->sortListeners(
                $this->wildcards[$pattern] ?? []
            );
        }

        return $this->wildcardsSorted[$pattern];
    }

    /**
     * 排序监听器：按优先级降序，返回纯 callable 列表。
     *
     * @param list<array{0: callable, 1: int}> $listeners
     * @return list<callable>
     */
    protected function sortListeners(array $listeners): array
    {
        // 按优先级降序稳定排序
        usort($listeners, fn (array $a, array $b) => $b[1] <=> $a[1]);

        return array_column($listeners, 0);
    }

    /**
     * 通配符模式匹配。
     *
     * 仅支持 `*` 通配符（匹配任意字符），如 `user.*` 匹配 `user.login`。
     */
    protected function matchWildcard(string $pattern, string $event): bool
    {
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

        return (bool) preg_match($regex, $event);
    }
}
