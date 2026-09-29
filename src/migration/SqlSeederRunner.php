<?php

declare(strict_types=1);

namespace Lychee\migration;

use Closure;
use Generator;
use PDO;
use RuntimeException;
use Throwable;

/**
 * 本地 SQL 种子脚本执行器。
 *
 * 扫描 database/sql 目录下的 .sql 文件，按文件名排序依次执行，
 * 执行记录保存在 sql_seeds 表中，已执行的文件默认跳过。
 *
 * 适用于不适合写成 PHP Seeder 的大批量裸 SQL 导入（如几十万行的
 * INSERT 导出脚本）：
 * - 流式分块解析（64KB），文件大小不影响内存占用；
 * - 词法状态机切分语句，引号与注释内的分号不会被误切；
 * - DML 按批提交（避免逐条 fsync），DDL 单独执行（MySQL 隐式提交）。
 */
class SqlSeederRunner
{
    public const TABLE = 'sql_seeds';

    private const CHUNK_SIZE     = 65536;
    private const BATCH_SIZE     = 1000;
    private const PROGRESS_EVERY = 10000;
    private const CHUNK_TAIL     = 4;

    private const STATE_NORMAL        = 0;
    private const STATE_SINGLE_QUOTE  = 1;
    private const STATE_DOUBLE_QUOTE  = 2;
    private const STATE_BACKTICK      = 3;
    private const STATE_LINE_COMMENT  = 4;
    private const STATE_BLOCK_COMMENT = 5;

    private int $state              = self::STATE_NORMAL;
    private string $buffer          = '';
    private int $line               = 1;
    private int $statementStartLine = 1;
    private bool $hasToken          = false;

