<?php

declare(strict_types=1);

namespace Lychee\migration;

use PDO;

/**
 * 数据填充基类。
 *
 * 子类实现 run() 方法写入初始化数据。
 */
abstract class Seeder
{
    public function __construct(
        protected readonly PDO $pdo,
        protected readonly string $prefix = '',
    ) {
    }

    /**
     * 执行数据填充。
     */
    abstract public function run(): void;

    /**
     * 向指定表插入数据（自动应用表前缀）。
     *
     * @param  array<string, mixed> $data
     */
    protected function insert(string $table, array $data): void
    {
        $table        = $this->prefix . $table;
        $columns      = array_keys($data);
        $placeholders = array_map(fn () => '?', $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
    }

    /**
     * 批量插入数据（自动应用表前缀）。
     *
     * @param  array<int, array<string, mixed>> $rows
     */
    protected function insertBatch(string $table, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $table           = $this->prefix . $table;
        $columns         = array_keys($rows[0]);
        $rowPlaceholders = '(' . implode(', ', array_map(fn () => '?', $columns)) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($rows), $rowPlaceholders));

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES %s',
            $table,
            implode('`, `', $columns),
            $allPlaceholders
        );

        $values = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $values[] = $row[$col] ?? null;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }

    /**
     * 清空表数据（自动应用表前缀）。
     */
    protected function truncate(string $table): void
    {
        $table = $this->prefix . $table;
        $this->pdo->exec("TRUNCATE TABLE `{$table}`");
    }

    /**
     * 执行原生 SQL。
     */
    protected function execute(string $sql): int
    {
        return $this->pdo->exec($sql);
    }
}
