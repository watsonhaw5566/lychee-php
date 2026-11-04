# 事件 Event

框架内置轻量级事件系统，支持事件监听与分发、通配符匹配、优先级排序，以及监听器中断传播。适用于模块间解耦通信，如用户登录后发送通知、订单创建后触发库存扣减等场景。

## 注册监听器

通过 `EventDispatcher` 的 `listen()` 方法注册监听器：

```php
use Lychee\event\facade\Event;

// 监听单个事件
Event::listen('user.login', function (string $event, $user) {
    // 记录登录日志
    logger()->info('用户登录', ['user_id' => $user->id]);
});

// 同时监听多个事件
Event::listen(['user.login', 'user.register'], function (string $event, $user) {
    // 发送欢迎通知
});
```

> 监听器的第一个参数始终是事件名，后续参数为分发时传入的 payload。

## 分发事件

```php
use Lychee\event\facade\Event;

// 分发事件，传入参数
Event::dispatch('user.login', [$user]);

// 获取所有监听器的返回值
$responses = Event::dispatch('order.created', [$order]);
```

## 助手函数

全局 `event()` 助手提供快捷调用：

```php
// 分发事件
event('user.login', [$user]);

// 无参数时返回调度器实例
event()->listen('user.logout', function ($event, $user) {
    // ...
});
```

## 通配符监听

事件名包含 `*` 时作为通配符监听器，匹配所有符合模式的事件：

```php
// 监听所有 order.* 事件
Event::listen('order.*', function (string $event, $order) {
    logger()->info("订单事件：{$event}", ['order_id' => $order->id]);
});

// 以下分发都会触发上面的监听器
Event::dispatch('order.created', [$order]);
Event::dispatch('order.paid', [$order]);
Event::dispatch('order.shipped', [$order]);
```

## 优先级

`listen()` 的第三个参数指定优先级，数值越大越先执行，默认为 `0`：

```php
// 优先级 10，最先执行
Event::listen('order.created', function ($event, $order) {
    // 校验库存
}, 10);

// 优先级 0（默认），其次执行
Event::listen('order.created', function ($event, $order) {
    // 记录日志
});

// 优先级 -5，最后执行
Event::listen('order.created', function ($event, $order) {
    // 发送通知
}, -5);
```

## 中断传播

监听器显式返回 `false` 可中断后续监听器的执行：

```php
Event::listen('order.creating', function ($event, $data) {
    if ($data['amount'] <= 0) {
        return false; // 中断，后续监听器不再执行
    }
});

Event::listen('order.creating', function ($event, $data) {
    // 如果上面返回了 false，这里不会被执行
});
```

## 移除监听器

使用 `forget()` 移除指定事件的所有监听器：

```php
Event::forget('user.login');       // 移除普通事件
Event::forget('order.*');          // 移除通配符事件
```

## 事件订阅器

订阅器类可以在一个类中集中管理多个事件的监听，需实现 `subscribe()` 方法：

```php
class UserEventSubscriber
{
    public function subscribe(\Lychee\event\EventDispatcher $dispatcher): void
    {
        $dispatcher->listen('user.login', [$this, 'onLogin']);
        $dispatcher->listen('user.logout', [$this, 'onLogout']);
    }

    public function onLogin(string $event, $user): void
    {
        logger()->info('用户登录', ['user_id' => $user->id]);
    }

    public function onLogout(string $event, $user): void
    {
        logger()->info('用户登出', ['user_id' => $user->id]);
    }
}

// 注册订阅器
Event::subscribe(new UserEventSubscriber());
```

## 在插件中注册事件

插件的 `register()` 方法是注册事件监听的天然入口：

```php
use Lychee\container\Container;
use Lychee\plugin\PluginInterface;
use Lychee\event\facade\Event;

class OrderPlugin implements PluginInterface
{
    public function register(Container $app): void
    {
        Event::listen('order.created', function ($event, $order) {
            // 扣减库存
        });

        Event::listen('order.paid', function ($event, $order) {
            // 发送确认邮件
        });
    }
}
```

## API 参考

| 方法 | 说明 |
|------|------|
| `listen(string\|array $events, callable $listener, int $priority = 0)` | 注册监听器 |
| `dispatch(string $event, array $payload = []): array` | 分发事件，返回监听器返回值 |
| `hasListeners(string $event): bool` | 判断事件是否有监听器 |
| `getListeners(string $event): array` | 获取事件的所有监听器 |
| `forget(string $event): void` | 移除事件的所有监听器 |
| `subscribe(object $subscriber): void` | 注册事件订阅器 |
