<?php

declare(strict_types=1);

namespace Lychee\session;

use Lychee\http\Cookie;
use Lychee\http\Request;
use Lychee\http\Response;

/**
 * Session 管理器。
 *
 * 负责会话 ID 管理、数据读写、与 Request/Response 的 Cookie 桥接。
 */
class Session
{
    protected string $id = '';

    /** @var array<string, mixed> */
    protected array $data = [];

    protected bool $started = false;

    /** @var array<string, mixed> */
    protected array $config;

    public function __construct(
        protected readonly SessionDriverInterface $driver,
        array $config = [],
    ) {
        $this->config = array_merge([
            'name'        => 'LYCHEE_SESSION',
            'expire'      => 120,
            'cookie_path' => '/',
            'domain'      => null,
            'secure'      => false,
            'http_only'   => true,
            'same_site'   => Cookie::SAME_SITE_LAX,
        ], $config);
    }

    /**
     * 从请求中启动会话（读取 Session ID，加载数据）。
     */
    public function start(Request $request): void
    {
        if ($this->started) {
            return;
        }

        $name = (string) $this->config['name'];
        $id   = (string) $request->cookie($name, '');

        if ($id === '' || !$this->isValidId($id)) {
            $id = $this->generateId();
        }

        $this->id      = $id;
        $this->data    = $this->readData($id);
        $this->started = true;
    }

    /**
     * 将会话数据持久化，并把 Session Cookie 写入响应。
     */
    public function save(Response $response): void
    {
        if (!$this->started) {
            return;
        }

        $this->driver->write($this->id, serialize($this->data));

        $response->cookie(
            name: (string) $this->config['name'],
            value: $this->id,
            minutes: (int) $this->config['expire'],
            path: (string) $this->config['cookie_path'],
            domain: $this->config['domain'],
            secure: (bool) $this->config['secure'],
            httpOnly: (bool) $this->config['http_only'],
            sameSite: $this->config['same_site'],
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function flush(): void
    {
        $this->data = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * 取出并删除一个键值。
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    /**
     * 重新生成 Session ID（可选销毁旧数据）。
     */
    public function regenerate(bool $destroy = false): string
    {
        $oldId = $this->id;

        if ($destroy) {
            $this->driver->destroy($oldId);
        }

        $this->id = $this->generateId();

        return $this->id;
    }

    /**
     * 销毁当前会话。
     */
    public function invalidate(Response $response): void
    {
        $this->driver->destroy($this->id);
        $this->data = [];
        $this->id   = $this->generateId();

        $response->withoutCookie(
            name: (string) $this->config['name'],
            path: (string) $this->config['cookie_path'],
            domain: $this->config['domain'],
        );
    }

    private function readData(string $id): array
    {
        $raw = $this->driver->read($id);

        if ($raw === '') {
            return [];
        }

        $data = @unserialize($raw);

        return is_array($data) ? $data : [];
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $id);
    }
}
