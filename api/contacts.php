<?php
/**
 * ChatApp - Contacts API (uses user_id for contacts table)
 */

require_once __DIR__ . '/config.php';

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
        if (is_numeric($q)) {
            $stmt = $pdo->prepare("SELECT username, user_id FROM users WHERE user_id = ? AND username != ? AND searchable = 1 AND searchable_by_uid = 1 LIMIT 1");
            $stmt->execute([(int)$q, $myUsername]);
        } else {
            $stmt = $pdo->prepare("SELECT username, user_id FROM users WHERE username = ? AND username != ? AND searchable = 1 LIMIT 1");
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
        $toUser = trim($_POST['username'] ?? '');
        $msg = trim(mb_substr($_POST['msg'] ?? '', 0, 200));
        // note = 我预留给对方的备注（行 (me→them) 的 note 列），发请求时即可设置
        $note = trim(mb_substr($_POST['note'] ?? '', 0, 500));
        if (empty($toUser) || $toUser === $myUsername) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$toUser]);
        $toUid = (int)($stmt->fetchColumn() ?: 0);
        if (!$toUid) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
            exit;
        }

        // 黑名单：对方拉黑则拒绝好友申请
        if (chatapp_is_blocked($myUid, $toUid)) {
            echo json_encode(['success' => false, 'error' => 'blocked']);
            exit;
        }
        // 对方关闭「允许任何人添加我为好友」
        $allowStmt = $pdo->prepare('SELECT anyone_add_friend FROM users WHERE user_id = ?');
        $allowStmt->execute([$toUid]);
        if ((int)$allowStmt->fetchColumn() === 0) {
            echo json_encode(['success' => false, 'error' => 'not_accepting']);
            exit;
        }

        $st = $pdo->prepare("SELECT id, status FROM contacts WHERE (user_from = ? AND user_to = ?) OR (user_from = ? AND user_to = ?)");
        $st->execute([$myUid, $toUid, $toUid, $myUid]);
        $ex = $st->fetch();

        if ($ex) {
            if ($ex['status'] === 'accepted') {
                echo json_encode(['success' => false, 'error' => 'Already friends.']);
                exit;
            }
            if ($ex['status'] === 'pending') {
                echo json_encode(['success' => false, 'error' => 'Request already pending.']);
                exit;
            }
            $pdo->prepare("UPDATE contacts SET status = 'pending', msg = ?, note = ?, created_at = NOW() WHERE id = ?")->execute([$msg ?: null, $note ?: null, $ex['id']]);
            echo json_encode(['success' => true]);
            exit;
        }

        $pdo->prepare("INSERT INTO contacts (user_from, user_to, status, msg, note) VALUES (?, ?, 'pending', ?, ?)")->execute([$myUid, $toUid, $msg ?: null, $note ?: null]);
        echo json_encode(['success' => true]);
        break;

    case 'respond':
        $fromUser = trim($_POST['username'] ?? '');
        $resp = trim($_POST['response'] ?? '');
        if (!in_array($resp, ['accept', 'reject'])) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$fromUser]);
        $fromUid = (int)($stmt->fetchColumn() ?: 0);
        if (!$fromUid) {
            echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
            exit;
        }
        $ns = $resp === 'accept' ? 'accepted' : 'rejected';
        // 行方向语义：(A → B).note = A 对 B 的备注（见 list/change_nickname）。
        // 待处理行是对方(them→me)发来的申请 —— 只能改状态；我的备注绝不能写进
        // 对方的行，否则对方那边会把我给 ta 的备注当成 ta 对我的备注来显示
        // （历史 bug：别人加我后，他那边显示我的名字 = 我接受的默认备注）。
        $note = trim(mb_substr($_POST['note'] ?? '', 0, 500));
        $stmt = $pdo->prepare("UPDATE contacts SET status = ? WHERE user_from = ? AND user_to = ? AND status = 'pending'");
        $stmt->execute([$ns, $fromUid, $myUid]);
        $ok = $stmt->rowCount() > 0;
        if ($ok && $ns === 'accepted') {
            // Level-gated contacts limit (jh.md Lv Limits: max_contacts).
            // Count unique accepted friends for ME (the accepter).
            $maxContacts = level_limits(user_level($pdo, $myUid))['max_contacts'];
            $friendCount = (int)$pdo->query(
                "SELECT COUNT(*) FROM (
                    SELECT user_to AS uid FROM contacts WHERE user_from = $myUid AND status = 'accepted'
                    UNION
                    SELECT user_from AS uid FROM contacts WHERE user_to = $myUid AND status = 'accepted'
                 ) t"
            )->fetchColumn();
            if ($friendCount >= $maxContacts) {
                // Roll back the accept: revert to pending so the requester can retry later.
                // 不要动对方的 note（那是 ta 对我的备注）
                $pdo->prepare("UPDATE contacts SET status = 'pending' WHERE user_from = ? AND user_to = ?")
                    ->execute([$fromUid, $myUid]);
                echo json_encode([
                    'success' => false,
                    'error' => 'Contact limit reached',
                    'max_contacts' => $maxContacts,
                ]);
                exit;
            }
            // 我的备注写到我自己的行 (me→them)；已有则更新，避免重复行
            $rev = $pdo->prepare("SELECT id FROM contacts WHERE user_from = ? AND user_to = ?");
            $rev->execute([$myUid, $fromUid]);
            if ($rev->fetch()) {
                $pdo->prepare("UPDATE contacts SET status = 'accepted', note = ? WHERE user_from = ? AND user_to = ?")
                    ->execute([$note ?: null, $myUid, $fromUid]);
            } else {
                $pdo->prepare("INSERT INTO contacts (user_from, user_to, status, note) VALUES (?, ?, 'accepted', ?)")
                    ->execute([$myUid, $fromUid, $note ?: null]);
            }
        }
        echo json_encode(['success' => $ok]);
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
        $targetUser = trim($_POST['username'] ?? '');
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
        $st = $pdo->prepare("SELECT id FROM contacts WHERE user_from = ? AND user_to = ?");
        $st->execute([$myUid, $targetUid]);
        if ($st->fetch()) {
            $pdo->prepare("UPDATE contacts SET pinned = 1 - pinned WHERE user_from = ? AND user_to = ?")
                ->execute([$myUid, $targetUid]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Contact relationship not found.']);
        }
        break;

    case 'toggle_pin_self':
        $pdo->prepare("UPDATE users SET pin_self = 1 - pin_self WHERE user_id = ?")->execute([$myUid]);
        echo json_encode(['success' => true]);
        break;


    case 'list':
        $stmt = $pdo->prepare("
            SELECT u.username, COALESCE(u.display_name, u.username) AS display_name, u.avatar,
                   c_my.note AS note, c_my.pinned AS pinned,
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
            WHERE u.user_id != ?
            GROUP BY u.username, COALESCE(u.display_name, u.username), u.avatar, c_my.note, c_my.pinned, u.user_id
            ORDER BY c_my.pinned DESC, last_msg_time IS NULL ASC, last_msg_time DESC
        ");
        $stmt->execute([$myUid, $myUid, $myUid, $myUid, $myUid, $myUid]);
        $contacts = $stmt->fetchAll();
        // 新格式 avatar 存的是文件名（如 10077.png），需转成 /api/avatar.php 可访问的 URL
        foreach ($contacts as &$c) {
            if (!empty($c['avatar']) && strpos($c['avatar'], 'data:') !== 0 && preg_match('/^[0-9a-zA-Z_]+\.(png|jpg|jpeg|gif|webp)$/i', $c['avatar'])) {
                $c['avatar'] = '../api/avatar.php?u=' . urlencode($c['username']);
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
            WHERE c.user_to = ? AND c.status = 'pending'
        ");
        $stmt->execute([$myUid]);
        $pending = $stmt->fetchAll();
        // 文件名 avatar → URL（新格式）
        foreach ($pending as &$p) {
            if (!empty($p['avatar']) && strpos($p['avatar'], 'data:') !== 0 && preg_match('/^[0-9a-zA-Z_]+\.(png|jpg|jpeg|gif|webp)$/i', $p['avatar'])) {
                $p['avatar'] = '../api/avatar.php?u=' . urlencode($p['username']);
            }
        }
        unset($p);
        echo json_encode(['success' => true, 'pending' => $pending]);
        break;

    case 'delete':
        $targetUser = trim($_POST['username'] ?? '');
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
        $stmt = $pdo->prepare("DELETE FROM contacts WHERE (user_from = ? AND user_to = ?) OR (user_from = ? AND user_to = ?)");
        $stmt->execute([$myUid, $targetUid, $targetUid, $myUid]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
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