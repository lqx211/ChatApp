<?php
/**
 * ChatApp — Maintenance credentials loader
 *
 * 加载维护门户凭据（$MAINT_USER / $MAINT_PASS / $MAINT_SECRET）：
 *   1. data/maint_config.php   —— Web 可写（OOBE 写入，权威；Mac/容器上
 *                                 maintenance/config.php 可能只读，这里兜底）
 *   2. maintenance/config.php  —— legacy，安装脚本生成，服务器上通常可写
 * 环境变量 MAINT_USER / MAINT_PASS / MAINT_SECRET 仍可覆盖（两个生成文件
 * 内部都带 getenv() 兜底）。
 */
function chatapp_maint_creds(): array {
    $candidates = [
        __DIR__ . '/../data/maint_config.php',
        __DIR__ . '/config.php',
    ];
    foreach ($candidates as $f) {
        if (is_file($f)) {
            $MAINT_USER = $MAINT_PASS = $MAINT_SECRET = null;
            include $f;
            if ($MAINT_USER !== null && $MAINT_PASS !== null && $MAINT_SECRET !== null) {
                return [
                    'user'   => $MAINT_USER,
                    'pass'   => $MAINT_PASS,
                    'secret' => $MAINT_SECRET,
                ];
            }
        }
    }
    // 最后兜底（与原始默认一致）
    return [
        'user'   => getenv('MAINT_USER')   ?: 'admin',
        'pass'   => getenv('MAINT_PASS')   ?: '',
        'secret' => getenv('MAINT_SECRET') ?: '',
    ];
}
