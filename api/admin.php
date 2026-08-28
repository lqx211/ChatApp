<?php
require_once __DIR__ . '/config.php';

chatapp_session_start();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
header('Content-Type: application/json');

// Admin mutations → POST only (the listed read actions may use GET). db_export
// is a GET download opened via window.open, so it additionally requires a CSRF
// token, verified inside its case.
chatapp_read_actions(['list', 'user_detail', 'role_list', 'admin_logs', 'login_logs', 'exp_logs', 'security_logs', 'db_tables', 'db_structure', 'db_export'], $action);

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false]); exit;
}

$pdo = db();
$myUid = 0;
$stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
$stmt->execute([$_SESSION['username']]);
$myUid = (int)($stmt->fetchColumn() ?: 0);

// NOTE: only actions that perform their own authorization internally may be listed
// here (role_* / set_role do). list / user_detail / admin_logs / login_logs /
// exp_logs are intentionally NOT public — they expose user IPs, logs and account data.
$publicActions = ['role_list', 'role_save', 'role_delete', 'set_role'];
if (!in_array($action, $publicActions)) {
    $role = chatapp_get_role($myUid);
    if ($role !== 'root' && $role !== 'admin') {
        echo json_encode(['success' => false]); exit;
    }
}

function _uid(PDO $pdo, string $u): int {
    $s = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
    $s->execute([$u]);
    return (int)($s->fetchColumn() ?: 0);
}

function _uid_override(string $u): int {
    $p = db();
    $s = $p->prepare("SELECT user_id FROM users WHERE username = ?");
    $s->execute([$u]);
    return (int)($s->fetchColumn() ?: 0);
}

