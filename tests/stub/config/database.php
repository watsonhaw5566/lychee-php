<?php

declare(strict_types=1);

return [
    // 默认连接：测试环境使用 sqlite 内存库
    'default'     => 'sqlite',

    // 连接列表
    'connections' => [
        'sqlite' => [
            'type'          => 'sqlite',
            'database'      => ':memory:',
            'prefix'        => '',
            'debug'         => false,
            // 是否严格检查字段是否存在（默认 true）
            // true：写入/更新时若包含数据表不存在的字段会抛出异常
            // false：忽略不存在的字段
            'fields_strict' => true,
        ],

        'mysql'  => [
            'type'            => 'mysql',
            'hostname'        => '127.0.0.1',
            'hostport'        => 3306,
            'database'        => '',
            'username'        => 'root',
            'password'        => '',
            'charset'         => 'utf8mb4',
            'prefix'          => '',
            'debug'           => false,
            // 是否严格检查字段是否存在（默认 true）
            'fields_strict'   => true,
            // 是否需要断线重连
            'break_reconnect' => false,
            // 监听 SQL
            'trigger_sql'     => true,
            // 开启字段缓存
            'fields_cache'    => false,
        ],
    ],
];
