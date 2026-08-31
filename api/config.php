<?php
/**
 * ChatApp - Configuration
 */

// ---- 全局 PHP 错误处理：任何致命/语法错误 → 显示友好 500 页，不暴露原始错误 ----
if (!defined('CHATAPP_ERR_HANDLER')) {
    define('CHATAPP_ERR_HANDLER', 1);
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    register_shutdown_function(function () {
        $e = error_get_last();
        if (!$e) return;
        static $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
        if (!in_array($e['type'], $fatalTypes, true)) return;
        // 清空已输出的内容，防止半渲染页面 / 原始错误泄漏
        while (ob_get_level() > 0) { @ob_end_clean(); }
        @http_response_code(500);
        // 记录日志（尽力而为；config 中途出错时 chatapp_log 可能未定义）
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
        $f500 = __DIR__ . '/../errors/500.php';
        if (is_file($f500)) { @include $f500; }
        else { echo '<h1 style="font-family:sans-serif;color:#eee;background:#1a1a1a;padding:40px;text-align:center">500 Internal Server Error</h1>'; }
    });
}

// ---- mbstring 兜底 ----
// 极简 / 容器 PHP 可能未安装 mbstring 扩展，而本应用大量使用 mb_* 函数。
// 若缺失则提供 UTF-8 安全的降级实现，避免「Call to undefined function」500。
// 真实扩展存在时本段自动跳过（function_exists 守卫）。
if (!function_exists('mb_substr') || !function_exists('mb_strlen')) {

    if (!function_exists('mb_strlen')) {
        function mb_strlen($string = '', $encoding = null) {
            $s = (string)$string;
            if (@preg_match('//u', $s) === 1) {
                $n = @preg_match_all('/./us', $s, $_m);
                return ($n === false) ? strlen($s) : $n;
            }
            return strlen($s);
        }
    }

    if (!function_exists('mb_substr')) {
        function mb_substr($string, $start, $length = null, $encoding = null) {
            $s = (string)$string;
            $chars = @preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false) { // 非 UTF-8 → 字节级降级
                return $length === null ? substr($s, $start) : substr($s, $start, $length);
            }
            if ($length === null) {
                $length = max(0, mb_strlen($s, $encoding) - $start);
            }
            return implode('', array_slice($chars, $start, $length));
        }
    }

    if (!function_exists('mb_strtoupper')) {
        function mb_strtoupper($string, $encoding = null) {
            return strtoupper((string)$string);
        }
    }
}

// Keep PHP time interpretation in sync with MySQL NOW() (HKT/UTC+8).
// Without this, DATETIME values stored via NOW() are parsed by strtotime()
// as UTC, producing negative cooldown diffs that permanently block
// message/attachment EXP awards, message revocation, incident EXP, etc.
date_default_timezone_set('Asia/Hong_Kong');

// ---- Global security headers (every page/API that loads config.php) ----
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// ---- 强制 HTTPS（生产环境可选开启；本地开发保持 false） ----
// FORCE_HTTPS=true 时：
//   * 页面 HTTP 请求 → 301 跳转 HTTPS；API 请求 → 403 JSON（避免破坏 fetch）
//   * 会话 cookie 强制 Secure（只在 HTTPS 下发送）
//   * HTTPS 响应附带 HSTS 头（max-age=1年）
// 开启方式二选一：① 服务器环境变量 CHATAPP_FORCE_HTTPS=1（Apache SetEnv /
//   Nginx fastcgi_param / systemd / shell export），② 直接改下面常量改为 true。
// 本地（127.0.0.1:8080、VM 内网 IP）没有证书，保持 false 即可照常走 HTTP。
define('FORCE_HTTPS', ((string)getenv('CHATAPP_FORCE_HTTPS') === '1' || false));

/** 当前请求是否为 HTTPS（含反向代理 X-Forwarded-Proto 透传）。 */
function chatapp_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    return false;
}

if (FORCE_HTTPS) {
    if (chatapp_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    } else {
        $__isApi = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false);
        if ($__isApi) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'HTTPS required']);
            exit;
        }
        $__hHost = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $__hUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: https://' . $__hHost . $__hUri, true, 301);
        exit;
    }
}

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'chatapp');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

// 维护模式闸门：放在 db() 之后，这样维护状态可从 MySQL 读取（文件仅应急覆盖）。
require_once __DIR__ . '/../maintenance.php';

function chatapp_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => FORCE_HTTPS || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443,
        ]);
        session_start();
    }
    // Re-validate an existing session's user on every request: if the account was
    // disabled / made a placeholder / deleted, or its tokens were reset after this
    // session was issued, destroy the session. This makes "disable account" and
    // "expire tokens" truly revoke active sessions across every endpoint.
    // (Validated at most once per request.)
    static $sessionValidated = false;
    if (!$sessionValidated) {
        $sessionValidated = true;
        if (isset($_SESSION['username']) && !chatapp_session_valid()) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
        }
    }
}

// Returns false when the session's user is no longer allowed (account disabled,
// placeholder, deleted, or token_reset newer than the session's login_time).
function chatapp_session_valid(): bool {
    if (empty($_SESSION['username'])) return true;
    $stmt = db()->prepare('SELECT enabled, placeholder, deleted_at, token_reset FROM users WHERE username = ?');
    $stmt->execute([$_SESSION['username']]);
    $row = $stmt->fetch();
    if (!$row) return false;
    if ((int)$row['enabled'] !== 1 || (int)$row['placeholder'] === 1 || $row['deleted_at'] !== null) return false;
    if (!empty($row['token_reset']) && isset($_SESSION['login_time']) && strtotime((string)$row['token_reset']) > (int)$_SESSION['login_time']) return false;
    return true;
}

// CSRF token: generated per session and used to protect high-value actions that
// cannot rely on POST-only (e.g. db_export opened via window.open GET).
function chatapp_csrf_token(): string {
    chatapp_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function chatapp_csrf_verify(): bool {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ($_GET['csrf'] ?? ''));
    return is_string($sent) && $sent !== '' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $sent);
}

