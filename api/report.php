<?php
/**
 * ChatApp - Report API (uses incidents table, type='report')
 */

require_once __DIR__ . '/config.php';

chatapp_session_start();

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']);
    exit;
}
header('Content-Type: application/json');
$pdo = db();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$myUsername = $_SESSION['username'];

// Resolve my UID for permission checks
$myUid = 0;
$stmtU = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
$stmtU->execute([$myUsername]);
$myUid = (int)($stmtU->fetchColumn() ?: 0);

function get_uid_rep(PDO $pdo, string $username): int {
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    return (int)($stmt->fetchColumn() ?: 0);
}

switch ($action) {

    case 'submit':
        $targetUser = trim($_POST['target'] ?? '');
        $reason = trim(mb_substr($_POST['reason'] ?? '', 0, 1000));
        $msgIdsJson = trim($_POST['message_ids'] ?? '[]');
        if (empty($targetUser)) {
            echo json_encode(['success' => false, 'error' => 'Invalid target.']); exit;
        }
        $myUid = get_uid_rep($pdo, $myUsername);
        $targetUid = get_uid_rep($pdo, $targetUser);
        if (!$myUid || !$targetUid || $myUid === $targetUid) {
            echo json_encode(['success' => false, 'error' => 'Invalid.']); exit;
        }

        // 10-minute window: same reporter -> same target, max 10 reports
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM incidents WHERE type='report' AND reporter_id=? AND target_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $stmt->execute([$myUid, $targetUid]);
        $recent = (int)$stmt->fetchColumn();
        if ($recent >= 10) {
            echo json_encode(['success' => false, 'error' => t('msg_report_timeout')]); exit;
        }

        $subject = t('admin_restricted_status') . ': ' . $targetUser;
        $pdo->prepare("INSERT INTO incidents (type, reporter_id, target_id, subject, reason, message_ids, status) VALUES ('report', ?, ?, ?, ?, ?, 'open')")
            ->execute([$myUid, $targetUid, $subject, $reason ?: null, $msgIdsJson]);

        // ---- Level system: first-ever report +20 exp (one-time) ----
        try {
            exp_bonus_claim($myUid, 'first_report', 20, 'bonus_report', 'target:' . $targetUser);
        } catch (Exception $e) { /* never break report */ }

        echo json_encode(['success' => true]);
        break;

    case 'list':
        if (!chatapp_has_permission($myUid, 'reports.view')) { echo json_encode(['success' => false]); exit; }
        $stmt = $pdo->query("
            SELECT r.id, r.reason, r.message_ids, r.created_at, r.status,
                   ru.username AS reporter_username, COALESCE(ru.display_name, ru.username) AS reporter_display,
                   tu.username AS target_username, COALESCE(tu.display_name, tu.username) AS target_display
            FROM incidents r
            JOIN users ru ON ru.user_id = r.reporter_id
            JOIN users tu ON tu.user_id = r.target_id
            WHERE r.type = 'report' AND r.status = 'open'
            ORDER BY r.created_at DESC
        ");
        $reports = $stmt->fetchAll();
        echo json_encode(['success' => true, 'reports' => $reports]);
        break;

    case 'count':
        if (!chatapp_has_permission($myUid, 'reports.view')) { echo json_encode(['success' => false]); exit; }
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE type='report' AND status='open'")->fetchColumn();
        echo json_encode(['success' => true, 'count' => $cnt]);
        break;

    case 'resolve':
        if (!chatapp_has_permission($myUid, 'reports.resolve')) { echo json_encode(['success' => false]); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $ban = ($_POST['ban'] ?? '') === '1';
        if ($id <= 0) { echo json_encode(['success' => false]); exit; }
        if ($ban) {
            $stmt = $pdo->prepare("SELECT target_id FROM incidents WHERE id = ? AND type = 'report'");
            $stmt->execute([$id]);
            $inc = $stmt->fetch();
            if ($inc && $inc['target_id']) {
                $reason = trim(mb_substr($_POST['reason'] ?? '', 0, 1000));
                $pdo->prepare("UPDATE users SET restricted = 1, enabled = 0, restricted_reason = ? WHERE user_id = ?")
                    ->execute([$reason ?: null, $inc['target_id']]);
            }
        }
        $pdo->prepare("UPDATE incidents SET status = 'resolved' WHERE id = ? AND type = 'report'")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false]);
}