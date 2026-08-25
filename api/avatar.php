<?php
/**
 * ChatApp - Avatar Image Server
 * Serves user avatars from data/pp/{user_id}.{ext} (new) or data/user/{user_id}/{hash} (legacy)
 * Also serves group avatars via ?g=<group_id> from data/pp/{avatar}
 */

require_once __DIR__ . '/config.php';

// ---- Group avatar (?g=group_id) ----
$gid = trim($_GET['g'] ?? '');
if ($gid !== '') {
    $gid = (int)$gid;
    if ($gid <= 0) { http_response_code(404); exit; }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT avatar FROM `groups` WHERE group_id = ?');
    $stmt->execute([$gid]);
    $gAv = $stmt->fetchColumn();
    if ($gAv && preg_match('/^[0-9a-zA-Z_]+\.(png|jpg|jpeg|gif|webp)$/i', $gAv)) {
        $ppBase = realpath(__DIR__ . '/../data/pp');
        $ppFile = __DIR__ . '/../data/pp/' . $gAv;
        $ppReal = realpath($ppFile);
        if ($ppBase !== false && $ppReal !== false && strpos($ppReal . '/', $ppBase . '/') === 0 && is_file($ppReal)) {
            $ext = strtolower(pathinfo($ppReal, PATHINFO_EXTENSION));
            $mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp'][$ext] ?? 'image/png';
            header('Content-Type: ' . $mime);
            header('Cache-Control: public, max-age=86400');
            header('Content-Length: ' . filesize($ppReal));
            readfile($ppReal);
            exit;
        }
    }
    // Group has no avatar → SVG placeholder with "群" initial
    $hash = md5('group:' . $gid);
    $r = hexdec(substr($hash, 0, 2)) % 128 + 64;
    $gg = hexdec(substr($hash, 2, 2)) % 128 + 64;
    $b = hexdec(substr($hash, 4, 2)) % 128 + 64;
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=3600');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="150" height="150">'
        . '<rect width="150" height="150" fill="rgb(' . $r . ',' . $gg . ',' . $b . ')"/>'
        . '<text x="75" y="105" font-family="Arial,sans-serif" font-size="80" font-weight="bold" fill="#ddd" text-anchor="middle">群</text></svg>';
    exit;
}

$user = trim($_GET['u'] ?? '');
if (empty($user)) { http_response_code(404); exit; }

// Allow both username (alphanumeric+underscore) and raw numeric uid
$isUid = is_numeric($user);

$pdo = db();
if ($isUid) {
    $stmt = $pdo->prepare('SELECT user_id, avatar FROM users WHERE user_id = ?');
    $stmt->execute([(int)$user]);
} else {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $user)) { http_response_code(404); exit; }
    $stmt = $pdo->prepare('SELECT user_id, avatar FROM users WHERE username = ?');
    $stmt->execute([$user]);
}
$row = $stmt->fetch();

$hasAvatar = false;
$avFile = null;
$fallbackName = $user;

if ($row && !empty($row['avatar'])) {
    $uid = (int)$row['user_id'];
    $fallbackName = $isUid ? 'uid:' . $uid : $user;

    // Check new format first: data/pp/{uid}.{ext} (with realpath containment)
    $ppBase = realpath(__DIR__ . '/../data/pp');
    $ppFile = __DIR__ . '/../data/pp/' . $row['avatar'];
    $ppReal = realpath($ppFile);
    if ($ppBase !== false && $ppReal !== false && strpos($ppReal . '/', $ppBase . '/') === 0 && is_file($ppReal)) {
        $avFile = $ppReal;
        $hasAvatar = true;
    }
    // Fallback: legacy data/user/{uid}/{filename} (with realpath containment)
    if (!$hasAvatar) {
        $legacyBase = realpath(__DIR__ . '/../data/user/' . $uid);
        $legacyFile = __DIR__ . '/../data/user/' . $uid . '/' . $row['avatar'];
        $legacyReal = realpath($legacyFile);
        if ($legacyBase !== false && $legacyReal !== false && strpos($legacyReal . '/', $legacyBase . '/') === 0 && is_file($legacyReal)) {
            $avFile = $legacyReal;
            $hasAvatar = true;
        }
    }
}

if (!$hasAvatar) {
    // Generate colored SVG placeholder with initial
    $name = $fallbackName;
    $hash = md5($name);
    $r = hexdec(substr($hash, 0, 2)) % 128 + 64;
    $g = hexdec(substr($hash, 2, 2)) % 128 + 64;
    $b = hexdec(substr($hash, 4, 2)) % 128 + 64;
    $initial = strtoupper(substr($name, 0, 1));
    header('Content-Type: image/svg+xml');
    header('Cache-Control: public, max-age=3600');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="150" height="150">'
        . '<rect width="150" height="150" fill="rgb(' . $r . ',' . $g . ',' . $b . ')"/>'
        . '<text x="75" y="105" font-family="Arial,sans-serif" font-size="80" font-weight="bold" fill="#ddd" text-anchor="middle">'
        . htmlspecialchars($initial) . '</text></svg>';
    exit;
}

$ext = strtolower(pathinfo($avFile, PATHINFO_EXTENSION));
$mime = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','webp'=>'image/webp'][$ext] ?? 'image/png';
header('Content-Type: ' . $mime);
header('Cache-Control: public, max-age=86400');
header('Content-Length: ' . filesize($avFile));
readfile($avFile);