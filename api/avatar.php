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

$pdo = db();
$row = false;
$isUid = false;
// 所有调用方传的都是 username（chatapp_avatar_url / wss_client）。先按用户名解析，
// 纯数字用户名（如 "6666"）也走这里，避免被误当成 user_id 查空导致头像不显示。
if (preg_match('/^[a-zA-Z0-9_]+$/', $user)) {
    $stmt = $pdo->prepare('SELECT user_id, avatar FROM users WHERE username = ?');
    $stmt->execute([$user]);
    $row = $stmt->fetch();
}
// 用户名未命中 → 回退：按数字 uid 解析
if (!$row && is_numeric($user)) {
    $isUid = true;
    $stmt = $pdo->prepare('SELECT user_id, avatar FROM users WHERE user_id = ?');
    $stmt->execute([(int)$user]);
    $row = $stmt->fetch();
}

$hasAvatar = false;
$avFile = null;
$fallbackName = $user;
$uid = $row ? (int)$row['user_id'] : 0;

// ① data/pp/{uid}.{ext} 优先（磁盘真实文件，权威）——即使 DB avatar 是旧值/data URI 也以磁盘为准
$ppBase = realpath(__DIR__ . '/../data/pp');
if ($ppBase !== false && $uid > 0) {
    foreach (['png', 'jpg', 'jpeg', 'gif', 'webp'] as $ext) {
        $ppFile = __DIR__ . '/../data/pp/' . $uid . '.' . $ext;
        $ppReal = realpath($ppFile);
        if ($ppReal !== false && strpos($ppReal . '/', $ppBase . '/') === 0 && is_file($ppReal)) {
            $avFile = $ppReal;
            $hasAvatar = true;
            break;
        }
    }
}

// ② 兜底：数据库 avatar 值（新格式文件名 → data/pp；旧格式 → data/user/{uid}/）
if (!$hasAvatar && $row && !empty($row['avatar'])) {
    $fallbackName = $isUid ? 'uid:' . $uid : $user;
    $ppFile = __DIR__ . '/../data/pp/' . $row['avatar'];
    $ppReal = realpath($ppFile);
    if ($ppBase !== false && $ppReal !== false && strpos($ppReal . '/', $ppBase . '/') === 0 && is_file($ppReal)) {
        $avFile = $ppReal;
        $hasAvatar = true;
    }
    if (!$hasAvatar && $uid > 0) {
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