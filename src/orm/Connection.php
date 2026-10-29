<?php

declare(strict_types=1);

namespace Lychee\orm;

use Closure;
use Lychee\orm\exception\DbException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * 单个数据库连接。
 *
 * 封装 PDO 连接、事务、断线重连与 SQL 日志回调。
 * 查询构造器 {@see Query} 通过本类执行 SQL 并复用预编译语句。
 */
class Connection
{
    protected ?PDO $pdo = null;

    protected int $transactionLevel = 0;

    /** @var Closure(string, string): void|null */
    protected ?Closure $logger = null;

    protected bool $breakReconnect = false;

    /** 已识别为「连接断开」的驱动错误关键字 */
    protected array $breakMatchStr = [
        'server has gone away',
        'no connection to the server',
        'Lost connection',
        'is dead or not enabled',
        'Error while sending',
        'decryption failed or bad record mac',
        'server closed the connection unexpectedly',
        'SSL connection has been closed unexpectedly',
        'Error writing data to the connection',
        'Resource deadlock avoided',
        'Transaction() on null',
        'child connection forced to terminate due to client_idle_limit',
        'query_wait_timeout',
        'reset by peer',
        'Physical connection is not usable',
        'TCP Provider: Error code 0x68',
        'ORA-03114',
        'Packets out of order. Expected',
        'Adaptive Server connection failed',
        'Communication link failure',
    ];

    public function __construct(
        protected string $name,
        protected array $config,
    ) {
        $this->breakReconnect = (bool) ($config['break_reconnect'] ?? false);
    }

    public function setLogger(?Closure $logger): void
    {
        $this->logger = $logger;
    }

    public function getConfig(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return $this->config[$key] ?? $default;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 建立或返回当前 PDO 实例。
     */
    public function getPdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        return $this->connect();
    }

