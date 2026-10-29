<?php

declare(strict_types=1);

namespace Tests\unit;

use Lychee\http\File;
use Lychee\validate\Validate;
use Lychee\validate\ValidateException;
use PHPUnit\Framework\TestCase;

class ValidateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Validate::clearMakers();
    }

    public function test_require_rule_fails_on_empty_values(): void
    {
        $v = (new Validate())->rule(['name' => 'require'])->useEn();

        $this->assertFalse($v->check([]));
        $this->assertSame('name is required', $v->getError());

        $this->assertFalse($v->check(['name' => '']));
        $this->assertSame('name is required', $v->getError());

        $this->assertTrue($v->check(['name' => 'alice']));
    }

    public function test_optional_rules_skip_missing_or_empty_values(): void
    {
        $v = (new Validate())->rule(['email' => 'email'])->useEn();

        // 缺字段 / 空值 → 跳过（非 require 规则默认可选）
        $this->assertTrue($v->check([]));
        $this->assertTrue($v->check(['email' => '']));

        $this->assertFalse($v->check(['email' => 'not-an-email']));
    }

    public function test_rule_params_and_placeholders(): void
    {
        $v = (new Validate())
            ->rule(['age' => 'between:1,120'])
            ->useEn();

        $this->assertFalse($v->check(['age' => 200]));
        $this->assertSame('age must be between 1 and 120', $v->getError());
    }

    public function test_message_lookup_chain(): void
    {
        $v = (new Validate())
            ->rule(['name' => 'require|max:5'])
            ->message([
                'name.require' => '用户名必填',
                'name.max'     => '用户名不超过 {:0}',
            ]);

        $v->check([]);
        $this->assertSame('用户名必填', $v->getError());

        $v->check(['name' => 'abcdefg']);
        $this->assertSame('用户名不超过 5', $v->getError());
    }

    public function test_batch_mode_collects_all_errors(): void
    {
        $v = (new Validate())
            ->rule([
                'name'  => 'require',
                'email' => 'require|email',
            ])
            ->batch()
            ->useEn();

        $this->assertFalse($v->check(['email' => 'bad']));

        $errors = $v->getError(true);
        $this->assertSame([
            'name'  => 'name is required',
            'email' => 'email is not a valid email',
        ], $errors);
    }

    public function test_fail_exception_throws_validate_exception(): void
    {
        $this->expectException(ValidateException::class);

        (new Validate())
            ->rule(['name' => 'require'])
            ->failException()
            ->check([]);
    }

    public function test_fail_exception_contains_array_errors_in_batch(): void
    {
        try {
            (new Validate())
                ->rule([
                    'name'  => 'require',
                    'email' => 'require',
                ])
                ->batch()
                ->failException()
                ->check([]);
            $this->fail('Expected ValidateException was not thrown');
        } catch (ValidateException $e) {
            $error = $e->getError();
            $this->assertIsArray($error);
            $this->assertArrayHasKey('name', $error);
            $this->assertArrayHasKey('email', $error);
        }
    }

    public function test_extend_custom_rule(): void
    {
        $v = (new Validate())
            ->extend('notAdmin', function ($value) {
                return $value !== 'admin' ? true : '用户名不能为 admin';
            })
            ->rule(['name' => 'require|notAdmin']);

        $this->assertFalse($v->check(['name' => 'admin']));
        $this->assertSame('用户名不能为 admin', $v->getError());

        $this->assertTrue($v->check(['name' => 'alice']));
    }

    public function test_extend_rule_default_message(): void
    {
        $v = (new Validate())
            ->extend('positive', fn ($value) => is_numeric($value) && $value > 0, '必须为正数')
            ->rule(['num' => 'positive']);

        $this->assertFalse($v->check(['num' => -1]));
        $this->assertSame('必须为正数', $v->getError());
    }

    public function test_scene_limits_validated_fields(): void
    {
        $v = new class () extends Validate {
            protected array $rule = [
                'name'  => 'require',
                'email' => 'require|email',
                'age'   => 'require|number',
            ];

            protected array $scene = [
                'edit' => ['name', 'age'],
            ];
        };

        $v->scene('edit')->useEn();

        // 场景只校验 name/age，缺少 email 不触发校验
        $this->assertFalse($v->check(['name' => 'alice', 'age' => 'x']));
        $this->assertSame('age must be a number', $v->getError());

        $this->assertTrue($v->check(['name' => 'alice', 'age' => 18]));
    }

    public function test_scene_method_hook(): void
    {
        $v = new class () extends Validate {
            protected array $rule = [
                'name'     => 'require',
                'password' => 'require|min:6',
            ];

            public function sceneLogin(): void
            {
                $this->only(['name']);
            }
        };

        $this->assertTrue($v->scene('login')->check(['name' => 'alice']));
    }

    public function test_confirm_rule(): void
    {
        $v = (new Validate())->rule(['password' => 'require|confirm'])->useEn();

        $data = ['password' => 'secret', 'password_confirm' => 'other'];
        $this->assertFalse($v->check($data));

        $data['password_confirm'] = 'secret';
        $this->assertTrue($v->check($data));
    }

    public function test_in_rule(): void
    {
        $v = (new Validate())->rule(['type' => 'in:1,2,3']);

        $this->assertTrue($v->check(['type' => 2]));
        $this->assertFalse($v->check(['type' => 9]));
    }

    public function test_file_rule_accepts_file_instance(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'validate');
        file_put_contents($tmp, 'hello');

        try {
            $file = new File($tmp);
            $v    = (new Validate())->rule(['doc' => 'file|fileSize:1024']);
            $this->assertTrue($v->check(['doc' => $file]));

            $v2 = (new Validate())->rule(['doc' => 'file'])->useEn();
            $this->assertFalse($v2->check(['doc' => 'not-a-file']));
        } finally {
            @unlink($tmp);
        }
    }

    public function test_subclass_check_prefix_method(): void
    {
        $v = new class () extends Validate {
            protected array $rule = ['name' => 'require|notAdmin'];

            protected function checkNotAdmin(mixed $value): bool|string
            {
                return $value !== 'admin' ? true : '不允许 admin';
            }
        };

        $this->assertFalse($v->check(['name' => 'admin']));
        $this->assertSame('不允许 admin', $v->getError());
        $this->assertTrue($v->check(['name' => 'alice']));
    }

    public function test_maker_hook_applies_to_all_instances(): void
    {
        Validate::maker(fn (Validate $v) => $v->useZh());

        $v = (new Validate())->rule(['name' => 'require']);
        $v->check([]);
        $this->assertSame('name不能为空', $v->getError());
    }

    public function test_use_zh_messages(): void
    {
        $v = (new Validate())->rule(['name' => 'require|max:5'])->useZh();

        $v->check([]);
        $this->assertSame('name不能为空', $v->getError());

        $v->check(['name' => '一二三四五六七']);
        $this->assertSame('name长度不能超过 5', $v->getError());
    }
}
