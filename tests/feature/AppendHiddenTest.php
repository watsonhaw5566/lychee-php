<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\orm\attribute\Append;
use Lychee\orm\Model;
use Lychee\orm\relation\HasMany;
use PHPUnit\Framework\TestCase;

/**
 * 验证 #[Append] 字段的隐藏策略：without() / hidden() / visible()。
 *
 * 重点验证 without() 能同时跳过预加载和输出，避免列表接口产生不必要的查询。
 */
class AppendHiddenTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $db   = $this->app->container->get('db');
        $conn = $db->connect();
        $conn->execute('CREATE TABLE ah_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            create_time DATETIME,
            update_time DATETIME
        )');
        $conn->execute('CREATE TABLE ah_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL,
            create_time DATETIME,
            update_time DATETIME
        )');

        $conn->execute("INSERT INTO ah_users (id, name) VALUES (1, 'Alice')");
        $conn->execute("INSERT INTO ah_posts (user_id, title) VALUES (1, 'Post A')");
        $conn->execute("INSERT INTO ah_posts (user_id, title) VALUES (1, 'Post B')");
    }

    public function test_append_relation_auto_loaded_and_output(): void
    {
        $user = AppendUser::query()->find(1);
        $arr  = $user->toArray();

        $this->assertArrayHasKey('posts', $arr);
        $this->assertCount(2, $arr['posts']);
        $this->assertSame('Post A', $arr['posts'][0]['title']);
    }

    public function test_without_skips_eager_load_and_output(): void
    {
        $user = AppendUser::query()->without(['posts'])->find(1);
        $arr  = $user->toArray();

        // posts 不应出现在输出中
        $this->assertArrayNotHasKey('posts', $arr);

        // 关联数据也不应被加载（relation 为空）
        $this->assertNull($user->getRelation('posts'));
    }

    public function test_hidden_only_hides_output_still_loads(): void
    {
        $user = AppendUser::query()->hidden(['posts'])->find(1);
        $arr  = $user->toArray();

        // 输出中隐藏
        $this->assertArrayNotHasKey('posts', $arr);

        // 但关联数据仍被预加载（without 与 hidden 的核心区别）
        $this->assertNotNull($user->getRelation('posts'));
    }

    public function test_visible_whitelist_excludes_append(): void
    {
        $user = AppendUser::query()->visible(['id', 'name'])->find(1);
        $arr  = $user->toArray();

        $this->assertSame(['id', 'name'], array_keys($arr));
        $this->assertArrayNotHasKey('posts', $arr);
    }

    public function test_without_prevents_n_plus_one_in_list(): void
    {
        // 列表查询 + without 不应触发 posts 的预加载查询
        $list = AppendUser::query()->without(['posts'])->select();

        $this->assertCount(1, $list);
        foreach ($list as $user) {
            $arr = $user->toArray();
            $this->assertArrayNotHasKey('posts', $arr);
        }
    }
}

/**
 * 测试模型：posts 关联标注了 #[Append]，默认自动预加载并输出。
 */
class AppendUser extends Model
{
    protected string $name = 'ah_users';

    #[Append]
    public function posts(): HasMany
    {
        return $this->hasMany(AppendPost::class, 'user_id', 'id');
    }
}

class AppendPost extends Model
{
    protected string $name = 'ah_posts';
}
