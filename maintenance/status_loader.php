<?php
/**
 * ChatApp — Maintenance status loader
 *
 * 读取维护状态（is_maintenance / mt_return_code / maintenance_page /
 * allow_mt_login / mt_login_use_mysql_creds），来源优先级：
 *   1. data/maintenance_status.php —— Web 可写（Maintenance Portal 写入，权威；
 *                                      Mac/容器上根 status.php 可能只读，这里兜底）
 *   2. status.php（根）—— 手改默认 / 安装脚本模板
 */
function chatapp_maint_status(): array {
    $defaults = [
        'is_maintenance'          => false,
        'mt_return_code'          => 500,
        'maintenance_page'        => '/errors/unavailable_erepair.html',
        'allow_mt_login'          => false,
        'mt_login_use_mysql_creds' => false,
    ];
    $candidates = [
        __DIR__ . '/../data/maintenance_status.php',
        __DIR__ . '/../status.php',
    ];
    foreach ($candidates as $f) {
        if (is_file($f)) {
            $r = include $f;
            if (is_array($r)) {
                return array_merge($defaults, $r);
            }
        }
    }
    return $defaults;
}
