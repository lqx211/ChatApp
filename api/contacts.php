<?php
/**
 * ChatApp - Contacts API (uses user_id for contacts table)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/contact_actions.php';   // 联系人写操作统一层（网页 + AI 工具共用）

chatapp_session_start();
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
    exit;
}
header('Content-Type: application/json');
$pdo = db();
$myUsername = $_SESSION['username'];

// Resolve my user_id
$stmtMe = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
$stmtMe->execute([$myUsername]);
$myUid = (int)($stmtMe->fetchColumn() ?: 0);
if (!$myUid) {
    echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Friend request mutations → POST only.
chatapp_read_actions(['search', 'list', 'pending'], $action);

switch ($action) {

    case 'search':
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) {
            echo json_encode(['success' => true, 'users' => []]);
            exit;
        }
        // Exact match only: UID or username (respect the searchable privacy flags)
        // is_bot=0：机器人搜不到（只能从「添加机器人」建）
        if (is_numeric($q)) {
            $stmt = $pdo->prepare("SELECT username, user_id FROM users WHERE user_id = ? AND username != ? AND searchable = 1 AND searchable_by_uid = 1 AND is_bot = 0 LIMIT 1");
            $stmt->execute([(int)$q, $myUsername]);
        } else {
            $stmt = $pdo->prepare("SELECT username, user_id FROM users WHERE username = ? AND username != ? AND searchable = 1 AND is_bot = 0 LIMIT 1");
            $stmt->execute([$q, $myUsername]);
        }
        $users = $stmt->fetchAll();
        $result = [];
        foreach ($users as $u) {
            $uid = (int)$u['user_id'];
            $st = $pdo->prepare("SELECT status FROM contacts WHERE (user_from = ? AND user_to = ?) OR (user_from = ? AND user_to = ?)");
            $st->execute([$myUid, $uid, $uid, $myUid]);
            $row = $st->fetch();
            $result[] = ['username' => $u['username'], 'user_id' => $uid, 'relation' => $row ? $row['status'] : null];
        }
        echo json_encode(['success' => true, 'users' => $result]);
        break;

    case 'send_request':
        // 逻辑已搬到 api/contact_actions.php（AI 工具 ca_contact_add 走同一份）
        echo json_encode(contact_action_send_request(
            $pdo, $myUid, $myUsername,
            (string)($_POST['username'] ?? ''),
            (string)($_POST['msg'] ?? ''),
            (string)($_POST['note'] ?? '')
        ));
        break;

    case 'respond':
        // 逻辑已搬到 api/contact_actions.php（行方向语义/等级上限都在里面）
        echo json_encode(contact_action_respond(
            $pdo, $myUid,
            (string)($_POST['username'] ?? ''),
            (string)($_POST['response'] ?? ''),
            (string)($_POST['note'] ?? '')
        ));
        break;

    case 'change_nickname':
        $targetUser = trim($_POST['username'] ?? '');
        $newNote = trim(mb_substr($_POST['note'] ?? '', 0, 500));
        if (empty($targetUser) || $targetUser === $myUsername) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$targetUser]);
        $targetUid = (int)($stmt->fetchColumn() ?: 0);
        if (!$targetUid) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
            exit;
        }
        // Only update MY note for them — must already be friends
        $st = $pdo->prepare("SELECT id FROM contacts WHERE user_from = ? AND user_to = ?");
        $st->execute([$myUid, $targetUid]);
        if ($st->fetch()) {
            $pdo->prepare("UPDATE contacts SET note = ? WHERE user_from = ? AND user_to = ?")
                ->execute([$newNote ?: null, $myUid, $targetUid]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Contact relationship not found.']);
        }
        break;


    case 'toggle_pin':
        // 逻辑已搬到 api/contact_actions.php（AI 工具 ca_pin 走同一份）
        echo json_encode(contact_action_toggle_pin($pdo, $myUid, $myUsername, (string)($_POST['username'] ?? '')));
        break;

    case 'toggle_pin_self':
        $pdo->prepare("UPDATE users SET pin_self = 1 - pin_self WHERE user_id = ?")->execute([$myUid]);
        echo json_encode(['success' => true]);
        break;

    case 'toggle_special':
        // 逻辑已搬到 api/contact_actions.php（AI 工具 ca_special_care 走同一份）
        echo json_encode(contact_action_toggle_special($pdo, $myUid, $myUsername, (string)($_POST['username'] ?? '')));
        break;


    case 'list':
        $stmt = $pdo->prepare("
            SELECT u.username, u.user_id, COALESCE(u.display_name, u.username) AS display_name, u.avatar, u.is_bot, u.bot_kind,
                   c_my.note AS note, c_my.pinned AS pinned, c_my.special AS special,
                   MAX(m.datetime) AS last_msg_time
            FROM users u
            INNER JOIN contacts c ON (
                (c.user_from = ? AND c.user_to = u.user_id AND c.status = 'accepted') OR
                (c.user_to = ? AND c.user_from = u.user_id AND c.status = 'accepted')
            )
            LEFT JOIN contacts c_my ON c_my.user_from = ? AND c_my.user_to = u.user_id
            LEFT JOIN messages m ON (
                m.recipient_id IS NOT NULL AND (
                    (m.sender_id = ? AND m.recipient_id = u.user_id) OR
                    (m.sender_id = u.user_id AND m.recipient_id = ?)
                )
            )
            WHERE u.user_id != ? AND (u.is_bot = 0 OR u.bot_owner_uid = ?)
            GROUP BY u.username, u.user_id, COALESCE(u.display_name, u.username), u.avatar, u.is_bot, u.bot_kind, c_my.note, c_my.pinned, c_my.special, u.user_id
            ORDER BY c_my.pinned DESC, last_msg_time IS NULL ASC, last_msg_time DESC
        ");
        $stmt->execute([$myUid, $myUid, $myUid, $myUid, $myUid, $myUid, $myUid]);
        $contacts = $stmt->fetchAll();
        // 新格式 avatar 存的是文件名（如 10077.png），需转成 /api/avatar.php 可访问的 URL
        foreach ($contacts as &$c) {
            if (!empty($c['avatar']) && strpos($c['avatar'], 'data:') !== 0 && preg_match('/^[0-9a-zA-Z_]+\.(png|jpg|jpeg|gif|webp)$/i', $c['avatar'])) {
                $c['avatar'] = '../../api/avatar.php?u=' . urlencode($c['username']);
            }
        }
        unset($c);
        $myPinSelf = (int)$pdo->query("SELECT pin_self FROM users WHERE user_id=$myUid")->fetchColumn();
        echo json_encode(['success' => true, 'contacts' => $contacts, 'pin_self' => $myPinSelf]);
        break;

    case 'pending':
        $stmt = $pdo->prepare("
            SELECT u.username, COALESCE(u.display_name, u.username) AS display_name, u.avatar, c.msg, c.created_at
            FROM contacts c
            JOIN users u ON u.user_id = c.user_from
            WHERE c.user_to = ? AND c.status = 'pending' AND u.is_bot = 0
        ");
        $stmt->execute([$myUid]);
        $pending = $stmt->fetchAll();
        // 文件名 avatar → URL（新格式）
        foreach ($pending as &$p) {
            if (!empty($p['avatar']) && strpos($p['avatar'], 'data:') !== 0 && preg_match('/^[0-9a-zA-Z_]+\.(png|jpg|jpeg|gif|webp)$/i', $p['avatar'])) {
                $p['avatar'] = '../../api/avatar.php?u=' . urlencode($p['username']);
            }
        }
        unset($p);
        echo json_encode(['success' => true, 'pending' => $pending]);
        break;

    case 'delete':
        // 逻辑已搬到 api/contact_actions.php（AI 工具 ca_contact_remove 走同一份）
        echo json_encode(contact_action_remove($pdo, $myUid, $myUsername, (string)($_POST['username'] ?? '')));
        break;

    case 'force_add':
        $targetUser = trim($_POST['username'] ?? '');
        if (empty($targetUser) || $targetUser === $myUsername) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
            exit;
        }
        // Only root or admin can force-add
        $role = chatapp_get_role($myUid);
        if ($role !== 'root' && $role !== 'admin') {
            echo json_encode(['success' => false, 'error' => 'No permission.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$targetUser]);
        $targetUid = (int)($stmt->fetchColumn() ?: 0);
        if (!$targetUid) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
            exit;
        }
        // Check existing
        $st = $pdo->prepare("SELECT id, status FROM contacts WHERE (user_from = ? AND user_to = ?) OR (user_from = ? AND user_to = ?)");
        $st->execute([$myUid, $targetUid, $targetUid, $myUid]);
        $ex = $st->fetch();
        if ($ex) {
            if ($ex['status'] === 'accepted') {
                echo json_encode(['success' => true, 'already' => true]);
                exit;
            }
            $pdo->prepare("UPDATE contacts SET status='accepted', created_at=NOW() WHERE id=?")->execute([$ex['id']]);
        } else {
            $pdo->prepare("INSERT INTO contacts (user_from, user_to, status) VALUES (?, ?, 'accepted')")->execute([$myUid, $targetUid]);
        }
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
}