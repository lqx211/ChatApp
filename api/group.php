<?php
/**
 * ChatApp - Group Chat API
 */

require_once __DIR__ . '/config.php';

chatapp_session_start();
isset($_SESSION['username']) or die(json_encode(['success' => false]));

header('Content-Type: application/json');
$pdo = db();
$me = $_SESSION['username'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Get my UID
$myUid = (int)($pdo->query("SELECT user_id FROM users WHERE username='$me'")->fetchColumn() ?: 0);
if ($myUid <= 0) { echo json_encode(['success' => false]); exit; }

function gen_group_id(PDO $pdo): int {
    for ($i = 0; $i < 20; $i++) {
        $id = random_int(1000000, 150000000);
        if (!$pdo->query("SELECT 1 FROM `groups` WHERE group_id=$id")->fetch()) {
            return $id;
        }
    }
    throw new Exception('Failed to generate unique group ID');
}

switch ($action) {

    case 'create':
        $name = trim(mb_substr($_POST['name'] ?? '', 0, 50));
        if (empty($name)) { echo json_encode(['success' => false]); exit; }

        // Level-gated owned-groups limit (jh.md Lv Limits: max_groups)
        $maxGroups = level_limits(user_level($pdo, $myUid))['max_groups'];
        $ownedCount = (int)$pdo->query("SELECT COUNT(*) FROM `groups` WHERE owner_id=$myUid")->fetchColumn();
        if ($ownedCount >= $maxGroups) {
            echo json_encode([
                'success' => false,
                'error' => 'Group limit reached',
                'max_groups' => $maxGroups,
            ]);
            exit;
        }

        $gid = gen_group_id($pdo);
        $pdo->prepare("INSERT INTO `groups` (group_id, name, owner_id) VALUES (?, ?, ?)")->execute([$gid, $name, $myUid]);
        $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$gid, $myUid]);
        echo json_encode(['success' => true, 'group_id' => $gid]);
        break;

    case 'join':
        $gid = (int)($_POST['group_id'] ?? 0);
        if ($gid <= 0) { echo json_encode(['success' => false]); exit; }
        $g = $pdo->query("SELECT * FROM `groups` WHERE group_id=$gid")->fetch();
        if (!$g || !$g['public']) { echo json_encode(['success' => false]); exit; }
        try { $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")->execute([$gid, $myUid]); }
        catch (Exception $e) { echo json_encode(['success' => false, 'error' => 'Already a member.']); exit; }
        echo json_encode(['success' => true]);
        break;

    case 'request':
        $gid = (int)($_POST['group_id'] ?? 0);
        if ($gid <= 0) { echo json_encode(['success' => false]); exit; }
        $g = $pdo->query("SELECT * FROM `groups` WHERE group_id=$gid")->fetch();
        if (!$g) { echo json_encode(['success' => false]); exit; }
        // Already a member?
        if ($pdo->query("SELECT 1 FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Already a member.']); exit;
        }
        try { $pdo->prepare("INSERT INTO group_requests (group_id, user_id) VALUES (?, ?)")->execute([$gid, $myUid]); }
        catch (Exception $e) { echo json_encode(['success' => false, 'error' => 'Already requested.']); exit; }
        echo json_encode(['success' => true]);
        break;

    case 'join_by_gid':
        // Join or request by GID
        $gid = (int)($_POST['group_id'] ?? 0);
        if ($gid <= 0) { echo json_encode(['success' => false]); exit; }
        $g = $pdo->query("SELECT * FROM `groups` WHERE group_id=$gid")->fetch();
        if (!$g) { echo json_encode(['success' => false, 'error' => 'Group not found.']); exit; }
        if ($g['public']) {
            try { $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")->execute([$gid, $myUid]); }
            catch (Exception $e) { echo json_encode(['success' => false, 'error' => 'Already a member.']); exit; }
            echo json_encode(['success' => true, 'joined' => true]);
        } else {
            try { $pdo->prepare("INSERT INTO group_requests (group_id, user_id) VALUES (?, ?)")->execute([$gid, $myUid]); }
            catch (Exception $e) { echo json_encode(['success' => false, 'error' => 'Already requested.']); exit; }
            echo json_encode(['success' => true, 'requested' => true]);
        }
        break;

    case 'approve':
    case 'reject':
        $rid = (int)($_POST['request_id'] ?? 0);
        $st = $action === 'approve' ? 'accepted' : 'rejected';
        $req = $pdo->query("SELECT * FROM group_requests WHERE id=$rid")->fetch();
        if (!$req) { echo json_encode(['success' => false]); exit; }
        // Check permission
        $myRole = $pdo->query("SELECT role FROM group_members WHERE group_id={$req['group_id']} AND user_id=$myUid")->fetchColumn();
        if (!$myRole || ($myRole !== 'owner' && $myRole !== 'admin')) { echo json_encode(['success' => false]); exit; }
        $pdo->prepare("UPDATE group_requests SET status=? WHERE id=?")->execute([$st, $rid]);
        if ($st === 'accepted') {
            try { $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")->execute([$req['group_id'], $req['user_id']]); }
            catch (Exception $e) {}
        }
        echo json_encode(['success' => true]);
        break;

    case 'search':
        $q = trim($_GET['q'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $where = "WHERE 1=1";
        $params = [];
        if ($q !== '') {
            $where .= " AND (g.name LIKE ? OR CAST(g.group_id AS CHAR) LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        $total = (int)$pdo->query("SELECT COUNT(*) FROM `groups` g $where")->fetchColumn();
        $stmt = $pdo->prepare("SELECT g.*, COALESCE(u.display_name, u.username) AS owner_name, u.avatar AS owner_avatar, (SELECT COUNT(*) FROM group_members WHERE group_id=g.group_id) AS member_count FROM `groups` g JOIN users u ON u.user_id=g.owner_id $where ORDER BY g.created_at DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        echo json_encode(['success' => true, 'groups' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        break;

    case 'list_my':
        $stmt = $pdo->prepare("SELECT g.*, gm.role, gm.muted FROM `groups` g JOIN group_members gm ON gm.group_id=g.group_id WHERE gm.user_id=? ORDER BY g.created_at DESC");
        $stmt->execute([$myUid]);
        echo json_encode(['success' => true, 'groups' => $stmt->fetchAll()]);
        break;

    case 'members':
        $gid = (int)($_GET['group_id'] ?? 0);
        if (!$pdo->query("SELECT 1 FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetch()) {
            echo json_encode(['success' => false]); exit;
        }
        $stmt = $pdo->prepare("SELECT gm.*, COALESCE(u.display_name, u.username) AS display_name, u.avatar FROM group_members gm JOIN users u ON u.user_id=gm.user_id WHERE gm.group_id=? ORDER BY FIELD(gm.role,'owner','admin','member'), gm.joined_at ASC");
        $stmt->execute([$gid]);
        echo json_encode(['success' => true, 'members' => $stmt->fetchAll()]);
        break;

    case 'pending':
        $gid = (int)($_GET['group_id'] ?? 0);
        if (!$gid) { echo json_encode(['success' => false]); exit; }
        $myRole = $pdo->query("SELECT role FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetchColumn();
        if (!$myRole || ($myRole !== 'owner' && $myRole !== 'admin')) { echo json_encode(['success' => false]); exit; }
        $stmt = $pdo->prepare("SELECT gr.*, COALESCE(u.display_name, u.username) AS display_name, u.avatar FROM group_requests gr JOIN users u ON u.user_id=gr.user_id WHERE gr.group_id=? AND gr.status='pending' ORDER BY gr.created_at DESC");
        $stmt->execute([$gid]);
        echo json_encode(['success' => true, 'requests' => $stmt->fetchAll()]);
        break;

    case 'kick':
        $gid = (int)($_POST['group_id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        if (!$gid || !$uid) { echo json_encode(['success' => false]); exit; }
        $myRole = $pdo->query("SELECT role FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetchColumn();
        if (!$myRole || ($myRole !== 'owner' && $myRole !== 'admin')) { echo json_encode(['success' => false]); exit; }
        // Cannot kick owner
        $targetRole = $pdo->query("SELECT role FROM group_members WHERE group_id=$gid AND user_id=$uid")->fetchColumn();
        if ($targetRole === 'owner') { echo json_encode(['success' => false]); exit; }
        $pdo->prepare("DELETE FROM group_members WHERE group_id=? AND user_id=?")->execute([$gid, $uid]);
        echo json_encode(['success' => true]);
        break;

    case 'set_admin':
        $gid = (int)($_POST['group_id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        if (!$gid || !$uid) { echo json_encode(['success' => false]); exit; }
        $myRole = $pdo->query("SELECT role FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetchColumn();
        if ($myRole !== 'owner') { echo json_encode(['success' => false]); exit; }
        $pdo->prepare("UPDATE group_members SET role='admin' WHERE group_id=? AND user_id=?")->execute([$gid, $uid]);
        echo json_encode(['success' => true]);
        break;

    case 'unset_admin':
        $gid = (int)($_POST['group_id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        if (!$gid || !$uid) { echo json_encode(['success' => false]); exit; }
        $myRole = $pdo->query("SELECT role FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetchColumn();
        if ($myRole !== 'owner') { echo json_encode(['success' => false]); exit; }
        $pdo->prepare("UPDATE group_members SET role='member' WHERE group_id=? AND user_id=?")->execute([$gid, $uid]);
        echo json_encode(['success' => true]);
        break;

    case 'rename':
        $gid = (int)($_POST['group_id'] ?? 0);
        $name = trim(mb_substr($_POST['name'] ?? '', 0, 50));
        if (!$gid || empty($name)) { echo json_encode(['success' => false]); exit; }
        $myRole = $pdo->query("SELECT role FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetchColumn();
        if ($myRole !== 'owner') { echo json_encode(['success' => false]); exit; }
        $pdo->prepare("UPDATE `groups` SET name=? WHERE group_id=?")->execute([$name, $gid]);
        echo json_encode(['success' => true]);
        break;

    case 'set_visibility':
        $gid = (int)($_POST['group_id'] ?? 0);
        $pub = (int)($_POST['public'] ?? 0);
        if (!$gid) { echo json_encode(['success' => false]); exit; }
        $myRole = $pdo->query("SELECT role FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetchColumn();
        if ($myRole !== 'owner') { echo json_encode(['success' => false]); exit; }
        $pdo->prepare("UPDATE `groups` SET public=? WHERE group_id=?")->execute([$pub, $gid]);
        echo json_encode(['success' => true]);
        break;

    case 'toggle_mute':
        $gid = (int)($_POST['group_id'] ?? 0);
        if (!$gid) { echo json_encode(['success' => false]); exit; }
        $cur = (int)($pdo->query("SELECT muted FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetchColumn() ?: 0);
        $pdo->prepare("UPDATE group_members SET muted=? WHERE group_id=? AND user_id=?")->execute([$cur ? 0 : 1, $gid, $myUid]);
        echo json_encode(['success' => true, 'muted' => $cur ? 0 : 1]);
        break;

    case 'leave':
        $gid = (int)($_POST['group_id'] ?? 0);
        $myRole = $pdo->query("SELECT role FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetchColumn();
        if ($myRole === 'owner') { echo json_encode(['success' => false, 'error' => 'Owner cannot leave. Dissolve the group instead.']); exit; }
        $pdo->prepare("DELETE FROM group_members WHERE group_id=? AND user_id=?")->execute([$gid, $myUid]);
        echo json_encode(['success' => true]);
        break;

    case 'dissolve':
        $gid = (int)($_POST['group_id'] ?? 0);
        $myRole = $pdo->query("SELECT role FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetchColumn();
        if ($myRole !== 'owner') { echo json_encode(['success' => false]); exit; }
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM group_requests WHERE group_id=?")->execute([$gid]);
        $pdo->prepare("DELETE FROM group_members WHERE group_id=?")->execute([$gid]);
        $pdo->prepare("DELETE FROM `groups` WHERE group_id=?")->execute([$gid]);
        $pdo->commit();
        echo json_encode(['success' => true]);
        break;

    case 'send':
        $gid = (int)($_POST['group_id'] ?? 0);
        $msg = trim(mb_substr($_POST['message'] ?? '', 0, 1000));
        if (!$gid || empty($msg)) { echo json_encode(['success' => false]); exit; }
        // Must be a member
        $info = $pdo->query("SELECT role, muted FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetch();
        if (!$info) { echo json_encode(['success' => false]); exit; }
        if ((int)$info['muted']) {
            echo json_encode(['success' => false, 'error' => 'You are muted in this group.']);
            exit;
        }
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO messages (sender_id, group_id, message, time, datetime) VALUES (?, ?, ?, ?, ?)")->execute([$myUid, $gid, $msg, $now, $now]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;

    case 'history':
        $gid = (int)($_GET['group_id'] ?? 0);
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 50)));
        $before = (int)($_GET['before'] ?? 0);
        if (!$gid) { echo json_encode(['success' => false]); exit; }
        if (!$pdo->query("SELECT 1 FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetch()) {
            echo json_encode(['success' => false]); exit;
        }
        $where = "m.group_id = $gid";
        if ($before) $where .= " AND m.id < $before";
        $stmt = $pdo->prepare("SELECT m.*, COALESCE(u.display_name, u.username) AS display_name, u.username, u.avatar FROM messages m JOIN users u ON u.user_id=m.sender_id WHERE $where ORDER BY m.id DESC LIMIT $limit");
        $stmt->execute();
        $messages = array_reverse($stmt->fetchAll());
        $hasMore = $before ? ($pdo->query("SELECT COUNT(*) FROM messages WHERE group_id=$gid AND id < $before")->fetchColumn() > 0) : (count($messages) == $limit);
        $oldestId = count($messages) > 0 ? (int)$messages[0]['id'] : 0;
        echo json_encode(['success' => true, 'messages' => $messages, 'has_more' => $hasMore, 'oldest_id' => $oldestId]);
        break;

    case 'info':
        $gid = (int)($_GET['group_id'] ?? 0);
        $g = $pdo->query("SELECT g.*, COALESCE(u.display_name, u.username) AS owner_name, u.avatar AS owner_avatar FROM `groups` g JOIN users u ON u.user_id=g.owner_id WHERE g.group_id=$gid")->fetch();
        if (!$g) { echo json_encode(['success' => false]); exit; }
        $myInfo = $pdo->query("SELECT * FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetch();
        $g['my_role'] = $myInfo ? $myInfo['role'] : null;
        $g['my_muted'] = (int)($myInfo ? $myInfo['muted'] : 0);
        echo json_encode(['success' => true, 'group' => $g]);
        break;

    case 'fetch':
        $gid = (int)($_GET['group_id'] ?? 0);
        $after = (int)($_GET['after'] ?? 0);
        if (!$gid || !$after) { echo json_encode(['success' => false]); exit; }
        if (!$pdo->query("SELECT 1 FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetch()) {
            echo json_encode(['success' => false]); exit;
        }
        $stmt = $pdo->prepare("SELECT m.*, COALESCE(u.display_name, u.username) AS display_name, u.username, u.avatar FROM messages m JOIN users u ON u.user_id=m.sender_id WHERE m.group_id=? AND m.id > ? ORDER BY m.id ASC LIMIT 50");
        $stmt->execute([$gid, $after]);
        $messages = $stmt->fetchAll();
        $latestId = count($messages) > 0 ? (int)$messages[count($messages)-1]['id'] : $after;
        echo json_encode(['success' => true, 'messages' => $messages, 'latest_id' => $latestId]);
        break;

    default:
        echo json_encode(['success' => false]);
}