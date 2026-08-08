<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/chat_actions.php';

chatapp_session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
header('Content-Type: application/json');

function get_my_uid(PDO $pdo): int {
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
    $stmt->execute([$_SESSION['username']]);
    return (int)($stmt->fetchColumn() ?: 0);
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
        $pdo = db();
        $senderId = get_my_uid($pdo);
        if (!$senderId) { echo json_encode(['success' => false]); exit; }
        $params = $_POST;
        // 保留原始消息长度用于 EXP（共享函数内部会处理 htmlspecialchars）
        $params['message_raw'] = $_POST['message'] ?? '';
        $res = chat_action_send($pdo, $senderId, $_SESSION['username'], $params, true);
        if (isset($res['error']) && $res['error'] === 'restricted') {
            $res['error'] = t('msg_user_restricted');
        }
        if (isset($res['error']) && $res['error'] === 'not_friends') {
            $res['error'] = t('msg_not_friends');
        }
        echo json_encode($res);
        break;

    case 'revoke':
        if (!isset($_SESSION['username'])) { echo json_encode(['success'=>false]); exit; }
        $pdo = db(); $myId = get_my_uid($pdo);
        if (!$myId) { echo json_encode(['success'=>false]); exit; }
        echo json_encode(chat_action_revoke($pdo, $myId, $_POST));
        break;

    case 'unread_counts':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['success'=>true,'counts'=>[]]); exit;
        }
        $pdo = db();
        $myUid = get_my_uid($pdo);
        echo json_encode(chat_action_unread_counts($pdo, $myUid, $_SESSION['username']));
        break;

    case 'mark_read':
        if (!isset($_SESSION['username'])) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']); exit;
        }
        $pdo = db();
        $myUid = get_my_uid($pdo);
        echo json_encode(chat_action_mark_read($pdo, $myUid, $_SESSION['username'], $_POST));
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