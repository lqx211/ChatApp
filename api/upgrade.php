<?php
/**
 * ChatApp · Upgrade System
 * check  : 检测本地与远程 git 版本差异
 * perform: 三重验证后从 origin 拉取并覆盖代码（排除 config/data/maintenance）
 */
require_once __DIR__ . '/config.php';

chatapp_session_start();
header('Content-Type: application/json');
// 升级接口仅限 admin/root：聊天会话 或 维护门户 token 均可
$__role = chatapp_portal_admin_role();
if ($__role === '') {
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$root = dirname(__DIR__); // ChatApp 仓库根

function up_git(string $cmd, string $root): array {
    $out = []; $ret = -1;
    exec('cd ' . escapeshellarg($root) . ' && ' . $cmd . ' 2>&1', $out, $ret);
    return [implode("\n", $out), $ret];
}

/**
 * 解析可用的 CLI php 二进制。
 * ⚠️ mod_php(Apache) 下 PHP_BINARY 指向 apache 而非 php，直接拿去 exec 会把 worker 跑成 apache，导致升级卡 0%。
 */
function up_php_binary(): string {
    $cands = [];
    if (defined('PHP_BINARY') && PHP_BINARY) $cands[] = PHP_BINARY;
    if (defined('PHP_BINDIR') && PHP_BINDIR) $cands[] = PHP_BINDIR . '/php';
    $cands[] = '/usr/bin/php8.3';
    $cands[] = '/usr/bin/php8.2';
    $cands[] = '/usr/bin/php8.1';
    $cands[] = '/usr/bin/php';
    $cands[] = '/usr/local/bin/php';
    $cands[] = '/opt/homebrew/bin/php'; // macOS
    foreach ($cands as $c) {
        if ($c !== '' && is_executable($c)) return $c;
    }
    return (string)(defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php');
}

switch ($action) {

    case 'check':
        [$head]   = up_git('git rev-parse HEAD', $root);
        [$branch] = up_git('git rev-parse --abbrev-ref HEAD', $root);
        [$remote] = up_git('git ls-remote origin main', $root);
        [$dirty]  = up_git('git status --short | wc -l', $root);
        $remoteSha = '';
        if (preg_match('/^([0-9a-f]{40})\s+/i', trim($remote), $m)) $remoteSha = strtolower($m[1]);
        $localSha = strtolower(trim($head));
        echo json_encode([
            'success' => true,
            'local' => $localSha,
            'remote' => $remoteSha,
            'branch' => trim($branch),
            'has_update' => ($remoteSha !== '' && $localSha !== $remoteSha),
            'dirty_count' => (int)trim($dirty),
        ]);
        break;

    case 'perform':
        // —— 三重验证（与 Factory Reset 一致）——
        $pwd = $_POST['password'] ?? '';
        $mu  = trim($_POST['maint_user'] ?? '');
        $ms  = $_POST['maint_secret'] ?? '';
        $h1  = strtoupper(trim($_POST['git_hash'] ?? ''));
        $h2  = strtoupper(trim($_POST['git_hash2'] ?? ''));
        if ($pwd === '' || $mu === '' || $ms === '' || $h1 === '' || $h2 === '') {
            echo json_encode(['success' => false, 'error' => 'All fields are required.']); exit;
        }
        if ($h1 !== $h2) {
            echo json_encode(['success' => false, 'error' => 'Git hash mismatch.']); exit;
        }
        // 1) Administrator 密码（10000）
        $stmt = db()->prepare('SELECT password FROM users WHERE user_id = 10000');
        $stmt->execute();
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($pwd, $admin['password'])) {
            echo json_encode(['success' => false, 'error' => 'Administrator password incorrect.']); exit;
        }
        // 2) Maintenance Portal 凭据（Passphrase = 明文密码 $MAINT_PASS，兼容旧 secret）
        require_once __DIR__ . '/../maintenance/creds.php';
        $__mt = chatapp_maint_creds();
        $MAINT_USER = $__mt['user']; $MAINT_PASS = $__mt['pass']; $MAINT_SECRET = $__mt['secret'];
        $msOk = ($ms !== '' && ($ms === $MAINT_PASS || ($MAINT_SECRET !== '' && $ms === $MAINT_SECRET)));
        if ($mu !== $MAINT_USER || !$msOk) {
            echo json_encode(['success' => false, 'error' => 'Maintenance credentials incorrect.']); exit;
        }
        // 3) git hash 必须等于当前 HEAD
        [$head] = up_git('git rev-parse HEAD', $root);
        if (strtoupper(trim($head)) !== $h1) {
            echo json_encode(['success' => false, 'error' => 'Git hash does not match current HEAD.']); exit;
        }

        // —— 置维护锁 + 启动后台升级 worker（server 端下载，进度可轮询）——
        $lock = $root . '/data/upgrade.lock';
        $progressFile = $root . '/data/upgrade_progress.json';
        @file_put_contents($lock, json_encode(['started' => time(), 'by' => $_SESSION['username']]));
        @file_put_contents($progressFile, json_encode(['status' => 'pending', 'step' => 'Starting…', 'pct' => 0, 'from' => trim($head)]));
        if (function_exists('chatapp_log_admin')) {
            chatapp_log_admin('upgrade', null, null, ['from' => trim($head), 'action' => 'armed']);
        }
        $worker = __DIR__ . '/upgrade_worker.php';
        $workerLog = $root . '/data/upgrade_worker.log';
        @file_put_contents($workerLog, '');
        // mod_php 下 PHP_BINARY 指向 apache——显式解析 CLI php；nohup 防请求结束后 SIGHUP 杀掉 worker
        $phpBin = up_php_binary();
        exec('cd ' . escapeshellarg($root) . ' && nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($worker) . ' >> ' . escapeshellarg($workerLog) . ' 2>&1 &');
        echo json_encode(['success' => true, 'started' => true, 'maintenance' => true]);
        break;

    case 'progress':
        // 读取后台 worker 的升级进度
        $progressFile = $root . '/data/upgrade_progress.json';
        $p = [];
        if (is_file($progressFile)) { $p = json_decode((string)file_get_contents($progressFile), true) ?: []; }
        $p['locked'] = is_file($root . '/data/upgrade.lock');
        echo json_encode(['success' => true] + $p);
        break;
}
