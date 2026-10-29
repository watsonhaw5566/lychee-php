<?php

declare(strict_types=1);

namespace Lychee\orm\command;

use Lychee\console\Command;
use Lychee\console\Input;
use Lychee\console\Output;
use Lychee\console\input\Argument;
use Lychee\console\input\Option;
use FilesystemIterator;
use Generator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * ORM 迁移命令：将项目源码中 think-orm 的 use 语句改写为 Lychee ORM。
 *
 * 词法级别替换（基于 token_get_all），仅改写符号引用，保留原有缩进、注释与业务逻辑。
 *
 * 支持的映射（think → Lychee）：
 *   think\Model                             → Lychee\orm\Model
 *   think\model\concern\SoftDelete          → Lychee\orm\concern\SoftDelete
 *   think\model\relation\HasOne             → Lychee\orm\relation\HasOne
 *   think\model\relation\HasMany            → Lychee\orm\relation\HasMany
 *   think\model\relation\BelongsTo          → Lychee\orm\relation\BelongsTo
 *   think\db\Query                          → Lychee\orm\Query
 *   think\facade\Db                         → Lychee\orm\facade\Db
 *   think\DbManager                         → Lychee\orm\ConnectionManager
 *   think\db\exception\DbException          → Lychee\orm\exception\DbException
 *   think\db\exception\DataNotFoundException→ Lychee\orm\exception\DataNotFoundException
 *   think\db\exception\ModelNotFoundException→ Lychee\orm\exception\ModelNotFoundException
 *
 * 不改写：think\Validate、think\exception\ValidateException 等校验器相关符号。
 *
 * 用法：
 *   php lee orm:migrate                 迁移默认目录（app/）
 *   php lee orm:migrate app             迁移指定目录
 *   php lee orm:migrate app --dry-run   仅输出将会改写的文件，不写盘
 */
class MigrateCommand extends Command
{
    protected string $name = 'orm:migrate';

    protected string $description = 'Migrate think-orm references to Lychee\\orm';

    /**
     * 符号映射表：旧 FQN => 新 FQN。
     *
     * 使用小写 key 以忽略大小写比较（PHP 命名空间比较本来就不区分大小写）。
     *
     * @var array<string, string>
     */
    protected array $classMap = [
        'think\\model'                                 => 'Lychee\\orm\\Model',
        'think\\model\\concern\\softdelete'            => 'Lychee\\orm\\concern\\SoftDelete',
        'think\\model\\relation\\hasone'               => 'Lychee\\orm\\relation\\HasOne',
        'think\\model\\relation\\hasmany'              => 'Lychee\\orm\\relation\\HasMany',
        'think\\model\\relation\\belongsto'            => 'Lychee\\orm\\relation\\BelongsTo',
        'think\\db\\query'                             => 'Lychee\\orm\\Query',
        'think\\facade\\db'                            => 'Lychee\\orm\\facade\\Db',
        'think\\dbmanager'                             => 'Lychee\\orm\\ConnectionManager',
        'think\\db\\exception\\dbexception'            => 'Lychee\\orm\\exception\\DbException',
        'think\\db\\exception\\datanotfoundexception'  => 'Lychee\\orm\\exception\\DataNotFoundException',
        'think\\db\\exception\\modelnotfoundexception' => 'Lychee\\orm\\exception\\ModelNotFoundException',
    ];

    protected function configure(): void
    {
        $this->setName($this->name);
        $this->setDescription($this->description);
        $this->addArgument(
            'path',
            Argument::OPTIONAL,
            'Target directory relative to project root (default: app)'
        );
        $this->addOption(
            'dry-run',
            null,
            Option::VALUE_NONE,
            'Only report files that would be modified; do not write changes',
        );
    }