    /** @var array<int, array{0: string, 1: int}> 已切分出的语句（SQL, 起始行号） */
    private array $emitted = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $sqlPath,
    ) {
    }

    /**
     * 执行未导入过的 SQL 种子脚本。
     *
     * @param  string|null                        $onlyFile 仅导入指定文件名（如 cod_regions.sql）
     * @param  bool                               $force    即使已导入过也重新执行
     * @param  Closure(string, string):void|null  $output   输出回调 (message, style)
     * @return int 本次执行的文件数
     */
    public function run(?string $onlyFile = null, bool $force = false, ?Closure $output = null): int
    {
        if (!is_dir($this->sqlPath)) {
            $this->writeln($output, 'SQL directory does not exist: ' . $this->sqlPath);

            return 0;
        }

        $this->ensureTable();

        $files = $this->getFiles();
        $ran   = $this->getRanFiles();

        if ($onlyFile !== null && $onlyFile !== '') {
            $files = array_values(array_filter(
                $files,
                static fn (string $f): bool => $f === $onlyFile
            ));

            if ($files === []) {
                throw new RuntimeException("SQL file not found in {$this->sqlPath}: {$onlyFile}");
            }
        }

        $executed = 0;

        foreach ($files as $file) {
            if (!$force && isset($ran[$file])) {
                continue;
            }

            $path    = $this->sqlPath . DIRECTORY_SEPARATOR . $file;
            $started = microtime(true);

            $this->writeln($output, "Importing: {$file}");

            $statements = $this->executeFile($path, $file, $output);

            // --force 重跑时记录已存在，不重复写入
            if (!isset($ran[$file])) {
                $this->recordFile($file);
            }

            $elapsed = number_format(microtime(true) - $started, 2);
            $this->writeln($output, "Imported:  {$file} ({$statements} statements, {$elapsed}s)");

            $executed++;
        }

        if ($executed === 0) {
            $this->writeln($output, 'Nothing to import.');
        }

        return $executed;
    }

    /**
     * 执行单个 SQL 文件。
     *
     * @param  Closure(string, string):void|null $output
     * @return int 执行的语句数
     */
    private function executeFile(string $path, string $file, ?Closure $output): int
    {
        $inTransaction = false;
        $batch         = 0;
        $count         = 0;
        $sql           = '';
        $startLine     = 1;

        try {
            foreach ($this->statements($path) as [$sql, $startLine]) {
                $count++;

                if ($this->isDdl($sql)) {
                    // DDL 会触发 MySQL 隐式提交：先提交挂起的 DML 批次，再单独执行 DDL
                    if ($inTransaction) {
                        $this->pdo->commit();
                        $inTransaction = false;
                        $batch         = 0;
                    }

                    $this->pdo->exec($sql);
                } else {
                    if (!$inTransaction) {
                        $this->pdo->beginTransaction();
                        $inTransaction = true;
                    }

                    $this->pdo->exec($sql);

                    if (++$batch >= self::BATCH_SIZE) {
                        $this->pdo->commit();
                        $inTransaction = false;
                        $batch         = 0;
                    }
                }

                if ($output !== null && $count % self::PROGRESS_EVERY === 0) {
                    $output("  ... {$count} statements", 'comment');
                }
            }

            if ($inTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $snippet = mb_substr((string) preg_replace('/\s+/', ' ', trim($sql)), 0, 200);

            throw new RuntimeException(
                "SQL file '{$file}' failed at statement #{$count} (line {$startLine}): "
                . $e->getMessage() . ($snippet !== '' ? " | statement: {$snippet}" : ''),
                (int) $e->getCode(),
                $e
            );
        }

        return $count;
    }

    /**
     * 流式解析 SQL 文件，逐条 yield [SQL 文本, 起始行号]。
     *
     * 按 64KB 分块读取；非末块保留尾部 4 字节与下一块拼接，保证
     * 引号、--、/* 等多字符词法符号不会被分块切断。
     *
     * @return Generator<int, array{0: string, 1: int}>
     */
    private function statements(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open SQL file: {$path}");
        }

        $this->resetParser();

        try {
            $carry = '';

            while (!feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);

                if ($chunk === false || $chunk === '') {
                    continue;
                }

                $data    = $carry . $chunk;
                $len     = strlen($data);
                $isFinal = feof($handle);
                $limit   = $isFinal ? $len : max(0, $len - self::CHUNK_TAIL);

                $consumed = $this->scan($data, $len, $limit, $isFinal);

                foreach ($this->emitted as $item) {
                    yield $item;
                }
                $this->emitted = [];

                $carry = $consumed >= $len ? '' : substr($data, $consumed);
            }

            if ($carry !== '') {
                $len = strlen($carry);
                $this->scan($carry, $len, $len, true);

                foreach ($this->emitted as $item) {
                    yield $item;
                }
                $this->emitted = [];
            }

            if (in_array($this->state, [
                self::STATE_SINGLE_QUOTE,
                self::STATE_DOUBLE_QUOTE,
                self::STATE_BACKTICK,
            ], true)) {
                throw new RuntimeException(
                    "Unterminated quoted string in SQL at line {$this->statementStartLine}"
                );
            }

            if ($this->hasToken && trim($this->buffer) !== '') {
                throw new RuntimeException(
                    "Unterminated SQL statement (missing semicolon) at line {$this->statementStartLine}"
                );
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * 词法扫描数据段 [0, $limit)。
     *
     * 返回下次扫描的起始位置（该位置起的数据尚未写入 buffer）；
     * 切分出的语句推入 $this->emitted。$final 为 true 时不留跨块余量。
     */
    private function scan(string $data, int $len, int $limit, bool $final): int
    {
        $safe = $final ? $limit : max(0, $limit - self::CHUNK_TAIL);
        $i    = 0;

        while ($i < $limit) {
            switch ($this->state) {
                case self::STATE_NORMAL:
                    $jump = strcspn($data, '\'";`#/-', $i, $limit - $i);
                    $j    = $i + $jump;

                    if ($j >= $safe) {
                        // 安全区内无词法事件，整段普通文本入库并从 $safe 处留待下块
                        if ($safe > $i) {
                            $this->appendNormal(substr($data, $i, $safe - $i));
                        }

                        return $safe;
                    }

                    $this->appendNormal(substr($data, $i, $jump));
                    $i = $j;
                    $c = $data[$i];

                    // MySQL 行注释要求 -- 后紧跟空白；$i 不动，由注释状态连同 "--" 一起消费
                    if ($c                                === '-'
                        && $i + 1 < $len && $data[$i + 1] === '-'
                        && ($i + 2 >= $len || ctype_space($data[$i + 2]))
                    ) {
                        $this->state = self::STATE_LINE_COMMENT;
                        break;
                    }

                    if ($c === '/' && $i + 1 < $len && $data[$i + 1] === '*') {
                        $this->state = self::STATE_BLOCK_COMMENT;
                        break;
                    }

                    if ($c === ';') {
                        $sql = trim($this->buffer);

                        if ($sql !== '') {
                            $this->emitted[] = [$sql, $this->statementStartLine];
                        }

                        $this->buffer             = '';
                        $this->hasToken           = false;
                        $this->statementStartLine = $this->line;
                        $i++;
                        break;
                    }

                    $this->appendChar($c);

                    if ($c === "'") {
                        $this->state = self::STATE_SINGLE_QUOTE;
                    } elseif ($c === '"') {
                        $this->state = self::STATE_DOUBLE_QUOTE;
                    } elseif ($c === '`') {
                        $this->state = self::STATE_BACKTICK;
                    } elseif ($c === '#') {
                        $this->state = self::STATE_LINE_COMMENT;
                    }

                    $i++;
                    break;

                case self::STATE_SINGLE_QUOTE:
                case self::STATE_DOUBLE_QUOTE:
                    $quote = $this->state === self::STATE_SINGLE_QUOTE ? "'" : '"';
                    $jump  = strcspn($data, $quote . '\\', $i, $limit - $i);
                    $j     = $i + $jump;

                    if ($j >= $safe) {
                        if ($safe > $i) {
                            $this->append(substr($data, $i, $safe - $i));
                        }

                        return $safe;
                    }

                    $this->append(substr($data, $i, $jump));
                    $i = $j;

                    if ($data[$i] === '\\') {
                        if ($i + 1 >= $len) {
                            // 反斜杠位于块尾，交给下一块定夺其转义目标
                            return $i;
                        }

                        $this->append(substr($data, $i, 2));
                        $i += 2;
                    } elseif ($i + 1 < $len && $data[$i + 1] === $quote) {
                        $this->append($quote . $quote);
                        $i += 2;
                    } else {
                        $this->appendChar($quote);
                        $this->state = self::STATE_NORMAL;
                        $i++;
                    }
                    break;

                case self::STATE_BACKTICK:
                    $jump = strcspn($data, '`', $i, $limit - $i);
                    $j    = $i + $jump;

                    if ($j >= $safe) {
                        if ($safe > $i) {
                            $this->append(substr($data, $i, $safe - $i));
                        }

                        return $safe;
                    }

                    $this->append(substr($data, $i, $jump));
                    $i = $j;

                    if ($i + 1 < $len && $data[$i + 1] === '`') {
                        $this->append('``');
                        $i += 2;
                    } else {
                        $this->appendChar('`');
                        $this->state = self::STATE_NORMAL;
                        $i++;
                    }
                    break;

                case self::STATE_LINE_COMMENT:
                    $jump = strcspn($data, "\n", $i, $limit - $i);
                    $j    = $i + $jump;

                    if ($j >= $safe) {
                        if ($safe > $i) {
                            $this->append(substr($data, $i, $safe - $i));
                        }

                        return $safe;
                    }

                    $this->append(substr($data, $i, $jump));
                    $i = $j;
                    // 换行符留给 NORMAL 状态消费并计数
                    $this->state = self::STATE_NORMAL;
                    break;

                case self::STATE_BLOCK_COMMENT:
                    $end = strpos($data, '*/', $i);

                    if ($end === false || $end >= $safe) {
                        if ($safe > $i) {
                            $this->append(substr($data, $i, $safe - $i));
                        }

                        return $safe;
                    }

                    $this->append(substr($data, $i, $end + 2 - $i));
                    $i           = $end + 2;
                    $this->state = self::STATE_NORMAL;
                    break;
            }
        }

        return $i;
    }

    private function resetParser(): void
    {
        $this->state              = self::STATE_NORMAL;
        $this->buffer             = '';
        $this->line               = 1;
        $this->statementStartLine = 1;
        $this->hasToken           = false;
        $this->emitted            = [];
    }

    private function append(string $s): void
    {
        if ($s === '') {
            return;
        }

        $this->buffer .= $s;
        $this->line += substr_count($s, "\n");
    }

    /**
     * 追普通文本片段，并在语句首个有效 token 出现时记录起始行号。
     */
    private function appendNormal(string $s): void
    {
        if ($s === '') {
            return;
        }

        if (!$this->hasToken && trim($s) !== '') {
            $leading                  = strspn($s, " \t\n\r\0\x0B");
            $this->statementStartLine = $this->line + substr_count($s, "\n", 0, $leading);
            $this->hasToken           = true;
        }

        $this->append($s);
    }

    private function appendChar(string $c): void
    {
        $this->buffer .= $c;

        if ($c === "\n") {
            $this->line++;
        }
    }

    /**
     * 判断语句是否为 DDL（MySQL 中 DDL 会隐式提交，无法纳入 DML 批次事务）。
     */
    private function isDdl(string $sql): bool
    {
        // 快速路径：语句直接以字母关键字开头（绝大多数 INSERT/UPDATE/...）
        if (preg_match('/^\s*[A-Za-z]/', $sql) === 1) {
            return preg_match('/^\s*(?:CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $sql) === 1;
        }

        // 前导注释（-- 、#、/* */）的语句先剥掉注释再判断
        $stripped = preg_replace(
            ['!/\*.*?\*/!s', '/--[^\n\r]*/', '/#[^\n\r]*/'],
            '',
            $sql
        );

        return preg_match(
            '/^\s*(?:CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i',
            (string) $stripped
        ) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function getFiles(): array
    {
        $files = glob($this->sqlPath . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $names = array_map(static fn (string $f): string => basename($f), $files);

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @return array<string, bool>
     */
    private function getRanFiles(): array
    {
        $stmt = $this->pdo->query("SELECT `filename` FROM `{$this->table()}`");

        if (!$stmt) {
            return [];
        }

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $result[(string) $name] = true;
        }

        return $result;
    }

    private function recordFile(string $file): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$this->table()}` (`filename`) VALUES (?)"
        );
        $stmt->execute([$file]);
    }

    private function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS `{$this->table()}` (
                    `id` INTEGER PRIMARY KEY,
                    `filename` VARCHAR(255) NOT NULL UNIQUE,
                    `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                )"
            );

            return;
        }

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table()}` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `filename` VARCHAR(255) NOT NULL,
                `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_filename` (`filename`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function table(): string
    {
        return self::TABLE;
    }

    /**
     * @param  Closure(string, string):void|null $output
     */
    private function writeln(?Closure $output, string $message): void
    {
        if ($output !== null) {
            $output($message, 'info');
        }
    }
}
