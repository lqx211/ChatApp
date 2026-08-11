<?php
/**
 * Simple file serving endpoint
 * Supports: ?u=$userId&f=$filename (user uploads: data/user/$uid/$f)
 *           ?f=path/rel (ticket images: data/ticket/...)
 *           ?type=bgi&u=$userId (background images: data/bgi/$uid.png, with privacy check)
 */
require_once __DIR__ . '/config.php';

chatapp_session_start();
$userId = (int)($_GET['u'] ?? 0);
$originalFile = $_GET['f'] ?? '';
$file = str_replace('\\', '/', $originalFile);
$file = preg_replace('/\.\./', '', $file);

// Reject if any ".." remains after stripping (e.g. "...." -> "..")
if (strpos($file, '..') !== false) {
    chatapp_log('security_logs', [
        'event_type' => 'path_traversal',
        'target_path' => mb_substr($originalFile, 0, 500),
        'details' => 'detected_by: dotdot_check',
    ]);
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid path']);
    exit;
}
$file = ltrim($file, '/');

// ---- Background "hidden fallback" media (data/bgi/<uid>.private.{png|mp4|webm}) ----
// 登录即可读（该图/视频就是设计给隐私不可见者看的替代封面），但同样经 file.php 防猜测路径
if (($_GET['type'] ?? '') === 'bgi_private') {
    if (empty($_SESSION['username'])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $baseDir = realpath(__DIR__ . '/../data/bgi');
    if ($baseDir === false) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'File not found']);
        exit;
    }
    // 依次尝试 private.png / private.mp4 / private.webm
    $real = false;
    foreach ([$userId . '.private.png', $userId . '.private.mp4', $userId . '.private.webm'] as $cand) {
        $rp = realpath($baseDir . '/' . $cand);
        if ($rp !== false && strpos($rp . '/', $baseDir . '/') === 0 && is_file($rp)) { $real = $rp; break; }
    }
    if ($real === false) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'File not found']);
        exit;
    }
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $mime = $ext === 'mp4' ? 'video/mp4' : ($ext === 'webm' ? 'video/webm' : 'image/png');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Cache-Control: private, max-age=86400');
    readfile($real);
    exit;
}

// ---- Background media (data/bgi/<uid>.{png|mp4|webm}) with privacy check ----
if (($_GET['type'] ?? '') === 'bgi') {
    $baseDir = realpath(__DIR__ . '/../data/bgi');
    if ($baseDir === false) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'File not found']);
        exit;
    }
    // 依次尝试 <uid>.png（图片） / <uid>.mp4 / <uid>.webm（视频）
    $real = false;
    foreach ([$userId . '.png', $userId . '.mp4', $userId . '.webm'] as $cand) {
        $rp = realpath($baseDir . '/' . $cand);
        if ($rp !== false && strpos($rp . '/', $baseDir . '/') === 0 && is_file($rp)) { $real = $rp; break; }
    }
    if ($real === false) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'File not found']);
        exit;
    }
    $path = $real;

    // 隐私校验：只有本人，或目标背景图按其隐私模式允许时才能读
    $meUid = 0;
    $stmt = db()->prepare('SELECT user_id FROM users WHERE username = ?');
    $stmt->execute([$_SESSION['username'] ?? '']);
    $meUid = (int)($stmt->fetchColumn() ?: 0);
    $isSelf = ($meUid === $userId);
    $allow = $isSelf;

    if (!$isSelf && !$allow) {
        $stmt = db()->prepare('SELECT bg_privacy, bg_blacklist, bg_whitelist, bg_no_friend FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $bgRow = $stmt->fetch();
        if (!$bgRow) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'File not found']);
            exit;
        }
        $bgPrivacy = (int)$bgRow['bg_privacy'];
        $bgNoFriend = (int)$bgRow['bg_no_friend'];
        $black = $bgRow['bg_blacklist'] ? json_decode($bgRow['bg_blacklist'], true) : [];
        $white = $bgRow['bg_whitelist'] ? json_decode($bgRow['bg_whitelist'], true) : [];
        if (!is_array($black)) $black = [];
        if (!is_array($white)) $white = [];

        if ($bgPrivacy === 0) {
            $allow = !in_array($meUid, $black, true);
        } elseif ($bgPrivacy === 1) {
            $allow = in_array($meUid, $white, true);
        } else {
            $allow = false; // 2 = 仅自己能看见
        }

        if ($bgNoFriend && $allow) {
            if ($meUid > 0) {
                $cstmt = db()->prepare("SELECT COUNT(*) FROM contacts WHERE status='accepted' AND ((user_from=? AND user_to=?) OR (user_from=? AND user_to=?))");
                $cstmt->execute([$meUid, $userId, $userId, $meUid]);
                $isFriend = (int)$cstmt->fetchColumn() > 0;
                if (!$isFriend) $allow = false;
            } else {
                $allow = false;
            }
        }
    }

    if (!$allow) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'File not found']);
        exit;
    }
    // bgi 目录可能为图片(png) 或视频(mp4/webm)，按扩展名返回 MIME
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = $ext === 'mp4' ? 'video/mp4' : ($ext === 'webm' ? 'video/webm' : 'image/png');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    readfile($path);
    exit;
}

