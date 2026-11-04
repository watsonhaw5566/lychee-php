<?php

declare(strict_types=1);

namespace Lychee\event\facade;

use Lychee\event\EventDispatcher;
use RuntimeException;

/**
 * Event 门面。
 *
 * 提供静态方法代理到 EventDispatcher 实例，简化调用。
 *
 * @method static void  listen(string|array $events, callable $listener, int $priority = 0)
 * @method static array dispatch(string $event, array $payload = [])
 * @method static void  forget(string $event)
 * @method static bool  hasListeners(string $event)
 * @method static array getListeners(string $event)
 * @method static void  subscribe(object $subscriber)
 */
class Event
{
    protected static ?EventDispatcher $dispatcher = null;

    public static function setDispatcher(EventDispatcher $dispatcher): void
    {
        self::$dispatcher = $dispatcher;
    }

    public static function getDispatcher(): EventDispatcher
    {
        if (self::$dispatcher === null) {
            throw new RuntimeException(
                'Lychee\event\facade\Event has no EventDispatcher; call Event::setDispatcher() first'
            );
        }

        return self::$dispatcher;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        return self::getDispatcher()->{$method}(...$arguments);
    }
}
