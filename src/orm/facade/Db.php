<?php

declare(strict_types=1);

namespace Lychee\orm\facade;

use Closure;
use Lychee\orm\Connection;
use Lychee\orm\ConnectionManager;
use Lychee\orm\Query;
use Lychee\orm\Raw;
use RuntimeException;

/**
 * Db 门面。
 *
 * 对齐 ThinkPHP `think\facade\Db` 的常用静态入口：
 * `Db::table()` / `Db::name()` / `Db::startTrans()` / `Db::commit()` / `Db::rollback()` /
 * `Db::transaction()` / `Db::raw()` / `Db::connect()`。
 *
 * @method static Query table(string $table)
 * @method static Query name(string $name)
 *
 * 需要在应用启动阶段调用 {@see Db::setManager()} 注入 {@see ConnectionManager}。
 */
class Db
{
    protected static ?ConnectionManager $manager = null;

    public static function setManager(ConnectionManager $manager): void
    {
        self::$manager = $manager;
    }

    public static function getManager(): ConnectionManager
    {
        if (self::$manager === null) {
            throw new RuntimeException('Lychee\\orm\\facade\\Db has no ConnectionManager; call Db::setManager() first');
        }

        return self::$manager;
    }

    /**
     * 指定连接；null 表示默认连接。
     */
    public static function connect(?string $name = null): Connection
    {
        return self::getManager()->connect($name);
    }

    /**
     * 以表名启动查询（会自动拼接表前缀）。
     */
    public static function name(string $name): Query
    {
        $query = new Query(self::connect());

        return $query->name($name);
    }

    /**
     * 以完整表名启动查询。
     */
    public static function table(string $table): Query
    {
        $query = new Query(self::connect());

        return $query->table($table);
    }

    /**
     * 创建 Raw 表达式。
     *
     * @param array<int, mixed> $bindings
     */
    public static function raw(string $value, array $bindings = []): Raw
    {
        return new Raw($value, $bindings);
    }

    // ── 事务 ────────────────────────────────────────────────────────

    public static function startTrans(): void
    {
        self::connect()->beginTransaction();
    }

    public static function commit(): void
    {
        self::connect()->commit();
    }

    public static function rollback(): void
    {
        self::connect()->rollBack();
    }

    /**
     * 执行事务闭包，失败自动回滚。
     */
    public static function transaction(Closure $callback): mixed
    {
        return self::connect()->transaction($callback);
    }

    // ── 原生执行 ─────────────────────────────────────────────────────

    /**
     * 执行查询并返回结果集。
     *
     * @param array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public static function query(string $sql, array $bindings = []): array
    {
        return self::connect()->select($sql, $bindings);
    }

    /**
     * 执行写操作（insert/update/delete）并返回影响行数。
     *
     * @param array<int|string, mixed> $bindings
     */
    public static function execute(string $sql, array $bindings = []): int
    {
        return self::connect()->execute($sql, $bindings);
    }

    /**
     * 注入 SQL 日志回调。
     */
    public static function setLog(Closure $logger): void
    {
        self::getManager()->setLog($logger);
    }
}
