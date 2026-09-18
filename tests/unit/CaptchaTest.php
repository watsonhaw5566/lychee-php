<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\Application;
use Lychee\captcha\Captcha;
use PHPUnit\Framework\TestCase;
use think\CacheManager;

class CaptchaTest extends TestCase
{
    private Captcha $captcha;
    private CacheManager $cache;

    protected function setUp(): void
    {
        $app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $this->captcha = $app->container->get(Captcha::class);
        $this->cache   = $app->container->get(CacheManager::class);
        $this->cache->clear();
    }

    public function test_image_generate_returns_png_binary(): void
    {
        $binary = $this->captcha->generate('image', 'test-id');

        $this->assertIsString($binary);
        // PNG 文件头
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $binary);
    }

    public function test_image_verify_succeeds_with_correct_code(): void
    {
        // 直接通过缓存写入一个已知验证码，绕过随机生成
        $this->cache->set('captcha:image:test-id', ['code' => 'ABCD', 'attempts' => 0], 300);

        $this->assertTrue($this->captcha->verify('image', 'test-id', 'abcd'));
    }

    public function test_verify_is_case_insensitive(): void
    {
        $this->cache->set('captcha:image:test-id', ['code' => 'AbCd', 'attempts' => 0], 300);

        $this->assertTrue($this->captcha->verify('image', 'test-id', 'aBcD'));
    }

    public function test_verify_fails_with_wrong_code(): void
    {
        $this->cache->set('captcha:image:test-id', ['code' => 'ABCD', 'attempts' => 0], 300);

        $this->assertFalse($this->captcha->verify('image', 'test-id', '1234'));
    }

    public function test_verify_is_one_time_use(): void
    {
        $this->cache->set('captcha:image:test-id', ['code' => 'ABCD', 'attempts' => 0], 300);

        // 第一次校验成功
        $this->assertTrue($this->captcha->verify('image', 'test-id', 'abcd'));

        // 第二次校验失败（已作废）
        $this->assertFalse($this->captcha->verify('image', 'test-id', 'abcd'));
    }

    public function test_verify_returns_false_when_not_exists(): void
    {
        $this->assertFalse($this->captcha->verify('image', 'non-existent', 'abcd'));
    }

    public function test_max_attempts_locks_out(): void
    {
        // max_attempts 默认为 5
        $this->cache->set('captcha:image:test-id', ['code' => 'ABCD', 'attempts' => 4], 300);

        // 第 5 次失败，attempts 变为 5
        $this->assertFalse($this->captcha->verify('image', 'test-id', 'wrong'));

        // 第 6 次尝试直接作废（attempts >= max_attempts）
        $this->assertFalse($this->captcha->verify('image', 'test-id', 'abcd'));
    }

    public function test_clear_removes_captcha(): void
    {
        $this->cache->set('captcha:image:test-id', ['code' => 'ABCD', 'attempts' => 0], 300);

        $this->captcha->clear('image', 'test-id');

        $this->assertFalse($this->captcha->verify('image', 'test-id', 'abcd'));
    }

    public function test_sms_generate_returns_true(): void
    {
        $result = $this->captcha->generate('sms', '13800138000');

        $this->assertTrue($result);
    }

    public function test_sms_verify_works(): void
    {
        $this->cache->set('captcha:sms:13800138000', ['code' => '123456', 'attempts' => 0], 300);

        $this->assertTrue($this->captcha->verify('sms', '13800138000', '123456'));
        $this->assertFalse($this->captcha->verify('sms', '13800138000', '123456'));
    }

    public function test_email_generate_returns_true(): void
    {
        $result = $this->captcha->generate('email', 'user@example.com');

        $this->assertTrue($result);
    }

    public function test_email_verify_works(): void
    {
        $this->cache->set('captcha:email:user@example.com', ['code' => '654321', 'attempts' => 0], 300);

        $this->assertTrue($this->captcha->verify('email', 'user@example.com', '654321'));
        $this->assertFalse($this->captcha->verify('email', 'user@example.com', '654321'));
    }

    public function test_email_send_class_method_is_called(): void
    {
        // 模拟 Captcha 解析后的 send 形式：[$instance, 'send']
        $sender = new class () {
            public string $receivedCode    = '';
            public string $receivedEmail   = '';
            public string $receivedSubject = '';

            public function send(string $code, string $email, string $subject): bool
            {
                $this->receivedCode    = $code;
                $this->receivedEmail   = $email;
                $this->receivedSubject = $subject;

                return true;
            }
        };

        $driver = new \Lychee\captcha\driver\EmailDriver([
            'send' => [$sender, 'send'],
        ]);

        $code = $driver->generateCode();
        $this->assertTrue($driver->render($code, 'user@example.com'));

        $this->assertSame($code, $sender->receivedCode);
        $this->assertSame('user@example.com', $sender->receivedEmail);
        $this->assertSame('您的验证码', $sender->receivedSubject);
    }
}
