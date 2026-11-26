<?php

declare(strict_types=1);

namespace Tests\feature;

use Lychee\Application;
use Lychee\orm\facade\Db;
use PHPUnit\Framework\TestCase;

/**
 * 验证 whereOrLike / whereOrNotLike 的 OR 模糊查询行为。
 */
class WhereOrLikeTest extends TestCase
{
    protected function setUp(): void
    {
        new Application(
            basePath: STUB_DIR,
            controllerNamespace: 'Tests\\stub\\app\\controller',
        );

        $conn = app('db')->connect();
        $conn->execute('CREATE TABLE wol_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(255) NOT NULL,
            nickname VARCHAR(255) NOT NULL
        )');

        $conn->execute("INSERT INTO wol_users (id, name, nickname) VALUES (1, '张三', '小张')");
        $conn->execute("INSERT INTO wol_users (id, name, nickname) VALUES (2, '李四', '张三疯')");
        $conn->execute("INSERT INTO wol_users (id, name, nickname) VALUES (3, '王五', '老王')");
    }

    public function test_where_or_like_matches_either_field(): void
    {
        // name 含「张」或 nickname 含「张」 → id 1、2
        $ids = Db::table('wol_users')
            ->whereLike('name', '%张%')
            ->whereOrLike('nickname', '%张%')
            ->column('id');

        sort($ids);
        $this->assertSame([1, 2], $ids);
    }

    public function test_where_or_like_equivalent_to_where_or_like_op(): void
    {
        $a = Db::table('wol_users')->whereOrLike('nickname', '%王%')->column('id');
        $b = Db::table('wol_users')->whereOr('nickname', 'LIKE', '%王%')->column('id');

        sort($a);
        sort($b);
        $this->assertSame($a, $b);
        $this->assertSame([3], $a);
    }

    public function test_where_or_not_like_combines_with_and(): void
    {
        // id != 1 且 (name 不含「张」 OR nickname 不含「张」)
        // 排除 id=1；id=2 的 name=李四 不含「张」→ 命中；id=3 都不含「张」→ 命中
        $ids = Db::table('wol_users')
            ->where('id', '<>', 1)
            ->whereOrNotLike('name', '%张%')
            ->column('id');

        sort($ids);
        $this->assertSame([2, 3], $ids);
    }
}
