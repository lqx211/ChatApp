<?php
/**
 * ChatApp - Donation API
 * Admin-only CRUD for donation records
 */

require_once __DIR__ . '/config.php';

chatapp_session_start();
isset($_SESSION['username']) or die(json_encode(['success' => false]));

header('Content-Type: application/json');
$pdo = db();
$me = $_SESSION['username'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Admin only
$myUid = 0;
$myUidStmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
$myUidStmt->execute([$me]);
$myUid = (int)($myUidStmt->fetchColumn() ?: 0);
$role = chatapp_get_role($myUid);
if ($role !== 'root' && $role !== 'admin') {
    echo json_encode(['success' => false]);
    exit;
}

switch ($action) {

    case 'list':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;
        $total = (int)$pdo->query("SELECT COUNT(*) FROM donations")->fetchColumn();
        $stmt = $pdo->prepare("SELECT * FROM donations ORDER BY datetime DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute();
        $donations = $stmt->fetchAll();
        echo json_encode(['success' => true, 'donations' => $donations, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        break;

    case 'add':
        $datetime = trim($_POST['datetime'] ?? '');
        $uid = (int)($_POST['user_id'] ?? 0);
        $weixin = trim(mb_substr($_POST['weixin_id'] ?? '', 0, 64));
        $qq = trim(mb_substr($_POST['qq'] ?? '', 0, 32));

        if (empty($datetime) || $uid <= 0) {
            echo json_encode(['success' => false]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT username, COALESCE(display_name, username) AS display_name FROM users WHERE user_id = ?");
        $stmt->execute([$uid]);
        $user = $stmt->fetch();
        if (!$user) {
            echo json_encode(['success' => false]);
            exit;
        }

        $pdo->prepare("INSERT INTO donations (datetime, user_id, username, display_name, weixin_id, qq) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$datetime, $uid, $user['username'], $user['display_name'], $weixin ?: null, $qq ?: null]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false]); exit; }
        $pdo->prepare("DELETE FROM donations WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    case 'search_users':
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 1) { echo json_encode(['success' => true, 'users' => []]); exit; }
        $stmt = $pdo->prepare("SELECT user_id, username, COALESCE(display_name, username) AS display_name FROM users WHERE (username LIKE ? OR CAST(user_id AS CHAR) LIKE ?) AND enabled = 1 AND placeholder = 0 AND deleted_at IS NULL LIMIT 15");
        $stmt->execute(["%$q%", "%$q%"]);
        $users = $stmt->fetchAll();
        echo json_encode(['success' => true, 'users' => $users]);
        break;

    default:
        echo json_encode(['success' => false]);
}