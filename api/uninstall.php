<?php
/**
 * ChatApp · Uninstall（卸载，单行道）
 *
 * 阶段一（请求内）：
 *   三重验证（admin 密码 + 维护凭据 + git hash）→ 删数据库 chatapp（可跳过）→
 *   移除 WSS systemd 服务 → 删除 /var/www/html 中除 api/ 与 modern/ 外的所有内容
 *   （保留 api/ 与 modern/ 让本接口 + 本页面继续工作）。
 *
 * 阶段二（后台 shell &，响应送达后）：
 *   sleep 后彻底 rm -rf /var/www/html（含 api/ modern/）→
 *   若 Apache 仅这一个站（无其它 DocumentRoot）→ sudo service apache2 stop。
 *
 * 依赖：setup（gh_container_setup.sh）为 www-data 配置了最小 sudoers（systemd/停 apache/删配置）。
 */
require_once __DIR__ . '/config.php';
chatapp_session_start();
header('Content-Type: application/json');
// 卸载仅限 root：聊天会话 或 维护门户 token
if (chatapp_portal_admin_role() === '') { echo json_encode(['success' => false, 'error' => 'Access denied.']); exit; }

function un_root_uid(): int {
    if (chatapp_portal_admin_role() === 'root') return 10000; // 门户 token = root
    $s = db()->prepare('SELECT user_id FROM users WHERE username = ?');
    $s->execute([$_SESSION['username'] ?? '']);
    return (int)($s->fetchColumn() ?: 0);
}
function un_deny() { echo json_encode(['success' => false, 'error' => 'Access denied.']); exit; }
function un_git(string $cmd): array {
    $out = []; $ret = -1;
    exec('cd ' . escapeshellarg('/var/www/html') . ' && ' . $cmd . ' 2>&1', $out, $ret);
    return [implode("\n", $out), $ret];
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'perform') {
    if (un_root_uid() !== 10000) un_deny();

    // —— 三重验证（与 Factory Reset / Upgrade 一致）——
    $pwd = $_POST['password'] ?? '';
    $mu  = trim($_POST['maint_user'] ?? '');
    $ms  = $_POST['maint_secret'] ?? '';
    $h1  = strtoupper(trim($_POST['git_hash'] ?? ''));
    $h2  = strtoupper(trim($_POST['git_hash2'] ?? ''));
    if ($pwd === '' || $mu === '' || $ms === '' || $h1 === '' || $h2 === '') { echo json_encode(['success' => false, 'error' => 'All fields are required.']); exit; }
    if ($h1 !== $h2) { echo json_encode(['success' => false, 'error' => 'Git hash mismatch.']); exit; }
    $st = db()->prepare('SELECT password FROM users WHERE user_id = 10000');
    $st->execute();
    $admin = $st->fetch();
    if (!$admin || !password_verify($pwd, $admin['password'])) { echo json_encode(['success' => false, 'error' => 'Administrator password incorrect.']); exit; }
    require_once __DIR__ . '/../maintenance/creds.php';
    $__mt = chatapp_maint_creds();
    $MAINT_USER = $__mt['user']; $MAINT_PASS = $__mt['pass']; $MAINT_SECRET = $__mt['secret'];
    $msOk = ($ms !== '' && ($ms === $MAINT_PASS || ($MAINT_SECRET !== '' && $ms === $MAINT_SECRET)));
    if ($mu !== $MAINT_USER || !$msOk) { echo json_encode(['success' => false, 'error' => 'Maintenance credentials incorrect.']); exit; }
    [$head] = un_git('git rev-parse HEAD');
    if (strtoupper(trim($head)) !== $h1) { echo json_encode(['success' => false, 'error' => 'Git hash does not match current HEAD.']); exit; }

    $dbDel = (($_POST['db_delete'] ?? '1') === '1');

    // —— 1) 数据库（默认删；取消勾选则保留）——
    if ($dbDel) {
        $out = []; $rc = -1;
        exec('mysql -h127.0.0.1 -uroot -e "DROP DATABASE IF EXISTS chatapp" 2>&1', $out, $rc);
        if ($rc !== 0) { echo json_encode(['success' => false, 'error' => 'DROP DATABASE failed: ' . implode(' ', $out)]); exit; }
    }

    // —— 2) 移除 WSS systemd 服务 + Apache 安全配置（best-effort；依赖 sudoers）——
    exec('sudo /usr/bin/systemctl stop chatapp-wss.service 2>&1', $o, $rc);
    exec('sudo /usr/bin/systemctl disable chatapp-wss.service 2>&1', $o, $rc);
    exec('sudo /bin/rm -f /etc/systemd/system/chatapp-wss.service 2>&1', $o, $rc);
    exec('sudo /usr/bin/systemctl daemon-reload 2>&1', $o, $rc);
    exec('sudo /bin/rm -f /etc/apache2/conf-enabled/chatapp-security.conf 2>&1', $o, $rc);
    exec('sudo /bin/rm -f /etc/apache2/conf-available/chatapp-security.conf 2>&1', $o, $rc);

    // —— 3) 阶段一：删除 /var/www/html 除 api/ 与 modern/ 外的所有内容（保留本接口+本页面）——
    if (is_file('/var/www/html/api/config.php')) {
        exec('cd /var/www/html && find . -mindepth 1 -maxdepth 1 ! -name api ! -name modern -exec rm -rf {} + 2>&1', $o, $rc);
    }

    // —— 返回成功（先确保送达客户端）——
    echo json_encode(['success' => true, 'uninstalled' => true, 'db_deleted' => $dbDel]);
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    if (function_exists('ob_end_flush')) { @ob_end_flush(); @flush(); }

    // —— 4) 阶段二（后台 shell &）：彻底删除 + Apache 仅此一站则停服 ——
    $tail = '/tmp/chatapp_uninstall_tail.sh';
    $tailBody = "#!/bin/bash\n"
        . "sleep 2\n"
        . "rm -rf /var/www/html 2>/dev/null\n"
        . "if ! grep -rh \"DocumentRoot\" /etc/apache2/sites-enabled/ 2>/dev/null | awk '{print \$2}' | grep -qv '^/var/www/html$'; then\n"
        . "    sudo /usr/sbin/service apache2 stop 2>/dev/null\n"
        . "fi\n"
        . "rm -f /tmp/chatapp_uninstall_tail.sh\n";
    @file_put_contents($tail, $tailBody);
    @chmod($tail, 0755);
    exec('nohup bash ' . escapeshellarg($tail) . ' >/dev/null 2>&1 &');
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action.']);
