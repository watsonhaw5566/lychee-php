<?php

declare(strict_types=1);

namespace Lychee\validate;

use Closure;
use BadMethodCallException;

/**
 * 轻量校验器。
 *
 * 兼容 think-validate 常用 API：rule / message / batch / failException /
 * scene / only / remove / append / extend / check / getError / maker。
 *
 * 典型用法：
 *
 *   $v = new Validate();
 *   $v->rule([
 *       'name'  => 'require|max:25',
 *       'email' => 'require|email',
 *   ])->message([
 *       'name.require' => '用户名必填',
 *   ])->failException()->check($data);
 *
 * 规则调度：
 *   - 字符串 "foo:a,b" → Rule::foo($value, ['a','b'], $data, $field)
 *   - 规则名也可是 extend() 注册的闭包
 *   - 子类可以通过 checkXxx() 方法扩展规则（与 ThinkPHP 习惯一致）
 *   - 返回 true / false / string（自定义消息）
 *
 * 失败消息查找链：
 *   $message["{field}.{rule}"] → $message[$field] → extend 注册的消息 → Lang 默认
 */
class Validate
{
    /** @var array<string, string|array<int|string, mixed>> */
    protected array $rule = [];

    /** @var array<string, string> */
    protected array $message = [];

    /** @var array<string, array<int, string>> */
    protected array $scene = [];

    /** @var array<string, string> 字段名 => 中文显示名 */
    protected array $field = [];

    /** 是否批量验证（返回全部错误） */
    protected bool $batch = false;

    /** check() 失败时是否抛出 ValidateException */
    protected bool $failException = false;

    /** 当前使用的默认语言包：zh / en */
    protected string $lang = 'en';

    /** @var array<int, string>|null 场景字段白名单 */
    protected ?array $only = null;

    /** @var array<int, string> 场景中要移除的字段/规则 */
    protected array $remove = [];

    /** @var array<string, string> 场景中要追加的规则 */
    protected array $append = [];

    /** @var array<string, Closure> extend 注册的自定义规则 */
    protected array $extend = [];

    /** @var array<string, string> extend 注册规则的默认消息 */
    protected array $extendMessage = [];

    /** @var array<string, string>|string 校验错误（批量时为数组） */
    protected string|array $error = '';

    /** @var array<int, Closure(self): void> Validate::maker() 注册的构造钩子 */
    protected static array $makers = [];

    public function __construct()
    {
        foreach (static::$makers as $maker) {
            $maker($this);
        }
    }

    /**
     * 注册 Validate 实例构造钩子（所有子类实例均生效）。
     *
     * 典型用法：Application 启动时调用 Validate::maker(fn($v) => $v->useZh())
     * 使框架内所有校验器默认中文提示。
     */
    public static function maker(Closure $maker): void
    {
        static::$makers[] = $maker;
    }

    /**
     * 清空 maker 钩子（主要用于测试隔离）。
     */
    public static function clearMakers(): void
    {
        static::$makers = [];
    }

    /**
     * 设置或追加规则。
     *
     * @param array<string, string|array<int|string, mixed>> $rules
     */
    public function rule(array $rules): static
    {
        $this->rule = $rules + $this->rule;

        return $this;
    }

    /**
     * 设置提示信息。
     *
     * @param array<string, string> $message
     */
    public function message(array $message): static
    {
        $this->message = $message + $this->message;

        return $this;
    }

    /**
     * 开启批量验证。
     */
    public function batch(bool $batch = true): static
    {
        $this->batch = $batch;

        return $this;
    }

    /**
     * check() 失败时是否抛出 ValidateException。
     */
    public function failException(bool $fail = true): static
    {
        $this->failException = $fail;

        return $this;
    }

    /**
     * 使用中文默认提示。
     */
    public function useZh(): static
    {
        $this->lang = 'zh';

        return $this;
    }

    /**
     * 使用英文默认提示。
     */
    public function useEn(): static
    {
        $this->lang = 'en';

        return $this;
    }

    /**
     * 应用场景：
     *   - 若子类定义了 sceneXxx() 方法，优先调用；
     *   - 否则读取 $scene 属性 [场景 => [字段1, 字段2, ...]]。
     */
    public function scene(string $scene): static
    {
        $this->only   = null;
        $this->remove = [];
        $this->append = [];

        $method = 'scene' . ucfirst($scene);

        if (method_exists($this, $method)) {
            $this->$method();

            return $this;
        }

        if (isset($this->scene[$scene]) && is_array($this->scene[$scene])) {
            $this->only = array_values($this->scene[$scene]);
        }

        return $this;
    }

    /**
     * 场景内限定字段白名单。
     *
     * @param array<int, string> $fields
     */
    public function only(array $fields): static
    {
        $this->only = array_values($fields);

        return $this;
    }

