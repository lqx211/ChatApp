<?php
/**
 * ChatApp - Authentication API (MySQL)
 */

require_once __DIR__ . '/config.php';

chatapp_session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
header('Content-Type: application/json');

// login/register/logout must be POST (check is the only GET read action).
chatapp_read_actions(['check'], $action);

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

    case 'login':
        sleep(1);
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
            $stmt = db()->prepare('SELECT username, display_name FROM users WHERE username = ?');
            $stmt->execute([$_SESSION['username']]);
            $user = $stmt->fetch();
            echo json_encode(['success' => true, 'username' => $user['username'], 'display_name' => $user['display_name']]);
        } else { echo json_encode(['success' => false]); }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'The script may be seriously broken or corrupted as you see this message.']);
}