if ($userId > 0) {
    $baseDir = __DIR__ . '/../data/user/' . $userId;
    $path = $baseDir . '/' . $file;
} else {
    $baseDir = __DIR__ . '/../data/';
    $path = $baseDir . $file;
}

// Resolve symlinks and normalize, then verify the file stays inside the allowed base directory
$real = realpath($path);
$realBase = realpath($baseDir);
if ($real === false || $realBase === false || strpos($real . '/', $realBase . '/') !== 0) {
    chatapp_log('security_logs', [
        'event_type' => 'path_traversal',
        'target_path' => mb_substr($originalFile, 0, 500),
        'details' => 'detected_by: realpath_check, base=' . mb_substr($baseDir, 0, 200) . ', real=' . mb_substr((string)$real, 0, 200),
    ]);
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File not found']);
    exit;
}
$path = $real;

if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File not found']);
    exit;
}

// ==================== Authorization (generic path) ====================
// Previously this branch served files with NO authentication and NO access
// control: any request could read any user's attachments, chat wallpapers,
// ticket screenshots and flash-transfer files. Now every file is classified
// by its resolved path and gated accordingly.

function _f_deny(): void {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

function _f_me_uid(PDO $pdo): int {
    if (empty($_SESSION['username'])) return 0;
    $s = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
    $s->execute([$_SESSION['username']]);
    return (int)($s->fetchColumn() ?: 0);
}

function _f_is_group_member(PDO $pdo, int $uid, int $gid): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?');
    $s->execute([$gid, $uid]);
    return (int)$s->fetchColumn() > 0;
}

// Background privacy — same model used by profile backgrounds (type=bgi).
// Shared here so chat wallpapers (user/<uid>/bg.*) honor the same settings.
function _f_bg_allowed(PDO $pdo, int $viewerUid, int $ownerUid): bool {
    if ($viewerUid === $ownerUid) return true;
    $s = $pdo->prepare('SELECT bg_privacy, bg_blacklist, bg_whitelist, bg_no_friend FROM users WHERE user_id = ?');
    $s->execute([$ownerUid]);
    $row = $s->fetch();
    if (!$row) return false;
    $bl = json_decode((string)$row['bg_blacklist'], true);
    $wl = json_decode((string)$row['bg_whitelist'], true);
    $black = is_array($bl) ? $bl : [];
    $white = is_array($wl) ? $wl : [];
    $privacy = (int)$row['bg_privacy'];
    if ($privacy === 0) $allow = !in_array($viewerUid, $black, true);
    elseif ($privacy === 1) $allow = in_array($viewerUid, $white, true);
    else $allow = false; // 2 = only the owner can see
    if ($allow && (int)$row['bg_no_friend'] && $viewerUid > 0) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE status='accepted' AND ((user_from=? AND user_to=?) OR (user_from=? AND user_to=?))");
        $c->execute([$viewerUid, $ownerUid, $ownerUid, $viewerUid]);
        $allow = (int)$c->fetchColumn() > 0;
    }
    return $allow;
}

// Chat attachment: the owner, or a participant of a message that references
// the file. Unknown/orphan files (content-hash names, effectively unguessable)
// fall back to "any logged-in user" — acceptable given the URL is the secret.
function _f_attachment_allowed(PDO $pdo, int $viewerUid, string $filename): bool {
    $stmt = $pdo->prepare("SELECT sender_id, recipient_id, group_id FROM messages WHERE (attachment = ? OR attachment LIKE ?) LIMIT 20");
    $stmt->execute([$filename, '%"file":"' . $filename . '"%']);
    $rows = $stmt->fetchAll();
    if (!$rows) return true;
    foreach ($rows as $row) {
        if ((int)$row['sender_id'] === $viewerUid || (int)$row['recipient_id'] === $viewerUid) return true;
        if ((int)$row['group_id'] > 0 && _f_is_group_member($pdo, $viewerUid, (int)$row['group_id'])) return true;
    }
    return false;
}

