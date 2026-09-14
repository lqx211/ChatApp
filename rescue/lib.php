<?php
/**
 * ChatApp · Rescue Panel — 自包含工具库
 *
 * 设计原则：零依赖。不 require api/config.php、不 require maintenance/ 或 data/
 * 里的任何代码 —— 大到「降级到远古版本、整个库炸掉」时，这里也必须能跑。
 * 只用：文件系统 + session + （尽力而为的）git 命令行 + 可选 PDO 探测。
 *
 * 提供：
 *   rescue_root()            项目根目录
 *   rescue_git()             执行 git 命令（git -C root ...）
 *   rescue_php_binary()      找到 CLI php 可执行文件（mod_php 下 PHP_BINARY 是 Apache）
 *   rescue_load_creds()      读维护凭据：maintenance/config.php → data/maint_config.php → env
 *   rescue_bootstrap()       确保 maintenance/ 目录与 maintenance/config.php 存在（缺则生成随机凭据）
 *   rescue_db_status()       尽力探测数据库可达性（正则解析 api/config.php 的连接常量）
 *   rescue_wss_restart()     尽力重启 WSS 常驻进程（升级/降级后调用）
 */

if (!function_exists('rescue_root')) {
    function rescue_root(): string { return dirname(__DIR__); }
}

if (!function_exists('rescue_git')) {
    /** 执行 git 命令（cwd=项目根），返回 trim 后的输出（含 stderr） */
    function rescue_git(string $args): string {
        $cmd = 'git -C ' . escapeshellarg(rescue_root()) . ' ' . $args . ' 2>&1';
        $out = @shell_exec($cmd);
        return trim((string)$out);
    }
}

if (!function_exists('rescue_php_binary')) {
    function rescue_php_binary(): string {
        $cands = [];
        if (defined('PHP_BINDIR') && PHP_BINDIR) $cands[] = PHP_BINDIR . '/php';
        $cands[] = '/usr/bin/php8.3';
        $cands[] = '/usr/bin/php8.2';
        $cands[] = '/usr/bin/php8.1';
        $cands[] = '/usr/bin/php';
        $cands[] = '/usr/local/bin/php';
        $cands[] = '/opt/homebrew/bin/php'; // macOS
        foreach ($cands as $c) {
            if ($c !== '' && @is_executable($c)) return $c;
        }
        // 最后兜底：PHP_BINARY（CLI 下正确；mod_php 下可能是 Apache，尽量别走到这）
        return (defined('PHP_BINARY') && PHP_BINARY) ? PHP_BINARY : 'php';
    }
}

if (!function_exists('rescue_cred_files')) {
    /** 维护凭据候选文件（保持 rescue 自己的顺序：maintenance/config.php 优先） */
    function rescue_cred_files(): array {
        $r = rescue_root();
        return [
            $r . '/maintenance/config.php',
            $r . '/data/maint_config.php',
        ];
    }
}

if (!function_exists('rescue_read_cred_file')) {
    /** 从单个凭据文件读 [$user,$pass,$secret]；失败返回 null */
    function rescue_read_cred_file(string $f): ?array {
        if (!is_file($f)) return null;
        $MAINT_USER = $MAINT_PASS = $MAINT_SECRET = null;
        try { include $f; } catch (\Throwable $e) { return null; }
        if ($MAINT_USER === null || $MAINT_PASS === null || $MAINT_SECRET === null) return null;
        return [(string)$MAINT_USER, (string)$MAINT_PASS, (string)$MAINT_SECRET];
    }
}

if (!function_exists('rescue_load_creds')) {
    /**
     * 读维护凭据。返回：
     *   ['sources' => [['file'=>..., 'user'=>..., 'pass'=>..., 'secret'=>...], ...],
     *    'user' => 第一个可用文件的用户名（仅用于展示判断，验证要逐文件比对）]
     */
    function rescue_load_creds(): array {
        $sources = [];
        foreach (rescue_cred_files() as $f) {
            $c = rescue_read_cred_file($f);
            if ($c !== null) {
                $sources[] = ['file' => $f, 'user' => $c[0], 'pass' => $c[1], 'secret' => $c[2]];
            }
        }
        if ($sources) {
            return ['sources' => $sources, 'user' => $sources[0]['user'], 'pass' => $sources[0]['pass'], 'secret' => $sources[0]['secret']];
        }
        // 环境变量兜底（与 maintenance/creds.php 行为一致）
        return ['sources' => [], 'user' => getenv('MAINT_USER') ?: 'admin', 'pass' => getenv('MAINT_PASS') ?: '', 'secret' => getenv('MAINT_SECRET') ?: ''];
    }
}

