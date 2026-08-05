<?php
/**
 * ChatApp - Settings API
 */

require_once __DIR__ . '/config.php';

chatapp_session_start();
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
    exit;
}
header('Content-Type: application/json');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'change_password':
        $cp = $_POST['current_password'] ?? '';
        $np = $_POST['new_password'] ?? '';
        if (empty($cp) || empty($np)) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        // Duress: typing the duress password in the OLD-password field → self-destruct
        if (chatapp_duress_check($_SESSION['username'], $cp)) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        $pwError = chatapp_validate_password($np);
        if ($pwError !== null) {
            echo json_encode(['success' => false, 'error' => t($pwError)]); exit;
        }
        // New password must not equal the duress password
        $duressCheck = db()->prepare('SELECT duress_password FROM users WHERE username = ?');
        $duressCheck->execute([$_SESSION['username']]);
        $duressHash = $duressCheck->fetchColumn();
        if (!empty($duressHash) && password_verify($np, $duressHash)) {
            echo json_encode(['success' => false, 'error' => t('msg_duress_new_same')]); exit;
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT password FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($cp, $user['password'])) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        $hash = password_hash($np, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password = ? WHERE username = ?')->execute([$hash, $_SESSION['username']]);
        echo json_encode(['success' => true]);
        break;

    case 'setup_duress':
        $cp = $_POST['current_password'] ?? '';
        $np = trim($_POST['duress_password'] ?? '');
        if (empty($cp)) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        // Duress: typing the duress password as the "current password" in the
        // duress setup window also triggers self-destruct. The New/Confirm
        // duress fields below are only ever STORED, never checked for duress.
        if (chatapp_duress_check($_SESSION['username'], $cp)) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT password, duress_password FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($cp, $user['password'])) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        if ($np === '') {
            // Clear duress password
            $pdo->prepare('UPDATE users SET duress_password = NULL WHERE username = ?')->execute([$_SESSION['username']]);
            echo json_encode(['success' => true, 'cleared' => true]);
            break;
        }
        // Must differ from the normal password (allow any otherwise)
        if (password_verify($np, $user['password'])) {
            echo json_encode(['success' => false, 'error' => 'Duress password must differ from your normal password.']); exit;
        }
        $pdo->prepare('UPDATE users SET duress_password = ? WHERE username = ?')->execute([password_hash($np, PASSWORD_BCRYPT), $_SESSION['username']]);
        echo json_encode(['success' => true]);
        break;

    case 'upload_avatar':
        $b64 = $_POST['avatar'] ?? '';
        if (empty($b64) || !preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,(.+)$/', $b64, $m) || strlen($b64) > 3 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT user_id, avatar FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['success' => false]); exit; }
        $uid = (int)$row['user_id'];
        $wasEmpty = empty($row['avatar']);

        $ext = strtolower($m[1]);
        if ($ext === 'jpeg') $ext = 'jpg';
        $raw = base64_decode($m[2]);
        $dir = __DIR__ . '/../data/pp';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        // Remove old avatar files
        foreach (glob($dir . '/' . $uid . '.*') as $old) @unlink($old);
        $filename = $uid . '.' . $ext;
        file_put_contents($dir . '/' . $filename, $raw);

        $pdo->prepare('UPDATE users SET avatar = ? WHERE username = ?')->execute([$uid . '.' . $ext, $_SESSION['username']]);

        // ---- Level system: first profile photo +20 exp (one-time) ----
        if ($wasEmpty) {
            try {
                if ($uid > 0) exp_bonus_claim($uid, 'profile_photo', 20, 'bonus_avatar', 'first avatar');
            } catch (Exception $e) { /* never break upload */ }
        }

        echo json_encode(['success' => true]);
        break;

    case 'change_language':
        $lang = trim($_POST['language'] ?? 'en');
        if (!in_array($lang, ['en', 'zh', 'zh_egg', 'wyw', 'raw'])) $lang = 'en';
        db()->prepare('UPDATE users SET preferred_language = ? WHERE username = ?')->execute([$lang, $_SESSION['username']]);
        $_SESSION['preferred_language'] = $lang;

        // ---- Level system: first-time use of easter-egg languages ----
        try {
            $uid = (int)(db()->query("SELECT user_id FROM users WHERE username='" . $_SESSION['username'] . "'")->fetchColumn() ?: 0);
            if ($uid > 0) {
                if ($lang === 'zh_egg') exp_bonus_claim($uid, 'zh_egg', 50, 'bonus_zh_egg', 'used zh_egg');
                elseif ($lang === 'wyw') exp_bonus_claim($uid, 'wyw', 25, 'bonus_wyw', 'used wyw');
            }
        } catch (Exception $e) { /* never break language switch */ }

        echo json_encode(['success' => true]);
        break;

    case 'change_display_name':
        $dn = trim(mb_substr($_POST['display_name'] ?? '', 0, 256));
        db()->prepare('UPDATE users SET display_name = ? WHERE username = ?')->execute([$dn ?: null, $_SESSION['username']]);
        echo json_encode(['success' => true]);
        break;

    case 'change_custom_title':
        $title = trim(mb_substr($_POST['custom_title'] ?? '', 0, 100));
        db()->prepare('UPDATE users SET custom_title = ? WHERE username = ?')->execute([$title ?: null, $_SESSION['username']]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_account':
        $password = $_POST['password'] ?? '';
        if (empty($password)) { echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit; }
        // Duress: typing the duress password as delete-account confirmation → self-destruct
        if (chatapp_duress_check($_SESSION['username'], $password)) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        $pdo = db();
        $stmt = $pdo->prepare('SELECT password, user_id FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password'])) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        $mode = $_POST['mode'] ?? 'delete';
        if ($mode === 'revoke') {
            // Revoke all chat records but DO NOT delete the account.
            $uid = (int)$user['user_id'];
            $pdo->prepare("UPDATE messages SET deleted_at = NOW() WHERE sender_id = ? AND deleted_at IS NULL")->execute([$uid]);
            // Also delete messages received by this user so the account has no chat history.
            $pdo->prepare("DELETE FROM messages WHERE recipient_id = ?")->execute([$uid]);
            // Remove uploaded files.
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
            echo json_encode(['success' => true, 'revoked' => true]);
            break;
        }
        // Default: permanently delete account.
        $pdo->prepare('DELETE FROM users WHERE username = ?')->execute([$_SESSION['username']]);
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'save_privacy':
        $s = (int)($_POST['searchable'] ?? 1);
        $u = (int)($_POST['searchable_by_uid'] ?? 1);
        $pdo = db();
        $pdo->prepare('UPDATE users SET searchable = ?, searchable_by_uid = ? WHERE username = ?')
            ->execute([$s, $u, $_SESSION['username']]);
        echo json_encode(['success' => true]);
        break;

    case 'toggle_searchable':
        $pdo = db();
        $stmt = $pdo->prepare('SELECT searchable FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $row = $stmt->fetch();
        $newVal = $row && $row['searchable'] ? 0 : 1;
        $pdo->prepare('UPDATE users SET searchable = ? WHERE username = ?')->execute([$newVal, $_SESSION['username']]);
        echo json_encode(['success' => true, 'searchable' => $newVal]);
        break;

    case 'change_timezone':
        $tz = trim($_POST['timezone'] ?? '');
        if (!preg_match('/^[+-]\d{2}:\d{2}$/', $tz)) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        db()->prepare('UPDATE users SET timezone = ? WHERE username = ?')->execute([$tz, $_SESSION['username']]);
        echo json_encode(['success' => true, 'timezone' => $tz]);
        break;

    case 'toggle_searchable_by_uid':
        $pdo = db();
        $stmt = $pdo->prepare('SELECT searchable_by_uid FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $row = $stmt->fetch();
        $newVal = $row && $row['searchable_by_uid'] ? 0 : 1;
        $pdo->prepare('UPDATE users SET searchable_by_uid = ? WHERE username = ?')->execute([$newVal, $_SESSION['username']]);
        echo json_encode(['success' => true, 'searchable_by_uid' => $newVal]);
        break;

    case 'discover':
        $q = trim($_GET['q'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $pdo = db();

        $where = "WHERE u.searchable = 1 AND u.enabled = 1 AND u.username != ?";
        $params = [$_SESSION['username']];

        if ($q !== '') {
            if (is_numeric($q)) {
                $where .= " AND u.user_id = ?";
                $params[] = (int)$q;
            } else {
                $where .= " AND (u.username LIKE ? OR COALESCE(u.display_name, '') LIKE ?)";
                $params[] = "%$q%";
                $params[] = "%$q%";
            }
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("SELECT u.user_id, u.username, COALESCE(u.display_name, u.username) AS display_name, u.avatar
            FROM users u $where ORDER BY u.user_id ASC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        echo json_encode(['success' => true, 'users' => $users, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        break;

    case 'toggle_data_saver':
        $pdo = db();
        $stmt = $pdo->prepare('SELECT data_saver FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $row = $stmt->fetch();
        $newVal = $row && $row['data_saver'] ? 0 : 1;
        $pdo->prepare('UPDATE users SET data_saver = ? WHERE username = ?')->execute([$newVal, $_SESSION['username']]);
        echo json_encode(['success' => true, 'data_saver' => $newVal]);
        break;

    case 'save_emoji_settings':
        $pm = trim($_POST['panel_mode'] ?? 'dynamic');
        $cm = trim($_POST['chat_mode'] ?? 'dynamic');
        if (!in_array($pm, ['dynamic', 'hover', 'static'])) $pm = 'dynamic';
        if (!in_array($cm, ['dynamic', 'static'])) $cm = 'dynamic';
        db()->prepare('UPDATE users SET emoji_panel_mode = ?, emoji_chat_mode = ? WHERE username = ?')
            ->execute([$pm, $cm, $_SESSION['username']]);
        echo json_encode(['success' => true, 'panel_mode' => $pm, 'chat_mode' => $cm]);
        break;

    case 'toggle_dnd':
        $pdo = db();
        $stmt = $pdo->prepare('SELECT dnd FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $row = $stmt->fetch();
        $newVal = $row && $row['dnd'] ? 0 : 1;
        $pdo->prepare('UPDATE users SET dnd = ? WHERE username = ?')->execute([$newVal, $_SESSION['username']]);
        echo json_encode(['success' => true, 'dnd' => $newVal]);
        break;

    case 'toggle_local_cache':
        $pdo = db();
        $enabled = (int)(($_POST['enabled'] ?? 0) ? 1 : 0);
        $stmt = $pdo->prepare('SELECT user_id, cache_key FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['success' => false]); exit; }
        if (empty($row['cache_key'])) {
            $key = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE users SET cache_key = ? WHERE username = ?')->execute([$key, $_SESSION['username']]);
            $row['cache_key'] = $key;
        }
        $pdo->prepare('UPDATE users SET local_cache_enabled = ? WHERE username = ?')->execute([$enabled, $_SESSION['username']]);
        echo json_encode(['success' => true, 'cache_key' => $row['cache_key'], 'local_cache_enabled' => $enabled]);
        break;

    // ================= Wallpaper (custom background) =================

    case 'upload_background':
        $pdo = db();
        $b64 = $_POST['image'] ?? '';
        if (empty($b64)) { echo json_encode(['success' => false, 'error' => 'No image']); exit; }
        if (!preg_match('/^data:(image\/(png|jpeg|jpg|webp));base64,(.+)$/', $b64, $m)) {
            echo json_encode(['success' => false, 'error' => 'Unsupported format']); exit;
        }
        $fmt = strtolower($m[2]);
        if ($fmt === 'jpeg') $fmt = 'jpg';
        // Reject disguised content (check base64 string, not binary data — binary JPEG/GIF/PNG data may contain bytes that accidentally match text patterns)
        $danger = ['PD9waHA=', 'PD89', 'Pz4=', 'PHNjcmlwdA==', 'PC9zY3JpcHQ+', 'amF2YXNjcmlwdDo=', 'ZXZhbCg=', 'c2hlbGxfZXhlYw==', 'c3lzdGVtKA==', 'cGFzc3RocnU=', 'ZXhlYyg='];
        $b64Body = $m[3];
        foreach ($danger as $d) {
            if (strpos($b64Body, $d) !== false) { echo json_encode(['success' => false, 'error' => 'Suspicious content']); exit; }
        }
        $raw = base64_decode($b64Body);
        if ($raw === false || $raw === '') { echo json_encode(['success' => false, 'error' => 'Empty image']); exit; }
        // 32MB cap
        if (strlen($raw) > 32 * 1024 * 1024) { echo json_encode(['success' => false, 'error' => 'Image too large (max 32MB)']); exit; }
        // Validate real image via GD
        $img = @imagecreatefromstring($raw);
        if (!$img) { echo json_encode(['success' => false, 'error' => 'Invalid image']); exit; }
        imagedestroy($img);

        // Save to data/user/<uid>/bg.<fmt>
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $uid = (int)$stmt->fetchColumn();
        if (!$uid) { echo json_encode(['success' => false]); exit; }
        $dir = __DIR__ . '/../data/user/' . $uid;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        // Remove old bg files
        foreach (glob($dir . '/bg.*') as $old) @unlink($old);
        $file = $dir . '/bg.' . $fmt;
        file_put_contents($file, $raw);
        $pdo->prepare("UPDATE users SET bg_image = ?, bg_updated_at = NOW() WHERE user_id = ?")
            ->execute(['user/' . $uid . '/bg.' . $fmt, $uid]);
        $url = '../api/file.php?u=' . $uid . '&f=bg.' . $fmt . '&v=' . time();
        echo json_encode(['success' => true, 'url' => $url, 'version' => time()]);
        break;

    case 'remove_background':
        $pdo = db();
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $uid = (int)$stmt->fetchColumn();
        if (!$uid) { echo json_encode(['success' => false]); exit; }
        $dir = __DIR__ . '/../data/user/' . $uid;
        foreach (glob($dir . '/bg.*') as $old) @unlink($old);
        $pdo->prepare("UPDATE users SET bg_image = NULL, bg_updated_at = NOW() WHERE user_id = ?")->execute([$uid]);
        echo json_encode(['success' => true]);
        break;

    case 'get_background':
        $pdo = db();
        $stmt = $pdo->prepare('SELECT user_id, bg_image, bg_updated_at FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $u = $stmt->fetch();
        $url = null; $version = null;
        if ($u && $u['bg_image']) {
            $ver = strtotime($u['bg_updated_at'] ?: date('Y-m-d H:i:s'));
            $url = '../api/file.php?f=' . rawurlencode($u['bg_image']) . '&v=' . $ver;
            $version = $ver;
        }
        // Built-in presets from data/res/wallpaper/
        $presets = [];
        $wpDir = __DIR__ . '/../data/res/wallpaper';
        if (is_dir($wpDir)) {
            foreach (glob($wpDir . '/*.jpg') as $f) {
                $presets[] = ['name' => basename($f, '.jpg'), 'url' => '../data/res/wallpaper/' . basename($f)];
            }
            foreach (glob($wpDir . '/*.png') as $f) {
                $presets[] = ['name' => basename($f, '.png'), 'url' => '../data/res/wallpaper/' . basename($f)];
            }
        }
        echo json_encode(['success' => true, 'url' => $url, 'version' => $version, 'presets' => $presets]);
        break;

    case 'set_preset_background':
        // Pick a built-in preset as background (no upload needed)
        $pdo = db();
        $name = trim($_POST['name'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) { echo json_encode(['success' => false]); exit; }
        $f = __DIR__ . '/../data/res/wallpaper/' . $name . '.jpg';
        if (!file_exists($f)) $f = __DIR__ . '/../data/res/wallpaper/' . $name . '.png';
        if (!file_exists($f)) { echo json_encode(['success' => false, 'error' => 'Preset not found']); exit; }
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $uid = (int)$stmt->fetchColumn();
        $pdo->prepare("UPDATE users SET bg_image = ?, bg_updated_at = NOW() WHERE user_id = ?")
            ->execute(['res/wallpaper/' . $name . '.' . pathinfo($f, PATHINFO_EXTENSION), $uid]);
        $ver = time();
        $url = '../data/res/wallpaper/' . $name . '.' . pathinfo($f, PATHINFO_EXTENSION) . '?v=' . $ver;
        echo json_encode(['success' => true, 'url' => $url, 'version' => $ver]);
        break;

    default: echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
}
