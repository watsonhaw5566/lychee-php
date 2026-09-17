<?php

declare(strict_types=1);

namespace Lychee\routing;

use Lychee\http\Controller;
use Lychee\http\JsonResponse;
use ReflectionClass;
use think\db\Query;
use think\exception\ValidateException;
use think\Model;
use think\Validate;
use RuntimeException;
use Throwable;
use ReflectionException;

/**
 * 资源控制器：基于模型类的零代码 CRUD（base* 方法）。
 *
 * 配合 #[Resource] 路由注解使用，子类只需声明 $model / $validate。
 *
 * 约定：
 *   - $model 留空时按控制器名自动推断（UserController → app\model\User）
 *   - 查询条件支持后缀 DSL：_like / _range / _between / _in
 *   - use HasDataPermission 可开启数据权限过滤
 *
 * 不需要 CRUD 的控制器请继承 {@see \Lychee\http\Controller}。
 */
abstract class ResourceController extends Controller
{
    /** 是否批量验证 */
    protected bool $batchValidate = false;

    /** 模型类名，留空则按控制器名自动推断 */
    protected string $model = '';

    /** 验证器类名 */
    protected string $validate = '';

    /** 唯一约束字段，支持联合唯一 */
    protected array $uniqueFields = [];

    /** 数据不存在时的提示信息 */
    protected string $notExistMessage = '数据不存在';

    // ── 统一 JSON 响应（格式可被子类覆盖）──────────────────────────

    /**
     * 分页响应。
     *
     * @throws \think\db\exception\DbException
     */
    protected function paginate(
        Model|Query $query,
        int $current = 1,
        int $pageSize = 10,
        string $msg = 'success',
        int $code = 200,
    ): JsonResponse {
        $current  = max(1, $current);
        $pageSize = max(1, min(200, $pageSize));

        $paginator = $query->paginate(['list_rows' => $pageSize, 'page' => $current]);

        return new JsonResponse([
            'errno' => 0,
            'code'  => $code,
            'msg'   => $msg,
            'data'  => [
                'total' => $paginator->total(),
                'list'  => $paginator->items(),
            ],
        ], $code);
    }

    // ── 验证快捷方式 ────────────────────────────────────────────────

    /**
     * 验证数据。
     *
     * @param array        $data     数据
     * @param array|string $validate 验证器类名或规则数组（支持 "Class.scene" 场景语法）
     * @param array        $message  提示信息
     * @param bool         $batch    是否批量验证
     * @return true
     *
     * @throws \think\exception\ValidateException
     */
    protected function validate(
        array $data,
        array|string $validate,
        array $message = [],
        bool $batch = false,
    ): true {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            $scene = null;
            if (str_contains($validate, '.')) {
                [$validate, $scene] = explode('.', $validate, 2);
            }

            $class = str_contains($validate, '\\')
                ? $validate
                : $this->app->getNamespace() . '\\validate\\' . $validate;
            $v = new $class();

            if ($scene !== null) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        if ($batch || $this->batchValidate) {
            $v->batch();
        }

        return $v->failException()->check($data);
    }

    /**
     * 将校验异常的错误信息格式化为字符串。
     *
     * think\Validate 的 getError() 在批量验证时返回数组，
     * 此处统一拼接为分号分隔的字符串，避免 fail(string) 类型不匹配。
     *
     * @param array|string $error
     */
    protected function formatValidateError(array|string $error): string
    {
        return is_array($error) ? implode('；', $error) : (string) $error;
    }

    // ── 模型解析 ────────────────────────────────────────────────────

    protected function getModel(): Model
    {
        $class = $this->model !== '' ? $this->model : $this->guessModelClass();

        if (!class_exists($class)) {
            throw new RuntimeException("模型类 {$class} 不存在");
        }

        return new $class();
    }

    /** UserController → app\model\User */
    protected function guessModelClass(): string
    {
        $short = (new ReflectionClass($this))->getShortName();
        $name  = preg_replace('/Controller$/', '', $short);

        return $this->app->getNamespace() . '\\model\\' . $name;
    }

    // ── 查询 DSL ────────────────────────────────────────────────────

    /**
     * 应用查询条件到模型。
     *
     * 支持的字段后缀：
     *   - _like:    模糊匹配（LIKE %value%）
     *   - _range:   时间范围（whereBetweenTime）
     *   - _between: 数值范围（whereBetween）
     *   - _in:      集合匹配（JSON 数组字段自动使用 JSON_CONTAINS）
     */
    protected function applyWhere(Model $query, array $where): Model
    {
        $jsonFields = $this->getJsonFields($query);

        foreach ($where as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            match (true) {
                str_ends_with($field, '_like')    => $query->whereLike(
                    $this->stripSuffix($field, '_like'),
                    "%{$value}%"
                ),
                str_ends_with($field, '_range')   => $query->whereBetweenTime(
                    $this->stripSuffix($field, '_range'),
                    $value[0],
                    $value[1]
                ),
                str_ends_with($field, '_between') => $query->whereBetween(
                    $this->stripSuffix($field, '_between'),
                    $value
                ),
                str_ends_with($field, '_in')      => $this->applyInClause(
                    $query,
                    $this->stripSuffix($field, '_in'),
                    $value,
                    $jsonFields
                ),
                default                           => $query->where($field, $value),
            };
        }

        return $query;
    }

