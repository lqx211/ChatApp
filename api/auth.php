<?php
/**
 * ChatApp - Authentication API (MySQL)
 */

require_once __DIR__ . '/config.php';

// ---- Proof-of-Work challenge (anti-bot register/login gate) ----
// Custom byte-level hash, implemented identically in modern/scripts/pow.js (no public
// algorithm code). The client must find a nonce with
//   powhash(challenge ':' nonce) < target   (target = 2^(256 - POW_TARGET_BITS))
define('POW_TARGET_BITS', 15);      // sub-second difficulty (~2^15 tries)
define('POW_MAX_NONCE_LEN', 10);    // nonce is decimal, <= 9999999999
define('POW_CHALLENGE_TTL', 300);   // seconds before a challenge expires

/** Custom PoW hash → 64 lowercase hex chars. Only 0-255 arithmetic (add/xor/
 *  shift), so the PHP and JS implementations are bit-for-bit identical with no
 *  32-bit signed-overflow or encoding pitfalls. Input is ASCII. */
function chatapp_pow_hash(string $input): string {
    $seed = [0x24, 0x5a, 0x10, 0x9f, 0x3d, 0x77, 0x81, 0xc2, 0x4b, 0x0e, 0x96, 0x55,
             0x1a, 0x68, 0xdc, 0x03, 0x7e, 0x92, 0x40, 0xcf, 0x11, 0x5d, 0xaa, 0x38,
             0x66, 0xf1, 0x0b, 0x9c, 0x27, 0x74, 0xdb, 0x32];
    $state = $seed;
    $bytes = array_values(unpack('C*', $input));
    $n = count($bytes);
    for ($round = 0; $round < 32; $round++) {
        $state[0] = ($state[0] ^ ($round + 1)) & 0xff;
        for ($i = 0; $i < 32; $i++) {
            $ib = $n > 0 ? $bytes[($i + $round) % $n] : 0;
            $a = $state[$i];
            $b = $state[($i + 7) % 32];
            $c = $state[($i + 13) % 32];
            $x = ((($a << 3) | ($a >> 5)) & 0xff);
            $x = ($x + $b) & 0xff;
            $x = ($x ^ $c) & 0xff;
            $x = ($x ^ $ib) & 0xff;
            $k = (($round * 31 + $i * 7 + 11) & 0xff);
            $state[$i] = ($x + $k) & 0xff;
        }
        $t = $state[0]; $state[0] = $state[31]; $state[31] = $t;
        $t = $state[5]; $state[5] = $state[21]; $state[21] = $t;
    }
    $out = '';
    foreach ($state as $b) { $out .= sprintf('%02x', $b); }
    return $out;
}

/** Target = 2^(256 - bits), a 64-char lowercase hex string (no gmp needed). */
function chatapp_pow_target(int $bits): string {
    $shift = 256 - $bits;
    $idx = intdiv($shift, 4);
    $digit = 1 << ($shift % 4);
    return str_pad(dechex($digit) . str_repeat('0', $idx), 64, '0', STR_PAD_LEFT);
}

/** Issue a fresh challenge bound to this session. */
function chatapp_pow_issue(): array {
    $pow = [
        'challenge' => bin2hex(random_bytes(16)),
        'target_bits' => POW_TARGET_BITS,
        'expires' => time() + POW_CHALLENGE_TTL,
    ];
    $_SESSION['pow'] = $pow;
    return $pow;
}

/** Verify a client PoW solution. Single-use (unset on success). Difficulty is
 *  always taken from the server-side session — never trusted from the client. */
function chatapp_verify_pow(string $challenge, string $nonce): bool {
    $pow = $_SESSION['pow'] ?? null;
    if (!$pow || !isset($pow['challenge'], $pow['target_bits'], $pow['expires'])) return false;
    if (time() > (int)$pow['expires']) return false;
    if (!hash_equals($pow['challenge'], $challenge)) return false;
    if ($nonce === '' || strlen($nonce) > POW_MAX_NONCE_LEN || !ctype_digit($nonce)) return false;
    if ((float)$nonce > 9999999999.0) return false;
    $target = chatapp_pow_target((int)$pow['target_bits']);
    if (strcmp(chatapp_pow_hash($pow['challenge'] . ':' . $nonce), $target) >= 0) return false;
    unset($_SESSION['pow']);
    return true;
}

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
        // Registration rate limit: max 5 attempts per hour per IP.
        $regIp = chatapp_client_ip();
        $regStmt = db()->prepare("SELECT COUNT(*) FROM security_logs WHERE event_type = 'register' AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $regStmt->execute([$regIp]);
        if ((int)$regStmt->fetchColumn() >= 5) {
            echo json_encode(['success' => false, 'error' => 'Too many registrations. Please try again later.']); exit;
        }
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
        $pdo = db();
        // IP rate limit: max 5 failed attempts per minute
        $ip = chatapp_client_ip();
        $rateStmt = $pdo->prepare("SELECT COUNT(*) FROM login_logs WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE) AND success = 0");
        $rateStmt->execute([$ip]);
        if ((int)$rateStmt->fetchColumn() >= 5) {
            echo json_encode(['success' => false, 'error' => t('msg_too_many_attempts') ?? 'Too many attempts. Please try again later.']);
            exit;
        }
        // Account-level rate limit: max 10 failed attempts per 15 minutes per username.
        $acctStmt = $pdo->prepare("SELECT COUNT(*) FROM login_logs WHERE username = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND success = 0");
        $acctStmt->execute([$username]);
        if ((int)$acctStmt->fetchColumn() >= 10) {
            echo json_encode(['success' => false, 'error' => t('msg_too_many_attempts') ?? 'Too many attempts. Please try again later.']);
            exit;
        }
        $stmt = $pdo->prepare('SELECT username, password, duress_password, enabled, preferred_language, placeholder, token_reset, restricted, restricted_reason, display_name, user_id, cache_key, local_cache_enabled FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || $user['placeholder'] || !$user['enabled']) {
            // Generic error for every failure branch — never reveal whether an
            // account exists / is disabled (prevents user enumeration).
            chatapp_log_login((int)($user['user_id'] ?? 0), $username, false);
            echo json_encode(['success' => false, 'error' => t('msg_invalid_login')]); exit;
        }
        if (!empty($user['token_reset']) && isset($_SESSION['login_time']) && strtotime($user['token_reset']) > $_SESSION['login_time']) {
            chatapp_log_login((int)$user['user_id'], $username, false);
            echo json_encode(['success' => false, 'error' => t('msg_session_expired')]); exit;
        }
        if (password_verify($password, $user['password'])) {
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
            echo json_encode(['success' => true, 'cache_key' => $user['cache_key'], 'local_cache_enabled' => (int)($user['local_cache_enabled'] ?? 0)]); exit;
        }
        // Duress password: if the submitted password matches the user's duress
        // password, silently destroy the account and all sent messages, then
        // respond exactly like a normal failed login (indistinguishable).
        if (chatapp_duress_check($username, $password, (int)$user['user_id'])) {
            echo json_encode(['success' => false, 'error' => t('msg_invalid_login')]);
            break;
        }
        chatapp_log_login((int)$user['user_id'], $username, false);
        echo json_encode(['success' => false, 'error' => t('msg_invalid_login')]);
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