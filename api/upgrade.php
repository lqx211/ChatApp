<?php
/**
 * ChatApp · Upgrade System
 * check  : 检测本地与远程 git 版本差异
 * perform: 三重验证后从 origin 拉取并覆盖代码（排除 config/data/maintenance）
 */
require_once __DIR__ . '/config.php';

chatapp_session_start();
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
    exit;
}
header('Content-Type: application/json');
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$root = dirname(__DIR__); // ChatApp 仓库根

function up_git(string $cmd, string $root): array {
    $out = []; $ret = -1;
    exec('cd ' . escapeshellarg($root) . ' && ' . $cmd . ' 2>&1', $out, $ret);
    return [implode("\n", $out), $ret];
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
        // 2) Maintenance Portal 凭据
        $MAINT_USER = ''; $MAINT_SECRET = '';
        $maintCfg = __DIR__ . '/../maintenance/config.php';
        if (is_file($maintCfg)) { include $maintCfg; }
        if ($mu !== $MAINT_USER || $ms !== $MAINT_SECRET) {
            echo json_encode(['success' => false, 'error' => 'Maintenance credentials incorrect.']); exit;
        }
        // 3) git hash 必须等于当前 HEAD
        [$head] = up_git('git rev-parse HEAD', $root);
        if (strtoupper(trim($head)) !== $h1) {
            echo json_encode(['success' => false, 'error' => 'Git hash does not match current HEAD.']); exit;
        }

        // —— 执行升级：fetch + checkout（排除 config/data/bkup/maintenance）——
        [$f, $rf] = up_git('git fetch origin main', $root);
        if ($rf !== 0) {
            echo json_encode(['success' => false, 'error' => 'git fetch failed: ' . mb_substr($f, 0, 300)]); exit;
        }
        $coCmd = "git checkout --force origin/main -- . ':!config' ':!data' ':!bkup' ':!maintenance'";
        [$co, $rc] = up_git($coCmd, $root);
        if ($rc !== 0) {
            echo json_encode(['success' => false, 'error' => 'git checkout failed: ' . mb_substr($co, 0, 300)]); exit;
        }
        // 更新 HEAD 指针（soft，保留工作区/保留目录不动）
        up_git('git reset --soft origin/main', $root);
        [$newHead] = up_git('git rev-parse HEAD', $root);
        if (function_exists('chatapp_log_admin')) {
            chatapp_log_admin('upgrade', null, null, ['from' => trim($head), 'to' => trim($newHead)]);
        }
        echo json_encode([
            'success' => true,
            'from' => trim($head),
            'to' => trim($newHead),
            'note' => 'config/data/bkup/maintenance preserved',
        ]);
        break;
}