    /** 获取模型声明的 JSON 字段列表 */
    protected function getJsonFields(Model $model): array
    {
        try {
            $ref = new ReflectionClass($model);
            if (!$ref->hasProperty('json')) {
                return [];
            }

            $prop = $ref->getProperty('json');
            $prop->setAccessible(true);

            return (array) $prop->getValue($model);
        } catch (ReflectionException) {
            return [];
        }
    }

    /** 处理 _in 查询，JSON 数组字段使用 JSON_CONTAINS */
    protected function applyInClause(Model $query, string $field, mixed $value, array $jsonFields): void
    {
        if (is_string($value)) {
            $value = array_filter(explode(',', $value), 'strlen');
        }

        $value = (array) $value;
        if (empty($value)) {
            return;
        }

        if (in_array($field, $jsonFields, true)) {
            $query->where(function ($q) use ($field, $value) {
                foreach ($value as $idx => $id) {
                    $clause = $idx === 0 ? 'where' : 'whereOr';
                    $q->$clause(
                        fn ($subQ) => $subQ->whereRaw(
                            "JSON_CONTAINS(`{$field}`, ?)",
                            [json_encode((int) $id)]
                        )
                    );
                }
            });
        } else {
            $query->whereIn($field, $value);
        }
    }

    protected function stripSuffix(string $field, string $suffix): string
    {
        return substr($field, 0, -strlen($suffix));
    }

    // ── 数据权限（由 HasDataPermission trait 覆盖）──────────────────

    /**
     * 应用数据权限过滤（默认不做过滤）。
     *
     * use HasDataPermission 后此方法会被覆盖，按用户角色的数据范围过滤。
     */
    protected function applyDataPermission(Model|Query $query, ?string $module = null): Model|Query
    {
        return $query;
    }

    // ── 多租户（由 HasTenant trait 覆盖）─────────────────────────────

    /**
     * 应用租户隔离过滤（默认不做过滤）。
     *
     * use HasTenant 后此方法会被覆盖，按当前租户 ID 过滤查询。
     */
    protected function applyTenantScope(Model|Query $query): Model|Query
    {
        return $query;
    }

    /**
     * 新建/更新时自动填充租户 ID（默认不修改数据）。
     *
     * use HasTenant 后此方法会被覆盖，自动写入 tenant_id 字段。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function fillTenantId(array $data): array
    {
        return $data;
    }

    // ── CRUD ────────────────────────────────────────────────────────

    /**
     * 列表查询。
     *
     * 默认从请求 query 中读取所有参数作为查询条件，
     * 子类可覆盖此方法，手动构建 where 后调用 baseIndex()。
     */
    public function index(): JsonResponse
    {
        return $this->baseIndex($this->request->get());
    }

