<?php
/**
 * ChatApp · Factory Reset（真·工厂重置，多阶段）
 * 流程：start(三重验证+置维护锁) → expire_tokens(清所有非root会话token)
 *       → setup_creds(设新admin) → rebuild(DROP库+重建schema+建root)
 * 执行前自动 mysqldump 备份到 bkup/factory_reset_*.sql（安全网，可删）。
 * 维护锁(data/upgrade.lock)期间：非 uid10000 全部拦截（config.php）。
 */
require_once __DIR__ . '/config.php';
chatapp_session_start();
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']); exit;
}
header('Content-Type: application/json');
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$root = dirname(__DIR__);
$lock = $root . '/data/upgrade.lock';
$stateFile = $root . '/data/factory_reset_state.json';

function fr_root_uid(): int {
    $s = db()->prepare('SELECT user_id FROM users WHERE username = ?');
    $s->execute([$_SESSION['username'] ?? '']);
    return (int)($s->fetchColumn() ?: 0);
}
function fr_state(): array {
    global $stateFile;
    return json_decode((string)@file_get_contents($stateFile), true) ?: [];
}
function fr_set_state(array $st): void {
    global $stateFile;
    @file_put_contents($stateFile, json_encode($st));
}
function fr_deny() {
    echo json_encode(['success' => false, 'error' => 'Access denied.']); exit;
}

