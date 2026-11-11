<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\orm\Model;
use Lychee\orm\relation\BelongsToMany;
use PHPUnit\Framework\TestCase;

/**
 * 验证多对多关联 BelongsToMany 的查询与写入能力。
 *
 * 覆盖：lazy 加载、eager 预加载、withPivot、withCount、
 *      attach / detach / sync / toggle。
 */
class BelongsToManyTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $conn = $this->app->container->get('db')->connect();

        $conn->execute('CREATE TABLE btm_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            create_time DATETIME,
            update_time DATETIME
        )');
        $conn->execute('CREATE TABLE btm_roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            create_time DATETIME,
            update_time DATETIME
        )');
        $conn->execute('CREATE TABLE btm_role_user (
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            expired_at VARCHAR(255),
            PRIMARY KEY (user_id, role_id)
        )');

        $conn->execute("INSERT INTO btm_users (id, name) VALUES (1, 'Alice')");
        $conn->execute("INSERT INTO btm_users (id, name) VALUES (2, 'Bob')");
        $conn->execute("INSERT INTO btm_roles (id, name) VALUES (1, 'Admin')");
        $conn->execute("INSERT INTO btm_roles (id, name) VALUES (2, 'Editor')");
        $conn->execute("INSERT INTO btm_roles (id, name) VALUES (3, 'Viewer')");

        // Alice -> Admin, Editor ; Bob -> Viewer
        $conn->execute("INSERT INTO btm_role_user (user_id, role_id) VALUES (1, 1)");
        $conn->execute("INSERT INTO btm_role_user (user_id, role_id, expired_at) VALUES (1, 2, '2026-12-31')");
        $conn->execute("INSERT INTO btm_role_user (user_id, role_id) VALUES (2, 3)");
    }

    // ── 读取 ────────────────────────────────────────────────────────────

    public function test_lazy_load_returns_related_models(): void
    {
        $user  = BtmUser::find(1);
        $roles = $user->roles;

        $this->assertCount(2, $roles);
        $names = array_map(fn ($r) => $r->name, $roles->all());
        sort($names);
        $this->assertSame(['Admin', 'Editor'], $names);
    }

    public function test_lazy_load_empty_returns_empty_collection(): void
    {
        // 用户 3 不存在，但模拟无关联的情况
        $user     = new BtmUser();
        $user->id = 999;
        $roles    = $user->roles;

        $this->assertCount(0, $roles);
    }

    public function test_eager_load_prevents_n_plus_one(): void
    {
        /** @var \Lychee\orm\Collection<int, BtmUser> $users */
        $users = BtmUser::with('roles')->select();

        $this->assertCount(2, $users);
        foreach ($users as $user) {
            $this->assertNotNull($user->getRelation('roles'));
        }

        $alice = $users[0]->id === 1 ? $users[0] : $users[1];
        $this->assertCount(2, $alice->roles);
    }

    public function test_eager_load_groups_correctly(): void
    {
        /** @var \Lychee\orm\Collection<int, BtmUser> $users */
        $users = BtmUser::with('roles')->select();

        $byId = [];
        foreach ($users as $u) {
            $byId[$u->id] = $u;
        }

        $this->assertCount(2, $byId[1]->roles);
        $this->assertCount(1, $byId[2]->roles);
        $this->assertSame('Viewer', $byId[2]->roles[0]->name);
    }

    public function test_with_pivot_loads_extra_columns(): void
    {
        $user  = BtmUser::find(1);
        $roles = $user->rolesWithPivot;

        $editor = null;
        foreach ($roles as $r) {
            if ($r->name === 'Editor') {
                $editor = $r;
            }
        }

        $this->assertNotNull($editor);
        $pivot = $editor->getRelation('pivot');
        $this->assertIsArray($pivot);
        $this->assertSame('2026-12-31', $pivot['expired_at']);
    }

    public function test_with_count(): void
    {
        /** @var \Lychee\orm\Collection<int, BtmUser> $users */
        $users = BtmUser::withCount('roles')->select();

        $byId = [];
        foreach ($users as $u) {
            $byId[$u->id] = $u;
        }

        $this->assertSame(2, (int) $byId[1]->roles_count);
        $this->assertSame(1, (int) $byId[2]->roles_count);
    }

    public function test_relation_methods_where_clause(): void
    {
        // 通过关联查询构造器添加条件
        $user  = BtmUser::find(1);
        /** @var \Lychee\orm\Collection<int, BtmRole> $roles */
        $roles = $user->roles()->where('name', 'Admin')->select();

        $this->assertCount(1, $roles);
        $this->assertSame('Admin', $roles[0]->name);
    }

    // ── 写入 ────────────────────────────────────────────────────────────

    public function test_attach_single_id(): void
    {
        $user = BtmUser::find(2);
        $user->roles()->attach(1);

        $roles = $user->roles;
        $names = array_map(fn ($r) => $r->name, $roles->all());
        sort($names);
        $this->assertSame(['Admin', 'Viewer'], $names);
    }

    public function test_attach_array_of_ids(): void
    {
        $user = BtmUser::find(2);
        $user->roles()->attach([1, 2]);

        $this->assertCount(3, $user->roles);
    }

    public function test_attach_with_attributes(): void
    {
        $user = BtmUser::find(2);
        $user->roles()->attach(1, ['expired_at' => '2027-01-01']);

        $roles = $user->rolesWithPivot;
        $admin = null;
        foreach ($roles as $r) {
            if ($r->name === 'Admin') {
                $admin = $r;
            }
        }

        $pivot = $admin->getRelation('pivot');
        $this->assertSame('2027-01-01', $pivot['expired_at']);
    }

    public function test_attach_associative_array(): void
    {
        $user = BtmUser::find(2);
        $user->roles()->attach([
            1 => ['expired_at' => '2027-01-01'],
            2 => [],
        ]);

        $this->assertCount(3, $user->roles);
    }

    public function test_detach_single_id(): void
    {
        $user  = BtmUser::find(1);
        $count = $user->roles()->detach(1);

        $this->assertSame(1, $count);
        $this->assertCount(1, $user->roles);
    }

    public function test_detach_array_of_ids(): void
    {
        $user  = BtmUser::find(1);
        $count = $user->roles()->detach([1, 2]);

        $this->assertSame(2, $count);
        $this->assertCount(0, $user->roles);
    }

    public function test_detach_all(): void
    {
        $user  = BtmUser::find(1);
        $count = $user->roles()->detach();

        $this->assertSame(2, $count);
        $this->assertCount(0, $user->roles);
    }

    public function test_sync_replaces_associations(): void
    {
        $user = BtmUser::find(1);
        // 原来 [1, 2]，同步为 [2, 3]
        $result = $user->roles()->sync([2, 3]);

        $this->assertEquals([3], $result['attached']);
        $this->assertEquals([1], $result['detached']);

        $roles = $user->roles;
        $names = array_map(fn ($r) => $r->name, $roles->all());
        sort($names);
        $this->assertSame(['Editor', 'Viewer'], $names);
    }

    public function test_sync_with_attributes(): void
    {
        $user = BtmUser::find(1);
        $user->roles()->sync([
            1 => ['expired_at' => '2028-01-01'],
            3 => [],
        ]);

        $roles = $user->rolesWithPivot;
        $admin = null;
        foreach ($roles as $r) {
            if ($r->name === 'Admin') {
                $admin = $r;
            }
        }
        $this->assertNotNull($admin);
        $this->assertSame('2028-01-01', $admin->getRelation('pivot')['expired_at']);
    }

    public function test_toggle_flips_existing_and_new(): void
    {
        $user = BtmUser::find(1);
        // 原来 [1, 2]，toggle [2, 3] -> 移除 2，添加 3，保留 1
        $result = $user->roles()->toggle([2, 3]);

        $this->assertEquals([3], $result['attached']);
        $this->assertEquals([2], $result['detached']);

        $roles = $user->roles;
        $names = array_map(fn ($r) => $r->name, $roles->all());
        sort($names);
        $this->assertSame(['Admin', 'Viewer'], $names);
    }

    // ── 默认命名推导 ────────────────────────────────────────────────────

    public function test_default_pivot_table_name(): void
    {
        $user     = new BtmUser();
        $relation = $user->rolesDefault();

        // btm_roles + btm_users 按字母序 -> btm_roles_btm_users
        $this->assertSame('btm_roles_btm_users', $relation->getPivotTable());
    }

    public function test_default_pivot_keys(): void
    {
        $user     = new BtmUser();
        $relation = $user->rolesDefault();

        // BtmUser -> btm_user_id；BtmRole -> btm_role_id
        $this->assertSame('btm_user_id', $relation->getForeignPivotKey());
        $this->assertSame('btm_role_id', $relation->getRelatedPivotKey());
    }
}

/**
 * 测试模型：User 多对多 Role。
 *
 * @property int $id
 * @property string $name
 * @property-read \Lychee\orm\Collection<int, BtmRole> $roles
 * @property-read \Lychee\orm\Collection<int, BtmRole> $rolesWithPivot
 * @property-read int $roles_count
 */
class BtmUser extends Model
{
    protected string $table = 'btm_users';

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(BtmRole::class, 'btm_role_user', 'user_id', 'role_id');
    }

    public function rolesWithPivot(): BelongsToMany
    {
        return $this->belongsToMany(BtmRole::class, 'btm_role_user', 'user_id', 'role_id')
            ->withPivot('expired_at');
    }

    /** 使用全部默认参数（表名、外键均自动推导）。 */
    public function rolesDefault(): BelongsToMany
    {
        return $this->belongsToMany(BtmRole::class);
    }
}

/**
 * @property string $name
 */
class BtmRole extends Model
{
    protected string $table = 'btm_roles';
}
