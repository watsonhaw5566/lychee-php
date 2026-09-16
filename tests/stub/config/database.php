<?php

declare(strict_types=1);

return [
    // 默认连接：测试环境使用 sqlite 内存库
    'default'     => 'sqlite',

    // 连接列表
    'connections' => [
        'sqlite' => [
            'type'     => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
            'debug'    => false,
        ],

        'mysql'  => [
            'type'     => 'mysql',
            'hostname' => '127.0.0.1',
            'hostport' => 3306,
            'database' => '',
            'username' => 'root',
            'password' => '',
            'charset'  => 'utf8mb4',
            'prefix'   => '',
            'debug'    => false,
        ],
    ],
];