switch ($action) {

    // ---- 1) 进入流程：三重验证 + 置维护锁 ----
    case 'start':
        if (fr_root_uid() !== 10000) fr_deny();
        $pwd = $_POST['password'] ?? '';
        $mu  = trim($_POST['maint_user'] ?? '');
        $ms  = $_POST['maint_secret'] ?? '';
        $h1  = strtoupper(trim($_POST['git_hash'] ?? ''));
        if ($pwd === '' || $mu === '' || $ms === '' || $h1 === '') {
            echo json_encode(['success' => false, 'error' => 'All fields are required.']); exit;
        }
        // admin 密码
        $st = db()->prepare('SELECT password FROM users WHERE user_id = 10000');
        $st->execute();
        $admin = $st->fetch();
        if (!$admin || !password_verify($pwd, $admin['password'])) {
            echo json_encode(['success' => false, 'error' => 'Administrator password incorrect.']); exit;
        }
        // maintenance 凭据（明文密码或 secret）
        $MAINT_USER = ''; $MAINT_PASS = ''; $MAINT_SECRET = '';
        if (is_file(__DIR__ . '/../maintenance/config.php')) { include __DIR__ . '/../maintenance/config.php'; }
        $msOk = ($ms !== '' && ($ms === $MAINT_PASS || ($MAINT_SECRET !== '' && $ms === $MAINT_SECRET)));
        if ($mu !== $MAINT_USER || !$msOk) {
            echo json_encode(['success' => false, 'error' => 'Maintenance credentials incorrect.']); exit;
        }
        // git hash = 当前 HEAD
        $head = trim((string)@shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>&1'));
        if (strtoupper($head) !== $h1) {
            echo json_encode(['success' => false, 'error' => 'Git hash does not match current HEAD.']); exit;
        }
        // 置维护锁 + state
        @file_put_contents($lock, json_encode(['type' => 'factory_reset', 'started' => time(), 'by' => $_SESSION['username']]));
        fr_set_state(['verified' => true, 'started' => time(), 'phase' => 'armed']);
        echo json_encode(['success' => true, 'preparing' => true]);
        break;

    // ---- 2) 使所有非 root 会话 token 过期 ----
    case 'expire_tokens':
        if (fr_root_uid() !== 10000) fr_deny();
        $total = (int)db()->query("SELECT COUNT(*) FROM ws_tokens WHERE user_id != 10000")->fetchColumn();
        $expired = db()->exec("DELETE FROM ws_tokens WHERE user_id != 10000");
        $st = fr_state(); $st['phase'] = 'tokens_expired'; fr_set_state($st);
        echo json_encode(['success' => true, 'expired' => (int)$expired, 'total' => $total]);
        break;

    // ---- 3) 暂存新 admin 凭据（含是否跳过备份）----
    case 'setup_creds':
        if (fr_root_uid() !== 10000) fr_deny();
        $u = trim($_POST['username'] ?? '');
        $p = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $u)) {
            echo json_encode(['success' => false, 'error' => 'Invalid username (3-20, letters/numbers/underscore).']); exit;
        }
        if (strlen($p) < 8) {
            echo json_encode(['success' => false, 'error' => 'Password too short (min 8).']); exit;
        }
        $st = fr_state();
        $st['phase'] = 'creds_set';
        $st['new_username'] = $u;
        $st['new_password'] = $p;
        $st['skip_dump'] = (($_POST['skip_dump'] ?? '') === '1');
        fr_set_state($st);
        echo json_encode(['success' => true]);
        break;

    // ---- 4) 备份 → DROP → 重建 → 建新 root ----
    case 'rebuild':
        if (fr_root_uid() !== 10000) fr_deny();
        $st = fr_state();
        if (empty($st['verified']) || empty($st['new_password'])) {
            echo json_encode(['success' => false, 'error' => 'Flow not fully verified.']); exit;
        }
        $u = $st['new_username'] ?? 'admin';
        $p = $st['new_password'];
        // mysql 用 TCP(127.0.0.1) 绕过 www-data 的 unix socket 权限；每个命令检查结果，不静默吞错
        $MYSQL = 'mysql -h127.0.0.1 -uroot';
        $out = []; $rc = -1;
        // 1) 备份（可跳过）：ca-db-bkup-YYYYMMDDHHMMSS.sql（ChatApp 根目录）
        $bak = '';
        if (empty($st['skip_dump'])) {
            $bak = $root . '/ca-db-bkup-' . date('YmdHis') . '.sql';
            exec('mysqldump -h127.0.0.1 -uroot chatapp > ' . escapeshellarg($bak) . ' 2>&1', $out, $rc);
            if ($rc !== 0) {
                echo json_encode(['success' => false, 'error' => 'mysqldump failed: ' . implode(' ', $out)]); exit;
            }
        }
        // 2) DROP + CREATE
        exec($MYSQL . ' -e "DROP DATABASE IF EXISTS chatapp" 2>&1', $out, $rc);
        if ($rc !== 0) {
            echo json_encode(['success' => false, 'error' => 'DROP failed: ' . implode(' ', $out)]); exit;
        }
        exec($MYSQL . ' -e "CREATE DATABASE chatapp DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci" 2>&1', $out, $rc);
        if ($rc !== 0) {
            echo json_encode(['success' => false, 'error' => 'CREATE failed: ' . implode(' ', $out)]); exit;
        }
        // 3) 从 schema.sql 重建
        $schema = $root . '/schema.sql';
        if (is_file($schema)) {
            exec($MYSQL . ' chatapp < ' . escapeshellarg($schema) . ' 2>&1', $out, $rc);
            if ($rc !== 0) {
                echo json_encode(['success' => false, 'error' => 'schema import failed: ' . implode(' ', $out)]); exit;
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'schema.sql not found']); exit;
        }
        // 4) 插入 root admin（uid 10000）
        $hash = password_hash($p, PASSWORD_BCRYPT);
        $cacheKey = bin2hex(random_bytes(32));
        $uEsc = addslashes($u); $hEsc = addslashes($hash);
        exec($MYSQL . " chatapp -e \"INSERT INTO users (user_id, username, password, role, enabled, cache_key, created_at) VALUES (10000, '$uEsc', '$hEsc', 'root', 1, '$cacheKey', NOW())\" 2>&1", $out, $rc);
        if ($rc !== 0) {
            echo json_encode(['success' => false, 'error' => 'admin insert failed: ' . implode(' ', $out)]); exit;
        }
        // 5) 清锁 + state
        @unlink($lock);
        @unlink($stateFile);
        if (function_exists('chatapp_log_admin')) {
            chatapp_log_admin('factory_reset', null, null, ['backup' => basename($bak), 'new_root' => $u]);
        }
        echo json_encode(['success' => true, 'username' => $u, 'password' => $p, 'backup' => basename($bak)]);
        break;

    // ---- 状态/阶段查询 ----
    case 'status':
        $st = fr_state();
        echo json_encode(['success' => true, 'phase' => $st['phase'] ?? '', 'locked' => is_file($lock)]);
        break;

    case 'abort':
        // 放弃：清除维护锁与 state（根用户）
        if (fr_root_uid() !== 10000) fr_deny();
        @unlink($lock);
        @unlink($stateFile);
        echo json_encode(['success' => true]);
        break;
}
