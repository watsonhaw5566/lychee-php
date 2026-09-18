<?php

declare(strict_types=1);

namespace Lychee\migration;

use PDO;

/**
 * 迁移基类。
 *
 * 子类实现 up()（执行迁移）与 down()（回滚迁移）。
 */
abstract class Migration
{
    public function __construct(
        protected readonly PDO $pdo,
        protected readonly string $prefix = '',
    ) {
    }

    /**
     * 执行迁移。
     */
    abstract public function up(): void;

    /**
     * 回滚迁移。
     */
    abstract public function down(): void;

    /**
     * 获取表构建器（自动应用表前缀）。
     *
     * @param  array<string, mixed> $options
     */
    protected function table(string $name, array $options = []): Table
    {
        return new Table($this->prefix . $name, $this->pdo, $options);
    }

    /**
     * 执行原生 SQL。
     */
    protected function execute(string $sql): int
    {
        return $this->pdo->exec($sql);
    }

    /**
     * 执行原生查询并返回结果集。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function query(string $sql): array
    {
        $stmt = $this->pdo->query($sql);

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}
