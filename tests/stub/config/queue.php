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

        // 仅用于验证驱动可被 QueueManager 解析，不会发起真实网络请求
        'mns'   => [
            'type'       => 'mns',
            'access_id'  => 'test-ak',
            'access_key' => 'test-sk',
            'endpoint'   => 'https://1234567890.mns.cn-hangzhou.aliyuncs.com',
            'queue'      => 'default',
        ],

        'cmq'   => [
            'type'       => 'cmq',
            'secret_id'  => 'test-ak',
            'secret_key' => 'test-sk',
            'region'     => 'ap-guangzhou',
            'endpoint'   => 'https://cmq-gz.example.com',
            'queue'      => 'default',
        ],
    ],
];
