<?php

declare(strict_types=1);

return [
    // 默认队列连接
    'default'     => 'sync',

    // 队列连接列表
    'connections' => [
        'sync'  => [
            'type' => 'sync',
        ],

        'redis' => [
            'type'        => 'redis',
            'host'        => '127.0.0.1',
            'port'        => 6379,
            'password'    => '',
            'select'      => 0,
            'timeout'     => 5,
            'persistent'  => false,
            'queue'       => 'default',
            'retry_after' => 60,
        ],
    ],
];
