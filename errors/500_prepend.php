<?php
/**
 * ChatApp - auto_prepend_file（httpd.conf 的 php_value auto_prepend_file）
 *
 * 在任意 PHP 文件【编译前】执行，注册全局 fatal/parse 错误处理。
 * 用途：语法错误（parse error）发生在编译阶段，config.php 的 handler 来不及注册；
 *       本文件先于主文件执行，保证 parse error 时也有 handler 接管 → 显示 errors/500.php。
 * 对 CLI 无影响（php_value 仅作用于 Apache mod_php 请求）。
 */
if (!defined('CHATAPP_PREPEND_HANDLER')) {
    define('CHATAPP_PREPEND_HANDLER', 1);
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    register_shutdown_function(function () {
        // 若已被 config.php 的 handler 处理过则跳过（防重复输出 500 页）
        if (!empty($GLOBALS['__chatapp_500_handled'])) return;
        $e = error_get_last();
        if (!$e) return;
        static $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array($e['type'], $fatalTypes, true)) return;
        $GLOBALS['__chatapp_500_handled'] = true;
        // 清空已输出的内容，防止半渲染页面 / 原始错误泄漏
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @http_response_code(500);
        // 记录日志（尽力而为；config 未加载时 chatapp_log 不可用则跳过）
        try {
            if (function_exists('chatapp_log')) {
                $f = (string)($e['file'] ?? '');
                $m = (string)($e['message'] ?? '');
                if (function_exists('mb_substr')) { $f = mb_substr($f, 0, 500); $m = mb_substr($m, 0, 1000); }
                chatapp_log('security_logs', [
                    'event_type' => 'php_fatal',
                    'target_path' => $f,
                    'details' => 'line:' . (int)($e['line'] ?? 0) . ' ' . $m,
                ]);
            }
        } catch (\Throwable $x) {}
        // 输出友好 500 页（自包含，零依赖）
        $f500 = __DIR__ . '/500.php';
        if (is_file($f500)) { @include $f500; }
        else { echo '<h1 style="font-family:sans-serif;color:#eee;background:#1a1a1a;padding:40px;text-align:center">500 Internal Server Error</h1>'; }
    });
}