    protected function execute(Input $input, Output $output): int
    {
        $base   = $this->app->getRootPath();
        $target = (string) ($input->getArgument('path') ?? 'app');
        $dryRun = (bool) $input->getOption('dry-run');

        $dir = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($target, DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            $output->writeln("<error>Directory not found:</error> {$dir}");

            return 1;
        }

        $output->writeln("<info>Scanning:</info> {$dir}");
        if ($dryRun) {
            $output->writeln('<comment>Dry-run mode: no file will be written.</comment>');
        }

        $modified = 0;
        $scanned  = 0;

        foreach ($this->listPhpFiles($dir) as $file) {
            $scanned++;
            $original = @file_get_contents($file);
            if ($original === false) {
                continue;
            }

            // 粗过滤：文件内不含 "think" 字样，跳过词法分析
            if (stripos($original, 'think') === false) {
                continue;
            }

            $replaced = $this->rewrite($original);
            if ($replaced === null || $replaced === $original) {
                continue;
            }

            $modified++;
            $rel = str_replace($base, '', $file);
            $output->writeln('<info>updated</info> ' . $rel);

            if (!$dryRun) {
                file_put_contents($file, $replaced);
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done.</info> scanned %d files, %s %d files.',
            $scanned,
            $dryRun ? 'would modify' : 'modified',
            $modified,
        ));

        return 0;
    }

    /**
     * 使用 token_get_all 词法扫描，仅替换 use 语句中的符号与被引用的 FQN，
     * 保留原有注释、缩进与业务代码。
     *
     * 替换策略：对连续的 T_NAME_QUALIFIED / T_NS_SEPARATOR / T_STRING 片段组合为完整符号，
     * 若命中映射表则整段替换为新 FQN。
     */
    protected function rewrite(string $source): ?string
    {
        $tokens = @token_get_all($source);
        if ($tokens === false) {
            return null;
        }

        $out = '';
        $i   = 0;
        $n   = count($tokens);

        while ($i < $n) {
            $token = $tokens[$i];
            if (is_array($token) && $this->isNameToken($token[0])) {
                [$fqn, $consumed] = $this->readFqn($tokens, $i, $n);
                $mapped           = $this->mapClass($fqn);
                if ($mapped !== null) {
                    $out .= $mapped;
                } else {
                    $out .= $fqn;
                }
                $i += $consumed;
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
            $i++;
        }

        return $out;
    }

    protected function isNameToken(int $type): bool
    {
        // PHP 8+ 具名 token
        return in_array($type, [
            T_STRING,
            T_NS_SEPARATOR,
            defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : -1,
            defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : -2,
            defined('T_NAME_RELATIVE') ? T_NAME_RELATIVE : -3,
        ], true);
    }

    /**
     * 从 $tokens[$start] 起读取一个完整的名字片段，返回原文与消费 token 数。
     *
     * @param array<int, mixed> $tokens
     * @return array{0:string, 1:int}
     */
    protected function readFqn(array $tokens, int $start, int $n): array
    {
        $buffer   = '';
        $consumed = 0;
        for ($j = $start; $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $this->isNameToken($t[0])) {
                $buffer .= $t[1];
                $consumed++;
                continue;
            }
            break;
        }

        return [$buffer, $consumed];
    }

    /**
     * 根据映射表尝试替换一个 FQN；无匹配时返回 null。
     *
     * 保留前导反斜杠（T_NAME_FULLY_QUALIFIED）与大小写不敏感匹配。
     */
    protected function mapClass(string $fqn): ?string
    {
        $leading = '';
        $body    = $fqn;
        if (str_starts_with($body, '\\')) {
            $leading = '\\';
            $body    = substr($body, 1);
        }

        $key = strtolower($body);
        if (isset($this->classMap[$key])) {
            return $leading . $this->classMap[$key];
        }

        return null;
    }

    /**
     * 递归枚举目录下所有 .php 文件（忽略 vendor / node_modules / runtime）。
     *
     * @return Generator<int, string>
     */
    protected function listPhpFiles(string $dir): Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file): bool {
                    if ($file->isDir()) {
                        $name = $file->getFilename();

                        return !in_array($name, ['vendor', 'node_modules', 'runtime', '.git'], true);
                    }

                    return str_ends_with($file->getFilename(), '.php');
                }
            )
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isFile()) {
                yield $file->getPathname();
            }
        }
    }
}