    /**
     * 场景内移除字段或字段上的某条规则。
     *
     * 用法：
     *   remove('name')              // 移除 name 字段的全部规则
     *   remove('name', 'require')   // 仅移除 name 的 require 规则
     *   remove(['name' => 'require', 'age' => true])
     *
     * @param string|array<string, string|true> $field
     */
    public function remove(string|array $field, string|true|null $rule = null): static
    {
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $this->remove[$k] = is_string($v) ? $v : '';
            }
        } else {
            $this->remove[$field] = $rule === null || $rule === true ? '' : $rule;
        }

        return $this;
    }

    /**
     * 场景内追加字段规则。
     */
    public function append(string|array $field, ?string $rule = null): static
    {
        if (is_array($field)) {
            foreach ($field as $k => $v) {
                $this->append[$k] = (string) $v;
            }
        } else {
            $this->append[$field] = (string) $rule;
        }

        return $this;
    }

    /**
     * 注册自定义规则。
     *
     * @param Closure(mixed, mixed, array<string, mixed>, string): (bool|string) $callback
     */
    public function extend(string $rule, Closure $callback, ?string $message = null): static
    {
        $this->extend[$rule] = $callback;

        if ($message !== null) {
            $this->extendMessage[$rule] = $message;
        }

        return $this;
    }

    /**
     * 执行校验。
     *
     * @param array<string, mixed> $data
     * @throws ValidateException 当 failException(true) 且校验失败时
     */
    public function check(array $data): bool
    {
        $this->error = $this->batch ? [] : '';

        $rules = $this->resolveRules();

        foreach ($rules as $field => $fieldRules) {
            $value = $this->getValue($data, $field);

            foreach ($this->parseFieldRules($fieldRules) as [$ruleName, $params]) {
                // require 单独处理：缺字段或空值即失败
                if ($ruleName === 'require') {
                    if (!Rule::require($value, $params, $data, $field)) {
                        if ($this->fail($field, 'require', $params)) {
                            return $this->finish();
                        }
                    }
                    continue;
                }

                // 其他规则：字段未提供值时跳过（require 之外的规则默认视为可选）
                if (!array_key_exists($field, $data) || $value === null || $value === '') {
                    continue;
                }

                $result = $this->runRule($ruleName, $value, $params, $data, $field);

                if ($result === true) {
                    continue;
                }

                $message = is_string($result) ? $result : null;
                if ($this->fail($field, $ruleName, $params, $message)) {
                    return $this->finish();
                }
            }
        }

        return $this->finish();
    }

    /**
     * 获取校验错误信息。
     *
     * @param bool $asArray true 时始终返回数组（批量模式下的 field => message）
     * @return string|array<string, string>
     */
    public function getError(bool $asArray = false): string|array
    {
        if ($asArray) {
            if (is_array($this->error)) {
                return $this->error;
            }

            return $this->error === '' ? [] : [0 => $this->error];
        }

        if (is_array($this->error) && count($this->error) === 1) {
            return (string) reset($this->error);
        }

        return $this->error;
    }

    /**
     * 解析当前要校验的规则表（应用 only / remove / append）。
     *
     * @return array<string, string|array<int|string, mixed>>
     */
    protected function resolveRules(): array
    {
        $rules = $this->rule;

        if ($this->only !== null) {
            $rules = array_intersect_key($rules, array_flip($this->only));
        }

        foreach ($this->remove as $field => $rule) {
            if ($rule === '') {
                $rules = array_diff_key($rules, [(string) $field => true]);
                continue;
            }

            if (!isset($rules[$field])) {
                continue;
            }

            $rules[$field] = $this->removeRuleFromField($rules[$field], $rule);
        }

        foreach ($this->append as $field => $extra) {
            if (!isset($rules[$field])) {
                $rules[$field] = $extra;
                continue;
            }

            $existing      = is_string($rules[$field]) ? $rules[$field] : implode('|', (array) $rules[$field]);
            $rules[$field] = $existing === '' ? $extra : ($existing . '|' . $extra);
        }

        return $rules;
    }

    /**
     * 从字段规则串/数组中移除指定规则。
     */
    protected function removeRuleFromField(string|array $fieldRules, string $target): string|array
    {
        if (is_string($fieldRules)) {
            $parts = array_filter(
                explode('|', $fieldRules),
                fn (string $r): bool => $this->ruleNameOf($r) !== $target
            );

            return implode('|', $parts);
        }

        $result = [];
        foreach ($fieldRules as $k => $v) {
            $name = is_string($k) ? $k : $this->ruleNameOf((string) $v);
            if ($name === $target) {
                continue;
            }
            $result[$k] = $v;
        }

        return $result;
    }

    /**
     * 规则串形式 "rule:a,b" 中取出规则名。
     */
    protected function ruleNameOf(string $rule): string
    {
        return str_contains($rule, ':') ? strstr($rule, ':', true) : $rule;
    }

    /**
     * 解析一个字段的规则定义为 [rule, params] 列表。
     *
     * 支持两种写法：
     *   - 字符串：'require|max:25'
     *   - 数组：  ['require', 'max' => 25] 或 ['require', 'between' => '1,120']
     *
     * @return array<int, array{0: string, 1: array<int, mixed>}>
     */
    protected function parseFieldRules(string|array $fieldRules): array
    {
        if (is_string($fieldRules)) {
            $fieldRules = array_filter(explode('|', $fieldRules), fn (string $r): bool => $r !== '');
        }

        $result = [];
        foreach ($fieldRules as $k => $v) {
            if (is_string($k)) {
                // ['max' => 25] 或 ['between' => '1,120']
                $result[] = [$k, $this->parseParams($v)];
            } else {
                $rule = (string) $v;
                if (str_contains($rule, ':')) {
                    [$name, $raw] = explode(':', $rule, 2);
                    $result[]     = [$name, $this->parseParams($raw)];
                } else {
                    $result[] = [$rule, []];
                }
            }
        }

        return $result;
    }

    /**
     * 把规则参数字符串解析为数组。
     *
     * - 规则参数一律按 "," 切分成数组
     * - regex 规则保留原始字符串
     */
    protected function parseParams(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }

        $raw = (string) $raw;

        if ($raw === '') {
            return [];
        }

        return array_map('trim', explode(',', $raw));
    }

    /**
     * 从数据中取字段值（支持 "a.b.c" 嵌套）。
     */
    protected function getValue(array $data, string $field): mixed
    {
        if (!str_contains($field, '.')) {
            return $data[$field] ?? null;
        }

        $value = $data;
        foreach (explode('.', $field) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * 调用具体的规则函数。
     *
     * @return bool|string true 通过；false 失败使用默认消息；string 失败使用返回消息
     */
    protected function runRule(string $rule, mixed $value, array $params, array $data, string $field): bool|string
    {
        // 1. extend 注册的自定义规则优先
        if (isset($this->extend[$rule])) {
            $result = ($this->extend[$rule])($value, $params[0] ?? null, $data, $field);

            return is_bool($result) || is_string($result) ? $result : false;
        }

        // 2. Rule 类的内置规则
        if (method_exists(Rule::class, $rule)) {
            return Rule::$rule($value, $params, $data, $field);
        }

        // 3. 子类定义的 checkXxx 方法（与 ThinkPHP 习惯一致）
        $method = 'check' . ucfirst($rule);
        if (method_exists($this, $method)) {
            $result = $this->$method($value, $params, $data, $field);

            return is_bool($result) || is_string($result) ? $result : false;
        }

        return "规则 {$rule} 未定义";
    }

    /**
     * 记录一次失败。返回 true 表示应立即终止（非 batch 模式）。
     */
    protected function fail(string $field, string $rule, array $params, ?string $customMessage = null): bool
    {
        $message = $customMessage ?? $this->resolveMessage($field, $rule);
        $message = $this->interpolate($message, $field, $rule, $params);

        if ($this->batch) {
            if (!is_array($this->error)) {
                $this->error = [];
            }
            $this->error[$field] = $message;

            return false;
        }

        $this->error = $message;

        return true;
    }

    /**
     * 完成校验：根据 error 状态返回 true/false 或抛异常。
     */
    protected function finish(): bool
    {
        $hasError = is_array($this->error) ? $this->error !== [] : $this->error !== '';

        if (!$hasError) {
            return true;
        }

        if ($this->failException) {
            if (is_array($this->error)) {
                throw new ValidateException($this->error);
            }

            throw new ValidateException($this->error);
        }

        return false;
    }

    /**
     * 消息查找链：$message[field.rule] → $message[field] → extendMessage[rule] → Lang。
     */
    protected function resolveMessage(string $field, string $rule): string
    {
        if (isset($this->message[$field . '.' . $rule])) {
            return $this->message[$field . '.' . $rule];
        }

        if (isset($this->message[$field])) {
            return $this->message[$field];
        }

        if (isset($this->extendMessage[$rule])) {
            return $this->extendMessage[$rule];
        }

        return Lang::get($rule, $this->lang);
    }

    /**
     * 替换消息中的占位符：{:field} / {:rule} / {:0} / {:1}。
     */
    protected function interpolate(string $message, string $field, string $rule, array $params): string
    {
        $display = $this->field[$field] ?? $field;

        $replace = [
            '{:field}' => $display,
            '{:rule}'  => $rule,
        ];

        foreach ($params as $i => $v) {
            $replace['{:' . $i . '}'] = is_array($v) ? implode(',', $v) : (string) $v;
        }

        return strtr($message, $replace);
    }

    /**
     * 兼容 ThinkPHP 的 __call：允许直接调用场景方法名。
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (str_starts_with($name, 'scene')) {
            return $this->scene(lcfirst(substr($name, 5)));
        }

        throw new BadMethodCallException(sprintf('方法 %s::%s 不存在', static::class, $name));
    }
}
