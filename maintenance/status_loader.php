<?php
/**
 * ChatApp — Maintenance status loader
 *
 * 维护状态来源优先级：
 *   1. status.php（根）：应急覆盖开关 override_mysql_maint_settings = true 时，
 *      以该文件为准（完全 DB 无关，DB 挂掉时手动维护用）。
 *   2. MySQL maintenance_settings 表：正常模式的权威来源（Maintenance Portal 写入）。
 *   3. DB 不可达时回退到文件（data/maintenance_status.php 覆盖根 status.php，
 *      门户写文件镜像 = 上次已知状态）。
 */

/** 确保 maintenance_settings 表存在（自愈，幂等） */
function chatapp_maint_ensure_table(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS maintenance_settings (
        id INT NOT NULL PRIMARY KEY,
        is_maintenance TINYINT(1) NOT NULL DEFAULT 0,
        mt_return_code INT NOT NULL DEFAULT 500,
        maintenance_page VARCHAR(120) NOT NULL DEFAULT '/errors/unavailable_erepair.html',
        allow_mt_login TINYINT(1) NOT NULL DEFAULT 0,
        mt_login_use_mysql_creds TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function chatapp_maint_status(): array {
    $defaults = [
        'is_maintenance'               => false,
        'mt_return_code'               => 500,
        'maintenance_page'             => '/errors/unavailable_erepair.html',
        'allow_mt_login'               => false,
        'mt_login_use_mysql_creds'     => false,
        'override_mysql_maint_settings' => false,
    ];

    // 1) 文件层：根 status.php（含应急覆盖开关）+ data 运行时镜像
    $rootFile = null;
    $dataFile = null;
    if (is_file(__DIR__ . '/../status.php')) {
        $r = include __DIR__ . '/../status.php';
        if (is_array($r)) $rootFile = $r;
    }
    if (is_file(__DIR__ . '/../data/maintenance_status.php')) {
        $r = include __DIR__ . '/../data/maintenance_status.php';
        if (is_array($r)) $dataFile = $r;
    }
    $fileRoot = array_merge($defaults, $rootFile ?: []);
    $override = !empty($rootFile['override_mysql_maint_settings'] ?? false);
    $file = array_merge($fileRoot, $dataFile ?: []);
    $file['override_mysql_maint_settings'] = $override; // 覆盖开关只看根 status.php

    // 2) 应急覆盖：根 status.php 的 override=true → 以根文件为准，不碰 DB
    if ($override) return $fileRoot;

    // 3) MySQL 权威（正常模式）
    try {
        chatapp_maint_ensure_table();
        $row = db()->query("SELECT is_maintenance, mt_return_code, maintenance_page, allow_mt_login, mt_login_use_mysql_creds FROM maintenance_settings WHERE id = 1")->fetch();
        if ($row) {
            return [
                'is_maintenance'               => (bool)(int)$row['is_maintenance'],
                'mt_return_code'               => (int)$row['mt_return_code'],
                'maintenance_page'             => (string)$row['maintenance_page'],
                'allow_mt_login'               => (bool)(int)$row['allow_mt_login'],
                'mt_login_use_mysql_creds'     => (bool)(int)$row['mt_login_use_mysql_creds'],
                'override_mysql_maint_settings' => false,
            ];
        }
    } catch (\Throwable $e) {}

    // 4) DB 不可达 → 文件（上次已知状态）
    return $file;
}
