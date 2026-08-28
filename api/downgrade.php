<?php
/**
 * ChatApp · Downgrade System（降级，极度危险）
 * list   : 列出当前仓库全部 git 历史（可选版本）
 * perform: 三重验证 + 选目标 commit → git checkout 回退代码（排除 config/data/bkup/maintenance）
 * 注：实际操作基本不可逆（回退后数据库结构/功能可能与旧代码不兼容）。
 */
require_once __DIR__ . '/config.php';
chatapp_session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['username'])) { echo json_encode(['success' => false, 'error' => 'Not logged in.']); exit; }
$__s = db()->prepare('SELECT user_id FROM users WHERE username = ?');
$__s->execute([$_SESSION['username']]);
$__role = chatapp_get_role((int)($__s->fetchColumn() ?: 0));
if ($__role !== 'root' && $__role !== 'admin') { echo json_encode(['success' => false, 'error' => 'Access denied.']); exit; }

$root = dirname(__DIR__); // 运行所在仓库根（VM: /var/www/html，本地: 源码目录）
function dg_git(string $cmd, string $root): array {
    $out = []; $ret = -1;
    exec('cd ' . escapeshellarg($root) . ' && ' . $cmd . ' 2>&1', $out, $ret);
    return [implode("\n", $out), $ret];
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'list':
        // %x09 = tab 分隔：不能用 |（exec 里会被 shell 当管道符，导致 command not found、列表为空）
        [$log] = dg_git('git log --pretty=format:%H%x09%s%x09%ai -n 200', $root);
        [$head] = dg_git('git rev-parse HEAD', $root);
        $head = strtolower(trim($head));
        $commits = [];
        foreach (explode("\n", trim($log)) as $line) {
            if ($line === '') continue;
            $parts = explode("\t", $line, 3);
            if (count($parts) === 3) {
                $commits[] = [
                    'hash' => $parts[0],
                    'short' => substr($parts[0], 0, 12),
                    'subject' => $parts[1],
                    'date' => $parts[2],
                    'current' => (strtolower($parts[0]) === $head),
                ];
            }
        }
        echo json_encode(['success' => true, 'head' => $head, 'commits' => $commits]);
        break;

    case 'perform':
        // —— 三重验证 ——
        $pwd = $_POST['password'] ?? '';
        $mu  = trim($_POST['maint_user'] ?? '');
        $ms  = $_POST['maint_secret'] ?? '';
        $h1  = strtoupper(trim($_POST['git_hash'] ?? ''));
        $h2  = strtoupper(trim($_POST['git_hash2'] ?? ''));
        $target = trim($_POST['target'] ?? '');
        if ($pwd === '' || $mu === '' || $ms === '' || $h1 === '' || $h2 === '' || $target === '') {
            echo json_encode(['success' => false, 'error' => 'All fields are required.']); exit;
        }
        if ($h1 !== $h2) { echo json_encode(['success' => false, 'error' => 'Git hash mismatch.']); exit; }
        if (!preg_match('/^[0-9a-f]{40}$/i', $target)) { echo json_encode(['success' => false, 'error' => 'Invalid target commit.']); exit; }
        $stmt = db()->prepare('SELECT password FROM users WHERE user_id = 10000');
        $stmt->execute();
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($pwd, $admin['password'])) { echo json_encode(['success' => false, 'error' => 'Administrator password incorrect.']); exit; }
        $MAINT_USER = ''; $MAINT_PASS = ''; $MAINT_SECRET = '';
        $maintCfg = __DIR__ . '/../maintenance/config.php';
        if (is_file($maintCfg)) { include $maintCfg; }
        $msOk = ($ms !== '' && ($ms === $MAINT_PASS || ($MAINT_SECRET !== '' && $ms === $MAINT_SECRET)));
        if ($mu !== $MAINT_USER || !$msOk) { echo json_encode(['success' => false, 'error' => 'Maintenance credentials incorrect.']); exit; }
        [$head] = dg_git('git rev-parse HEAD', $root);
        if (strtoupper(trim($head)) !== $h1) { echo json_encode(['success' => false, 'error' => 'Git hash does not match current HEAD.']); exit; }
        [$t] = dg_git('git cat-file -t ' . escapeshellarg($target), $root);
        if (trim($t) !== 'commit') { echo json_encode(['success' => false, 'error' => 'Target commit not found.']); exit; }

        // 置维护锁（短暂，防并发）
        $lock = $root . '/data/upgrade.lock';
        @file_put_contents($lock, json_encode(['type' => 'downgrade', 'started' => time(), 'by' => $_SESSION['username']]));

        // 回退代码（排除 config/data/bkup/maintenance，保留配置与数据）
        $coCmd = 'git checkout --force ' . escapeshellarg($target) . ' -- . \':!config\' \':!data\' \':!bkup\' \':!maintenance\'';
        [$co, $rc] = dg_git($coCmd, $root);
        if ($rc !== 0) {
            @unlink($lock);
            echo json_encode(['success' => false, 'error' => 'Checkout failed: ' . mb_substr($co, 0, 300)]); exit;
        }
        dg_git('git reset --soft ' . escapeshellarg($target), $root);
        [$newHead] = dg_git('git rev-parse HEAD', $root);
        @unlink($lock);
        if (function_exists('chatapp_log_admin')) chatapp_log_admin('downgrade', null, null, ['from' => trim($head), 'to' => trim($target)]);
        echo json_encode(['success' => true, 'from' => trim($head), 'to' => trim($newHead)]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
}
