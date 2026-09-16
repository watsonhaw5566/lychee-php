<?php

declare(strict_types=1);


use app\model\UserRoles;
use app\model\Users;
use app\validate\IdsValidate;
use Exception;
use think\App;
use think\db\exception\DbException;
use think\db\Query;
use think\exception\ValidateException;
use think\Model;
use think\Request;
use think\response\Json;
use think\Validate;
use ReflectionClass;
use ReflectionException;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     */
    protected Request $request;

    /**
     * 应用实例
     */
    protected App $app;

    /**
     * 是否批量验证
     */
    protected bool $batchValidate = false;

    /**
     * 控制器中间件
     */
    protected array $middleware = [];

    /**
     * 模型类名
     */
    protected string $modelClass = '';

    /**
     * 验证器类名
     */
    protected string $validateClass = '';

    /**
     * 不存在时的提示信息
     */
    protected string $notExistMessage = '数据不存在';

    /**
     * 唯一约束字段（save/update 时自动校验，支持联合唯一）
     * 示例：['title'] 或 ['title', 'ad_platform_id']
     */
    protected array $uniqueFields = [];

    /**
     * 当前登录用户（非登录态动作时为 null）
     */
    protected ?Users $user = null;

    /**
     * 不需要登录鉴权的动作名
     */
    protected array $noAuthActions = ['login', 'upload', 'user_avatar'];

    /**
     * 数据权限配置：
     * - enabled:     是否开启数据权限控制（默认 false）
     * - userIdField: 数据所有者字段名（默认 user_id）
     * - module:      当前模块标识，用于从 data_scope 数组中按模块匹配权限级别
     *                可选值参考前端 dataScopeKeyMap：
     *                account / account_role / bill / customer /
     *                receive_account / payment_account / reimburse / reimburse_type
     */
    protected array $dataPermission = [
        'enabled'     => false,
        'userIdField' => 'user_id',
        'module'      => '',
    ];

    /**
     * 构造方法
     *
     * @param App $app 应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    protected function initialize(): void
    {
        // 合并默认配置（子类只需覆盖需要的字段）
        $this->dataPermission = array_merge([
            'enabled'     => false,
            'userIdField' => 'user_id',
            'module'      => '',
        ], $this->dataPermission);

        $this->user = $this->getCurrentUser();
    }

    /**
     * 获取当前登录用户（登录免鉴权动作返回 null）
     */
    protected function getCurrentUser(): ?Users
    {
        $action = $this->request->action();
        if (in_array($action, $this->noAuthActions)) {
            return null;
        }

        $id   = \app\currentId();
        $user = $id ? Users::find($id) : null;

        return $user instanceof Users ? $user : null;
    }

    /**
     * 获取当前模块的数据权限级别
     *
     * @param string $module 可选，强制指定模块名（默认用控制器配置的 module）
     * @return string  'all' | 'self' （未命中时默认 self，最保守策略）
     */
    protected function getPermissionLevel(string $module = ''): string
    {
        if (!$this->user) {
            return 'self';
        }

        // 超管不受数据权限限制
        if ($this->user->isAdmin()) {
            return 'all';
        }

        $currentModule = $module ?: $this->dataPermission['module'];
        if (empty($currentModule)) {
            return 'self';
        }

        // 使用 users.role_id 关联 user_roles.id（与 Users::access() 保持一致）
        $role = $this->user->role_id ? UserRoles::find($this->user->role_id) : null;
        if (!$role || empty($role->data_scope)) {
            return 'self';
        }

        $dataScope = is_array($role->data_scope)
            ? $role->data_scope
            : json_decode($role->data_scope, true);

        return $dataScope[$currentModule] ?? 'self';
    }

    /**
     * 获取模型实例
     *
     * @throws Exception
     */
    protected function getModel(): Model
    {
        if (empty($this->modelClass)) {
            throw new Exception('请设置模型类名或确保控制器命名符合自动推断规则');
        }

        // 检查模型类是否存在
        if (!class_exists($this->modelClass)) {
            throw new Exception('模型类 ' . $this->modelClass . ' 不存在');
        }

        return new $this->modelClass();
    }

    /**
     * 验证数据
     *
     * @param array $data 数据
     * @param array|string $validate 验证器名或者验证规则数组
     * @param array $message 提示信息
     * @param bool $batch 是否批量验证
     * @return array|string|true
     *
     * @throws ValidateException
     */
    protected function validate(
        array        $data,
        array|string $validate,
        array        $message = [],
        bool         $batch = false
    ): bool|array|string {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = str_contains($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch();
        }

        return $v->failException()->check($data);
    }

    /**
     * 通用的列表查询方法
     */
    protected function baseIndex(
        array $where = [],
        int   $current = 1,
        int   $pageSize = 10,
        array $append = [],
        array $with = [],
        $order = ['create_time' => 'desc']
    ): Json {
        try {
            $model = $this->getModel();
            $query = $model->order($order);

            if (!empty($append)) {
                $query->append($append);
            }
            if (!empty($with)) {
                $query->with($with);
            }
            // 获取模型声明的 JSON 字段列表（通过反射读取 protected $json 属性）
            $jsonFields = [];

            try {
                $ref = new ReflectionClass($model);
                if ($ref->hasProperty('json')) {
                    $prop = $ref->getProperty('json');
                    $prop->setAccessible(true);
                    $jsonFields = (array) $prop->getValue($model);
                }
            } catch (ReflectionException $e) {
            }

            foreach ($where as $field => $value) {
                if (is_null($value)) {
                    continue;
                }
                if (strpos($field, 'like')) {
                    $fieldName = str_replace('_like', '', $field);
                    $query->whereLike($fieldName, "%$value%");
                } elseif (strpos($field, 'range')) {
                    $fieldName = str_replace('_range', '', $field);
                    if (!empty($value)) {
                        $query->whereBetweenTime($fieldName, $value[0], $value[1]);
                    }
                } elseif (strpos($field, 'between')) {
                    $fieldName = str_replace('_between', '', $field);
                    if (!empty($value)) {
                        $query->whereBetween($fieldName, $value[0], $value[1]);
                    }
                } elseif (strpos($field, 'in')) {
                    $fieldName = str_replace('_in', '', $field);
                    // 兼容逗号分隔字符串和数组
                    if (is_string($value)) {
                        $value = array_filter(explode(',', $value), 'strlen');
                    }
                    $value = (array) $value;
                    if (empty($value)) {
                        continue;
                    }
                    if (in_array($fieldName, $jsonFields, true)) {
                        // JSON 数组字段：使用 JSON_CONTAINS 匹配，多值 OR 连接（命中任意一个即满足）
                        $query->where(function ($q) use ($fieldName, $value) {
                            foreach ($value as $idx => $id) {
                                $clause = $idx === 0 ? 'where' : 'whereOr';
                                $q->$clause(
                                    fn ($subQ) => $subQ->whereRaw(
                                        "JSON_CONTAINS(`$fieldName`, ?)",
                                        [json_encode((int) $id)]
                                    )
                                );
                            }
                        });
                    } else {
                        // 普通字段：使用原生 whereIn
                        $query->whereIn($fieldName, $value);
                    }
                } else {
                    $query->where($field, $value);
                }
            }

            // 应用数据权限过滤
            $query = $this->applyDataPermission($query);

            return $this->paginate($query, $current, $pageSize);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 应用数据权限过滤（统一入口，按模块从 data_scope 读取权限级别）
     *
     * @param Query|Model $query 查询对象或模型实例
     * @param string|null $module 模块名（可选，默认使用控制器配置的 module）
     * @return Query  应用权限过滤后的查询对象
     */
    protected function applyDataPermission(Query|Model $query, string $module = null): Query
    {
        if (!$this->dataPermission['enabled'] || !$this->user) {
            return $query instanceof Model ? $query->db() : $query;
        }

        $dbQuery     = $query instanceof Model ? $query->db() : $query;
        $userIdField = $this->dataPermission['userIdField'];
        $level       = $this->getPermissionLevel($module ?? '');

        switch ($level) {
            case 'all':
                // 查看全部，不附加过滤
                break;
            case 'self':
            default:
                // 默认最保守：仅看自己的数据
                $fields = $dbQuery->getTableFields();
                if (in_array($userIdField, $fields)) {
                    $dbQuery->where($userIdField, $this->user->id);
                }
                break;
        }

        return $dbQuery;
    }

    /**
     * 检查单条数据（按 ownerId）当前用户在指定模块下是否可访问
     */
    protected function canAccessData(int $ownerId, string $module = null): bool
    {
        if (!$this->dataPermission['enabled'] || !$this->user) {
            return true;
        }
        if ($this->user->isAdmin()) {
            return true;
        }

        $level = $this->getPermissionLevel($module ?? '');
        if ($level === 'all') {
            return true;
        }

        // self 级别：必须是自己创建的
        return $ownerId === $this->user->id;
    }

    /**
     * 检查字段唯一性（支持联合唯一约束）
     *
     * @param Model $model 模型实例
     * @param array $postData 请求数据
     * @param array $uniqueFields 唯一性检查字段数组
     * @param int|null $excludeId 排除的ID（用于更新操作）
     * @return string|null 检查通过返回null，否则返回错误信息
     *
     * @throws DbException
     */
    private function checkUnique(Model $model, array $postData, array $uniqueFields, ?int $excludeId = null): ?string
    {
        // 检查字段是否存在于postData中
        foreach ($uniqueFields as $field) {
            if (!isset($postData[$field])) {
                return "字段 $field 不存在，请检查输入";
            }
        }

        $query = $model->db();

        // 构建where条件
        $where = [];
        foreach ($uniqueFields as $field) {
            $where[$field] = $postData[$field];
        }

        // 非超管 + 开启数据权限时，唯一性检查限制在自己的数据范围
        if ($this->dataPermission['enabled'] && $this->user && !$this->user->isAdmin()) {
            $userIdField = $this->dataPermission['userIdField'];
            if ($userIdField !== 'id') {
                $fields = $model->db()->getTableFields();
                if (in_array($userIdField, $fields)) {
                    $where[$userIdField] = $this->user->id;
                }
            }
        }

        if ($excludeId) {
            $exits = $query->where($where)->where('id', '<>', $excludeId)->find();
        } else {
            $exits = $query->where($where)->find();
        }
        if ($exits) {
            $valueStr = implode('、', array_values($where));

            return "($valueStr) 已存在，请更换";
        }

        return null;
    }

    /**
     * 通用的保存方法
     */
    protected function baseSave(Request $request, array $uniqueFields = []): Json
    {
        try {
            $postData = $request->post();
            $model    = $this->getModel();

            // 验证数据
            if ($this->validateClass) {
                $this->validate($postData, $this->validateClass);
            }

            // 检查唯一性（参数优先，否则回退到属性配置）
            $uniqueFields = empty($uniqueFields) ? $this->uniqueFields : $uniqueFields;
            if (!empty($uniqueFields)) {
                $error = $this->checkUnique($model, $postData, $uniqueFields);
                if ($error) {
                    return $this->fail($error);
                }
            }

            // 当开启数据权限控制时，自动设置数据所有者字段
            if ($this->dataPermission['enabled'] && $this->user) {
                $userIdField = $this->dataPermission['userIdField'];
                if ($userIdField !== 'id') {
                    $fields = $model->db()->getTableFields();
                    if (in_array($userIdField, $fields) && !isset($postData[$userIdField])) {
                        $postData[$userIdField] = $this->user->id;
                    }
                }
            }
            $ret = $model->create($postData);

            return $this->success($ret);
        } catch (ValidateException $e) {
            return $this->fail($e->getError());
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 通用的读取方法
     */
    protected function baseRead(int $id, array $append = []): Json
    {
        try {
            $model = $this->getModel();
            $query = $this->applyDataPermission($model);

            $data = $query->find($id);
            if (!$data) {
                return $this->fail($this->notExistMessage);
            }

            // 关联查询
            if (!empty($append)) {
                $data->append($append);
            }

            return $this->success($data);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 通用的更新方法
     */
    protected function baseUpdate(Request $request, int $id, array $uniqueFields = []): Json
    {
        try {
            $postData = $request->post();
            $model    = $this->getModel();

            // 验证数据
            if ($this->validateClass) {
                $this->validate($postData, $this->validateClass);
            }

            $query = $this->applyDataPermission($model);
            $info  = $query->find($id);
            if (!$info) {
                return $this->fail($this->notExistMessage);
            }

            // 检查唯一性（参数优先，否则回退到属性配置）
            $uniqueFields = empty($uniqueFields) ? $this->uniqueFields : $uniqueFields;
            if (!empty($uniqueFields)) {
                $error = $this->checkUnique($model, $postData, $uniqueFields, $id);
                if ($error) {
                    return $this->fail($error);
                }
            }

            $info->save($postData);

            return $this->success($info);
        } catch (ValidateException $e) {
            return $this->fail($e->getError());
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 通用的删除方法
     */
    protected function baseDelete(int $id): Json
    {
        try {
            $model = $this->getModel();
            $query = $this->applyDataPermission($model);

            $data = $query->find($id);
            if (!$data) {
                return $this->fail($this->notExistMessage);
            }

            return $this->success($data->delete());
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * 通用的批量删除方法
     */
    protected function baseBatchDelete(Request $request): Json
    {
        try {
            $post = $request->post();
            $this->validate($post, IdsValidate::class);
            $model = $this->getModel();

            $query = $this->applyDataPermission($model);
            $list  = $query->whereIn('id', $post['ids'])->select();
            if (empty($list)) {
                return $this->fail($this->notExistMessage);
            }
            $ret = $list->delete();

            return $this->success($ret);
        } catch (ValidateException $e) {
            return $this->fail($e->getError());
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    protected function success(mixed $data = null, string $message = 'success', int $httpStatus = 200): Json
    {
        return \app\json([
            'errno' => 0,
            'code' => $httpStatus,
            'msg' => $message,
            'data' => $data
        ], $httpStatus);
    }

    protected function fail(string $message = 'fail', int $httpStatus = 400): Json
    {
        return \app\json([
            'errno' => 0,
            'code' => $httpStatus,
            'msg' => $message,
            'data' => null
        ], $httpStatus);
    }

    /**
     * 分页方法
     *
     * @throws DbException
     */
    protected function paginate(
        Query  $query,
        int    $current = 1,
        int    $pageSize = 10,
        string $message = 'success',
        int    $httpStatus = 200
    ): Json {
        // 参数验证和修正
        $current   = max(1, $current);
        $pageSize  = max(1, min(200, $pageSize));
        $paginator = $query->paginate(['list_rows' => $pageSize, 'page' => $current]);

        return \app\json([
            'errno' => 0,
            'code' => $httpStatus,
            'msg' => $message,
            'data' => [
                'total' => $paginator->total(),
                'list' => $paginator->items()
            ]
        ], $httpStatus);
    }
}