    protected function connect(): PDO
    {
        $cfg      = $this->config;
        $type     = strtolower((string) ($cfg['type'] ?? 'mysql'));
        $host     = (string) ($cfg['hostname'] ?? '127.0.0.1');
        $port     = (string) ($cfg['hostport'] ?? '3306');
        $database = (string) ($cfg['database'] ?? '');
        $charset  = (string) ($cfg['charset'] ?? 'utf8mb4');
        $socket   = (string) ($cfg['socket'] ?? '');
        $username = (string) ($cfg['username'] ?? '');
        $password = (string) ($cfg['password'] ?? '');

        if ($type === 'mysql') {
            $dsn = $socket !== ''
                ? "mysql:unix_socket={$socket};dbname={$database}"
                : "mysql:host={$host};port={$port};dbname={$database}";
            $dsn .= ";charset={$charset}";
        } elseif ($type === 'sqlite') {
            $dsn = "sqlite:{$database}";
        } elseif (in_array($type, ['pgsql', 'postgres', 'postgresql'], true)) {
            $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
        } else {
            $dsn = "{$type}:host={$host};port={$port};dbname={$database};charset={$charset}";
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
        if (!empty($cfg['params']) && is_array($cfg['params'])) {
            $options = $cfg['params'] + $options;
        }

        try {
            $this->pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            throw new DbException('database connect failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        return $this->pdo;
    }

    /**
     * 执行查询并返回结果集。
     *
     * @param array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->run($sql, $bindings);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        return $rows;
    }

    /**
     * 执行写操作（insert/update/delete）并返回影响行数。
     *
     * @param array<int|string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->run($sql, $bindings);
        $count     = $statement->rowCount();
        $statement->closeCursor();

        return $count;
    }

    /**
     * 执行 INSERT 后返回自增主键。
     */
    public function insertGetId(string $sql, array $bindings = []): int|string
    {
        $this->execute($sql, $bindings);

        return $this->getPdo()->lastInsertId();
    }

    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            try {
                $this->getPdo()->beginTransaction();
            } catch (Throwable $e) {
                if ($this->causedByLostConnection($e) && $this->breakReconnect) {
                    $this->pdo = null;
                    $this->getPdo()->beginTransaction();
                } else {
                    throw new DbException($e->getMessage(), (int) $e->getCode(), $e);
                }
            }
        } else {
            $this->getPdo()->exec('SAVEPOINT trans' . ($this->transactionLevel + 1));
        }

        $this->transactionLevel++;
    }

    public function commit(): void
    {
        if ($this->transactionLevel <= 0) {
            return;
        }

        if ($this->transactionLevel === 1) {
            $this->getPdo()->commit();
        }

        $this->transactionLevel--;
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel <= 0) {
            return;
        }

        if ($this->transactionLevel === 1) {
            $this->getPdo()->rollBack();
            $this->transactionLevel = 0;

            return;
        }

        $this->getPdo()->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactionLevel);
        $this->transactionLevel--;
    }

    public function transaction(Closure $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * 获取数据表字段列表（含类型信息）。
     *
     * 兼容 think-orm 的 Connection::getTableFields() 接口，
     * 旧业务依赖此 API 做字段白名单。
     *
     * @return array<int, string>
     */
    public function getTableFields(string $table): array
    {
        $info = $this->getFields($table);

        return array_keys($info);
    }

    /**
     * 获取数据表字段元信息。
     *
     * @return array<string, array{name:string, type:string, notnull:bool, default:mixed, primary:bool, autoinc:bool}>
     */
    public function getFields(string $table): array
    {
        $type = strtolower((string) $this->getConfig('type', 'mysql'));

        if ($type === 'mysql') {
            $rows   = $this->select("SHOW FULL COLUMNS FROM `{$table}`");
            $fields = [];
            foreach ($rows as $row) {
                $name          = (string) $row['Field'];
                $fields[$name] = [
                    'name'    => $name,
                    'type'    => (string) ($row['Type'] ?? ''),
                    'notnull' => strtolower((string) ($row['Null'] ?? 'YES')) === 'no',
                    'default' => $row['Default'] ?? null,
                    'primary' => strtolower((string) ($row['Key'] ?? '')) === 'pri',
                    'autoinc' => str_contains(strtolower((string) ($row['Extra'] ?? '')), 'auto_increment'),
                ];
            }

            return $fields;
        }

        if ($type === 'sqlite') {
            $rows   = $this->select("PRAGMA table_info({$table})");
            $fields = [];
            foreach ($rows as $row) {
                $name          = (string) $row['name'];
                $fields[$name] = [
                    'name'    => $name,
                    'type'    => (string) ($row['type'] ?? ''),
                    'notnull' => (int) ($row['notnull'] ?? 0) === 1,
                    'default' => $row['dflt_value'] ?? null,
                    'primary' => (int) ($row['pk'] ?? 0) === 1,
                    'autoinc' => false,
                ];
            }

            return $fields;
        }

        // 其他驱动：退化为 SELECT 结果列
        try {
            $stmt    = $this->getPdo()->query("SELECT * FROM {$table} LIMIT 0");
            $columns = $stmt?->columnCount() ?? 0;
            $fields  = [];
            for ($i = 0; $i < $columns; $i++) {
                $meta          = $stmt->getColumnMeta($i) ?: [];
                $name          = (string) ($meta['name'] ?? '');
                $fields[$name] = [
                    'name'    => $name,
                    'type'    => (string) ($meta['native_type'] ?? ''),
                    'notnull' => false,
                    'default' => null,
                    'primary' => false,
                    'autoinc' => false,
                ];
            }

            return $fields;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 底层执行 PDO 预处理语句，含断线重连与 SQL 日志。
     *
     * @param array<int|string, mixed> $bindings
     */
    protected function run(string $sql, array $bindings): PDOStatement
    {
        try {
            return $this->runStatement($sql, $bindings);
        } catch (Throwable $e) {
            if ($this->causedByLostConnection($e) && $this->breakReconnect && $this->transactionLevel === 0) {
                $this->pdo = null;

                return $this->runStatement($sql, $bindings);
            }

            throw new DbException($e->getMessage() . ' SQL: ' . $sql, (int) $e->getCode(), $e);
        }
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    protected function runStatement(string $sql, array $bindings): PDOStatement
    {
        $statement = $this->getPdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $param = is_int($key) ? $key + 1 : $key;
            $statement->bindValue($param, $value, $this->bindType($value));
        }

        $start = microtime(true);
        $statement->execute();
        $elapsed = (microtime(true) - $start) * 1000;

        if ($this->logger instanceof Closure) {
            ($this->logger)('sql', sprintf(
                '[%s] %s | bindings: %s | %.2fms',
                $this->name,
                $sql,
                json_encode($bindings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $elapsed,
            ));
        }

        return $statement;
    }

    protected function bindType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }

    protected function causedByLostConnection(Throwable $e): bool
    {
        $msg = $e->getMessage();
        foreach ($this->breakMatchStr as $needle) {
            if (stripos($msg, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
