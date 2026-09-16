# 容器 Container

基于 PSR-11 的轻量依赖注入容器，支持绑定、单例、自动解析。

## 获取容器

```php
use Lychee\container\Container;

$container = Container::getInstance();
// 或通过辅助函数
$container = app();
```

## 绑定服务

```php
// 绑定闭包
$container->bind(Foo::class, fn () => new Foo());

// 绑定单例
$container->singleton(Bar::class, fn () => new Bar());

// 绑定已有实例
$container->instance('config', $config);
```

## 解析服务

```php
$foo = $container->get(Foo::class);
$config = $container->get('config');

// 带构造参数
$obj = $container->make(MyClass::class, ['name' => 'test']);
```

## 辅助函数

```php
app();                    // 获取容器实例
app(Foo::class);          // 解析服务
app('config');            // 按标识解析
```