if (!function_exists('rescue_verify_creds')) {
    /** 验证 维护用户名+口令（口令可以是 pass 或 secret）；任一凭据文件匹配即通过 */
    function rescue_verify_creds(string $user, string $secret): bool {
        if ($user === '' || $secret === '') return false;
        $c = rescue_load_creds();
        foreach ($c['sources'] as $s) {
            if (hash_equals($s['user'], $user) && (hash_equals($s['pass'], $secret) || ($s['secret'] !== '' && hash_equals($s['secret'], $secret)))) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('rescue_bootstrap')) {
    /**
     * 确保 maintenance/ 目录 + maintenance/config.php 存在。
     * 两个凭据文件都缺失时才生成随机凭据（绝不覆盖已有文件）。
     * 返回 ['created' => bool, 'file' => path, 'user' => 明文(仅本次生成时), 'pass' => 明文(仅本次生成时)]
     */
    function rescue_bootstrap(string $root): array {
        $dir = $root . '/maintenance';
        $cfg = $dir . '/config.php';
        $alt = $root . '/data/maint_config.php';
        if (is_file($cfg) || is_file($alt)) {
            return ['created' => false, 'file' => $cfg, 'user' => '', 'pass' => ''];
        }
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        if (!is_dir($dir)) {
            return ['created' => false, 'file' => $cfg, 'user' => '', 'pass' => ''];
        }
        if (is_file($cfg)) { // 并发竞态：别人刚好创建了
            return ['created' => false, 'file' => $cfg, 'user' => '', 'pass' => ''];
        }
        $u = 'rescue_' . bin2hex(random_bytes(3));
        $p = bin2hex(random_bytes(8));
        $s = bin2hex(random_bytes(16));
        $body = "<?php\n"
              . "/**\n"
              . " * ChatApp - Maintenance credentials\n"
              . " * Auto-generated by Rescue Panel on " . date('Y-m-d H:i:s') . " (both credential files were missing).\n"
              . " * Change these values (or use MAINT_USER / MAINT_PASS / MAINT_SECRET env vars).\n"
              . " */\n\n"
              . "\$MAINT_USER   = " . var_export($u, true) . ";\n"
              . "\$MAINT_PASS   = " . var_export($p, true) . ";\n"
              . "\$MAINT_SECRET = " . var_export($s, true) . ";\n";
        $ok = @file_put_contents($cfg, $body) !== false;
        if ($ok) { @chmod($cfg, 0640); }
        return ['created' => $ok, 'file' => $cfg, 'user' => $ok ? $u : '', 'pass' => $ok ? $p : ''];
    }
}

if (!function_exists('rescue_db_status')) {
    /**
     * 尽力探测数据库：从 api/config.php 正则解析连接常量（不执行该文件！），
     * 然后 2 秒超时 PDO 探测。DB 挂了也必须 graceful。
     * 返回 ['ok'=>bool, 'error'=>string, 'host'=>string, 'name'=>string]
     */
    function rescue_db_status(): array {
        $defs = ['DB_HOST' => '127.0.0.1', 'DB_NAME' => 'chatapp', 'DB_USER' => 'root', 'DB_PASS' => ''];
        $cfg = rescue_root() . '/api/config.php';
        if (is_file($cfg)) {
            $src = (string)@file_get_contents($cfg);
            if ($src !== '') {
                foreach ($defs as $k => $v) {
                    if (preg_match("/define\(\s*'" . $k . "'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/", $src, $m)) {
                        $defs[$k] = stripcslashes($m[1]);
                    }
                }
            }
        }
        try {
            $pdo = new PDO('mysql:host=' . $defs['DB_HOST'] . ';dbname=' . $defs['DB_NAME'] . ';charset=utf8mb4', $defs['DB_USER'], $defs['DB_PASS'], [
                PDO::ATTR_TIMEOUT => 2,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->query('SELECT 1');
            return ['ok' => true, 'error' => '', 'host' => $defs['DB_HOST'], 'name' => $defs['DB_NAME']];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'host' => $defs['DB_HOST'], 'name' => $defs['DB_NAME']];
        }
    }
}

if (!function_exists('rescue_wss_restart')) {
    /** 尽力重启 WSS 常驻进程（systemd → start.sh）；返回提示文案 */
    function rescue_wss_restart(): string {
        $root = rescue_root();
        foreach (['sudo -n systemctl restart chatapp-wss', 'systemctl restart chatapp-wss'] as $c) {
            $out = []; $rc = -1;
            @exec('cd ' . escapeshellarg($root) . ' && ' . $c . ' 2>&1', $out, $rc);
            if ($rc === 0) return 'WSS restarted';
        }
        $out = []; $rc = -1;
        @exec('cd ' . escapeshellarg($root) . ' && bash ' . escapeshellarg($root . '/wss/start.sh') . ' restart 2>&1', $out, $rc);
        if ($rc === 0) return 'WSS restarted (start.sh)';
        return 'WSS not restarted - run: sudo systemctl restart chatapp-wss';
    }
}

if (!function_exists('rescue_progress_path')) {
    function rescue_progress_path(): string { return rescue_root() . '/data/rescue_progress.json'; }
}
if (!function_exists('rescue_lock_path')) {
    function rescue_lock_path(): string { return rescue_root() . '/data/rescue.lock'; }
}
