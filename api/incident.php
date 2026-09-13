<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/incident_actions.php';   // 工单规则统一在这层（AI 工具 ca_ticket 也调它）

chatapp_session_start();

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']); exit;
}
header('Content-Type: application/json');
$pdo = db();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$myUsername = $_SESSION['username'];

// create/respond/update_status are state-changing → POST only.
chatapp_read_actions(['list', 'detail', 'count'], $action);

if (!function_exists('get_uid_inc')) {
    function get_uid_inc(PDO $pdo, string $u): int {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$u]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
}
$myUid = get_uid_inc($pdo, $myUsername);
// Admin = UID 10000 (root) OR role 'admin'
$isAdmin = ($myUid === 10000 || chatapp_get_role($myUid) === 'admin');

switch ($action) {

    case 'create':
        echo json_encode(incident_action_create($pdo, $myUid, $_POST));
        break;

    case 'list':
        echo json_encode(incident_action_list($pdo, $myUid, $isAdmin, [
            'status' => $_GET['status'] ?? 'open',
            'page' => $_GET['page'] ?? 1,
            'per_page' => $_GET['per_page'] ?? 15,
            'search' => $_GET['search'] ?? '',
        ]));
        break;

    case 'detail':
        echo json_encode(incident_action_detail($pdo, $myUid, $isAdmin, (int)($_GET['id'] ?? 0)));
        break;

    case 'respond':
        echo json_encode(incident_action_respond($pdo, $myUid, $isAdmin, (int)($_POST['id'] ?? 0), (string)($_POST['message'] ?? '')));
        break;

    case 'update_status':
        echo json_encode(incident_action_update_status($pdo, $isAdmin, (int)($_POST['id'] ?? 0), $_POST['status'] ?? null, $_POST['priority'] ?? null));
        break;

    case 'count':
        echo json_encode(incident_action_count($pdo, $myUid, $isAdmin));
        break;

    default:
        echo json_encode(['success' => false]);
}