switch ($action) {

    case 'list':
        $search = trim($_GET['search'] ?? '');
        $regex  = ($_GET['regex'] ?? '') === '1';
        $showDeleted = ($_GET['deleted'] ?? '') === '1';
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $sortMap = ['user_id'=>'user_id','username'=>'username','enabled'=>'enabled','last_login'=>'last_login','created_at'=>'created_at','status'=>'enabled'];
        $sortCol = $sortMap[$_GET['sort'] ?? 'username'] ?? 'username';
        $sortDir = (($_GET['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';
        $orderBy = "ORDER BY $sortCol $sortDir, user_id ASC";

        if ($showDeleted) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL");
        } elseif ($search !== '') {
            if ($regex) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND username REGEXP ?");
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND username LIKE ?");
                $search = "%$search%";
            }
            $stmt->execute([$search]);
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL");
        }
        $total = (int)$stmt->fetchColumn();
        $offset = ($page - 1) * $perPage;

        if ($showDeleted) {
            $stmt = $pdo->prepare("SELECT user_id, username, display_name, enabled, placeholder, restricted, role, created_at, last_login FROM users WHERE deleted_at IS NOT NULL $orderBy LIMIT $perPage OFFSET $offset");
            $stmt->execute();
        } elseif ($search !== '') {
            if ($regex) {
                $stmt = $pdo->prepare("SELECT user_id, username, display_name, enabled, placeholder, restricted, role, created_at, last_login FROM users WHERE deleted_at IS NULL AND username REGEXP ? $orderBy LIMIT $perPage OFFSET $offset");
            } else {
                $stmt = $pdo->prepare("SELECT user_id, username, display_name, enabled, placeholder, restricted, role, created_at, last_login FROM users WHERE deleted_at IS NULL AND username LIKE ? $orderBy LIMIT $perPage OFFSET $offset");
            }
            $stmt->execute([$search]);
        } else {
            $stmt = $pdo->prepare("SELECT user_id, username, display_name, enabled, placeholder, restricted, role, created_at, last_login FROM users WHERE deleted_at IS NULL $orderBy LIMIT $perPage OFFSET $offset");
            $stmt->execute();
        }
        $users = $stmt->fetchAll();
        foreach ($users as &$u) {
            $u['enabled'] = (int)$u['enabled'];
            if (_uid_override($u['username']) === 10000) $u['role'] = 'root';
        }
        echo json_encode(['success'=>true,'users'=>$users,'total'=>$total,'page'=>$page,'per_page'=>$perPage]);
        break;

    case 'toggle':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username) || $uid === $myUid) { echo json_encode(['success'=>false]); exit; }
        $stmt = $pdo->prepare("SELECT enabled FROM users WHERE username = ?");
        $stmt->execute([$username]); $user = $stmt->fetch();
        if (!$user) { echo json_encode(['success'=>false]); exit; }
        $newState = $user['enabled'] ? 0 : 1;
        // When disabling, also bump token_reset so any session issued before this
        // moment is invalidated on its next request (see chatapp_session_valid).
        $pdo->prepare("UPDATE users SET enabled = ?, token_reset = IF(? = 0, NOW(), token_reset) WHERE username = ?")
            ->execute([$newState, $newState, $username]);
        echo json_encode(['success'=>true,'enabled'=>$newState]);
        chatapp_log_admin('toggle', $uid, $username, ['enabled' => (bool)$newState]);
        break;

    case 'delete':
        if (!chatapp_has_permission($myUid, 'users.delete')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username) || $uid === $myUid) { echo json_encode(['success'=>false]); exit; }
        $pdo->prepare("UPDATE users SET username=CONCAT('deleted_', user_id), enabled=0, placeholder=1, deleted_at=NOW() WHERE username=?")->execute([$username]);
        echo json_encode(['success'=>true]);
        chatapp_log_admin('delete', $uid, $username);
        break;

    case 'change_password':
        if (!chatapp_has_permission($myUid, 'users.change_password')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username)) { echo json_encode(['success'=>false]); exit; }
        // Changing another admin's password requires root.
        $targetRole = chatapp_get_role($uid);
        if (($targetRole === 'admin' || $targetRole === 'root') && chatapp_get_role($myUid) !== 'root') { echo json_encode(['success'=>false]); exit; }
        $pw = $_POST['new_password'] ?? '';
        $err = chatapp_validate_password($pw);
        if ($err) { echo json_encode(['success'=>false,'error'=>t($err)]); exit; }
        $pdo->prepare("UPDATE users SET password = ? WHERE username = ?")->execute([password_hash($pw, PASSWORD_BCRYPT), $username]);
        echo json_encode(['success'=>true]);
        break;

    case 'login_as':
        // Only the root account (uid 10000 / username admin) can impersonate.
        if (chatapp_get_role($myUid) !== 'root') { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username) || $uid === $myUid) { echo json_encode(['success'=>false]); exit; }
        $loginBy = $_SESSION['username'];
        session_regenerate_id(true);
        $_SESSION['admin_username'] = $loginBy;
        $_SESSION['username'] = $username;
        echo json_encode(['success'=>true]);
        chatapp_log('admin_logs', ['admin_uid' => $myUid, 'admin_username' => $loginBy, 'action' => 'login_as', 'target_uid' => $uid, 'target_username' => $username, 'details' => json_encode(['by' => $loginBy], JSON_UNESCAPED_UNICODE)]);
        break;

    case 'add_user':
        if (!chatapp_has_permission($myUid, 'users.add_user')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $lang = trim($_POST['language'] ?? 'en');
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) { echo json_encode(['success'=>false]); exit; }
        if (chatapp_validate_password($password)) { echo json_encode(['success'=>false,'error'=>t(chatapp_validate_password($password))]); exit; }
        if (!in_array($lang,['en','zh','zh_egg','wyw','raw'])) $lang='en';
        try {
            $pdo->prepare("INSERT INTO users (username, password, preferred_language, role, created_at) VALUES (?, ?, ?, 'user', NOW())")->execute([$username, password_hash($password, PASSWORD_BCRYPT), $lang]);
            echo json_encode(['success'=>true]);
            chatapp_log_admin('add_user', (int)$pdo->lastInsertId(), $username);
        } catch (Exception $e) { echo json_encode(['success'=>false,'error'=>'Username may already exist.']); }
        break;

    case 'add_placeholder':
        if (!chatapp_has_permission($myUid, 'users.add_user')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) { echo json_encode(['success'=>false]); exit; }
        try {
            $pdo->prepare("INSERT INTO users (username, password, enabled, placeholder, role, preferred_language, created_at) VALUES (?, ?, 0, 1, 'user', 'en', NOW())")->execute([$username, password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT)]);
            echo json_encode(['success'=>true]);
            chatapp_log_admin('add_placeholder', (int)$pdo->lastInsertId(), $username);
        } catch (Exception $e) { echo json_encode(['success'=>false,'error'=>'Username may already exist.']); }
        break;

    case 'user_detail':
        $username = trim($_GET['username'] ?? '');
        if (empty($username)) { echo json_encode(['success'=>false]); exit; }
        $stmt = $pdo->prepare("SELECT username, display_name, user_id, avatar, enabled, restricted, restricted_reason, placeholder, dnd, role, created_at, last_login, exp, level FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $u = $stmt->fetch();
        if (!$u) { echo json_encode(['success'=>false]); exit; }
        if ((int)$u['user_id'] === 10000) $u['role'] = 'root';
        $st = 'Enabled';
        if ($u['placeholder']) $st = 'Placeholder';
        elseif ($u['restricted']) $st = 'Restricted';
        elseif (!$u['enabled']) $st = 'Disabled';
        $u['status_label'] = $st;
        $cs = $pdo->prepare("SELECT status FROM contacts WHERE (user_from=? AND user_to=?) OR (user_from=? AND user_to=?)");
        $cs->execute([$myUid, (int)$u['user_id'], (int)$u['user_id'], $myUid]);
        $rel = $cs->fetch();
        $u['friend_relation'] = $rel ? $rel['status'] : null;
        // 文件名 avatar → ../api/avatar.php URL（data URI 保留原样）
        if (!empty($u['avatar']) && strpos($u['avatar'], 'data:') !== 0 && preg_match('/^[0-9a-zA-Z_]+\.(png|jpg|jpeg|gif|webp)$/i', $u['avatar'])) {
            $u['avatar'] = '../../api/avatar.php?u=' . urlencode($u['username']);
        }
        echo json_encode(['success'=>true,'user'=>$u]);
        break;

    case 'change_username':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $newName = trim($_POST['new_username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username) || empty($newName)) { echo json_encode(['success'=>false]); exit; }
        // Renaming another admin requires root.
        $targetRole = chatapp_get_role($uid);
        if (($targetRole === 'admin' || $targetRole === 'root') && chatapp_get_role($myUid) !== 'root') { echo json_encode(['success'=>false]); exit; }
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $newName)) { echo json_encode(['success'=>false]); exit; }
        $st = $pdo->prepare("SELECT user_id FROM users WHERE username = ?"); $st->execute([$newName]);
        if ($st->fetch()) { echo json_encode(['success'=>false,'error'=>'Username taken.']); exit; }
        $pdo->prepare("UPDATE users SET username = ? WHERE username = ?")->execute([$newName, $username]);
        echo json_encode(['success'=>true]);
        chatapp_log_admin('change_username', $uid, $username, ['old' => $username, 'new' => $newName]);
        break;

    case 'change_display_name_adm':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username)) { echo json_encode(['success'=>false]); exit; }
        // 修改其他 admin 需要 root
        $targetRole = chatapp_get_role($uid);
        if (($targetRole === 'admin' || $targetRole === 'root') && chatapp_get_role($myUid) !== 'root') { echo json_encode(['success'=>false]); exit; }
        $dn = trim(mb_substr($_POST['display_name'] ?? '', 0, 256));
        $pdo->prepare("UPDATE users SET display_name = ? WHERE username = ?")->execute([$dn ?: null, $username]);
        echo json_encode(['success'=>true]);
        chatapp_log_admin('change_display_name', $uid, $username, ['new' => $dn ?: '']);
        break;

    case 'toggle_dnd_adm':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username) || $uid === $myUid) { echo json_encode(['success'=>false]); exit; }
        // 修改其他 admin 需要 root
        $targetRole = chatapp_get_role($uid);
        if (($targetRole === 'admin' || $targetRole === 'root') && chatapp_get_role($myUid) !== 'root') { echo json_encode(['success'=>false]); exit; }
        $pdo->prepare("UPDATE users SET dnd = NOT dnd WHERE username = ?")->execute([$username]);
        echo json_encode(['success'=>true]);
        chatapp_log_admin('toggle_dnd', $uid, $username);
        break;

    case 'change_status':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username)) { echo json_encode(['success'=>false]); exit; }
        // 修改其他 admin 需要 root
        $targetRole = chatapp_get_role($uid);
        if (($targetRole === 'admin' || $targetRole === 'root') && chatapp_get_role($myUid) !== 'root') { echo json_encode(['success'=>false]); exit; }
        $status = trim($_POST['status'] ?? '');
        if (!in_array($status, ['enabled','disabled','restricted','placeholder'])) { echo json_encode(['success'=>false]); exit; }
        $pdo->prepare("UPDATE users SET enabled=?, restricted=?, placeholder=? WHERE username=?")
            ->execute([($status==='enabled'||$status==='restricted')?1:0, $status==='restricted'?1:0, $status==='placeholder'?1:0, $username]);
        if ($status === 'placeholder') {
            $pdo->prepare("UPDATE users SET password=? WHERE username=?")->execute([password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), $username]);
        }
        echo json_encode(['success'=>true]);
        chatapp_log_admin('change_status', $uid, $username, ['new' => $status]);
        break;

    case 'set_restrict_reason':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username)) { echo json_encode(['success'=>false]); exit; }
        $reason = trim(mb_substr($_POST['reason'] ?? '', 0, 1000));
        $pdo->prepare("UPDATE users SET restricted_reason = ? WHERE username = ?")->execute([$reason ?: null, $username]);
        echo json_encode(['success'=>true]);
        chatapp_log_admin('set_restrict_reason', $uid, $username, ['reason' => $reason ?: '']);
        break;

    case 'adjust_level':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false,'error'=>'Permission denied']); exit; }
        $username = trim($_POST['username'] ?? '');
        if (empty($username)) { echo json_encode(['success'=>false,'error'=>'Missing username']); exit; }
        $uid = _uid($pdo, $username);
        if (!$uid) { echo json_encode(['success'=>false,'error'=>'User not found']); exit; }
        $newLevel = (int)($_POST['level'] ?? 0);
        if ($newLevel < 1 || $newLevel > 100) { echo json_encode(['success'=>false,'error'=>'Level must be 1-100']); exit; }
        $pdo->prepare("UPDATE users SET level = ? WHERE user_id = ?")->execute([$newLevel, $uid]);
        echo json_encode(['success'=>true, 'level'=>$newLevel]);
        chatapp_log_admin('adjust_level', $uid, $username, ['new_level' => $newLevel]);
        break;

    case 'adjust_exp':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false,'error'=>'Permission denied']); exit; }
        $username = trim($_POST['username'] ?? '');
        if (empty($username)) { echo json_encode(['success'=>false,'error'=>'Missing username']); exit; }
        $uid = _uid($pdo, $username);
        if (!$uid) { echo json_encode(['success'=>false,'error'=>'User not found']); exit; }
        $newExp = max(0, (int)($_POST['exp'] ?? 0));
        $pdo->prepare("UPDATE users SET exp = ? WHERE user_id = ?")->execute([$newExp, $uid]);
        echo json_encode(['success'=>true, 'exp'=>$newExp]);
        chatapp_log_admin('adjust_exp', $uid, $username, ['new_exp' => $newExp]);
        break;

    case 'reset_exp':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false,'error'=>'Permission denied']); exit; }
        $username = trim($_POST['username'] ?? '');
        if (empty($username)) { echo json_encode(['success'=>false,'error'=>'Missing username']); exit; }
        $uid = _uid($pdo, $username);
        if (!$uid) { echo json_encode(['success'=>false,'error'=>'User not found']); exit; }
        $pdo->prepare("UPDATE users SET exp = 0 WHERE user_id = ?")->execute([$uid]);
        echo json_encode(['success'=>true]);
        chatapp_log_admin('reset_exp', $uid, $username, ['reset' => true]);
        break;

    case 'delete_permanently':
        if (!chatapp_has_permission($myUid, 'users.delete')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username) || $uid === $myUid) { echo json_encode(['success'=>false]); exit; }
        // Permanently deleting another admin requires root.
        $targetRole = chatapp_get_role($uid);
        if (($targetRole === 'admin' || $targetRole === 'root') && chatapp_get_role($myUid) !== 'root') { echo json_encode(['success'=>false]); exit; }
        try {
            chatapp_destroy_user($uid, $username, false);
            echo json_encode(['success'=>true]);
            chatapp_log_admin('delete_permanently', $uid, $username);
        } catch (\Exception $e) {
            echo json_encode(['success'=>false,'error'=>'Database error.']);
        }
        break;

    case 'clear_duress':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username) || $uid === $myUid) { echo json_encode(['success'=>false]); exit; }
        // 清除其他 admin 的 duress 密码需要 root
        $targetRole = chatapp_get_role($uid);
        if (($targetRole === 'admin' || $targetRole === 'root') && chatapp_get_role($myUid) !== 'root') { echo json_encode(['success'=>false]); exit; }
        $pdo->prepare("UPDATE users SET duress_password = NULL WHERE username = ?")->execute([$username]);
        echo json_encode(['success'=>true]);
        chatapp_log_admin('clear_duress', $uid, $username);
        break;

    case 'expire_tokens':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username)) { echo json_encode(['success'=>false]); exit; }
        // 强制下线其他 admin 需要 root
        $targetRole = chatapp_get_role($uid);
        if (($targetRole === 'admin' || $targetRole === 'root') && chatapp_get_role($myUid) !== 'root') { echo json_encode(['success'=>false]); exit; }
        $pdo->prepare("UPDATE users SET token_reset = NOW() WHERE username = ?")->execute([$username]);
        // Also revoke active WebSocket tokens for the user.
        $pdo->prepare("DELETE FROM ws_tokens WHERE username = ?")->execute([$username]);
        echo json_encode(['success'=>true]);
        chatapp_log_admin('expire_tokens', $uid, $username);
        break;

    case 'set_role':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $username = trim($_POST['username'] ?? '');
        $newRole = trim($_POST['role'] ?? '');
        $uid = _uid($pdo, $username);
        if ($uid === 10000 || empty($username) || $uid === $myUid) { echo json_encode(['success'=>false]); exit; }
        if (!in_array($newRole, ['admin','user'])) { echo json_encode(['success'=>false]); exit; }
        // Admin cannot change another admin's role (only root can)
        if ($newRole === 'admin' && chatapp_get_role($myUid) !== 'root') { echo json_encode(['success'=>false]); exit; }
        $targetRole = chatapp_get_role($uid);
        if ($targetRole === 'admin' && chatapp_get_role($myUid) !== 'root') { echo json_encode(['success'=>false]); exit; }
        $pdo->prepare("UPDATE users SET role = ? WHERE username = ?")->execute([$newRole, $username]);
        echo json_encode(['success'=>true]);
        chatapp_log_admin('set_role', $uid, $username, ['new' => $newRole, 'old' => $targetRole]);
        break;

    case 'role_list':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $roles = $pdo->query("SELECT role_name, permissions, editable FROM role_defs ORDER BY role_name")->fetchAll();
        foreach ($roles as &$r) $r['editable'] = (int)$r['editable'];
        echo json_encode(['success'=>true,'roles'=>$roles]);
        break;

    case 'role_save':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $roleName = trim($_POST['role_name'] ?? '');
        if (empty($roleName) || $roleName === 'root') { echo json_encode(['success'=>false]); exit; }
        $perms = trim($_POST['permissions'] ?? '{}');
        if (json_decode($perms) === null) { echo json_encode(['success'=>false]); exit; }
        $edit = ($_POST['editable'] ?? '1') === '1' ? 1 : 0;
        $st = $pdo->prepare("SELECT editable FROM role_defs WHERE role_name = ?"); $st->execute([$roleName]);
        $exist = $st->fetch();
        if ($exist && !$exist['editable']) { echo json_encode(['success'=>false]); exit; }
        $pdo->prepare("INSERT INTO role_defs (role_name, permissions, editable) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE permissions=?, editable=?")
            ->execute([$roleName, $perms, $edit, $perms, $edit]);
        echo json_encode(['success'=>true]);
        chatapp_log_admin('role_save', null, null, ['role' => $roleName]);
        break;

    case 'role_delete':
        if (!chatapp_has_permission($myUid, 'users.edit_role')) { echo json_encode(['success'=>false]); exit; }
        $roleName = trim($_POST['role_name'] ?? '');
        if (in_array($roleName, ['root','admin','user']) || empty($roleName)) { echo json_encode(['success'=>false]); exit; }
        $st = $pdo->prepare("SELECT editable FROM role_defs WHERE role_name = ?"); $st->execute([$roleName]);
        $exist = $st->fetch();
        if (!$exist || !$exist['editable']) { echo json_encode(['success'=>false]); exit; }
        $pdo->prepare("UPDATE users SET role = 'user' WHERE role = ?")->execute([$roleName]);
        $pdo->prepare("DELETE FROM role_defs WHERE role_name = ?")->execute([$roleName]);
        echo json_encode(['success'=>true]);
        chatapp_log_admin('role_delete', null, null, ['role' => $roleName]);
        break;

    case 'admin_logs':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $q = trim($_GET['q'] ?? '');
        $where = "WHERE 1=1";
        $params = [];
        if ($q !== '') {
            $where .= " AND (admin_username LIKE ? OR action LIKE ? OR target_username LIKE ? OR CAST(details AS CHAR) LIKE ?)";
            $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
        }
        $total = (int)$pdo->query("SELECT COUNT(*) FROM admin_logs $where")->fetchColumn();
        $stmt = $pdo->prepare("SELECT * FROM admin_logs $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        echo json_encode(['success' => true, 'logs' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        break;

    case 'login_logs':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $q = trim($_GET['q'] ?? '');
        $where = "WHERE 1=1";
        $params = [];
        if ($q !== '') {
            $where .= " AND (username LIKE ? OR CAST(user_id AS CHAR) LIKE ?)";
            $params[] = "%$q%"; $params[] = "%$q%";
        }
        $total = (int)$pdo->query("SELECT COUNT(*) FROM login_logs $where")->fetchColumn();
        $stmt = $pdo->prepare("SELECT * FROM login_logs $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        echo json_encode(['success' => true, 'logs' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        break;

    case 'exp_logs':
        // All exp_log entries EXCEPT Message send (msg) and Message recv (receive)
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $q = trim($_GET['q'] ?? '');
        $where = "WHERE type NOT IN ('msg','receive')";
        $params = [];
        if ($q !== '') {
            $where .= " AND (CAST(user_id AS CHAR) LIKE ? OR type LIKE ? OR detail LIKE ?)";
            $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
        }
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM exp_log $where");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT el.*, COALESCE(u.username, CONCAT('uid:', el.user_id)) AS username FROM exp_log el LEFT JOIN users u ON u.user_id = el.user_id $where ORDER BY el.id DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        echo json_encode(['success' => true, 'logs' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        break;

    case 'security_logs':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $q = trim($_GET['q'] ?? '');
        $where = "WHERE 1=1";
        $params = [];
        if ($q !== '') {
            $where .= " AND (event_type LIKE ? OR ip_address LIKE ? OR target_path LIKE ? OR details LIKE ?)";
            $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
        }
        $total = (int)$pdo->query("SELECT COUNT(*) FROM security_logs $where")->fetchColumn();
        $stmt = $pdo->prepare("SELECT * FROM security_logs $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        echo json_encode(['success' => true, 'logs' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        break;

    // ================= Database admin (root only) =================

    case 'wss_get':
        if (chatapp_get_role($myUid) !== 'root') {
            echo json_encode(['success' => false, 'error' => 'Access denied']); exit;
        }
        echo json_encode(['success' => true] + chatapp_wss_config());
        break;

    case 'wss_set':
        if (chatapp_get_role($myUid) !== 'root') {
            echo json_encode(['success' => false, 'error' => 'Access denied']); exit;
        }
        $newCfg = ['local' => '', 'private' => '', 'public' => ''];
        foreach (array_keys($newCfg) as $k) {
            $v = trim($_POST[$k] ?? '');
            if ($v !== '' && !preg_match('#^(ws://|wss://)?[a-zA-Z0-9.\-\[\]:]+(:\d+)?(/\S*)?$#', $v)) {
                echo json_encode(['success' => false, 'error' => 'Invalid WebSocket address: ' . $k]); exit;
            }
            $newCfg[$k] = $v;
        }
        $wssCfg = __DIR__ . '/../config/wss_server.php';
        $phpBody = "<?php\n/** ChatApp · WebSocket 通讯模式（可在 WebSocket Settings 修改）：local/private/public */\nreturn " . var_export($newCfg, true) . ";\n";
        if (@file_put_contents($wssCfg, $phpBody) === false) {
            echo json_encode(['success' => false, 'error' => 'Write failed (permission?)']); exit;
        }
        echo json_encode(['success' => true] + $newCfg);
        break;

    case 'db_tables':
        if (chatapp_get_role($myUid) !== 'root') {
            echo json_encode(['success' => false, 'error' => 'Access denied']); exit;
        }
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['success' => true, 'tables' => $tables]);
        break;

    case 'db_structure':
        if (chatapp_get_role($myUid) !== 'root') {
            echo json_encode(['success' => false, 'error' => 'Access denied']); exit;
        }
        $table = trim($_GET['table'] ?? '');
        if ($table === '') { echo json_encode(['success' => false, 'error' => 'No table']); exit; }
        // Validate table name exists in current DB
        $found = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        if (!$found) { echo json_encode(['success' => false, 'error' => 'Table not found']); exit; }
        $create = $pdo->query("SHOW CREATE TABLE `" . str_replace('`', '', $table) . "`")->fetch();
        $cols = $pdo->query("DESCRIBE `" . str_replace('`', '', $table) . "`")->fetchAll();
        $rowCount = (int)$pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '', $table) . "`")->fetchColumn();
        echo json_encode([
            'success' => true,
            'table' => $table,
            'create_sql' => $create['Create Table'] ?? '',
            'columns' => $cols,
            'row_count' => $rowCount,
        ]);
        break;

    case 'db_query':
        if (chatapp_get_role($myUid) !== 'root') {
            echo json_encode(['success' => false, 'error' => 'Access denied']); exit;
        }
        if (!chatapp_csrf_verify()) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
        }
        $sql = trim($_POST['sql'] ?? '');
        if ($sql === '') { echo json_encode(['success' => false, 'error' => 'Empty SQL']); exit; }
        // Strict read-only enforcement: only SELECT, SHOW, DESC, DESCRIBE, EXPLAIN allowed
        if (!preg_match('/^\s*(SELECT|SHOW|DESC\b|DESCRIBE|EXPLAIN)\b/i', $sql)) {
            echo json_encode(['success' => false, 'error' => 'Only read-only queries (SELECT/SHOW/DESC/DESCRIBE/EXPLAIN) are allowed']); exit;
        }
        // Block dangerous keywords even if they somehow bypass the regex
        $dangerous = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'RENAME', 'REPLACE', 'GRANT', 'REVOKE', 'LOAD', 'INTO OUTFILE', 'INTO DUMPFILE'];
        foreach ($dangerous as $kw) {
            if (preg_match('/\b' . $kw . '\b/i', $sql)) {
                echo json_encode(['success' => false, 'error' => "Keyword '$kw' is not allowed"]); exit;
            }
        }
        // Enforce LIMIT if not present
        if (!preg_match('/\bLIMIT\b\s+\d+/i', $sql)) {
            $sql = rtrim($sql, ';') . ' LIMIT 200';
        }
        try {
            $stmt = $pdo->query($sql);
            $rows = $stmt->fetchAll();
            $cols = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                $cols[] = $meta['name'] ?? ('col_' . $i);
            }
            echo json_encode(['success' => true, 'columns' => $cols, 'rows' => $rows, 'row_count' => count($rows)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'db_export':
        if (chatapp_get_role($myUid) !== 'root') {
            echo json_encode(['success' => false, 'error' => 'Access denied']); exit;
        }
        if (!chatapp_csrf_verify()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
        }
        $table = trim($_GET['table'] ?? '');
        if ($table === '') { echo json_encode(['success' => false, 'error' => 'No table']); exit; }
        $found = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        if (!$found) { echo json_encode(['success' => false, 'error' => 'Table not found']); exit; }
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $table . '_' . date('Ymd_His') . '.sql"');
        // Dump CREATE TABLE
        $create = $pdo->query("SHOW CREATE TABLE `" . str_replace('`', '', $table) . "`")->fetch();
        echo "-- ChatApp DB Export: $table\n";
        echo "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        echo ($create['Create Table'] ?? '') . ";\n\n";
        // Dump data
        $stmt = $pdo->query("SELECT * FROM `" . str_replace('`', '', $table) . "`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $vals = [];
            foreach ($row as $v) {
                if ($v === null) $vals[] = 'NULL';
                else $vals[] = $pdo->quote($v);
            }
            echo "INSERT INTO `$table` VALUES (" . implode(', ', $vals) . ");\n";
        }
        exit;

    default:
        echo json_encode(['success'=>false]);
}