    /**
     * 通用列表查询（可被子类复用）。
     *
     * 分页参数（current / pageSize）与排序（order）默认从请求中自动获取，
     * 子类调用时通常只需传入 $where 即可。
     *
     * @param array              $where    查询条件（支持 _like / _range / _between / _in 后缀 DSL）
     * @param array              $append   追加属性
     * @param array              $with     关联预加载
     * @param array|string|null  $order    排序，为 null 时从请求 order 参数读取
     */
    protected function baseIndex(
        array $where = [],
        array $append = [],
        array $with = [],
        array|string|null $order = null,
    ): JsonResponse {
        try {
            $current  = (int) $this->request->param('current', 1);
            $pageSize = (int) $this->request->param('pageSize', 20);
            $order ??= $this->request->param('order', ['create_time' => 'desc']);

            $model = $this->getModel();
            $this->applyWhere($model, $where);

            if (!empty($append)) {
                $model->append($append);
            }
            if (!empty($with)) {
                $model->with($with);
            }

            if (is_string($order)) {
                $order = [$order => 'desc'];
            }
            $model->order($order);

            $query = $this->applyDataPermission($model);
            $query = $this->applyTenantScope($query);

            return $this->paginate($query, $current, $pageSize);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save(): JsonResponse
    {
        return $this->baseSave($this->request->post());
    }

    /**
     * 通用新建方法（可被子类复用）。
     *
     * @param array $postData 请求数据
     * @param array $uniqueFields 唯一约束字段（为空时使用 $this->uniqueFields）
     */
    protected function baseSave(array $postData, array $uniqueFields = []): JsonResponse
    {
        try {
            $model = $this->getModel();

            if ($this->validate !== '') {
                $this->validate($postData, $this->validate);
            }

            $fields = empty($uniqueFields) ? $this->uniqueFields : $uniqueFields;
            if (!empty($fields)) {
                $error = $this->checkUnique($model, $postData, null, $fields);
                if ($error !== null) {
                    return $this->fail($error);
                }
            }

            $postData = $this->fillTenantId($postData);

            $ret = $model->create($postData);

            return $this->success($ret);
        } catch (ValidateException $e) {
            return $this->fail($this->formatValidateError($e->getError()));
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function read(int $id): JsonResponse
    {
        return $this->baseRead($id);
    }

    /**
     * 通用读取方法（可被子类复用）。
     *
     * @param int   $id     主键 ID
     * @param array $append 追加属性
     */
    protected function baseRead(int $id, array $append = []): JsonResponse
    {
        try {
            $model = $this->getModel();
            $query = $this->applyDataPermission($model);
            $query = $this->applyTenantScope($query);
            $data  = $query->find($id);

            if (!$data) {
                return $this->fail($this->notExistMessage);
            }

            if (!empty($append)) {
                $data->append($append);
            }

            return $this->success($data);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function update(int $id): JsonResponse
    {
        return $this->baseUpdate($id, $this->request->post());
    }

    /**
     * 通用更新方法（可被子类复用）。
     *
     * @param int   $id           主键 ID
     * @param array $postData     请求数据
     * @param array $uniqueFields 唯一约束字段（为空时使用 $this->uniqueFields）
     */
    protected function baseUpdate(int $id, array $postData, array $uniqueFields = []): JsonResponse
    {
        try {
            $model = $this->getModel();

            if ($this->validate !== '') {
                $this->validate($postData, $this->validate);
            }

            $query = $this->applyDataPermission($model);
            $query = $this->applyTenantScope($query);
            $info  = $query->find($id);

            if (!$info) {
                return $this->fail($this->notExistMessage);
            }

            $fields = empty($uniqueFields) ? $this->uniqueFields : $uniqueFields;
            if (!empty($fields)) {
                $error = $this->checkUnique($model, $postData, $id, $fields);
                if ($error !== null) {
                    return $this->fail($error);
                }
            }

            $postData = $this->fillTenantId($postData);

            $info->save($postData);

            return $this->success($info);
        } catch (ValidateException $e) {
            return $this->fail($this->formatValidateError($e->getError()));
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete(int $id): JsonResponse
    {
        return $this->baseDelete($id);
    }

    /**
     * 通用删除方法（可被子类复用）。
     */
    protected function baseDelete(int $id): JsonResponse
    {
        try {
            $model = $this->getModel();
            $query = $this->applyDataPermission($model);
            $query = $this->applyTenantScope($query);
            $data  = $query->find($id);

            if (!$data) {
                return $this->fail($this->notExistMessage);
            }

            return $this->success($data->delete());
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function batch_delete(): JsonResponse
    {
        $ids = (array) ($this->request->post()['ids'] ?? []);

        return $this->baseBatchDelete($ids);
    }

    /**
     * 通用批量删除方法（可被子类复用）。
     *
     * @param array $ids 主键 ID 列表
     */
    protected function baseBatchDelete(array $ids): JsonResponse
    {
        try {
            if (empty($ids)) {
                return $this->fail('ids 参数不能为空');
            }

            $model = $this->getModel();
            $query = $this->applyDataPermission($model);
            $query = $this->applyTenantScope($query);
            $list  = $query->whereIn('id', $ids)->select();

            if ($list->isEmpty()) {
                return $this->fail($this->notExistMessage);
            }

            return $this->success($list->delete());
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    // ── 唯一校验 ────────────────────────────────────────────────────

    /**
     * 检查字段唯一性（支持联合唯一约束）。
     *
     * @param array|null $uniqueFields 唯一约束字段（为空时使用 $this->uniqueFields）
     * @return string|null 检查通过返回 null，否则返回错误信息
     */
    protected function checkUnique(Model $model, array $postData, ?int $excludeId = null, ?array $uniqueFields = null): ?string
    {
        $fields = $uniqueFields ?? $this->uniqueFields;

        foreach ($fields as $field) {
            if (!isset($postData[$field])) {
                return "字段 {$field} 不存在，请检查输入";
            }
        }

        $query = $model->db();
        $where = [];
        foreach ($fields as $field) {
            $where[$field] = $postData[$field];
        }

        if ($excludeId) {
            $exits = $query->where($where)->where('id', '<>', $excludeId)->find();
        } else {
            $exits = $query->where($where)->find();
        }

        if ($exits) {
            $valueStr = implode('、', array_values($where));

            return "({$valueStr}) 已存在，请更换";
        }

        return null;
    }
}
