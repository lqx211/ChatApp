<?php
/**
 * ChatApp - Emoji API (built-in + custom per-user)
 */

require_once __DIR__ . '/config.php';

chatapp_session_start();
isset($_SESSION['username']) or die(json_encode(['success' => false]));
header('Content-Type: application/json');

$pdo = db();
$me = $_SESSION['username'];
$myUid = 0;
$myUidStmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
$myUidStmt->execute([$me]);
$myUid = (int)($myUidStmt->fetchColumn() ?: 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function _emoji_public_dir(): string {
    $dir = __DIR__ . '/../data/ce';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function _emoji_pub_dir(): string {
    $dir = __DIR__ . '/../data/cep';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function _emoji_owner_map(): array {
    global $pdo;
    static $cache = null;
    if ($cache !== null) return $cache;
    $rows = $pdo->query("SELECT hash, owner_uid FROM custom_emoji ORDER BY id ASC")->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $hash = $r['hash'] ?? '';
        if (!$hash) continue;
        if (!isset($map[$hash])) $map[$hash] = [];
        $map[$hash][] = (int)($r['owner_uid'] ?? 0);
    }
    $cache = $map;
    return $map;
}

function _emoji_file_for_hash(string $hash): ?string {
    // Public emoji library first
    $pubPng = _emoji_pub_dir() . '/' . $hash . '.png'; $pubGif = _emoji_pub_dir() . '/' . $hash . '.gif';
    if (file_exists($pubGif)) return $pubGif; if (file_exists($pubPng)) return $pubPng;
    $shared = _emoji_public_dir() . '/' . $hash . '.png';
    if (file_exists($shared)) return $shared;
    global $pdo;
    $row = $pdo->prepare("SELECT owner_uid FROM custom_emoji WHERE hash = ? ORDER BY id ASC LIMIT 1");
    $row->execute([$hash]);
    $ownerUid = (int)($row->fetchColumn() ?: 0);
    if ($ownerUid > 0) {
        $legacy = __DIR__ . '/../data/user/' . $ownerUid . '/' . $hash . '.png';
        if (file_exists($legacy)) return $legacy;
    }
    return null;
}

// Helper: load built-in emoji config
function _builtin_list(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $path = __DIR__ . '/../data/res/emoji/default_config.json';
    if (!file_exists($path)) { $cache = []; return []; }
    $raw = json_decode(file_get_contents($path), true);
    $list = [];
    $dir = __DIR__ . '/../data/res/emoji/';
    foreach (($raw['normalPanelResult']['SysEmojiGroupList'] ?? []) as $g) {
        $gname = $g['groupName'] ?? 'Emoji';
        foreach ($g['SysEmojiList'] ?? [] as $e) {
            if (!empty($e['isHide'])) continue;
            $etype = (int)($e['emojiType'] ?? 0);
            $eid   = (string)($e['emojiId'] ?? '');
            $desc  = $e['describe'] ?? '';
            $entry = [
                'id'    => $eid,
                'code'  => $desc,
                'type'  => $etype,
                'group' => $gname,
                'img'   => null,
            ];
            if ($etype === 4) {
                // Unicode native emoji – no PNG
                $entry['unicode'] = $eid;
            } else {
                if (file_exists($dir . $eid . '.png')) {
                    $entry['img'] = 'data/res/emoji/' . $eid . '.png';
                    if (file_exists($dir . 's' . $eid . '.png')) {
                        $entry['img_dyn'] = 'data/res/emoji/s' . $eid . '.png';
                    }
                } else {
                    continue; // missing PNG – skip
                }
            }
            $list[] = $entry;
        }
    }
    $cache = $list;
    return $list;
}

switch ($action) {

    case 'list':
        echo json_encode(['success' => true, 'emojis' => _builtin_list()]);
        break;

    case 'my_custom':
        $stmt = $pdo->prepare("SELECT id, hash, created_at FROM custom_emoji WHERE owner_uid = ? ORDER BY created_at ASC");
        $stmt->execute([$myUid]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['img'] = 'api/emoji.php?action=img&hash=' . $r['hash'];
        }
        echo json_encode(['success' => true, 'custom' => $rows]);
        break;

    case 'img':
        $hash = trim($_GET['hash'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $hash)) { http_response_code(404); exit; }
        $file = _emoji_file_for_hash($hash);
        if (!$file) { http_response_code(404); exit; }
        // Realpath containment: only serve files under data/ (neutralizes symlinks).
        $emojiBase = realpath(__DIR__ . '/../data');
        $emojiReal = realpath($file);
        if ($emojiBase === false || $emojiReal === false || strpos($emojiReal . '/', $emojiBase . '/') !== 0) {
            http_response_code(404);
            exit;
        }
        $file = $emojiReal;
        $pathInfo = pathinfo($file);
        $mime = ['png' => 'image/png', 'gif' => 'image/gif', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
        header('Content-Type: ' . ($mime[$pathInfo['extension']] ?? 'image/png'));
        readfile($file);
        break;

    case 'upload':
        $b64 = trim($_POST['image'] ?? '');
        if (empty($b64)) { echo json_encode(['success' => false, 'error' => 'No image']); exit; }
        // Strip data: URL prefix
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/s', $b64, $m)) {
            $fmt = strtolower($m[1]);
            if (!in_array($fmt, ['png', 'jpeg', 'jpg', 'gif', 'webp'])) {
                echo json_encode(['success' => false, 'error' => 'Unsupported format']); exit;
            }
            $raw = base64_decode($m[2]);
        } elseif (preg_match('/^data:.+;base64,(.+)$/s', $b64, $m)) {
            $raw = base64_decode($m[1]);
        } else {
            $raw = base64_decode($b64);
        }
        if (strlen($raw) > 2 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'Image too large (>2MiB)']); exit;
        }
        // Validate it's actually an image
        $img = @imagecreatefromstring($raw);
        if (!$img) {
            echo json_encode(['success' => false, 'error' => 'Invalid image']); exit;
        }
        imagedestroy($img);

        $hash = md5($raw);
        $filePath = _emoji_public_dir() . '/' . $hash . '.png';

        // Convert to PNG and save
        $img2 = @imagecreatefromstring($raw);
        if (!$img2) { echo json_encode(['success' => false]); exit; }
        // Ensure true color so alpha channel is preserved
        if (function_exists('imageistruecolor') && !imageistruecolor($img2)) {
            imagepalettetotruecolor($img2);
        }
        // Preserve transparency (for PNG/GIF with alpha)
        imagealphablending($img2, false);
        imagesavealpha($img2, true);
        // Keep original dimensions, save as PNG
        imagepng($img2, $filePath);
        imagedestroy($img2);

        // Write DB record if not exists
        $stmt = $pdo->prepare("INSERT IGNORE INTO custom_emoji (owner_uid, hash) VALUES (?, ?)");
        $stmt->execute([$myUid, $hash]);

        echo json_encode(['success' => true, 'hash' => $hash]);
        break;

    case 'add':
        // Add an emoji (built-in or custom) to my custom emoji list
        $code = trim($_POST['code'] ?? '');
        if ($code === '') { echo json_encode(['success' => false, 'error' => 'No code']); exit; }
        $hash = '';
        // Custom emoji: [emoji:hash]
        if (preg_match('/^\[emoji:([a-f0-9]{32})\]$/', $code, $m)) {
            $hash = $m[1];
            $srcFile = _emoji_file_for_hash($hash);
            if (!$srcFile) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }
            $dst = _emoji_public_dir() . '/' . $hash . '.png';
            if (!file_exists($dst)) copy($srcFile, $dst);
        } else {
            // Built-in emoji by code: find in builtin list, copy its PNG
            $found = null;
            foreach (_builtin_list() as $e) {
                if (($e['code'] ?? '') === $code && !empty($e['img'])) { $found = $e; break; }
            }
            if (!$found) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }
            $src = __DIR__ . '/../' . $found['img'];
            if (!file_exists($src)) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }
            // Prefer dynamic variant if present, else static
            if (!empty($found['img_dyn']) && file_exists(__DIR__ . '/../' . $found['img_dyn'])) {
                $src = __DIR__ . '/../' . $found['img_dyn'];
            }
            $bin = file_get_contents($src);
            $hash = md5($bin);
            $dst = _emoji_public_dir() . '/' . $hash . '.png';
            if (!file_exists($dst)) {
                // Convert through GD to ensure PNG + preserve alpha
                $img = @imagecreatefromstring($bin);
                if ($img) {
                    if (function_exists('imageistruecolor') && !imageistruecolor($img)) imagepalettetotruecolor($img);
                    imagealphablending($img, false);
                    imagesavealpha($img, true);
                    imagepng($img, $dst);
                    imagedestroy($img);
                } else {
                    copy($src, $dst);
                }
            }
        }
        // Register ownership
        $pdo->prepare("INSERT IGNORE INTO custom_emoji (owner_uid, hash) VALUES (?, ?)")->execute([$myUid, $hash]);
        echo json_encode(['success' => true, 'hash' => $hash]);
        break;

    case 'public_list':
        // List all public emoji with uploader info + can_delete (self or admin)
        $isAdm = ($myUid === 10000 || chatapp_get_role($myUid) === 'admin');
        $rows = $pdo->query("SELECT pe.id, pe.owner_uid, pe.hash, pe.created_at,
                    COALESCE(u.username, '?') AS username,
                    COALESCE(u.display_name, u.username, '?') AS display_name
                 FROM public_emoji pe
                 LEFT JOIN users u ON u.user_id = pe.owner_uid
                 ORDER BY pe.created_at DESC, pe.id DESC")->fetchAll();
        foreach ($rows as &$r) {
            $r['img'] = 'api/emoji.php?action=img&hash=' . $r['hash'];
            $r['can_delete'] = ($isAdm || (int)$r['owner_uid'] === $myUid);
        }
        echo json_encode(['success' => true, 'emojis' => $rows]);
        break;

    case 'public_upload':
        $b64 = trim($_POST['image'] ?? '');
        if (empty($b64)) { echo json_encode(['success' => false, 'error' => 'No image']); exit; }
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/s', $b64, $m)) {
            $fmt = strtolower($m[1]);
            if (!in_array($fmt, ['png', 'jpeg', 'jpg', 'gif', 'webp'])) {
                echo json_encode(['success' => false, 'error' => 'Unsupported format']); exit;
            }
            $raw = base64_decode($m[2]);
        } elseif (preg_match('/^data:.+;base64,(.+)$/s', $b64, $m)) {
            $raw = base64_decode($m[1]);
        } else {
            $raw = base64_decode($b64);
        }
        // Reject disguised executable texts (check base64 string, not binary data — binary image bytes may accidentally match text patterns)
        $danger = ['PD9waHA=', 'PD89', 'Pz4=', 'PHNjcmlwdA==', 'PC9zY3JpcHQ+', 'amF2YXNjcmlwdDo=', 'ZXZhbCg=', 'ZG9jdW1lbnQuY29va2ll', 'WE1MSHR0cFJlcXVlc3Q=', 'c2hlbGxfZXhlYw==', 'c3lzdGVtKA==', 'cGFzc3RocnU=', 'ZXhlYyg=', 'YmFzZTY0X2RlY29kZSg=', 'PD94bWw='];
        $b64Body = $m[2];
        foreach ($danger as $d) {
            if (strpos($b64Body, $d) !== false) {
                echo json_encode(['success' => false, 'error' => 'Suspicious content']); exit;
            }
        }
        $raw = base64_decode($b64Body);
        if ($raw === false || $raw === '') { echo json_encode(['success' => false, 'error' => 'Empty image']); exit; }
        if (strlen($raw) > 2 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'Image too large (>2MiB)']); exit;
        }
        // Must decode as a real image via GD (rejects renamed JS/PHP files)
        $img = @imagecreatefromstring($raw);
        if (!$img) { echo json_encode(['success' => false, 'error' => 'Invalid image']); exit; }
        imagedestroy($img);

        $ext = ($fmt === 'gif') ? 'gif' : 'png';
        $hash = md5($raw);
        $filePath = _emoji_pub_dir() . '/' . $hash . '.' . $ext;

        if (!file_exists($filePath)) {
            if ($fmt === 'gif') {
                // Preserve GIF animation — store original bytes as-is
                file_put_contents($filePath, $raw);
            } else {
                $img2 = @imagecreatefromstring($raw);
                if (!$img2) { echo json_encode(['success' => false]); exit; }
                if (function_exists('imageistruecolor') && !imageistruecolor($img2)) {
                    imagepalettetotruecolor($img2);
                }
                imagealphablending($img2, false);
                imagesavealpha($img2, true);
                imagepng($img2, $filePath);
                imagedestroy($img2);
            }
        }

        $stmt = $pdo->prepare("INSERT IGNORE INTO public_emoji (owner_uid, hash) VALUES (?, ?)");
        $stmt->execute([$myUid, $hash]);
        $isNew = $stmt->rowCount() > 0;

        // ---- Level system: public emoji upload +2 exp (dedup via INSERT IGNORE), daily max 100 ----
        if ($isNew) {
            try {
                exp_daily_incr($myUid, 'public_emoji', 100, 2, 'emoji');
            } catch (Exception $e) {
                // never break upload
            }
        }

        echo json_encode(['success' => true, 'hash' => $hash]);
        break;

    case 'public_delete':
        $hash = trim($_POST['hash'] ?? '');
        if (empty($hash) || !preg_match('/^[a-f0-9]{32}$/', $hash)) { echo json_encode(['success' => false]); exit; }
        $isAdm = ($myUid === 10000 || chatapp_get_role($myUid) === 'admin');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM public_emoji WHERE owner_uid = ? AND hash = ?");
        $stmt->execute([$myUid, $hash]);
        $mine = (int)$stmt->fetchColumn();
        if ($isAdm) {
            // Admin may delete anyone's uploads: remove ALL records for this hash
            $pdo->prepare("DELETE FROM public_emoji WHERE hash = ?")->execute([$hash]);
        } else {
            if ($mine <= 0) { echo json_encode(['success' => false, 'error' => 'No permission']); exit; }
            // Normal user: delete only own record(s) for this hash
            $pdo->prepare("DELETE FROM public_emoji WHERE owner_uid = ? AND hash = ?")->execute([$myUid, $hash]);
        }
        // Remove file only if no remaining references to this hash
        $left = $pdo->prepare("SELECT COUNT(*) FROM public_emoji WHERE hash = ?");
        $left->execute([$hash]);
        if ((int)$left->fetchColumn() === 0) {
            $fpPng = _emoji_pub_dir() . '/' . $hash . '.png';
            $fpGif = _emoji_pub_dir() . '/' . $hash . '.gif';
            if (file_exists($fpPng)) @unlink($fpPng);
            if (file_exists($fpGif)) @unlink($fpGif);
        }
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        $hash = trim($_POST['hash'] ?? '');
        if (empty($hash) || !preg_match('/^[a-f0-9]{32}$/', $hash)) {
            echo json_encode(['success' => false]); exit;
        }
        // Delete DB record
        $pdo->prepare("DELETE FROM custom_emoji WHERE owner_uid = ? AND hash = ?")->execute([$myUid, $hash]);
        // Delete file from shared storage if no one else owns it
        $owners = _emoji_owner_map();
        $fp = _emoji_public_dir() . '/' . $hash . '.png';
        $stillOwned = false;
        foreach ($owners[$hash] ?? [] as $uid) {
            if ((int)$uid !== (int)$myUid) { $stillOwned = true; break; }
        }
        if (file_exists($fp) && !$stillOwned) unlink($fp);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false]);
}