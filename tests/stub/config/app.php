<?php

declare(strict_types=1);

return [
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',

    // 路由全局前缀，API 开发时可设置为 'api'，所有路由自动加上此前缀
    'route_prefix'     => '',

    // 错误显示信息，非调试模式有效
    'error_message'    => '页面错误！请稍后再试~',

    // 是否显示错误信息（非调试模式下是否暴露真实异常信息）
    'show_error_msg'   => false,
];
