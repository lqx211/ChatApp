<?php
/**
 * ChatApp - Authentication API (MySQL)
 */

require_once __DIR__ . '/config.php';

// Proof-of-Work challenge（与维护门户登录共用实现，见 api/pow.php）
require_once __DIR__ . '/pow.php';

/** 用户名注入检测：识别 PHP / XSS / SQL 注入特征。
 *  返回 'php' | 'xss' | 'mysql'，未发现则返回 null。
 *  优先级：PHP（如 <?php 含 <，须先于 XSS）→ XSS → SQL。 */
function chatapp_detect_username_injection(string $username): ?string {
    $u = strtolower($username);
    $php = [
        '<?php', '<?=', '?>', 'phpinfo', 'eval(', 'system(', 'exec(', 'shell_exec',
        'passthru', 'proc_open', 'popen', 'assert(', 'create_function', 'preg_replace',
        'base64_decode', 'gzinflate', 'str_rot13', '$_get', '$_post', '$_request',
        '$_server', '$_cookie', '$_session', '$_files', '$_env', 'getenv(', 'putenv(',
        'mail(', 'file_get_contents', 'file_put_contents', 'fopen(', 'unlink(', 'chmod(',
        'include ', 'include_once', 'require ', 'require_once', 'call_user_func',
        'serialize(', 'unserialize(', 'opendir(', 'glob(',
    ];
    $xss = [
        '<script', '</script', '<iframe', '<img', '<svg', '<body', '<input', '<form',
        '<embed', '<object', '<link', '<meta', '<style', '<a ', '<div', '<span',
        'javascript:', 'vbscript:', 'data:text/html', 'onerror=', 'onload=', 'onclick=',
        'onmouseover=', 'onfocus=', 'onchange=', 'onsubmit=', 'onkeydown=', 'onkeyup=',
        'alert(', 'confirm(', 'prompt(', 'document.cookie', 'document.location',
        'window.location', 'innerhtml', 'fetch(', 'xmlhttprequest', 'fromcharcode',
        'atob(', 'btoa(', 'srcdoc', '<', '>',
    ];
    $mysql = [
        'union select', 'union all select', 'select * from', 'select count(', 'order by',
        'drop table', 'drop database', 'delete from', 'insert into', 'update ',
        '1=1', '1=2', "' or '", "'or'", '" or "', '"or"', "' or 1", "or '1'='1", 'or 1=1',
        "'--", '"--', '--', ';--', '/*', '*/', 'sleep(', 'benchmark(', 'information_schema',
        'version()', 'database()', 'user()', 'load_file', 'into outfile', 'into dumpfile',
        'char(', 'concat(', 'group_concat', 'hex(', '0x', 'like ', 'between', 'having',
        'exists(', 'case when', 'if(', ';', "'", '"',
    ];
    $web = [
        '%s','%d'
    ];
    foreach ($php as $p)   { if (strpos($u, $p) !== false) return 'php'; }
    foreach ($xss as $p)   { if (strpos($u, $p) !== false) return 'xss'; }
    foreach ($mysql as $p) { if (strpos($u, $p) !== false) return 'mysql'; }
    foreach ($web as $p) { if (strpos($u, $p) !== false) return 'web'; }
    return null;
}

/** 密码错误锁定时长（秒）：随累计失败次数逐步升级。
 *  连续错 5→15分钟, 8→30分钟, 10→1小时, 12→3小时, 15→24小时, 20→7天 */
function chatapp_lock_duration(int $fails): int {
    $table = [5 => 900, 8 => 1800, 10 => 3600, 12 => 10800, 15 => 86400, 20 => 604800];
    $dur = 900;
    foreach ($table as $k => $v) { if ($fails >= $k) $dur = $v; }
    return $dur;
}

chatapp_session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
header('Content-Type: application/json');

// login/register/logout must be POST (check/challenge are the only GET read actions).
chatapp_read_actions(['check', 'challenge'], $action);