// Enforce POST for state-changing actions. The session cookie is SameSite=Lax
// (blocks cross-site POST cookies), so requiring POST closes the remaining
// GET-based CSRF vector (top-level GET navigations carry the Lax cookie).
function chatapp_post_only(array $mutating, string $action): void {
    if (in_array($action, $mutating, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
}

// Inverse of chatapp_post_only: allow the listed read-only actions via GET, but
// require POST for every other (mutating) action.
function chatapp_read_actions(array $readOnly, string $action): void {
    if (!in_array($action, $readOnly, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
}

function chatapp_get_user(): ?array {
    chatapp_session_start();
    if (isset($_SESSION['username'])) {
        $stmt = db()->prepare('SELECT user_id, username, display_name, preferred_language, avatar, custom_title, searchable, searchable_by_uid, timezone, data_saver, dnd, placeholder, restricted, role, emoji_panel_mode, emoji_chat_mode, exp, level, likes, pin_self, created_at, cache_key, local_cache_enabled, gender, birthday, gender_privacy, bg_image, bg_updated_at, bg_privacy, bg_blacklist, bg_whitelist, bg_no_friend, bg_private_image, profile_bg_image, profile_bg_updated_at, notif_system, notif_banner, typing_visible, stranger_invite_group, stranger_like, anyone_add_friend, sig_privacy, sig_blacklist, sig_whitelist, sig_no_friend, sig_hidden_text, space_ears FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $user = $stmt->fetch();
        if ($user) {
            $_SESSION['preferred_language'] = $user['preferred_language'] ?? 'en';
        }
        return $user ?: null;
    }
    return null;
}

/** 门户级管理员身份：返回 'root' | 'admin' | ''。
 *  来源①：已登录的聊天管理员（root/admin 角色）
 *  来源②：有效的维护门户 token（MT_TOKEN）→ 视为 root
 *  供维护门户直接调用升级/降级/工厂重置/卸载等危险接口。 */
function chatapp_portal_admin_role(): string {
    // ① 聊天会话管理员
    if (isset($_SESSION['username'])) {
        $stmt = db()->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $__uid = (int)($stmt->fetchColumn() ?: 0);
        if ($__uid > 0) {
            $__role = chatapp_get_role($__uid);
            if ($__role === 'root' || $__role === 'admin') return $__role;
        }
    }
    // ② 维护门户 token = root 级
    require_once __DIR__ . '/../maintenance/creds.php';
    $__c = chatapp_maint_creds();
    $__secret = (string)$__c['secret'];
    $__h = floor(time() / 3600);
    $__tok = $_COOKIE['MT_TOKEN'] ?? ($_GET['token'] ?? '');
    if ($__secret !== '' && $__tok !== '' && hash_equals(hash_hmac('sha256', 'mt:' . $__h, $__secret), (string)$__tok)) {
        return 'root';
    }
    return '';
}

function chatapp_require_login(): void {
    chatapp_session_start();
    if (!isset($_SESSION['username'])) { header('Location: login.php'); exit; }
}

/**
 * Convert a stored avatar value into a renderable URL.
 * New format stores a bare filename (e.g. "10116.png", served from
 * data/pp/{uid}.{ext}); legacy stores a hash filename under data/user/{uid}/.
 * Bare filenames must be routed through api/avatar.php; data URIs are kept
 * as-is. Returns '' for empty avatars so `if ($avatar):` guards still work.
 */
function chatapp_avatar_url(?string $avatar, ?string $username, int $uid = 0): string {
    static $ppCache = [];
    if (empty($username)) return '';
    // data/pp/{uid}.{ext} 优先（磁盘真实文件，权威）——即使 DB 存的是旧值/data URI 也以磁盘为准
    if ($uid > 0) {
        if (!array_key_exists($uid, $ppCache)) {
            $ppCache[$uid] = '';
            foreach (['png', 'jpg', 'jpeg', 'gif', 'webp'] as $ext) {
                $f = __DIR__ . '/../data/pp/' . $uid . '.' . $ext;
                if (is_file($f)) { $ppCache[$uid] = (string)@filemtime($f); break; }
            }
        }
        if ($ppCache[$uid] !== '') {
            // 带文件 mtime 版本号：换头像后 URL 变化，浏览器不会命中旧缓存（否则两个头像并存）
            return '../../api/avatar.php?u=' . urlencode($username) . '&v=' . $ppCache[$uid];
        }
    }
    if (empty($avatar)) return '';
    if (strpos($avatar, 'data:') === 0) return $avatar;
    if (preg_match('/^[0-9a-zA-Z_]+\.(png|jpg|jpeg|gif|webp)$/i', $avatar)) {
        return '../../api/avatar.php?u=' . urlencode($username);
    }
    return $avatar;
}

/**
 * 通用可见性判断（背景图/签名共用）：0=黑名单 1=白名单 2=仅自己；noFriend 要求是好友。
 */
function chatapp_privacy_allowed(int $viewerUid, int $ownerUid, int $mode, bool $noFriend, array $black, array $white): bool {
    if ($viewerUid === $ownerUid) return true;
    if ($mode === 0) $allow = !in_array($viewerUid, $black, true);
    elseif ($mode === 1) $allow = in_array($viewerUid, $white, true);
    else $allow = false; // 2 = only owner
    if ($allow && $noFriend && $viewerUid > 0) {
        $c = db()->prepare("SELECT COUNT(*) FROM contacts WHERE status='accepted' AND ((user_from=? AND user_to=?) OR (user_from=? AND user_to=?))");
        $c->execute([$viewerUid, $ownerUid, $ownerUid, $viewerUid]);
        $allow = (int)$c->fetchColumn() > 0;
    }
    return $allow;
}

/**
 * 返回观众应看到的签名文本：本人/有权限 → 真实签名；无权限 → 不可见时签名（可为空）。
 */
function chatapp_sig_for_viewer(int $viewerUid, int $ownerUid, string $realSig): string {
    $s = db()->prepare('SELECT sig_privacy, sig_blacklist, sig_whitelist, sig_no_friend, sig_hidden_text FROM users WHERE user_id = ?');
    $s->execute([$ownerUid]);
    $row = $s->fetch();
    if (!$row) return $realSig;
    $black = json_decode((string)$row['sig_blacklist'], true); $black = is_array($black) ? $black : [];
    $white = json_decode((string)$row['sig_whitelist'], true); $white = is_array($white) ? $white : [];
    $allowed = chatapp_privacy_allowed($viewerUid, $ownerUid, (int)$row['sig_privacy'], (int)$row['sig_no_friend'] === 1, $black, $white);
    return $allowed ? $realSig : (string)($row['sig_hidden_text'] ?? '');
}

/**
 * 群头像 URL：文件名格式 → ../api/avatar.php?g=<group_id>；data URI 原样返回。
 */
function chatapp_group_avatar_url(?string $avatar, int $groupId): string {
    if (empty($avatar)) return '';
    if (strpos($avatar, 'data:') === 0) return $avatar;
    if (preg_match('/^[0-9a-zA-Z_]+\.(png|jpg|jpeg|gif|webp)$/i', $avatar)) {
        return '../../api/avatar.php?g=' . $groupId;
    }
    return $avatar;
}

/**
 * Detect phone/tablet user agents (used to serve the QQ-style mobile UI).
 */
/**
 * WebSocket 通讯模式配置（3 字段：本地/私网/公网）。
 * 读取 config/wss_server.php（root 在「WebSocket Settings」维护）。
 * 兼容旧版单值字符串：视为公网地址，本地/私网给默认值。
 */
function chatapp_wss_config(): array {
    $file = __DIR__ . '/../config/wss_server.php';
    $raw = '';
    if (is_file($file)) { $raw = @include $file; }
    $cfg = ['local' => '', 'private' => '', 'public' => ''];
    if (is_array($raw)) {
        foreach (array_keys($cfg) as $k) {
            $cfg[$k] = trim((string)($raw[$k] ?? ''));
        }
    } elseif (is_string($raw) && trim((string)$raw) !== '') {
        $cfg['public'] = trim((string)$raw);
        $cfg['local'] = '127.0.0.1:9090';
        $cfg['private'] = '0.0.0.0:9090';
    }
    return $cfg;
}

/** 把配置值归一化为完整 ws:// / wss:// URL：host:port → ws://，裸域名 → wss://。 */
function chatapp_wss_url(?string $v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (strpos($v, '://') !== false) return $v;
    if (preg_match('/^[a-zA-Z0-9.\-\[\]:]+:\d+$/', $v)) return (FORCE_HTTPS ? 'wss://' : 'ws://') . $v;
    return 'wss://' . $v;
}

function chatapp_is_mobile_ua(): bool {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (bool)preg_match('/iPhone|iPod|iPad|Android|Mobile|Mobi|Opera Mini|IEMobile|Windows Phone/i', $ua);
}

function chatapp_get_role(int $uid): string {
    if ($uid === 10000) {
        // uid 10000 is the seeded root account. Also require the reserved username
        // so a fresh DB where 10000 is not the seeded admin can never be root.
        $stmt = db()->prepare('SELECT username FROM users WHERE user_id = 10000');
        $stmt->execute();
        if (strtolower((string)$stmt->fetchColumn()) === 'admin') return 'root';
        return 'user';
    }
    $stmt = db()->prepare("SELECT role FROM users WHERE user_id = ? AND deleted_at IS NULL");
    $stmt->execute([$uid]);
    return $stmt->fetchColumn() ?: 'user';
}

function chatapp_has_permission(int $uid, string $perm): bool {
    $role = chatapp_get_role($uid);
    if ($role === 'root') return true;
    // Check role_defs
    $stmt = db()->prepare("SELECT permissions FROM role_defs WHERE role_name = ?");
    $stmt->execute([$role]);
    $def = $stmt->fetch();
    if (!$def) return false;
    $perms = json_decode($def['permissions'], true);
    // perm format: "resource.action" e.g. "users.view"
    $parts = explode('.', $perm, 2);
    if (count($parts) !== 2) return false;
    return in_array($parts[1], $perms[$parts[0]] ?? []);
}

function chatapp_validate_password(string $password): ?string {
    $len = strlen($password);
    if ($len < 8) return 'msg_password_too_weak';
    // bcrypt truncates at 72 bytes; reject longer passwords to avoid silent
    // collisions between two different long passwords.
    if ($len > 72) return 'msg_password_too_weak';
    $hasLetter = false; $hasDigit = false; $hasSpecial = false;
    for ($i = 0; $i < $len; $i++) {
        $char = $password[$i];
        if (ctype_alpha($char)) $hasLetter = true;
        elseif (ctype_digit($char)) $hasDigit = true;
        else $hasSpecial = true;
        if ($hasLetter && $hasDigit) break;
    }
    if (!$hasLetter || !$hasDigit) return 'msg_password_too_weak';
    if ($hasSpecial) return null;
    if (preg_match('/^(.)\1{7,}$/', $password)) return 'msg_password_too_weak';
    $seqAsc = '0123456789'; $seqDesc = '9876543210';
    for ($i = 0; $i <= 2; $i++) {
        if (strpos($password, substr($seqAsc, $i * 3, 8)) !== false) return 'msg_password_too_weak';
        if (strpos($password, substr($seqDesc, $i * 3, 8)) !== false) return 'msg_password_too_weak';
    }
    $letters = 'abcdefghijklmnopqrstuvwxyz'; $lettersRev = 'zyxwvutsrqponmlkjihgfedcba';
    for ($i = 0; $i <= 18; $i++) {
        if (stripos($password, substr($letters, $i, 8)) !== false) return 'msg_password_too_weak';
        if (stripos($password, substr($lettersRev, $i, 8)) !== false) return 'msg_password_too_weak';
    }
    $keyboardPatterns = ['qwertyui','wertyuio','ertyuiop','asdfghjk','sdfghjkl','zxcvbnm','poiuytre','oiuytrew','iuytrewq','lkjhgfds','kjhgfdsa','mnbvcxz'];
    foreach ($keyboardPatterns as $pat) if (stripos($password, $pat) !== false) return 'msg_password_too_weak';
    $diag = ['1qaz','2wsx','3edc','4rfv','5tgb','6yhn','7ujm','1qazxsw2','2wsxedc3','3edcvfr4'];
    foreach ($diag as $pat) if (stripos($password, $pat) !== false) return 'msg_password_too_weak';
    if (preg_match('/^([a-zA-Z][0-9]){4,}$/', $password) || preg_match('/^([0-9][a-zA-Z]){4,}$/', $password)) return 'msg_password_too_weak';
    $weak = ['password','password1','password123','password12','qwertyui','qwerty123','qwertyu1','qwerty12','abc12345','abcd1234','abcde123','abcdefgh','admin123','adminadmin','administrator','letmein1','letmein12','letmein123','iloveyou','iloveyou1','iloveyou12','sunshine','sunshine1','sunshine12','princess','princess1','princess12','football','football1','football12','baseball','baseball1','baseball12','monkey12','dragon12','master12','shadow12','michael1','superman','batman12','trustno1','starwars','12312312','homo114514','Homo114514','87654321','11223344','12121212','13131313','23232323','1q2w3e4r','q1w2e3r4','1qaz2wsx','qazwsxed','passwor1','login123','welcome1','welcome12','changeme','changeme1','whatever','whatever1','nicole12','daniel12','jessica1','ashley12','11111111','22222222','33333333','44444444','55555555','66666666','77777777','88888888','99999999','00000000','aaaaaaaa','bbbbbbbb','cccccccc','dddddddd','eeeeeeee','ffffffff','gggggggg','hhhhhhhh','iiiiiiii','jjjjjjjj','kkkkkkkk','llllllll','mmmmmmmm','nnnnnnnn','oooooooo','pppppppp','qqqqqqqq','rrrrrrrr','ssssssss','tttttttt','uuuuuuuu','vvvvvvvv','wwwwwwww','xxxxxxxx','yyyyyyyy','zzzzzzzz','pass1234','p@ssword','p@ssw0rd','123qwe','qwe123','abc123','test1234','demo1234','user1234','hello123','love123','happy123'];
    if (in_array(strtolower($password), $weak, true)) return 'msg_password_too_weak';
    return null;
}

function chatapp_display_name(?array $user): string {
    if (!$user) return '';
    $name = $user['display_name'] ?: $user['username'];
    $uid = $user['user_id'] ?? '';
    return htmlspecialchars($name . ' (' . $uid . ')');
}

function lang_load(): array {
    chatapp_session_start();
    $lang = $_SESSION['preferred_language'] ?? 'en';
    $allowed = ['en', 'zh', 'zh_egg', 'wyw', 'raw'];
    if (!in_array($lang, $allowed, true)) $lang = 'en';
    $file = __DIR__ . '/../lang/' . $lang . '.php';
    return file_exists($file) ? include $file : include __DIR__ . '/../lang/en.php';
}

function t(string $key, $default = null, ...$args): string {
    $lang = lang_load();
    if (array_key_exists($key, $lang)) {
        $msg = $lang[$key];
    } elseif ($default !== null) {
        $msg = $default;
    } else {
        $msg = $key;
    }
    return $args ? sprintf($msg, ...$args) : $msg;
}

function db_add_column_if_missing(string $table, string $column, string $definition): void {
    $pdo = db();
    $table = preg_replace('/[^a-zA-Z_]/', '', $table);
    $column = preg_replace('/[^a-zA-Z_]/', '', $column);
    // 用 fetch() 判断列是否存在：rowCount() 对 SHOW COLUMNS 在某些驱动上恒为 0，
    // 会把已存在的列误判为缺失 → 重复 ALTER → "Duplicate column" 500。
    $result = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($result && $result->fetch() === false) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        } catch (\PDOException $e) {
            // 并发 init_db 竞态：列可能刚被别的进程加上 → 忽略 Duplicate column (1060)
            if (!in_array((int)$e->getCode(), [1060, 1061], true)) { throw $e; }
        }
    }
}

/**
 * 确保签名隐私列存在（幂等；init_db 已调用，这里供 sig API/页面在操作前自愈，
 * 避免升级后列缺失导致“设置改不动/报错”）。
 */
function chatapp_ensure_sig_columns(): void {
    db_add_column_if_missing('users', 'sig_privacy', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('users', 'sig_blacklist', "TEXT DEFAULT NULL");
    db_add_column_if_missing('users', 'sig_whitelist', "TEXT DEFAULT NULL");
    db_add_column_if_missing('users', 'sig_no_friend', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('users', 'sig_hidden_text', "VARCHAR(100) DEFAULT ''");
}

/* ================= 个人空间 · 朋友圈 ================= */

/** 确保朋友圈表存在（幂等 CREATE TABLE IF NOT EXISTS） */
function ensure_space_feeds_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS space_feeds (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        content TEXT NULL,
        images TEXT NULL,
        visibility TINYINT NOT NULL DEFAULT 0,
        visible_to TEXT NULL,
        likes INT NOT NULL DEFAULT 0,
        liked_by TEXT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** 确保朋友圈评论表存在（幂等） */
function ensure_space_comments_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS space_comments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        feed_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        content VARCHAR(1000) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_feed (feed_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** 确保空间留言板表存在（幂等） */
function ensure_space_messages_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS space_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        to_uid INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        content VARCHAR(1000) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_to (to_uid),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** 确保空间日志表存在（幂等） */
function ensure_space_blogs_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS space_blogs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        title VARCHAR(200) NOT NULL DEFAULT '',
        content TEXT NULL,
        visibility TINYINT NOT NULL DEFAULT 0,
        visible_to TEXT NULL,
        views INT NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** 确保朋友圈艾特通知表存在（幂等） */
function ensure_space_mentions_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS space_mentions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        feed_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        mentioned_uid INT UNSIGNED NOT NULL,
        by_uid INT UNSIGNED NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_mentioned (mentioned_uid, is_read),
        KEY idx_feed (feed_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_add_column_if_missing('space_mentions', 'type', "VARCHAR(16) NOT NULL DEFAULT 'mention'");
    db_add_column_if_missing('space_mentions', 'comment_id', "BIGINT UNSIGNED NOT NULL DEFAULT 0");
}

/** 确保相册表存在（幂等）——相册本身 */
function ensure_space_albums_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS space_albums (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        name VARCHAR(60) NOT NULL,
        description TEXT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'personal',
        visibility TINYINT NOT NULL DEFAULT 4,
        visible_to TEXT NULL,
        is_dynamic TINYINT(1) NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_dynamic (user_id, is_dynamic)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** 确保相册照片表存在（幂等）——media 为图片/视频 URL，featured=是否同步精选 */
function ensure_space_album_photos_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS space_album_photos (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        album_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        media TEXT NOT NULL,
        featured TINYINT(1) NOT NULL DEFAULT 0,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_album (album_id),
        KEY idx_featured (user_id, featured, enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * 相册可见性判断（与朋友圈可见度枚举一致）：0公开 1好友 2部分好友可见 3部分好友不可见 4私密
 * 本人总是可见。
 */
function space_album_allowed(PDO $pdo, int $viewerUid, array $album): bool {
    $owner = (int)($album['user_id'] ?? 0);
    if ($owner === $viewerUid) return true;
    $vis = (int)($album['visibility'] ?? 0);
    if ($vis === 4) return false;                                       // 私密：仅自己
    if ($vis === 0) return true;                                        // 公开
    $ids = space_parse_ids($album['visible_to'] ?? '');
    if ($vis === 1) return space_is_friend($pdo, $viewerUid, $owner);   // 好友可见
    if ($vis === 2) return in_array($viewerUid, $ids, true);            // 部分好友可见
    if ($vis === 3) return !in_array($viewerUid, $ids, true);           // 部分好友不可见
    return false;
}

/** 相册类型中文标签 */
function space_album_type_label(string $type): string {
    return [
        'personal' => '个人', 'multi' => '多人', 'couple' => '情侣',
        'family' => '亲子', 'travel' => '旅行', 'other' => '其他',
    ][$type] ?? '个人';
}

/** 相册可见度中文标签 */
function space_album_vis_label(int $vis): string {
    return [0 => '公开', 1 => '好友', 2 => '部分好友可见', 3 => '部分好友不可见', 4 => '私密'][$vis] ?? '私密';
}

/** 确保空间访客表存在（幂等）——viewer 看过 target；同一天同一人只记一次 */
function ensure_space_visits_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS space_visits (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        viewer_uid INT UNSIGNED NOT NULL,
        target_uid INT UNSIGNED NOT NULL,
        hidden TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_target (target_uid, hidden, created_at),
        KEY idx_viewer (viewer_uid, hidden, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** 星座：生日 YYYY-MM-DD（也可 MM-DD）→ 星座名；无效返回 '' */
function space_zodiac(string $birthday): string {
    $b = trim((string)$birthday);
    if ($b === '') return '';
    $p = explode('-', $b);
    $m = (int)($p[1] ?? 0); $d = (int)($p[2] ?? 0);
    if ($m < 1 || $m > 12 || $d < 1 || $d > 31) return '';
    $cut = [20, 19, 21, 20, 21, 22, 23, 23, 23, 24, 23, 22];
    $names = ['摩羯', '水瓶', '双鱼', '白羊', '金牛', '双子', '巨蟹', '狮子', '处女', '天秤', '天蝎', '射手', '摩羯'];
    $idx = $d < $cut[$m - 1] ? $m - 1 : $m;
    return $names[$idx] . '座';
}

/** 两人共同好友数（双向 accepted） */
function space_common_friends(PDO $pdo, int $a, int $b): int {
    if ($a <= 0 || $b <= 0 || $a === $b) return 0;
    $q = $pdo->query("SELECT user_to FROM contacts WHERE user_from=$a AND status='accepted' "
        . "UNION SELECT user_from FROM contacts WHERE user_to=$a AND status='accepted'");
    $setA = array_flip(array_map('intval', array_filter($q->fetchAll(PDO::FETCH_COLUMN))));
    if (!$setA) return 0;
    $q2 = $pdo->query("SELECT user_to FROM contacts WHERE user_from=$b AND status='accepted' "
        . "UNION SELECT user_from FROM contacts WHERE user_to=$b AND status='accepted'");
    $n = 0;
    foreach (array_map('intval', array_filter($q2->fetchAll(PDO::FETCH_COLUMN))) as $f) {
        if (isset($setA[$f])) $n++;
    }
    return $n;
}

/** 两人是否为好友（contacts 双向 accepted） */
function space_is_friend(PDO $pdo, int $a, int $b): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE status='accepted' AND ((user_from=? AND user_to=?) OR (user_from=? AND user_to=?))");
    $s->execute([$a, $b, $b, $a]);
    return (int)$s->fetchColumn() > 0;
}

/** 相对时间文案 */
function space_fmt_time(string $dt): string {
    $t = strtotime($dt);
    if (!$t) return '';
    $d = time() - $t;
    if ($d < 60) return '刚刚';
    if ($d < 3600) return floor($d / 60) . ' 分钟前';
    if ($d < 86400) return floor($d / 3600) . ' 小时前';
    if ($d < 86400 * 7) return floor($d / 86400) . ' 天前';
    return date('m月d日 H:i', $t);
}

/** 解析 uid 数组（支持 JSON 或逗号分隔字符串） */
function space_parse_ids($raw): array {
    $arr = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($arr)) $arr = array_filter(array_map('trim', explode(',', (string)$raw)));
    $ids = array_filter(array_map('intval', $arr), function ($x) { return $x > 0; });
    return array_values(array_unique($ids));
}

/** 可见性标签 */
function space_vis_label(int $vis): string {
    return [0 => '所有人可见', 1 => '好友可见', 2 => '部分好友可见', 3 => '部分好友不可见', 4 => '仅自己可见'][$vis] ?? '所有人可见';
}

/** 内联 SVG 图标（灰线风格，currentColor 着色，禁止 emoji/文字当图标） */
function svg_ic(string $n, int $s = 16): string {
    static $map = [
        'wrench'   => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
        'monitor'  => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>',
        'home'     => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>',
        'globe'    => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>',
        'volume'   => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>',
        'chat'     => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
        'users'    => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'award'    => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><path d="M8.21 13.89 7 23l5-3 5 3-1.21-9.11"/></svg>',
        'lock'     => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
        'zap'      => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
        'database' => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>',
        'trash'    => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        'heart'    => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
        'warning'  => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
        'close'    => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>',
        'music'    => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
        'check'    => '<svg viewBox="0 0 24 24" width="{S}" height="{S}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
    ];
    $svg = $map[$n] ?? '';
    if ($svg === '') return '';
    return str_replace('{S}', (string)$s, str_replace('<svg ', '<svg style="vertical-align:-2px" ', $svg));
}


function chatapp_client_ip(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $cf = trim($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    // Only trust the client-supplied CF-Connecting-IP header when REMOTE_ADDR is
    // genuinely inside Cloudflare's published IPv4 ranges. Otherwise anyone can
    // spoof an arbitrary IP to bypass per-IP rate limits / poison audit logs.
    $cfCidrs = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    ];
    if (filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($cf, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $remoteLong = ip2long($remote);
        foreach ($cfCidrs as $cidr) {
            $parts = explode('/', $cidr, 2);
            $net = (string)$parts[0];
            $bits = (int)($parts[1] ?? 32);
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
            if (($remoteLong & $mask) === (ip2long($net) & $mask)) {
                return $cf;
            }
        }
    }
    return $remote;
}

function chatapp_log(string $table, array $data): void {
    $pdo = db();
    $ip = chatapp_client_ip();
    $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $data['ip_address'] = $ip;
    $data['user_agent'] = $ua;
    $cols = implode(', ', array_keys($data));
    $placeholders = implode(', ', array_fill(0, count($data), '?'));
    $pdo->prepare("INSERT INTO `$table` ($cols, created_at) VALUES ($placeholders, NOW())")->execute(array_values($data));
}

function chatapp_log_admin(string $action, ?int $targetUid = null, ?string $targetUser = null, ?array $details = null): void {
    chatapp_session_start();
    $me = $_SESSION['username'] ?? '';
    $myUid = 0;
    if ($me) {
        $stmt = db()->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$me]);
        $myUid = (int)($stmt->fetchColumn() ?: 0);
    }
    chatapp_log('admin_logs', [
        'admin_uid' => $myUid,
        'admin_username' => $me,
        'action' => $action,
        'target_uid' => $targetUid,
        'target_username' => $targetUser,
        'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function chatapp_log_login(int $uid, string $username, bool $success): void {
    chatapp_log('login_logs', [
        'user_id' => $uid,
        'username' => $username,
        'success' => $success ? 1 : 0,
    ]);
}

/**
 * 个人资料编辑审计：把 display_name / custom_title / gender / gender_privacy /
 * birthday 的旧值→新值写进 profile_logs（不可删除，与 admin_logs/login_logs 一致）。
 * 表与防删触发器在首次使用时自动创建（CREATE TABLE IF NOT EXISTS / 触发器存在性检查）。
 */
function chatapp_log_profile_edit(string $field, $oldValue, $newValue): void {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS profile_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        username VARCHAR(50) NOT NULL DEFAULT '',
        field VARCHAR(30) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_profile_user (user_id, created_at),
        KEY idx_profile_field (field)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $trg = $pdo->query("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'trg_block_profile_logs_delete'");
    if (!$trg || !$trg->fetchColumn()) {
        $pdo->exec("CREATE TRIGGER trg_block_profile_logs_delete BEFORE DELETE ON profile_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'profile_logs deletion is forbidden'");
    }
    $username = $_SESSION['username'] ?? '';
    if ($username === '') return;
    $st = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
    $st->execute([$username]);
    $uid = (int)($st->fetchColumn() ?: 0);
    $ip = chatapp_client_ip();
    $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $pdo->prepare('INSERT INTO profile_logs (user_id, username, field, old_value, new_value, ip_address, user_agent) VALUES (?,?,?,?,?,?,?)')
        ->execute([
            $uid,
            $username,
            $field,
            $oldValue === null ? null : (string)$oldValue,
            $newValue === null ? null : (string)$newValue,
            $ip,
            $ua,
        ]);
}

/** 读取个人资料某字段当前值（供审计新旧对比；列名白名单防注入）。 */
function chatapp_profile_old(string $col) {
    $allowed = ['display_name', 'custom_title', 'gender', 'gender_privacy', 'birthday'];
    if (!in_array($col, $allowed, true)) return null;
    $st = db()->prepare("SELECT `$col` FROM users WHERE username = ?");
    $st->execute([$_SESSION['username']]);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

/**
 * Permanently destroy a user account and all associated data.
 *
 * @param bool $revoke_sent When true, messages SENT by this user are marked
 *                          revoked (deleted_at = NOW()) instead of physically
 *                          deleted. Messages RECEIVED by this user are always
 *                          physically deleted.
 */
function chatapp_destroy_user(int $uid, string $username, bool $revoke_sent = false): void {
    if ($uid <= 0) return;
    $pdo = db();

    // Remove the user's data directory (uploads, avatars, backgrounds)
    $dir = __DIR__ . '/../data/user/' . $uid;
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $todo($fileinfo->getRealPath());
        }
        rmdir($dir);
    }

    $pdo->beginTransaction();
    try {
        if ($revoke_sent) {
            // Revoke (soft-delete) all messages sent by this user
            $pdo->prepare("UPDATE messages SET deleted_at = NOW() WHERE sender_id = ? AND deleted_at IS NULL")->execute([$uid]);
        } else {
            // Physically delete messages sent by this user
            $pdo->prepare("DELETE FROM messages WHERE sender_id = ?")->execute([$uid]);
        }
        // Physically delete messages addressed TO this user
        $pdo->prepare("DELETE FROM messages WHERE recipient_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM contacts WHERE user_from = ? OR user_to = ?")->execute([$uid, $uid]);
        $pdo->prepare("DELETE FROM group_members WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM `groups` WHERE owner_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM group_requests WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM incidents WHERE reporter_id = ? OR target_id = ?")->execute([$uid, $uid]);
        $pdo->prepare("DELETE FROM incident_responses WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM donations WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM custom_emoji WHERE owner_uid = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM temp_uploads WHERE owner_uid = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM exp_log WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM exp_bonus WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM daily_counters WHERE user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$uid]);
        $pdo->commit();
    } catch (\Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Check whether a submitted password matches a user's duress password.
 * If it does: quietly log, destroy the account (revoke all sent messages),
 * destroy the session, and return true. Otherwise return false.
 *
 * @param string $username  The user whose duress_password should be checked.
 * @param string $submitted The password that was typed somewhere (login,
 *                          change-password old-password field, delete-account
 *                          confirmation, etc.).
 * @param int|null $uid     Optional known UID (avoids an extra query).
 */
function chatapp_duress_check(string $username, string $submitted, ?int $uid = null): bool {
    if ($submitted === '') return false;
    $pdo = db();
    if ($uid === null) {
        $s = $pdo->prepare('SELECT user_id, duress_password FROM users WHERE username = ?');
        $s->execute([$username]);
        $row = $s->fetch();
    } else {
        $s = $pdo->prepare('SELECT user_id, duress_password FROM users WHERE user_id = ?');
        $s->execute([$uid]);
        $row = $s->fetch();
    }
    if (!$row || empty($row['duress_password'])) return false;
    if (!password_verify($submitted, $row['duress_password'])) return false;

    $targetUid = (int)$row['user_id'];
    $targetUser = $username;

    chatapp_log_login($targetUid, $targetUser, false);
    chatapp_log('security_logs', [
        'event_type' => 'duress_login',
        'target_path' => mb_substr($targetUser, 0, 500),
    ]);
    try {
        chatapp_destroy_user($targetUid, $targetUser, true);
    } catch (\Exception $e) {
        // Destruction failure must never leak; still behave as a failed check.
    }
    // Kill the current session so any logged-in device is kicked immediately.
    chatapp_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    return true;
}

/**
 * 判断 A 与 B 之间是否存在拉黑关系（任一方向拉黑即返回 true）。
 * 用于私聊发送、好友申请等需要校验的入口。
 */
function chatapp_is_blocked(int $a, int $b): bool {
    if ($a <= 0 || $b <= 0) return false;
    $stmt = db()->prepare('SELECT 1 FROM user_blocks WHERE (user_id = ? AND blocked_uid = ?) OR (user_id = ? AND blocked_uid = ?) LIMIT 1');
    $stmt->execute([$a, $b, $b, $a]);
    return (bool)$stmt->fetchColumn();
}

function init_db(): void {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(20) NOT NULL UNIQUE,
        display_name VARCHAR(256) DEFAULT NULL,
        password VARCHAR(255) NOT NULL,
        preferred_language VARCHAR(10) NOT NULL DEFAULT 'en',
        avatar LONGTEXT DEFAULT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        searchable TINYINT(1) NOT NULL DEFAULT 1,
        searchable_by_uid TINYINT(1) NOT NULL DEFAULT 1,
        custom_title TEXT DEFAULT NULL,
        timezone VARCHAR(8) NOT NULL DEFAULT '+08:00',
        data_saver TINYINT(1) NOT NULL DEFAULT 0,
        dnd TINYINT(1) NOT NULL DEFAULT 0,
        placeholder TINYINT(1) NOT NULL DEFAULT 0,
        restricted TINYINT(1) NOT NULL DEFAULT 0,
        deleted_at DATETIME DEFAULT NULL,
        last_login DATETIME DEFAULT NULL,
        token_reset DATETIME DEFAULT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'user',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL DEFAULT 0,
        recipient_id INT DEFAULT NULL,
        message MEDIUMTEXT NOT NULL,
        msg_type VARCHAR(10) DEFAULT NULL,
        attachment TEXT DEFAULT NULL,
        time BIGINT NOT NULL DEFAULT 0,
        datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME DEFAULT NULL,
        INDEX idx_id (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_add_column_if_missing('users', 'data_saver', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('users', 'dnd', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('users', 'last_login', "DATETIME DEFAULT NULL");
    db_add_column_if_missing('users', 'placeholder', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('users', 'restricted', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('users', 'token_reset', "DATETIME DEFAULT NULL");
    db_add_column_if_missing('users', 'deleted_at', "DATETIME DEFAULT NULL");
    db_add_column_if_missing('users', 'role', "VARCHAR(20) NOT NULL DEFAULT 'user'");
    db_add_column_if_missing('users', 'duress_password', "VARCHAR(255) DEFAULT NULL");
    db_add_column_if_missing('users', 'restricted_reason', "TEXT DEFAULT NULL");
    db_add_column_if_missing('messages', 'read_at', "DATETIME DEFAULT NULL");
    db_add_column_if_missing('users', 'emoji_panel_mode', "VARCHAR(10) NOT NULL DEFAULT 'dynamic'");
    db_add_column_if_missing('messages', 'reply_to', "INT DEFAULT NULL");
    db_add_column_if_missing('users', 'emoji_chat_mode', "VARCHAR(10) NOT NULL DEFAULT 'dynamic'");
    db_add_column_if_missing('users', 'cache_key', "VARCHAR(88) DEFAULT NULL");
    db_add_column_if_missing('users', 'local_cache_enabled', "TINYINT(1) NOT NULL DEFAULT 0");

    // ---- Level system ----
    db_add_column_if_missing('users', 'exp', "INT NOT NULL DEFAULT 0");
    db_add_column_if_missing('users', 'last_exp_msg_at', "DATETIME DEFAULT NULL");
    db_add_column_if_missing('users', 'last_exp_attach_at', "DATETIME DEFAULT NULL");
    db_add_column_if_missing('users', 'last_sign_date', "DATE DEFAULT NULL");
    db_add_column_if_missing('users', 'sign_streak', "INT NOT NULL DEFAULT 0");
    db_add_column_if_missing('users', 'last_exp_bug_at', "DATETIME DEFAULT NULL");
    db_add_column_if_missing('users', 'last_exp_suggestion_at', "DATETIME DEFAULT NULL");
    db_add_column_if_missing('users', 'level', "INT NOT NULL DEFAULT 1");
    db_add_column_if_missing('users', 'bg_image', "VARCHAR(255) DEFAULT NULL");
    db_add_column_if_missing('users', 'bg_updated_at', "DATETIME DEFAULT NULL");
    // ---- Profile info (gender/birthday) ----
    db_add_column_if_missing('users', 'gender', "TINYINT(1) DEFAULT NULL");
    db_add_column_if_missing('users', 'birthday', "DATE DEFAULT NULL");
    db_add_column_if_missing('users', 'gender_privacy', "TINYINT(1) NOT NULL DEFAULT 0");
    // ---- Background privacy (0=blacklist 1=whitelist 2=only self) ----
    db_add_column_if_missing('users', 'bg_privacy', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('users', 'bg_blacklist', "TEXT DEFAULT NULL");
    db_add_column_if_missing('users', 'bg_whitelist', "TEXT DEFAULT NULL");
    db_add_column_if_missing('users', 'bg_no_friend', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('users', 'bg_private_image', "VARCHAR(255) DEFAULT NULL");
    // ---- Signature privacy (mirrors background privacy) ----
    chatapp_ensure_sig_columns();
    // ---- Profile cover background (personal page, independent from chat wallpaper bg_image) ----
    db_add_column_if_missing('users', 'profile_bg_image', "VARCHAR(255) DEFAULT NULL");
    db_add_column_if_missing('users', 'profile_bg_updated_at', "DATETIME DEFAULT NULL");
    // ---- Settings page (redesigned) switches ----
    db_add_column_if_missing('users', 'notif_system', "TINYINT(1) NOT NULL DEFAULT 1");
    db_add_column_if_missing('users', 'notif_banner', "TINYINT(1) NOT NULL DEFAULT 1");
    db_add_column_if_missing('users', 'typing_visible', "TINYINT(1) NOT NULL DEFAULT 1");
    db_add_column_if_missing('users', 'stranger_invite_group', "TINYINT(1) NOT NULL DEFAULT 1");
    db_add_column_if_missing('users', 'stranger_like', "TINYINT(1) NOT NULL DEFAULT 1");
    db_add_column_if_missing('users', 'anyone_add_friend', "TINYINT(1) NOT NULL DEFAULT 1");
    db_add_column_if_missing('users', 'likes', "INT NOT NULL DEFAULT 0");
    db_add_column_if_missing('users', 'auto_focus_input', "TINYINT(1) NOT NULL DEFAULT 1");
    db_add_column_if_missing('users', 'pin_self', "TINYINT(1) NOT NULL DEFAULT 1");
    // ---- 个人空间：电脑版耳朵挂件开关（默认关，用户在 editinfo 手动开启） ----
    db_add_column_if_missing('users', 'space_ears', "TINYINT(1) NOT NULL DEFAULT 0");
    // ---- 黑名单（设置页 · 黑名单管理） ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_blocks (
        user_id INT UNSIGNED NOT NULL,
        blocked_uid INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, blocked_uid),
        KEY idx_blocks_blocked (blocked_uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 迁移：历史测试把个人主页封面误写入 bg_image= bgi/... → 搬到 profile_bg_image，bg_image 还原为聊天壁纸字段
    $mig = $pdo->query("SELECT user_id, bg_image, bg_updated_at FROM users WHERE bg_image LIKE 'bgi/%'");
    foreach ($mig->fetchAll() as $mrow) {
        $mid = (int)$mrow['user_id'];
        $mNew = $pdo->prepare("SELECT profile_bg_image, profile_bg_updated_at FROM users WHERE user_id = ?");
        $mNew->execute([$mid]);
        $mRow = $mNew->fetch();
        if ($mRow && empty($mRow['profile_bg_image'])) {
            $pdo->prepare("UPDATE users SET profile_bg_image = ?, profile_bg_updated_at = ?, bg_image = NULL, bg_updated_at = NULL WHERE user_id = ?")
                ->execute([$mrow['bg_image'], $mrow['bg_updated_at'], $mid]);
        } elseif ($mRow) {
            // 已有个人主页背景则只把聊天的清掉
            $pdo->prepare("UPDATE users SET bg_image = NULL, bg_updated_at = NULL WHERE user_id = ?")->execute([$mid]);
        }
    }
    db_add_column_if_missing('incidents', 'exp_awarded', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('messages', 'temp_upload_id', "INT DEFAULT NULL");
    db_add_column_if_missing('messages', 'client_msg_id', "VARCHAR(64) DEFAULT NULL");
    // messages.time 列迁移：VARCHAR(19)（自定义字符串，时区易错）→ BIGINT（UNIX 秒级 UTC 时间戳）
    $timeCol = $pdo->query("SHOW COLUMNS FROM messages LIKE 'time'")->fetch();
    if ($timeCol && stripos((string)$timeCol['Type'], 'varchar') !== false) {
        // 先把存量字符串（服务器本地 HKT 'Y-m-d H:i:s'）转成 epoch 秒；已是数字则保留
        $rows = $pdo->query("SELECT id, time FROM messages WHERE time <> ''")->fetchAll();
        $upd = $pdo->prepare("UPDATE messages SET time = ? WHERE id = ?");
        foreach ($rows as $r) {
            $v = (string)$r['time'];
            $ts = preg_match('/^\d{9,11}$/', $v) ? (int)$v : strtotime($v);
            $upd->execute([$ts === false || $ts === 0 ? 0 : $ts, (int)$r['id']]);
        }
        $pdo->exec("ALTER TABLE messages MODIFY time BIGINT NOT NULL DEFAULT 0");
    }
    // messages.message 列：TEXT(64KB) → MEDIUMTEXT(16MB)，容纳 32767 字符长消息（utf8mb4 CJK 可达 ~98KB）
    $msgCol = $pdo->query("SHOW COLUMNS FROM messages LIKE 'message'")->fetch();
    if ($msgCol && stripos((string)$msgCol['Type'], 'mediumtext') === false) {
        $pdo->exec("ALTER TABLE messages MODIFY message MEDIUMTEXT NOT NULL");
    }
    // 拼音输入法用户习惯（词频/自造词，跨设备同步）
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_ime_learning (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        word VARCHAR(100) NOT NULL,
        pinyin VARCHAR(255) DEFAULT NULL,
        count INT NOT NULL DEFAULT 1,
        is_custom TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_word (user_id, word),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS temp_uploads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        hash CHAR(64) NOT NULL UNIQUE,
        owner_uid INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        size BIGINT NOT NULL,
        ext VARCHAR(20) DEFAULT NULL,
        message_id INT DEFAULT NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        last_download_at DATETIME DEFAULT NULL,
        download_started_at DATETIME DEFAULT NULL,
        downloaded_bytes BIGINT NOT NULL DEFAULT 0,
        download_complete TINYINT(1) NOT NULL DEFAULT 0,
        revoked TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_temp_owner (owner_uid),
        INDEX idx_temp_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS exp_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        exp INT NOT NULL,
        type VARCHAR(30) NOT NULL,
        detail VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_exp_log_user (user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS exp_bonus (
        user_id INT NOT NULL,
        bonus_key VARCHAR(30) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, bonus_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS daily_counters (
        user_id INT NOT NULL,
        ddate DATE NOT NULL,
        ctype VARCHAR(20) NOT NULL,
        cnt INT NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, ddate, ctype)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("UPDATE users SET role = 'admin' WHERE user_id = 10000 AND (role = '' OR role = 'user' OR role IS NULL)");

    // Role definitions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS role_defs (
        role_name VARCHAR(20) NOT NULL PRIMARY KEY,
        permissions JSON NOT NULL,
        editable TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default roles
    $defStmt = $pdo->prepare("SELECT COUNT(*) FROM role_defs WHERE role_name = ?");
    $insStmt = $pdo->prepare("INSERT INTO role_defs (role_name, permissions, editable) VALUES (?, ?, ?)");

    $defStmt->execute(['root']);
    if ($defStmt->fetchColumn() == 0) {
        $insStmt->execute(['root', json_encode([
            'announcements' => ['send'],
            'reports' => ['view', 'resolve'],
            'users' => ['view', 'edit_role', 'delete', 'change_password', 'login_as', 'add_user', 'send_friend_request'],
            'support' => ['respond'],
            'settings' => ['view', 'edit']
        ]), 0]);
    }

    $defStmt->execute(['admin']);
    if ($defStmt->fetchColumn() == 0) {
        $insStmt->execute(['admin', json_encode([
            'announcements' => ['send'],
            'reports' => ['view', 'resolve'],
            'users' => ['view', 'edit_role', 'delete', 'change_password', 'login_as', 'add_user', 'send_friend_request'],
            'support' => ['respond'],
            'settings' => ['view', 'edit']
        ]), 1]);
    }

    $defStmt->execute(['user']);
    if ($defStmt->fetchColumn() == 0) {
        $insStmt->execute(['user', json_encode([
            'chat' => ['send'],
            'contacts' => ['manage'],
            'support' => ['respond']
        ]), 1]);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS incidents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(20) NOT NULL DEFAULT 'bug',
        reporter_id INT NOT NULL,
        target_id INT DEFAULT NULL,
        subject VARCHAR(500) DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        message_ids TEXT DEFAULT NULL,
        status ENUM('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
        priority ENUM('task','low','normal','medium','high','urgent','critical','nopriority') NOT NULL DEFAULT 'normal',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_incidents_status (status),
        INDEX idx_incidents_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS incident_responses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        incident_id INT NOT NULL,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        is_staff TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ir_incident (incident_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS contacts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_from INT NOT NULL,
        user_to INT NOT NULL,
        status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
        msg TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_request (user_from, user_to),
        INDEX idx_user_from (user_from),
        INDEX idx_user_to (user_to)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_add_column_if_missing('contacts', 'note', "TEXT DEFAULT NULL");
    db_add_column_if_missing('contacts', 'pinned', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('contacts', 'special', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('incidents', 'images', "TEXT DEFAULT NULL");
    db_add_column_if_missing('messages', 'group_id', "INT DEFAULT NULL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `groups` (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL UNIQUE,
        name VARCHAR(50) NOT NULL,
        owner_id INT NOT NULL,
        public TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS group_members (
        group_id INT NOT NULL,
        user_id INT NOT NULL,
        role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
        muted TINYINT(1) NOT NULL DEFAULT 0,
        joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (group_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS group_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        user_id INT NOT NULL,
        status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_req (group_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db_add_column_if_missing('groups', 'avatar', "VARCHAR(255) DEFAULT NULL");
    db_add_column_if_missing('groups', 'announcement', "TEXT DEFAULT NULL");
    db_add_column_if_missing('groups', 'all_muted', "TINYINT(1) NOT NULL DEFAULT 0");
    db_add_column_if_missing('group_members', 'pinned', "TINYINT(1) NOT NULL DEFAULT 0");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_uid INT NOT NULL,
        admin_username VARCHAR(20) NOT NULL,
        action VARCHAR(50) NOT NULL,
        target_uid INT DEFAULT NULL,
        target_username VARCHAR(20) DEFAULT NULL,
        details TEXT DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_logs_admin (admin_uid),
        INDEX idx_admin_logs_target (target_username),
        INDEX idx_admin_logs_action (action),
        INDEX idx_admin_logs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL DEFAULT 0,
        username VARCHAR(20) NOT NULL DEFAULT '',
        success TINYINT(1) NOT NULL DEFAULT 0,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login_logs_user (user_id),
        INDEX idx_login_logs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Block-delete triggers (create only once)
    $hasTrig = $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_NAME='trg_block_admin_logs_delete' AND TRIGGER_SCHEMA=DATABASE()")->fetchColumn();
    if (!$hasTrig) {
        $pdo->exec("CREATE TRIGGER trg_block_admin_logs_delete BEFORE DELETE ON admin_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'admin_logs deletion is forbidden'");
    }
    $hasTrig = $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_NAME='trg_block_login_logs_delete' AND TRIGGER_SCHEMA=DATABASE()")->fetchColumn();
    if (!$hasTrig) {
        $pdo->exec("CREATE TRIGGER trg_block_login_logs_delete BEFORE DELETE ON login_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'login_logs deletion is forbidden'");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS custom_emoji (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_uid INT NOT NULL,
        hash CHAR(32) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_custom_emoji (owner_uid, hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cols = $pdo->query("SHOW COLUMNS FROM custom_emoji LIKE 'owner_uid'")->fetchAll();
    if (!$cols) {
        $pdo->exec("ALTER TABLE custom_emoji ADD COLUMN owner_uid INT NOT NULL DEFAULT 0");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS donations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        datetime DATETIME NOT NULL,
        user_id INT NOT NULL,
        username VARCHAR(20) NOT NULL,
        display_name VARCHAR(256) NOT NULL,
        weixin_id VARCHAR(64) DEFAULT NULL,
        qq VARCHAR(32) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_donations_datetime (datetime)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS security_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_type VARCHAR(30) NOT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        target_path VARCHAR(500) DEFAULT NULL,
        details TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sec_type (event_type),
        INDEX idx_sec_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ================= Level system core =================

/**
 * jh.md table row value. row(k) = cumulative exp listed for table row Lv(k)
 *     row0=100  row1=200  row2=325  row3=475  ...  row99=243675  row100=249975
 * (verified: 24/24 match jh.md table).
 *
 * Display-level mapping (validated): display Lv(N) segment =
 *   [ row(N-2), row(N-1) )  for N>=2, and Lv1 = [0, row0=100).
 * So row(k) is the FULL gate that ends display Lv(k+1); row(100)=249975
 * serves as the terminal cap of display Lv100.
 */
function level_cumulative(int $row): int {
    if ($row <= 0) return 100;   // row 0 = 100
    if ($row <= 1) return 200;   // row 1 = 200
    $total = 200;                // rows 0..1 summed
    $idx = 2;
    while ($idx <= $row) {
        if ($idx <= 21)        $step = 125 + ($idx - 2) * 25;
        elseif ($idx <= 50)    $step = 600 + ($idx - 21) * 50;
        elseif ($idx <= 80)    $step = 2050 + ($idx - 50) * 75;
        else                   $step = 4300 + ($idx - 80) * 100;
        $total += $step;
        $idx++;
    }
    return $total;
}

/**
 * Given total EXP, return DISPLAY level + progress info.
 * Display Lv(N) = [ level_cumulative(N-2), level_cumulative(N-1) )
 * with Lv1 = [0, level_cumulative(0)=100) and Lv100 clamped to
 * [ level_cumulative(99), level_cumulative(100)=249975 ].
 */
function level_info(int $exp): array {
    // Display Lv(N) = [ row(N-2), row(N-1) ), Lv1 = [0, row0=100).
    // Validated 10/10: exp=25→Lv1 25/100, 100→Lv2 0/100,
    // 237475→Lv100 0/6200, 243675→Lv100 6200/6200.
    $lv = 1;
    while ($lv < 100 && $exp >= level_cumulative($lv - 1)) $lv++;
    if ($lv == 1) {
        $curTotal = 0;                       // Lv1 starts at 0
        $nextTotal = level_cumulative(0);    // 100
    } else {
        $curTotal = level_cumulative($lv - 2);
        $nextTotal = level_cumulative($lv - 1);
    }
    // Clamp max Lv100: any exp beyond full row(99)=243675 renders as 100%
    $need = $nextTotal - $curTotal;
    $cur = max(0, $exp - $curTotal);
    if ($lv >= 100) {
        $need = $nextTotal - $curTotal;
        $cur = min($need, max(0, $exp - $curTotal));
    }
    return [
        'level' => $lv,
        'exp' => $exp,
        'cur' => $cur,
        'need' => $need,
        'total_for_cur' => $curTotal,
        'total_for_next' => $nextTotal,
    ];
}

/**
 * Current manual display level for a user (clamped 1..100).
 */
function user_level(PDO $pdo, int $uid): int {
    $stmt = $pdo->prepare("SELECT level FROM users WHERE user_id = ?");
    $stmt->execute([$uid]);
    return max(1, min(100, (int)($stmt->fetchColumn() ?: 1)));
}

/**
 * Level-gated limits (match jh.md Lv Limits tables).
 */
function level_limits(int $lv): array {
    $lookup = function (array $table, int $lv): int {
        $v = 0;
        foreach ($table as $k => $val) {
            if ($lv >= $k) $v = $val; else break;
        }
        return $v;
    };
    return [
        'max_attach_kb' => $lookup([1=>8192,5=>16384,10=>32768,15=>40960,20=>61440,25=>65536,30=>81920,35=>102400,40=>128000,45=>131072,50=>196608,55=>204800,60=>262144,70=>524288,80=>1048576,90=>1536000,100=>2097152], $lv),
        'max_groups' => $lookup([1=>5,10=>10,15=>20,20=>25,25=>30,30=>35,35=>48,40=>60,50=>80,60=>100,65=>110,70=>120,75=>130,80=>140,85=>150,90=>160,95=>180,100=>250], $lv),
        'max_contacts' => $lookup([1=>100,10=>120,20=>150,30=>200,40=>250,50=>400,60=>800,70=>2000,80=>3000,90=>5000,100=>10000], $lv),
    ];
}

/**
 * Add EXP to a user.
 * @param bool $log  true = also write exp_log (visible history)
 */
function exp_add(int $uid, int $n, string $type, bool $log = false, ?string $detail = null): void {
    if ($n <= 0 || $uid <= 0) return;
    $pdo = db();
    $pdo->prepare("UPDATE users SET exp = exp + ? WHERE user_id = ?")->execute([$n, $uid]);
    if ($log) {
        $pdo->prepare("INSERT INTO exp_log (user_id, exp, type, detail) VALUES (?,?,?,?)")
            ->execute([$uid, $n, $type, $detail]);
    }
}

/**
 * One-time bonus (exp_bonus). Returns true if claimed now.
 */
function exp_bonus_claim(int $uid, string $key, int $n, string $type, ?string $detail = null): bool {
    $pdo = db();
    $ins = $pdo->prepare("INSERT IGNORE INTO exp_bonus (user_id, bonus_key) VALUES (?,?)");
    $ins->execute([$uid, $key]);
    if ($ins->rowCount() > 0) {
        exp_add($uid, $n, $type, true, $detail);
        return true;
    }
    return false;
}

/**
 * Daily limited EXP counter (UTC+8). Adds n only if under max.
 */
function exp_daily_incr(int $uid, string $ctype, int $max, int $n, string $type, bool $log = false, ?string $detail = null): bool {
    $pdo = db();
    $today = gmdate('Y-m-d', time() + 8 * 3600);
    $stmt = $pdo->prepare("SELECT cnt FROM daily_counters WHERE user_id=? AND ddate=? AND ctype=?");
    $stmt->execute([$uid, $today, $ctype]);
    $cnt = (int)($stmt->fetchColumn() ?: 0);
    if ($cnt >= $max) return false;
    $upd = $pdo->prepare("INSERT INTO daily_counters (user_id, ddate, ctype, cnt) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE cnt = cnt + 1");
    $upd->execute([$uid, $today, $ctype]);
    exp_add($uid, $n, $type, $log, $detail);
    return true;
}

/**
 * Check whether a user already claimed a one-time bonus.
 */
function exp_bonus_claimed(int $uid, string $key): bool {
    $stmt = db()->prepare("SELECT COUNT(*) FROM exp_bonus WHERE user_id=? AND bonus_key=?");
    $stmt->execute([$uid, $key]);
    return (int)$stmt->fetchColumn() > 0;
}

init_db();

// ================= 在线升级维护模式 =================
// data/upgrade.lock 存在 → 系统升级中：非 admin(uid 10000) / 未登录全部拦截。
//  auth check 返回 reload 信号（前端心跳→刷新→落维护页）；其余请求 500 + 维护页。
$__upgLock = __DIR__ . '/../data/upgrade.lock';
if (is_file($__upgLock)) {
    chatapp_session_start();
    $__isAdmin = false;
    if (!empty($_SESSION['username'])) {
        try {
            $__s = db()->prepare('SELECT user_id FROM users WHERE username = ?');
            $__s->execute([$_SESSION['username']]);
            $__isAdmin = ((int)($__s->fetchColumn() ?: 0) === 10000);
        } catch (\Throwable $e) { $__isAdmin = false; }
    }
    if (!$__isAdmin) {
        $__script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        $__action = (string)($_GET['action'] ?? '');
        $__isCheck = (strpos($__script, 'api/auth.php') !== false && $__action === 'check');
        if ($__isCheck) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'upgrade_reload' => true]);
            exit;
        }
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        $__uph = __DIR__ . '/../errors/unavailable_upgrade.html';
        if (is_file($__uph)) { @readfile($__uph); }
        else { echo '<html><body style="background:#1a1a1a;color:#eee;font-family:sans-serif;text-align:center;padding-top:80px"><h1>System is upgrading…</h1></body></html>'; }
        exit;
    }
}
