<?php
require_once __DIR__ . '/config.php';

chatapp_session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
header('Content-Type: application/json');

function get_my_uid(PDO $pdo): int {
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
    $stmt->execute([$_SESSION['username']]);
    return (int)($stmt->fetchColumn() ?: 0);
}

function getGlobalConfig(string $key) {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config/lvconfig.php';
    }
    return $config[$key] ?? null;
}

function get_uid_by_name(PDO $pdo, string $username): int {
    if (empty($username)) return 0;
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    return (int)($stmt->fetchColumn() ?: 0);
}

switch ($action) {

    case 'send':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']); exit;
        }
        $message = trim($_POST['message'] ?? '');
        $attachmentB64 = trim($_POST['attachment'] ?? '');
        $tempUploadId = (int)($_POST['temp_upload_id'] ?? 0);
        // Flash transfer messages carry only temp_upload_id; allow empty message/attachment.
        if (empty($message) && empty($attachmentB64) && $tempUploadId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Empty']); exit;
        }
        if (mb_strlen($message) > 1000) {
            echo json_encode(['success' => false, 'error' => 'Too long']); exit;
        }

        $pdo = db();
        $senderId = get_my_uid($pdo);
        if (!$senderId) exit;

        $msgType = null;
        $attachmentFilename = null;
        $recipientName = trim($_POST['recipient'] ?? '');

        if (!empty($attachmentB64)) {
            // Support arbitrary file types: data:<mime>;base64,...
            if (!preg_match('/^data:([^;]+);base64,(.+)$/s', $attachmentB64, $m)) {
                echo json_encode(['success' => false, 'error' => 'Invalid attachment']); exit;
            }
            $mediaMainType = strtolower($m[1]);
            $binary = base64_decode($m[2]);
            // Level-gated attachment size limit (jh.md Lv Limits: max_attach_kb)
            $maxAttachKb = level_limits(user_level($pdo, $senderId))['max_attach_kb'];
            $maxAttachBytes = $maxAttachKb * 1024;
            if ($binary === false || strlen($binary) > $maxAttachBytes) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Too large',
                    'max_attach_kb' => $maxAttachKb,
                ]); exit;
            }

            $hash = hash('sha256', $binary);
            // Extract proper extension from mime, fallback to 'bin'
            $ext = 'bin';
            if (preg_match('/^image\/(\w+)$/', $mediaMainType, $em)) {
                $ext = strtolower($em[1]);
                if ($ext === 'jpeg') $ext = 'jpg';
            } elseif (preg_match('/^video\/(\w+)$/', $mediaMainType, $em)) {
                $ext = strtolower($em[1]);
                if ($ext === 'quicktime') $ext = 'mov';
            } elseif (preg_match('/^audio\/(\w+)$/', $mediaMainType, $em)) {
                $ext = strtolower($em[1]);
                if ($ext === 'mpeg') $ext = 'mp3';
                elseif ($ext === 'x-m4a') $ext = 'm4a';
                elseif ($ext === 'x-wav' || $ext === 'wav') $ext = 'wav';
                elseif ($ext === 'x-flac') $ext = 'flac';
                elseif ($ext === 'ogg') $ext = 'ogg';
            } elseif (preg_match('/^text\/(\w+)$/', $mediaMainType, $em)) {
                $ext = strtolower($em[1]);
            } elseif (preg_match('/^application\/([\w.+-]+)$/', $mediaMainType, $em)) {
                $ext = strtolower($em[1]);
                if ($ext === 'msword') $ext = 'doc';
                elseif ($ext === 'vnd.ms-excel') $ext = 'xls';
                elseif ($ext === 'pdf') $ext = 'pdf';
                elseif ($ext === 'zip') $ext = 'zip';
            }

            $filename = $hash . '.' . $ext;
            $dir = __DIR__ . '/../data/user/' . $senderId;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            file_put_contents($dir . '/' . $filename, $binary);

            if (strpos($mediaMainType, 'image/') === 0) {
                $msgType = 'photo';
                $attachmentFilename = $filename;
            } elseif (strpos($mediaMainType, 'video/') === 0) {
                $msgType = 'photo';
                $attachmentFilename = $filename;
            } elseif (strpos($mediaMainType, 'audio/') === 0) {
                $msgType = 'audio';
                $attachmentFilename = $filename;
            } else {
                // Generic file: store json with original name + size
                $msgType = 'file';
                $origName = isset($_POST['filename']) ? mb_substr(trim($_POST['filename']), 0, 255) : ('file.' . $ext);
                $attachmentFilename = json_encode([
                    'file' => $filename,
                    'name' => $origName,
                    'size' => strlen($binary),
                ], JSON_UNESCAPED_UNICODE);
            }
            if (empty($message)) $message = '';
        }

        $replyTo = (int)($_POST['reply_to'] ?? 0);
        $isMd = ($_POST['md'] ?? '') === '1';
        if ($isMd) {
            $msg = strip_tags($message);
            $msgType = 'md';
        } else {
            $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            $msgType = $msgType ?? null;
        }
        $time = gmdate('Y/m/d H:i:s');
        $recipientId = null;

        // Check if recipient is restricted
        if (!empty($recipientName)) {
            $rStmt = $pdo->prepare('SELECT restricted, user_id FROM users WHERE username = ?');
            $rStmt->execute([$recipientName]);
            $rUser = $rStmt->fetch();
            if ($rUser && $rUser['restricted']) {
                echo json_encode(['success' => false, 'error' => t('msg_user_restricted')]); exit;
            }
        }

        // ---- Flash transfer (temp upload) message ----
        // Resolve BEFORE insert so msg_type/attachment carry the temp file meta.
        $tempUploadId = (int)($_POST['temp_upload_id'] ?? 0);
        $tempMeta = null;
        if ($tempUploadId > 0) {
            $tmpStmt = $pdo->prepare("SELECT id, filename, size, revoked FROM temp_uploads WHERE id = ?");
            $tmpStmt->execute([$tempUploadId]);
            $tmpRow = $tmpStmt->fetch();
            if ($tmpRow && !(int)$tmpRow['revoked']) {
                $msgType = 'temp';
                $tempMeta = json_encode([
                    'file' => (int)$tempUploadId,
                    'name' => $tmpRow['filename'],
                    'size' => (int)$tmpRow['size'],
                ], JSON_UNESCAPED_UNICODE);
                $attachmentFilename = $tempMeta;
            } else {
                $tempUploadId = 0;
            }
        }

        try {
            $isAdmin = ($_SESSION['username'] === 'admin');
            if (!$isAdmin || !empty($recipientName)) {
                $recipientId = get_uid_by_name($pdo, $recipientName);
                if (!$recipientId) { echo json_encode(['success' => false]); exit; }
            }
            $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, message, msg_type, attachment, reply_to, time, datetime, temp_upload_id) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)')
                ->execute([$senderId, $recipientId, $msg, $msgType, $attachmentFilename, $replyTo ?: null, $time, $tempUploadId ?: null]);
            $newMsgId = (int)$pdo->lastInsertId();

            // Link temp_upload to this message
            if ($tempUploadId > 0) {
                $pdo->prepare("UPDATE temp_uploads SET message_id = ? WHERE id = ? AND message_id IS NULL")
                    ->execute([$newMsgId, $tempUploadId]);
            }

        // ---- Level system: award EXP only AFTER the message is persisted ----
            try {
                // 1) Message EXP: 20s cooldown, daily max 5000
                $stmt = $pdo->prepare("SELECT last_exp_msg_at FROM users WHERE user_id = ?");
                $stmt->execute([$senderId]);
                $lastMsgAt = $stmt->fetchColumn();
                $lastMsgTs = $lastMsgAt ? strtotime((string)$lastMsgAt) : 0;
                if (!$lastMsgAt || (time() - $lastMsgTs) >= getGlobalConfig('exp_msg_cooldown')) { // Make cooldown adjustable in config/lvconfig.php
                    $origLen = strlen($_POST['message'] ?? '');
                    if ($origLen > 192)      $msgExp = 6;
                    elseif ($origLen > 128)  $msgExp = 5;
                    elseif ($origLen > 96)   $msgExp = 4;
                    elseif ($origLen > 64)   $msgExp = 3;
                    else                     $msgExp = 2;
                    $pdo->prepare("UPDATE users SET last_exp_msg_at = NOW() WHERE user_id = ?")->execute([$senderId]);
                    exp_daily_incr($senderId, 'msg', getGlobalConfig('exp_msg_max_daily'), $msgExp, 'msg');
                }

                // 2) Attachment EXP: 300s cooldown, daily max 75 (read type/size from persisted record)
                if (!empty($attachmentFilename)) {
                    $stmt = $pdo->prepare("SELECT last_exp_attach_at FROM users WHERE user_id = ?");
                    $stmt->execute([$senderId]);
                    $lastAttAt = $stmt->fetchColumn();
                    $lastAttTs = $lastAttAt ? strtotime((string)$lastAttAt) : 0;
                    if (!$lastAttAt || (time() - $lastAttTs) >= getGlobalConfig('exp_attach_cooldown')) { // Make cooldown adjustable in config/lvconfig.php
                        $attName = '';
                        $attSizeBytes = 0;
                        $attJson = json_decode((string)$attachmentFilename, true);
                        if (is_array($attJson) && !empty($attJson['file'])) {
                            $attName = (string)$attJson['file'];
                            $attSizeBytes = (int)($attJson['size'] ?? 0);
                        } else {
                            $attName = (string)$attachmentFilename;
                        }
                        $ext = strtolower(pathinfo($attName, PATHINFO_EXTENSION));
                        if ($attSizeBytes <= 0) {
                            // file attachments carry size; photos/videos/audio read from disk
                            $fpath = __DIR__ . '/../data/user/' . $senderId . '/' . basename($attName);
                            if (is_file($fpath)) $attSizeBytes = filesize($fpath);
                        }
                        $sKb = $attSizeBytes / 1024;
                        $allowlist = ['jpeg','jpg','png','heif','gif','mp3','mp4','m4a','mov'];
                        if (in_array($ext, $allowlist, true)) {
                            if ($sKb > 4096)      $attExp = 10;
                            elseif ($sKb > 1024)  $attExp = 8;
                            elseif ($sKb > 512)   $attExp = 6;
                            else                  $attExp = 4;
                        } elseif ($ext === 'txt') {
                            if ($sKb > 8) $attExp = 3;
                            elseif ($sKb > 1) $attExp = 2;
                            else $attExp = 1;
                        } else {
                            $attExp = 2;
                        }
                        $pdo->prepare("UPDATE users SET last_exp_attach_at = NOW() WHERE user_id = ?")->execute([$senderId]);
                        exp_daily_incr($senderId, 'attach', 75, $attExp, 'attach');
                    }
                }
            } catch (Exception $e) {
                // EXP award must never break message send
            }

            echo json_encode(['success' => true, 'message_id' => $newMsgId]);
        } catch (Exception $e) {
            echo json_encode(['success' => false]);
        }
        break;

    case 'revoke':
        if (!isset($_SESSION['username'])) { echo json_encode(['success'=>false]); exit; }
        $messageId = (int)($_POST['message_id'] ?? 0);
        if ($messageId <= 0) exit;
        $pdo = db(); $myId = get_my_uid($pdo);
        $stmt = $pdo->prepare("SELECT sender_id, datetime FROM messages WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$messageId]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['sender_id'] !== $myId) { echo json_encode(['success'=>false]); exit; }
        if ((time() - strtotime($row['datetime'])) > 120) { echo json_encode(['success'=>false]); exit; }
        $pdo->prepare("UPDATE messages SET deleted_at = NOW() WHERE id = ?")->execute([$messageId]);
        echo json_encode(['success' => true]);
        break;

    case 'unread_counts':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['success'=>true,'counts'=>[]]); exit;
        }
        $pdo = db();
        $myUid = get_my_uid($pdo);
        if (!$myUid) {
            echo json_encode(['success'=>true,'counts'=>[]]); exit;
        }
        $stmt = $pdo->prepare("SELECT u.username, COUNT(*) AS cnt
            FROM messages m
            JOIN users u ON u.user_id = m.sender_id
            WHERE m.recipient_id = ? AND m.read_at IS NULL AND m.deleted_at IS NULL AND u.username != ?
            GROUP BY u.username");
        $stmt->execute([$myUid, $_SESSION['username']]);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['username']] = (int)$row['cnt'];
        }
        echo json_encode(['success' => true, 'counts' => $counts]);
        break;

    case 'mark_read':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        $fromUser = trim($_POST['from'] ?? '');
        if (empty($fromUser)) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        $pdo = db();
        $myUid = get_my_uid($pdo);
        if (!$myUid) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$fromUser]);
        $fromUid = (int)($stmt->fetchColumn() ?: 0);
        if (!$fromUid) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        $affCount = $pdo->prepare("UPDATE messages SET read_at = NOW() WHERE sender_id = ? AND recipient_id = ? AND read_at IS NULL");
        $affCount->execute([$fromUid, $myUid]);
        $marked = $affCount->rowCount();

        // ---- Level system: receiving messages = +1 exp each (daily max 200) ----
        if ($marked > 0) {
            try {
                // Award per message, respecting daily cap (rowCount may exceed remaining cap)
                for ($i = 0; $i < $marked; $i++) {
                    if (!exp_daily_incr($myUid, 'receive', getGlobalConfig('exp_msg_max_daily'), 1, 'receive')) break;
                }
            } catch (Exception $e) {
                // never break read flow
            }
        }

        echo json_encode(['success' => true, 'marked' => $fromUser, 'count' => $marked]);
        break;

    case 'fetch':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['success'=>true,'messages'=>[],'latest_id'=>0]); exit;
        }
        $pdo = db();
        $after = (int)($_GET['after'] ?? 0);

        $sel = "SELECT m.id, m.sender_id, su.username, su.display_name, su.avatar, su.user_id,
                       m.recipient_id, ru.username AS recipient_name,
                       m.message, m.msg_type, m.attachment, m.time, m.datetime, m.deleted_at, m.reply_to, m.temp_upload_id
                FROM messages m
                LEFT JOIN users su ON su.user_id = m.sender_id
                LEFT JOIN users ru ON ru.user_id = m.recipient_id
                WHERE m.id > ? AND m.group_id IS NULL AND (m.recipient_id IS NULL OR m.recipient_id = ? OR m.sender_id = ?)
                ORDER BY m.id ASC";
        $myUid = get_my_uid($pdo);
        $stmt = $pdo->prepare($sel);
        $stmt->execute([$after, $myUid, $myUid]);
        $messages = $stmt->fetchAll();
        $processed = proc($messages);
        $latestId = !empty($messages) ? end($messages)['id'] : (int)($pdo->query('SELECT MAX(id) FROM messages')->fetchColumn() ?? 0);
        echo json_encode(['success'=>true,'messages'=>$processed,'latest_id'=>$latestId]);
        break;

    case 'all':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['success'=>true,'messages'=>[],'latest_id'=>0,'has_more'=>false]); exit;
        }
        $pdo = db();
        $myId = get_my_uid($pdo);
        $dmPartner = trim($_GET['dm'] ?? '');
        $dmId = get_uid_by_name($pdo, $dmPartner);
        $before = (int)($_GET['before'] ?? 0);
        $after = (int)($_GET['after'] ?? 0);
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 50)));

        $sel = "SELECT m.id, m.sender_id, su.username, su.display_name, su.avatar, su.user_id,
                       m.recipient_id, ru.username AS recipient_name, ru.display_name AS recipient_display, ru.user_id AS recipient_uid,
                       m.message, m.msg_type, m.attachment, m.time, m.datetime, m.deleted_at, m.reply_to, m.temp_upload_id
                FROM messages m
                LEFT JOIN users su ON su.user_id = m.sender_id
                LEFT JOIN users ru ON ru.user_id = m.recipient_id";

        if ($action === 'fetch') {
            if ($dmId) {
                $stmt = $pdo->prepare("$sel WHERE m.id > ? AND ((m.sender_id=? AND m.recipient_id=?) OR (m.sender_id=? AND m.recipient_id=?)) ORDER BY m.id ASC");
                $stmt->execute([$after, $myId, $dmId, $dmId, $myId]);
            } else {
                $stmt = $pdo->prepare("$sel WHERE m.id > ? AND m.group_id IS NULL AND (m.recipient_id IS NULL OR m.recipient_id=? OR m.sender_id=?) ORDER BY m.id ASC");
                $stmt->execute([$after, $myId, $myId]);
            }
            $messages = $stmt->fetchAll();
            $processed = proc($messages);
            $latestId = !empty($messages) ? end($messages)['id'] : (int)($pdo->query('SELECT MAX(id) FROM messages')->fetchColumn() ?? 0);
            echo json_encode(['success'=>true,'messages'=>$processed,'latest_id'=>$latestId]);
        } else {
            if ($dmId) {
                if ($before > 0) {
                    $stmt = $pdo->prepare("$sel WHERE m.id < ? AND ((m.sender_id=? AND m.recipient_id=?) OR (m.sender_id=? AND m.recipient_id=?)) ORDER BY m.id DESC LIMIT ?");
                    $stmt->execute([$before, $myId, $dmId, $dmId, $myId, $limit]);
                } else {
                    $stmt = $pdo->prepare("$sel WHERE (m.sender_id=? AND m.recipient_id=?) OR (m.sender_id=? AND m.recipient_id=?) ORDER BY m.id DESC LIMIT ?");
                    $stmt->execute([$myId, $dmId, $dmId, $myId, $limit]);
                }
            } else {
                if ($before > 0) {
                    $stmt = $pdo->prepare("$sel WHERE m.id < ? AND m.group_id IS NULL AND (m.recipient_id IS NULL OR m.recipient_id=? OR m.sender_id=?) ORDER BY m.id DESC LIMIT ?");
                    $stmt->execute([$before, $myId, $myId, $limit]);
                } else {
                    $stmt = $pdo->prepare("$sel WHERE m.group_id IS NULL AND (m.recipient_id IS NULL OR m.recipient_id=? OR m.sender_id=?) ORDER BY m.id DESC LIMIT ?");
                    $stmt->execute([$myId, $myId, $limit]);
                }
            }
            $messages = array_reverse($stmt->fetchAll());
            $processed = proc($messages);
            $oldestId = !empty($messages) ? $messages[0]['id'] : 0;
            $hasMore = false;
            if ($oldestId > 0) {
                if ($dmId) {
                    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE id < ? AND ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?))");
                    $cntStmt->execute([$oldestId, $myId, $dmId, $dmId, $myId]);
                } else {
                    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE id < ? AND group_id IS NULL AND (recipient_id IS NULL OR recipient_id=? OR sender_id=?)");
                    $cntStmt->execute([$oldestId, $myId, $myId]);
                }
                $hasMore = ((int)$cntStmt->fetchColumn()) > 0;
            }
            $latestId = (int)($pdo->query('SELECT MAX(id) FROM messages')->fetchColumn() ?? 0);
            echo json_encode(['success'=>true,'messages'=>$processed,'latest_id'=>$latestId,'has_more'=>$hasMore,'oldest_id'=>$oldestId]);
        }
        break;

    case 'search_messages':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']); exit;
        }
        $pdo = db();
        $myId = get_my_uid($pdo);
        $q = trim($_GET['q'] ?? '');
        $dm = trim($_GET['dm'] ?? '');
        $gid = (int)($_GET['group_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        if (empty($q) || mb_strlen($q) < 2) {
            echo json_encode(['success' => false, 'error' => 'Search query too short']); exit;
        }

        $like = '%' . $q . '%';
        if ($gid > 0) {
            // Group chat search
            $chk = $pdo->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
            $chk->execute([$gid, $myId]);
            if (!$chk->fetch()) { echo json_encode(['success' => true, 'messages' => [], 'total' => 0]); exit; }
            $where = "AND m.group_id = ?";
            $params = [$like, $like, $gid];
            $countParams = [$like, $gid];
        } elseif (!empty($dm)) {
            $dmId = get_uid_by_name($pdo, $dm);
            if (!$dmId) { echo json_encode(['success' => true, 'messages' => [], 'total' => 0]); exit; }
            $where = "AND ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))";
            $params = [$like, $like, $myId, $dmId, $dmId, $myId];
            $countParams = [$like, $myId, $dmId, $dmId, $myId];
        } else {
            $where = "AND m.group_id IS NULL AND (m.recipient_id IS NULL OR m.recipient_id = ? OR m.sender_id = ?)";
            $params = [$like, $like, $myId, $myId];
            $countParams = [$like, $myId, $myId];
        }

        $countSql = "SELECT COUNT(*) FROM messages m WHERE m.deleted_at IS NULL AND m.message LIKE ? $where";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT m.id, m.sender_id, su.username, su.display_name, su.avatar, su.user_id,
                       m.recipient_id, ru.username AS recipient_name,
                       m.message, m.msg_type, m.attachment, m.time, m.datetime, m.deleted_at, m.reply_to, m.temp_upload_id
                FROM messages m
                LEFT JOIN users su ON su.user_id = m.sender_id
                LEFT JOIN users ru ON ru.user_id = m.recipient_id
                WHERE m.deleted_at IS NULL AND (m.message LIKE ? OR m.msg_type = 'md' AND m.message LIKE ?) $where
                ORDER BY m.id DESC
                LIMIT $perPage OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();
        $processed = proc(array_reverse($messages));
        echo json_encode(['success' => true, 'messages' => $processed, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        break;

    default:
        echo json_encode(['success' => false]);
}

function proc(array $msgs): array {
    // Resolve reply_to references
    $replyIds = [];
    foreach ($msgs as $m) {
        if (!empty($m['reply_to'])) $replyIds[] = (int)$m['reply_to'];
    }
    $replyMap = [];
    if (!empty($replyIds)) {
        $pdo = db();
        $placeholders = implode(',', array_fill(0, count($replyIds), '?'));
        $rst = $pdo->prepare("SELECT m.id, m.message, m.deleted_at, u.username, COALESCE(u.display_name,u.username) AS display_name 
            FROM messages m LEFT JOIN users u ON u.user_id = m.sender_id 
            WHERE m.id IN ($placeholders)");
        $rst->execute($replyIds);
        while ($r = $rst->fetch()) {
            $replyMap[$r['id']] = [
                'id' => (int)$r['id'],
                'username' => $r['username'],
                'display_name' => $r['display_name'],
                'message' => ($r['deleted_at'] !== null) ? '[This message has been revoked]' : mb_substr($r['message'], 0, 80),
            ];
        }
    }

    $out = [];
    foreach ($msgs as $m) {
        $m['id'] = (int)$m['id'];
        $m['username'] = $m['username'] ?? 'Unknown';
        $m['display_name'] = $m['display_name'] ?? $m['username'];
        // Keep recipient as username string for JS compatibility
        $m['recipient'] = $m['recipient_name'] ?: null;
        $m['avatar'] = $m['avatar'] ?? null;
        $m['msg_type'] = $m['msg_type'] ?? null;
        $m['is_markdown'] = ($m['msg_type'] === 'md');
        if ($m['is_markdown']) {
            $m['message'] = $m['message'];
        }
        $m['is_deleted'] = ($m['deleted_at'] !== null);
        if ($m['is_deleted']) {
            $m['message'] = '[This message has been revoked]';
            $m['attachment_url'] = null;
        }
        unset($m['deleted_at']);
        $m['attachment_name'] = null;
        $m['attachment_size'] = null;
        if (!$m['is_deleted'] && !empty($m['attachment']) && !empty($m['msg_type'])) {
            if ($m['msg_type'] === 'file') {
                $meta = json_decode($m['attachment'], true);
                if (is_array($meta) && !empty($meta['file'])) {
                    $m['attachment_url'] = '../api/file.php?u=' . ((int)$m['user_id']) . '&f=' . rawurlencode($meta['file']) . '&name=' . rawurlencode($meta['name'] ?? 'file');
                    $m['attachment_name'] = $meta['name'] ?? 'file';
                    $m['attachment_size'] = isset($meta['size']) ? (int)$meta['size'] : null;
                } else {
                    $m['attachment_url'] = null;
                }
            } elseif ($m['msg_type'] === 'temp') {
                // Flash transfer: output temp_upload_id + file meta. Frontend polls status via api/temp.php.
                $meta = is_array(json_decode($m['attachment'], true)) ? json_decode($m['attachment'], true) : [];
                $m['temp_upload_id'] = (int)($meta['file'] ?? $m['temp_upload_id'] ?? 0);
                $m['attachment_name'] = $meta['name'] ?? 'file';
                $m['attachment_size'] = isset($meta['size']) ? (int)$meta['size'] : null;
                $m['attachment_url'] = null;
                if ($m['temp_upload_id'] > 0) {
                    $tStmt = db()->prepare("SELECT revoked, expires_at FROM temp_uploads WHERE id = ?");
                    $tStmt->execute([(int)$m['temp_upload_id']]);
                    $tmps = $tStmt->fetch();
                    $m['temp_revoked'] = $tmps ? (int)$tmps['revoked'] : 0;
                    $m['temp_expires'] = $tmps ? $tmps['expires_at'] : null;
                } else {
                    $m['temp_revoked'] = 0;
                    $m['temp_expires'] = null;
                }
            } else {
                $m['attachment_url'] = '../api/file.php?u=' . ((int)$m['user_id']) . '&f=' . $m['attachment'];
            }
        } elseif (!$m['is_deleted']) {
            $m['attachment_url'] = null;
        }
        // (temp block above replaces attachment processing for msg_type='temp')
        if (!$m['is_deleted'] && !empty($m['attachment']) && empty($m['msg_type']) && strpos($m['attachment'], 'data:') === 0) {
            $m['attachment_url'] = $m['attachment'];
        }
        $m['reply_data'] = (!empty($m['reply_to']) && isset($replyMap[(int)$m['reply_to']])) ? $replyMap[(int)$m['reply_to']] : null;
        unset($m['reply_to'], $m['sender_id'], $m['recipient_id'], $m['user_id'], $m['recipient_name'], $m['recipient_display'], $m['recipient_uid']);
        $out[] = $m;
    }
    return $out;
}