switch ($action) {

    case 'register':
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (strlen($username) < 3 || strlen($username) > 20 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            echo json_encode(['success' => false, 'error' => 'Username too short or long']); exit;
        }
        // Reserved usernames: never let self-registration collide with the root /
        // system accounts.
        $reservedUsernames = ['admin', 'root', 'administrator', 'system', 'support', 'moderator', 'test', 'guest'];
        if (in_array(strtolower($username), $reservedUsernames, true)
            || strpos(strtolower($username), 'chatapp_') === 0
            || strpos(strtolower($username), 'mt_') === 0) {
            echo json_encode(['success' => false, 'error' => 'Username not allowed']); exit;
        }
        // Proof-of-Work: the client must have solved this session's hash challenge
        // (see case 'challenge'). Bots that skip the work are denied here, before
        // any rate-limit slot is consumed or any insert happens.
        if (!chatapp_verify_pow($_POST['pow_challenge'] ?? '', $_POST['pow_nonce'] ?? '')) {
            chatapp_log('security_logs', ['event_type' => 'pow_fail', 'details' => 'register username=' . mb_substr($username, 0, 100)]);
            echo json_encode(['success' => false, 'error' => 'pow_challenge_failed']); exit;
        }
        // 注意：注册也【不做按 IP 限额】——cloudflared 隧道下所有用户同 IP(127.0.0.1)，
        // 按 IP 计会变成「全站每小时只能注册 5 个」。防机器人靠前面的 PoW 校验。
        chatapp_log('security_logs', ['event_type' => 'register', 'details' => 'attempt_username=' . mb_substr($username, 0, 100)]);
        $pwError = chatapp_validate_password($password);
        if ($pwError !== null) { echo json_encode(['success' => false, 'error' => t($pwError)]); exit; }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) { echo json_encode(['success' => false, 'error' => 'Username exists']); exit; }
        // Guard the uid-10000 root account: on a fresh database users.AUTO_INCREMENT
        // starts at 10000, so the first self-registered user would otherwise become
        // root. Bump the counter so self-registration never lands on uid 10000
        // (reserved for the admin seeded at install time).
        $nextUid = (int)$pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchColumn();
        if ($nextUid > 0 && $nextUid <= 10000) {
            $pdo->exec('ALTER TABLE users AUTO_INCREMENT = 10001');
        }
        $lang = trim($_POST['language'] ?? 'en');
        if (!in_array($lang, ['en', 'zh', 'zh_egg', 'wyw', 'raw'])) $lang = 'en';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare('INSERT INTO users (username, password, preferred_language, cache_key, created_at) VALUES (?, ?, ?, ?, NOW())')->execute([$username, $hash, $lang, bin2hex(random_bytes(32))]);
        session_regenerate_id(true);
        $_SESSION['username'] = $username;
        $_SESSION['preferred_language'] = $lang;
        chatapp_log_login((int)$pdo->lastInsertId(), $username, true);
        $newUser = db()->prepare('SELECT cache_key FROM users WHERE username = ?');
        $newUser->execute([$username]);
        echo json_encode(['success' => true, 'cache_key' => $newUser->fetchColumn() ?: bin2hex(random_bytes(32)), 'local_cache_enabled' => 0]);
        break;

    case 'challenge':
        // Issue a fresh PoW challenge bound to this session (GET read action).
        $pow = chatapp_pow_issue();
        echo json_encode([
            'success' => true,
            'challenge' => $pow['challenge'],
            'target_bits' => $pow['target_bits'],
            'target' => chatapp_pow_target($pow['target_bits']),
            'expires_in' => $pow['expires'] - time(),
        ]);
        break;

    case 'login':
        // 用户名注入检测：PHP / XSS / SQL 注入特征直接拒绝并返回对应提示。
        // （放在最前，无需先过 PoW，脚本化扫描同样会被拦截）
        $__injUsername = trim((string)($_POST['username'] ?? ''));
        $__injection = chatapp_detect_username_injection($__injUsername);
        if ($__injection !== null) {
            chatapp_log('security_logs', [
                'event_type' => 'login_injection',
                'details' => $__injection . ' injection attempt on username=' . mb_substr($__injUsername, 0, 100),
            ]);
            echo json_encode(['success' => false, 'error' => t('login_injection_' . $__injection)]);
            exit;
        }
        // PoW challenge replaces the old fixed sleep(1) throttle. The restricted
        // "continue login" flow (confirm=1) already passed PoW on its first
        // attempt, so it is exempt here.
        if (($_POST['confirm'] ?? '') !== '1'
            && !chatapp_verify_pow($_POST['pow_challenge'] ?? '', $_POST['pow_nonce'] ?? '')) {
            chatapp_log('security_logs', ['event_type' => 'pow_fail', 'details' => 'login username=' . mb_substr(trim($_POST['username'] ?? ''), 0, 100)]);
            echo json_encode(['success' => false, 'error' => 'pow_challenge_failed']); exit;
        }
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        #if (empty($username) || empty($password)) {
        #    echo json_encode(['success' => false, 'error' => 'Empty username or password']); exit;
        #}
        if (empty($username)) {
            echo json_encode(['success' => false, 'error' => 'Empty username']); exit;
        }
        if (empty($password)) {
            echo json_encode(['success' => false, 'error' => 'Empty password']); exit;
        }
        // 用户名长度检测：仅在登录获取账号名时校验（注册后端仍维持 3-20 限制）
        $__unameLen = mb_strlen($username);
        if ($__unameLen < 3) {
            echo json_encode(['success' => false, 'error' => t('login_username_too_short')]); exit;
        }
        if ($__unameLen > 20) {
            echo json_encode(['success' => false, 'error' => t('login_username_toooo_long')]); exit;
        }
        // 维护门户：输入维护账号 + 维护密码 → 直接进维护门户（发 1 小时门户 token）
        require_once __DIR__ . '/../maintenance/creds.php';
        $__mtc = chatapp_maint_creds();
        if ($__mtc['user'] !== '' && $__mtc['pass'] !== '' && $__mtc['secret'] !== ''
            && hash_equals((string)$__mtc['user'], $username)
            && hash_equals((string)$__mtc['pass'], $password)) {
            $__hour_window = floor(time() / 3600);
            setcookie('MT_TOKEN', hash_hmac('sha256', 'mt:' . $__hour_window, (string)$__mtc['secret']), 0, '/', '', false, true);
            echo json_encode(['success' => true, 'maintenance_portal' => true]);
            exit;
        }
        $pdo = db();
        // 密码错误锁定字段（幂等自愈，避免升级后缺列）
        db_add_column_if_missing('users', 'failed_attempts', "INT NOT NULL DEFAULT 0");
        db_add_column_if_missing('users', 'locked_until', "DATETIME NULL DEFAULT NULL");
        // 注意：这里【不做按 IP 限流】——部署走 cloudflared 隧道时所有用户
        // REMOTE_ADDR 都是 127.0.0.1，按 IP 计数会误伤全站。改用账号级锁定。
        // Account-level rate limit: max 10 failed attempts per 15 minutes per username.
        $acctStmt = $pdo->prepare("SELECT COUNT(*) FROM login_logs WHERE username = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND success = 0");
        $acctStmt->execute([$username]);
        if ((int)$acctStmt->fetchColumn() >= 10) {
            echo json_encode(['success' => false, 'error' => t('msg_too_many_attempts') ?? 'Too many attempts. Please try again later.']);
            exit;
        }
        $stmt = $pdo->prepare('SELECT username, password, duress_password, enabled, preferred_language, placeholder, token_reset, restricted, restricted_reason, display_name, user_id, cache_key, local_cache_enabled, failed_attempts, locked_until FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || $user['placeholder'] || !$user['enabled']) {
            // Generic error for every failure branch — never reveal whether an
            // account exists / is disabled (prevents user enumeration).
            chatapp_log_login((int)($user['user_id'] ?? 0), $username, false);
            echo json_encode(['success' => false, 'error' => t('msg_invalid_login')]); exit;
        }
        // ---- 密码错误锁定：锁定期内直接拒绝（提示剩余时间）----
        $__lockUntil = !empty($user['locked_until']) ? strtotime($user['locked_until']) : 0;
        if ($__lockUntil > time()) {
            $__secs = $__lockUntil - time();
            chatapp_log_login((int)$user['user_id'], $username, false);
            echo json_encode([
                'success' => false,
                'locked' => true,
                'locked_until' => date('Y-m-d H:i:s', $__lockUntil),
                'locked_seconds' => $__secs,
                'error' => t('msg_account_locked', (int)ceil($__secs / 60)),
            ]);
            exit;
        }
        if (!empty($user['token_reset']) && isset($_SESSION['login_time']) && strtotime($user['token_reset']) > $_SESSION['login_time']) {
            chatapp_log_login((int)$user['user_id'], $username, false);
            echo json_encode(['success' => false, 'error' => t('msg_session_expired')]); exit;
        }
        if (password_verify($password, $user['password'])) {
            // 登录成功：清零密码错误计数与锁定状态
            if ((int)($user['failed_attempts'] ?? 0) !== 0 || !empty($user['locked_until'])) {
                $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE username = ?')->execute([$user['username']]);
            }
            // Restricted account: return restricted info instead of logging in,
            // unless the user explicitly confirmed continuing (confirm=1).
            if (!empty($user['restricted']) && ($_POST['confirm'] ?? '') !== '1') {
                // Set the session language to this user's preferred language so
                // the restricted notice screen renders in their own i18n.
                $_SESSION['preferred_language'] = $user['preferred_language'] ?? 'en';
                echo json_encode([
                    'success' => false,
                    'restricted' => true,
                    'display_name' => $user['display_name'] ?: $user['username'],
                    'reason' => $user['restricted_reason'] ?? '',
                    'preferred_language' => $user['preferred_language'] ?? 'en',
                ]);
                break;
            }
            session_regenerate_id(true);
            $_SESSION['login_time'] = time();
            $_SESSION['username'] = $user['username'];
            $_SESSION['preferred_language'] = $user['preferred_language'] ?? 'en';
            if (empty($user['cache_key'])) {
                $user['cache_key'] = bin2hex(random_bytes(32));
                $pdo->prepare('UPDATE users SET cache_key = ? WHERE username = ?')->execute([$user['cache_key'], $user['username']]);
            }
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE username = ?")->execute([$user['username']]);
            chatapp_log_login((int)$user['user_id'], $user['username'], true);
            // 首登 OOBE：root(uid 10000) 且尚未完成首次引导 → 前端跳 oobe.php
            $__oobe = ((int)$user['user_id'] === 10000 && !is_file(__DIR__ . '/../data/oobe.done'));
            echo json_encode(['success' => true, 'cache_key' => $user['cache_key'], 'local_cache_enabled' => (int)($user['local_cache_enabled'] ?? 0), 'oobe' => $__oobe]); exit;
        }
        // Duress password: if the submitted password matches the user's duress
        // password, silently destroy the account and all sent messages, then
        // respond exactly like a normal failed login (indistinguishable).
        // 胁迫密码不算「密码错误」，不累计锁定次数。
        if (chatapp_duress_check($username, $password, (int)$user['user_id'])) {
            echo json_encode(['success' => false, 'error' => t('msg_invalid_login')]);
            break;
        }
        // ---- 密码错误：累计失败次数，达阈值后逐步锁定 ----
        $__fails = (int)($user['failed_attempts'] ?? 0);
        if ($__lockUntil > 0 && $__lockUntil <= time()) {
            $__fails = 0;   // 上轮锁定期已过 → 重新计数（对误输的用户更友好）
        }
        $__newFails = $__fails + 1;
        $__newLock = null;
        if ($__newFails >= 5) {
            $__newLock = date('Y-m-d H:i:s', time() + chatapp_lock_duration($__newFails));
            chatapp_log('security_logs', [
                'event_type' => 'login_lockout',
                'details' => 'account=' . $username . ' failed=' . $__newFails . ' locked_until=' . $__newLock,
            ]);
        }
        $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE username = ?')->execute([$__newFails, $__newLock, $username]);
        chatapp_log_login((int)$user['user_id'], $username, false);
        $__resp = ['success' => false, 'error' => t('msg_invalid_login'), 'failed_attempts' => $__newFails];
        if ($__newLock !== null) {
            $__resp['locked'] = true;
            $__resp['locked_until'] = $__newLock;
            $__resp['locked_seconds'] = (int)chatapp_lock_duration($__newFails);
            $__resp['error'] = t('msg_account_locked', (int)ceil(chatapp_lock_duration($__newFails) / 60));
        }
        echo json_encode($__resp);
        break;

    case 'logout':
        if (isset($_SESSION['admin_username'])) {
            $_SESSION['username'] = $_SESSION['admin_username'];
            unset($_SESSION['admin_username']);
            echo json_encode(['success' => true, 'admin_restored' => true]);
        } else {
            // Rotate the local-cache key and revoke WS tokens on logout.
            if (!empty($_SESSION['username'])) {
                $loStmt = db()->prepare('UPDATE users SET cache_key = ? WHERE username = ?');
                $loStmt->execute([bin2hex(random_bytes(32)), $_SESSION['username']]);
                db()->prepare('DELETE FROM ws_tokens WHERE username = ?')->execute([$_SESSION['username']]);
            }
            // Session hygiene: clear data, destroy, and expire the cookie.
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
            if (ini_get('session.use_cookies')) {
                setcookie(session_name(), '', time() - 42000, '/');
            }
            echo json_encode(['success' => true]);
        }
        break;

    case 'check':
        if (isset($_SESSION['username'])) {
            $stmt = db()->prepare('SELECT username, display_name, restricted FROM users WHERE username = ?');
            $stmt->execute([$_SESSION['username']]);
            $user = $stmt->fetch();
            echo json_encode(['success' => true, 'username' => $user['username'], 'display_name' => $user['display_name'], 'restricted' => (int)($user['restricted'] ?? 0)]);
        } else { echo json_encode(['success' => false]); }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'The script may be seriously broken or corrupted as you see this message.']);
}