// Flash-transfer file (data/sc/<hash>): owner, the message recipient, group
// members, or an admin.
function _f_temp_allowed(PDO $pdo, int $viewerUid, string $hash): bool {
    $s = $pdo->prepare("SELECT owner_uid, message_id FROM temp_uploads WHERE hash = ? AND revoked = 0 LIMIT 1");
    $s->execute([$hash]);
    $row = $s->fetch();
    if (!$row) return false;
    if ((int)$row['owner_uid'] === $viewerUid) return true;
    if ((int)$row['message_id'] > 0) {
        $ms = $pdo->prepare("SELECT recipient_id, group_id FROM messages WHERE id = ?");
        $ms->execute([(int)$row['message_id']]);
        $m = $ms->fetch();
        if ($m && (int)$m['recipient_id'] === $viewerUid) return true;
        if ($m && (int)$m['group_id'] > 0 && _f_is_group_member($pdo, $viewerUid, (int)$m['group_id'])) return true;
    }
    $role = chatapp_get_role($viewerUid);
    return ($role === 'root' || $role === 'admin');
}

$dataRoot = realpath(__DIR__ . '/../data');
$kind = '';
$targetUid = 0;
if (preg_match('#/user/([0-9]+)/#', $path, $pm)) { $kind = 'user'; $targetUid = (int)$pm[1]; }
elseif ($dataRoot !== false && strpos($path, $dataRoot . '/res/') === 0) { $kind = 'res'; }
elseif ($dataRoot !== false && strpos($path, $dataRoot . '/sc/') === 0) { $kind = 'sc'; }
elseif ($dataRoot !== false && strpos($path, $dataRoot . '/ticket/') === 0) { $kind = 'ticket'; }

if ($kind === 'res') {
    // Public static resource (data/res/*) — served without login.
} elseif ($kind === 'user') {
    if (empty($_SESSION['username'])) _f_deny();
    $meUid = _f_me_uid(db());
    if (preg_match('/^bg\./', basename($path))) {
        // Chat wallpaper: honor the background privacy model.
        if (!$targetUid || !_f_bg_allowed(db(), $meUid, $targetUid)) _f_deny();
    } else {
        // Attachment: owner, or a participant of a message referencing it.
        if ($meUid !== $targetUid && !_f_attachment_allowed(db(), $meUid, basename($path))) _f_deny();
    }
} elseif ($kind === 'sc') {
    if (empty($_SESSION['username'])) _f_deny();
    $meUid = _f_me_uid(db());
    if (!$meUid || !_f_temp_allowed(db(), $meUid, basename($path))) _f_deny();
} elseif ($kind === 'ticket') {
    if (empty($_SESSION['username'])) _f_deny();
    $meUid = _f_me_uid(db());
    $role = chatapp_get_role($meUid);
    if ($role !== 'root' && $role !== 'admin') _f_deny();
} else {
    _f_deny();
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp',
    'mp4' => 'video/mp4', 'webm' => 'video/webm',
    'mov' => 'video/quicktime', 'ogg' => 'video/ogg',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'm4b' => 'audio/mp4',
    'wav' => 'audio/wav', 'aac' => 'audio/aac', 'opus' => 'audio/ogg',
    'flac' => 'audio/flac',
    'oga' => 'audio/ogg',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

// Sanitize download filename: strip control characters (CR/LF etc.), quotes and backslashes
// to prevent HTTP response header injection
$dlName = isset($_GET['name']) ? $_GET['name'] : basename($file);
$dlName = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $dlName);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
// Private user content must not be cached by shared proxies; only public data/res may be public.
header('Cache-Control: ' . ($kind === 'res' ? 'public' : 'private') . ', max-age=86400');
// Force download for generic files or when dl=1 is requested
if (!preg_match('/^(image|video)\//', $mime) || (isset($_GET['dl']) && $_GET['dl'] === '1')) {
    header('Content-Disposition: attachment; filename="' . $dlName . '"');
}
readfile($path);