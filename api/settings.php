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

    case 'save_gender':
        $g = $_POST['gender'] ?? '';
        if ($g === '') {
            $gv = null; // 未设置
        } elseif ($g === '0' || $g === '1') {
            $gv = (int)$g; // 0=女 1=男
        } else {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        db()->prepare('UPDATE users SET gender = ? WHERE username = ?')->execute([$gv, $_SESSION['username']]);
        echo json_encode(['success' => true]);
        break;

    case 'save_gender_privacy':
        $p = $_POST['privacy'] ?? '';
        if ($p !== '0' && $p !== '1' && $p !== '2') {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        // 0=所有人可见 1=仅好友可见 2=所有人不可见
        db()->prepare('UPDATE users SET gender_privacy = ? WHERE username = ?')->execute([(int)$p, $_SESSION['username']]);
        echo json_encode(['success' => true]);
        break;

    case 'save_birthday':
        $b = trim($_POST['birthday'] ?? '');
        if ($b === '') {
            $bv = null; // 未设置
        } else {
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $b, $m)) {
                echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
            }
            $by = (int)$m[1]; $bm = (int)$m[2]; $bd = (int)$m[3];
            if ($by < 1900 || $by > 2026 || $bm < 1 || $bm > 12 || $bd < 1 || $bd > 31 || !checkdate($bm, $bd, $by)) {
                echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
            }
            $bv = sprintf('%04d-%02d-%02d', $by, $bm, $bd);
        }
        db()->prepare('UPDATE users SET birthday = ? WHERE username = ?')->execute([$bv, $_SESSION['username']]);
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
        // 聊天页面壁纸（原版协议）：仅图片，存 data/user/<uid>/bg.<fmt>，DB bg_image=user/<uid>/bg.<fmt>
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

    // ================= Background privacy (blacklist/whitelist/only-self) =================

    case 'get_bg_privacy':
        $stmt = db()->prepare('SELECT bg_blacklist, bg_whitelist, bg_privacy, bg_no_friend, bg_private_image FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['success' => false]); exit; }
        $black = $row['bg_blacklist'] ? json_decode($row['bg_blacklist'], true) : [];
        $white = $row['bg_whitelist'] ? json_decode($row['bg_whitelist'], true) : [];
        if (!is_array($black)) $black = [];
        if (!is_array($white)) $white = [];
        echo json_encode([
            'success' => true,
            'privacy' => (int)$row['bg_privacy'],
            'no_friend' => (int)$row['bg_no_friend'],
            'blacklist' => array_values($black),
            'whitelist' => array_values($white),
            'private_image' => $row['bg_private_image'] ?? '',
        ]);
        break;

    case 'upload_bg_private':
        // 用户自行上传「不可见时背景图」→ 存 data/bgi/<uid>.private.{png|mp4|webm}（与主背景分开）
        $pdo = db();

        // ---- 优先支持原文件上传（multipart $_FILES['file']），可获真实上传进度 ----
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $raw = file_get_contents($_FILES['file']['tmp_name']);
            if ($raw === false || $raw === '') { echo json_encode(['success' => false, 'error' => 'Empty media']); exit; }
            // 由内容实际检测类型
            $type = 'image/png';
            $fmt = 'png';
            if (!@imagecreatefromstring($raw)) {
                if (strlen($raw) > 12 && substr($raw, 4, 4) === 'ftyp') { $type = 'video/mp4'; $fmt = 'mp4'; }
                elseif (strlen($raw) > 4 && substr($raw, 0, 4) === "\x1A\x45\xDF\xA3") { $type = 'video/webm'; $fmt = 'webm'; }
                else { echo json_encode(['success' => false, 'error' => 'Invalid media']); exit; }
            }
            $b64Body = base64_encode($raw);
            $danger = ['PD9waHA=', 'PD89', 'Pz4=', 'PHNjcmlwdA==', 'PC9zY3JpcHQ+', 'amF2YXNjcmlwdDo=', 'ZXZhbCg=', 'c2hlbGxfZXhlYw==', 'c3lzdGVtKA==', 'cGFzc3RocnU=', 'ZXhlYyg='];
            foreach ($danger as $d) {
                if (strpos($b64Body, $d) !== false) { echo json_encode(['success' => false, 'error' => 'Suspicious content']); exit; }
            }
            if (strlen($raw) > 64 * 1024 * 1024) { echo json_encode(['success' => false, 'error' => 'File too large (max 64MB)']); exit; }
            $isVideo = ($type !== 'image/png');
            goto bg_private_save;
        }

        $b64 = $_POST['image'] ?? '';
        if (empty($b64)) { echo json_encode(['success' => false, 'error' => 'No media']); exit; }
        if (!preg_match('/^data:(image\/(png|jpeg|jpg|webp)|video\/(mp4|webm));base64,(.+)$/', $b64, $m)) {
            echo json_encode(['success' => false, 'error' => 'Unsupported format']); exit;
        }
        $type = $m[1]; $fmt = strtolower($m[2]);
        if ($fmt === 'jpeg') $fmt = 'jpg';
        $b64Body = $m[3];
        $danger = ['PD9waHA=', 'PD89', 'Pz4=', 'PHNjcmlwdA==', 'PC9zY3JpcHQ+', 'amF2YXNjcmlwdDo=', 'ZXZhbCg=', 'c2hlbGxfZXhlYw==', 'c3lzdGVtKA==', 'cGFzc3RocnU=', 'ZXhlYyg='];
        foreach ($danger as $d) {
            if (strpos($b64Body, $d) !== false) { echo json_encode(['success' => false, 'error' => 'Suspicious content']); exit; }
        }
        $raw = base64_decode($b64Body);
        if ($raw === false || $raw === '') { echo json_encode(['success' => false, 'error' => 'Empty media']); exit; }
        if (strlen($raw) > 64 * 1024 * 1024) { echo json_encode(['success' => false, 'error' => 'File too large (max 64MB)']); exit; }

        $isVideo = (strpos($type, 'video/') === 0);
        if (!$isVideo) {
            $img = @imagecreatefromstring($raw);
            if (!$img) { echo json_encode(['success' => false, 'error' => 'Invalid image']); exit; }
            imagedestroy($img);
        } else {
            $headOk = false;
            if ($fmt === 'mp4') {
                $headOk = strlen($raw) > 12 && substr($raw, 4, 4) === 'ftyp';
            } elseif ($fmt === 'webm') {
                $headOk = strlen($raw) > 4 && substr($raw, 0, 4) === "\x1A\x45\xDF\xA3";
            }
            if (!$headOk) { echo json_encode(['success' => false, 'error' => 'Invalid video']); exit; }
        }

        // 文件上传（$_FILES）与 base64 均汇流至此进行存储
        bg_private_save:
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $uid = (int)$stmt->fetchColumn();
        if (!$uid) { echo json_encode(['success' => false]); exit; }
        $dir = __DIR__ . '/../data/bgi';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        // 清理旧的 private 变体（png/jpg/mp4/webm）
        foreach (glob($dir . '/' . $uid . '.private.png') as $old) @unlink($old);
        foreach (glob($dir . '/' . $uid . '.private.mp4') as $old) @unlink($old);
        foreach (glob($dir . '/' . $uid . '.private.webm') as $old) @unlink($old);
        $ext = $isVideo ? $fmt : 'png';
        $file = $dir . '/' . $uid . '.private.' . $ext;
        if ($isVideo) {
            file_put_contents($file, $raw);
            chmod($file, 0644);
        } else {
            $img = imagecreatefromstring($raw);
            if (!$img) { echo json_encode(['success' => false, 'error' => 'Invalid image']); exit; }
            imagepng($img, $file);
            imagedestroy($img);
        }
        $pdo->prepare("UPDATE users SET bg_private_image = ? WHERE username = ?")
            ->execute(['bgi/' . $uid . '.private.' . $ext, $_SESSION['username']]);
        $ver = time();
        $url = '../api/file.php?type=bgi_private&u=' . $uid . '&v=' . $ver;
        echo json_encode(['success' => true, 'url' => $url, 'private_image' => 'bgi/' . $uid . '.private.' . $ext, 'video' => $isVideo]);
        break;

    case 'set_bg_private':
        $img = trim($_POST['private_image'] ?? '');
        // 允许空（清除）、预设名（res/wallpaper/xxx.jpg|png）、或本人 bgi/<uid>.private.{png|mp4|webm}
        $valid = false;
        if ($img === '') {
            $valid = true;
        } elseif (preg_match('/^res\/wallpaper\/[a-zA-Z0-9_-]+\.(jpg|png)$/', $img)) {
            $f = __DIR__ . '/../data/' . $img;
            $valid = file_exists($f);
        } elseif (preg_match('/^bgi\/\d+\.private\.(png|mp4|webm)$/', $img)) {
            $stmt = db()->prepare('SELECT user_id FROM users WHERE username = ?');
            $stmt->execute([$_SESSION['username']]);
            $uid = (int)$stmt->fetchColumn();
            $valid = (strpos($img, 'bgi/' . $uid . '.private.') === 0) && file_exists(__DIR__ . '/../data/' . $img);
        }
        if (!$valid) { echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit; }
        db()->prepare('UPDATE users SET bg_private_image = ? WHERE username = ?')->execute([$img ?: null, $_SESSION['username']]);
        echo json_encode(['success' => true, 'private_image' => $img]);
        break;

    case 'set_bg_privacy':
        $p = $_POST['privacy'] ?? '';
        if ($p !== '0' && $p !== '1' && $p !== '2') {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        // 0=黑名单 1=白名单 2=仅自己能看见
        db()->prepare('UPDATE users SET bg_privacy = ? WHERE username = ?')->execute([(int)$p, $_SESSION['username']]);
        echo json_encode(['success' => true]);
        break;

    case 'set_bg_blacklist':
        $raw = $_POST['blacklist'] ?? '';
        $list = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($list)) { echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit; }
        // 校验仅包含数字 UID
        $clean = [];
        foreach ($list as $v) {
            if (is_numeric($v)) $clean[] = (int)$v;
            else { echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit; }
        }
        $clean = array_values(array_unique($clean));
        $json = $clean ? json_encode($clean) : null;
        db()->prepare('UPDATE users SET bg_blacklist = ? WHERE username = ?')->execute([$json, $_SESSION['username']]);
        echo json_encode(['success' => true, 'blacklist' => $clean]);
        break;

    case 'set_bg_whitelist':
        $raw = $_POST['whitelist'] ?? '';
        $list = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($list)) { echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit; }
        $clean = [];
        foreach ($list as $v) {
            if (is_numeric($v)) $clean[] = (int)$v;
            else { echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit; }
        }
        $clean = array_values(array_unique($clean));
        $json = $clean ? json_encode($clean) : null;
        db()->prepare('UPDATE users SET bg_whitelist = ? WHERE username = ?')->execute([$json, $_SESSION['username']]);
        echo json_encode(['success' => true, 'whitelist' => $clean]);
        break;

    // ================= 个人主页封面背景（独立 profile_bg_image 字段，与聊天壁纸 bg_image 分开） =================

    case 'upload_profile_bg':
        // 支持图片（png/jpeg/jpg/webp 转 PNG）或视频（mp4/webm 存原件），存 data/bgi/<uid>.{png|mp4|webm}
        $pdo = db();
        $b64 = $_POST['image'] ?? '';
        $raw = null;
        $isVideo = false; $fmt = 'png';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $raw = file_get_contents($_FILES['file']['tmp_name']);
            if (@imagecreatefromstring($raw)) { $isVideo = false; $fmt = 'png'; }
            elseif (strlen($raw) > 12 && substr($raw, 4, 4) === 'ftyp') { $isVideo = true; $fmt = 'mp4'; }
            elseif (strlen($raw) > 4 && substr($raw, 0, 4) === "\x1A\x45\xDF\xA3") { $isVideo = true; $fmt = 'webm'; }
            else { echo json_encode(['success' => false, 'error' => 'Invalid media']); exit; }
        } else {
            if (empty($b64) || !preg_match('/^data:(image\/(png|jpeg|jpg|webp)|video\/(mp4|webm));base64,(.+)$/', $b64, $m)) {
                echo json_encode(['success' => false, 'error' => 'Unsupported format']); exit;
            }
            $type = $m[1]; $fmt = strtolower($m[2]); if ($fmt === 'jpeg') $fmt = 'jpg';
            $raw = base64_decode($m[3]);
            $isVideo = (strpos($type, 'video/') === 0);
            if (!$isVideo) { $fmt = 'png'; } // 图片统一存 PNG
        }
        if ($raw === false || $raw === '') { echo json_encode(['success' => false, 'error' => 'Empty media']); exit; }
        if (strlen($raw) > 64 * 1024 * 1024) { echo json_encode(['success' => false, 'error' => 'File too large (max 64MB)']); exit; }
        // 危险串检查
        $b64Body = base64_encode($raw);
        $danger = ['PD9waHA=', 'PD89', 'Pz4=', 'PHNjcmlwdA==', 'PC9zY3JpcHQ+', 'amF2YXNjcmlwdDo=', 'ZXZhbCg=', 'c2hlbGxfZXhlYw==', 'c3lzdGVtKA==', 'cGFzc3RocnU=', 'ZXhlYyg='];
        foreach ($danger as $d) { if (strpos($b64Body, $d) !== false) { echo json_encode(['success' => false, 'error' => 'Suspicious content']); exit; } }

        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $uid = (int)$stmt->fetchColumn();
        if (!$uid) { echo json_encode(['success' => false]); exit; }
        $dir = __DIR__ . '/../data/bgi';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        foreach (glob($dir . '/' . $uid . '.png') as $old) @unlink($old);
        foreach (glob($dir . '/' . $uid . '.mp4') as $old) @unlink($old);
        foreach (glob($dir . '/' . $uid . '.webm') as $old) @unlink($old);
        $ext = $isVideo ? $fmt : 'png';
        $file = $dir . '/' . $uid . '.' . $ext;
        if ($isVideo) {
            file_put_contents($file, $raw); chmod($file, 0644);
        } else {
            $img = @imagecreatefromstring($raw);
            if (!$img) { echo json_encode(['success' => false, 'error' => 'Invalid image']); exit; }
            imagepng($img, $file); imagedestroy($img);
        }
        $pdo->prepare("UPDATE users SET profile_bg_image = ?, profile_bg_updated_at = NOW() WHERE user_id = ?")
            ->execute(['bgi/' . $uid . '.' . $ext, $uid]);
        $ver = time();
        $url = '../api/file.php?type=bgi&u=' . $uid . '&v=' . $ver;
        echo json_encode(['success' => true, 'url' => $url, 'version' => $ver, 'video' => $isVideo]);
        break;

    case 'remove_profile_bg':
        $pdo = db();
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $uid = (int)$stmt->fetchColumn();
        if (!$uid) { echo json_encode(['success' => false]); exit; }
        $dir = __DIR__ . '/../data/bgi';
        if (is_dir($dir)) {
            foreach (glob($dir . '/' . $uid . '.png') as $old) @unlink($old);
            foreach (glob($dir . '/' . $uid . '.mp4') as $old) @unlink($old);
            foreach (glob($dir . '/' . $uid . '.webm') as $old) @unlink($old);
        }
        $pdo->prepare("UPDATE users SET profile_bg_image = NULL, profile_bg_updated_at = NULL WHERE user_id = ?")->execute([$uid]);
        echo json_encode(['success' => true]);
        break;

    case 'get_profile_bg':
        $pdo = db();
        $stmt = $pdo->prepare('SELECT profile_bg_image, profile_bg_updated_at FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $u = $stmt->fetch();
        $url = null; $version = null;
        if ($u && $u['profile_bg_image']) {
            $ver = strtotime($u['profile_bg_updated_at'] ?: date('Y-m-d H:i:s'));
            $url = '../api/file.php?type=bgi&u=' . (int)($pdo->query("SELECT user_id FROM users WHERE username='".$_SESSION['username']."'")->fetchColumn() ?: 0) . '&v=' . $ver;
            $version = $ver;
        }
        echo json_encode(['success' => true, 'url' => $url, 'version' => $version]);
        break;

    default: echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
}
