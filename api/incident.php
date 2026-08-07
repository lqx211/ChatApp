<?php
require_once __DIR__ . '/config.php';

chatapp_session_start();

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in.']); exit;
}
header('Content-Type: application/json');
$pdo = db();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$myUsername = $_SESSION['username'];

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
        $type = trim($_POST['type'] ?? 'bug');
        if (!in_array($type, ['bug', 'recommendation', 'account_issue'])) $type = 'bug';
        $subject = trim(mb_substr($_POST['subject'] ?? '', 0, 500));
        $reason = trim(mb_substr($_POST['reason'] ?? '', 0, 5000));
        $priority = trim($_POST['priority'] ?? 'normal');
        $allowedP = ['task','low','normal','medium','high','urgent','critical','nopriority'];
        if (!in_array($priority, $allowedP)) $priority = 'normal';
        if (empty($subject)) { echo json_encode(['success' => false, 'error' => 'Subject required.']); exit; }
        $imagesJson = null;
        $rawImages = trim($_POST['images'] ?? '');
        if (!empty($rawImages)) {
            $decoded = json_decode($rawImages, true);
            if (is_array($decoded) && count($decoded) > 0 && count($decoded) <= 5) {
                // Create ticket dir for file storage
                $ticketDir = __DIR__ . '/../data/ticket';
                if (!is_dir($ticketDir)) mkdir($ticketDir, 0755, true);
                $savedPaths = [];
                foreach ($decoded as $b64) {
                    if (!preg_match('/^data:image\/(\w+);base64,(.+)$/s', $b64, $m)) continue;
                    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
                    $bin = base64_decode($m[2]);
                    if ($bin === false || strlen($bin) > 8 * 1024 * 1024) continue;
                    $hash = substr(hash('sha256', $bin), 0, 16);
                    $filename = $hash . '.' . $ext;
                    file_put_contents($ticketDir . '/' . $filename, $bin);
                    $savedPaths[] = 'ticket/' . $filename;
                }
                if (count($savedPaths) > 0) {
                    $imagesJson = json_encode($savedPaths);
                }
            }
        }
        $pdo->prepare("INSERT INTO incidents (type, reporter_id, subject, reason, priority, images, status) VALUES (?, ?, ?, ?, ?, ?, 'open')")
            ->execute([$type, $myUid, $subject, $reason ?: null, $priority, $imagesJson]);
        $newIncidentId = (int)$pdo->lastInsertId();

        // ---- Level system: ticket creation EXP (after persisted) ----
        // UID 10000 (root/owner) does not earn EXP from its own bug/suggestion reports.
        try {
            if ($myUid !== 10000) {
                if ($type === 'bug') {
                    // Bug: +20 with 12h cooldown; first-ever bug gets extra +75 (once)
                    $stmt = $pdo->prepare("SELECT last_exp_bug_at FROM users WHERE user_id = ?");
                    $stmt->execute([$myUid]);
                    $lastBugAt = $stmt->fetchColumn();
                    $lastBugTs = $lastBugAt ? strtotime((string)$lastBugAt) : 0;
                    if (!$lastBugAt || (time() - $lastBugTs) >= 12 * 3600) {
                        $pdo->prepare("UPDATE users SET last_exp_bug_at = NOW() WHERE user_id = ?")->execute([$myUid]);
                        exp_add($myUid, 20, 'bug', true, 'ticket:' . $newIncidentId);
                    }
                    // First-time bug bonus +75 (independent of cooldown)
                    exp_bonus_claim($myUid, 'first_bug', 75, 'bonus_first_bug', 'ticket:' . $newIncidentId);
                } elseif ($type === 'recommendation') {
                    // Suggestion: +10 with 12h cooldown
                    $stmt = $pdo->prepare("SELECT last_exp_suggestion_at FROM users WHERE user_id = ?");
                    $stmt->execute([$myUid]);
                    $lastSugAt = $stmt->fetchColumn();
                    $lastSugTs = $lastSugAt ? strtotime((string)$lastSugAt) : 0;
                    if (!$lastSugAt || (time() - $lastSugTs) >= 12 * 3600) {
                        $pdo->prepare("UPDATE users SET last_exp_suggestion_at = NOW() WHERE user_id = ?")->execute([$myUid]);
                        exp_add($myUid, 10, 'suggestion', true, 'ticket:' . $newIncidentId);
                    }
                }
            }
        } catch (Exception $e) {
            // never break ticket create
        }

        echo json_encode(['success' => true, 'id' => $newIncidentId]);
        break;

    case 'list':
        $status = $_GET['status'] ?? 'open';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(5, min(100, (int)($_GET['per_page'] ?? 15)));
        $search = trim($_GET['search'] ?? '');
        $offset = ($page - 1) * $perPage;

        if ($isAdmin) {
            $where = "WHERE 1=1";
            $params = [];
        } else {
            $where = "WHERE reporter_id = ? AND type != 'report'";
            $params = [$myUid];
        }

        if ($status === 'open') {
            $where .= " AND status IN ('open', 'in_progress')";
        } elseif ($status === 'closed') {
            $where .= " AND status IN ('resolved', 'closed')";
        } elseif ($status !== 'all') {
            $where .= " AND status = ?";
            $params[] = $status;
        }
        if ($search !== '') {
            $where .= " AND (subject LIKE ? OR CAST(id AS CHAR) LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM incidents $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT i.*, COALESCE(u.display_name, u.username) AS reporter_name
            FROM incidents i JOIN users u ON u.user_id = i.reporter_id
            $where ORDER BY i.created_at DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute($params);
        $incidents = $stmt->fetchAll();

        echo json_encode(['success' => true, 'incidents' => $incidents, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
        break;

    case 'detail':
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT i.*, COALESCE(u.display_name, u.username) AS reporter_name
            FROM incidents i JOIN users u ON u.user_id = i.reporter_id WHERE i.id = ?");
        $stmt->execute([$id]);
        $incident = $stmt->fetch();
        if (!$incident) { echo json_encode(['success' => false]); exit; }
        if (!$isAdmin && $incident['reporter_id'] !== $myUid) { echo json_encode(['success' => false]); exit; }
        $resStmt = $pdo->prepare("SELECT ir.*, COALESCE(u.display_name, u.username) AS username FROM incident_responses ir
            JOIN users u ON u.user_id = ir.user_id WHERE ir.incident_id = ? ORDER BY ir.created_at ASC");
        $resStmt->execute([$id]);
        $incident['responses'] = $resStmt->fetchAll();

        // For report-type incidents: resolve reported messages (show originals even if revoked)
        $incident['reported_messages'] = [];
        if ($isAdmin && $incident['type'] === 'report' && !empty($incident['message_ids'])) {
            $msgIds = json_decode($incident['message_ids'], true);
            if (is_array($msgIds) && count($msgIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($msgIds), '?'));
                $msgStmt = $pdo->prepare("SELECT m.id, m.message, m.datetime, m.deleted_at, COALESCE(u.display_name, u.username) AS sender_name
                    FROM messages m JOIN users u ON u.user_id = m.sender_id
                    WHERE m.id IN ($placeholders) ORDER BY m.id ASC");
                $msgStmt->execute(array_map('intval', $msgIds));
                while ($msg = $msgStmt->fetch()) {
                    $msg['is_revoked'] = ($msg['deleted_at'] !== null);
                    $incident['reported_messages'][] = $msg;
                }
            }
        }

        echo json_encode(['success' => true, 'incident' => $incident]);
        break;

    case 'respond':
        $id = (int)($_POST['id'] ?? 0);
        $message = trim(mb_substr($_POST['message'] ?? '', 0, 5000));
        if (empty($message)) { echo json_encode(['success' => false]); exit; }
        $stmt = $pdo->prepare("SELECT id, reporter_id FROM incidents WHERE id = ?");
        $stmt->execute([$id]);
        $inc = $stmt->fetch();
        if (!$inc) { echo json_encode(['success' => false]); exit; }
        if (!$isAdmin && $inc['reporter_id'] !== $myUid) { echo json_encode(['success' => false]); exit; }
        $pdo->prepare("INSERT INTO incident_responses (incident_id, user_id, message, is_staff) VALUES (?, ?, ?, ?)")
            ->execute([$id, $myUid, $message, $isAdmin ? 1 : 0]);
        echo json_encode(['success' => true]);
        break;

    case 'update_status':
        if (!$isAdmin) { echo json_encode(['success' => false]); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $priority = trim($_POST['priority'] ?? '');
        if (!in_array($status, ['open','in_progress','resolved','closed'])) $status = null;
        if (!in_array($priority, ['task','low','normal','medium','high','urgent','critical','nopriority'])) $priority = null;
        $sets = []; $params = [];
        if ($status) { $sets[] = 'status = ?'; $params[] = $status; }
        if ($priority) { $sets[] = 'priority = ?'; $params[] = $priority; }
        // ---- Level system: award when switched TO resolved (not closed) ----
        // Only award if this update actually sets status=resolved and reward not already paid.
        $rewardType = null;
        $rewardExp = 0;
        if ($status === 'resolved') {
            $stmt = $pdo->prepare("SELECT type, exp_awarded FROM incidents WHERE id = ?");
            $stmt->execute([$id]);
            $inc = $stmt->fetch();
            if ($inc && !(int)($inc['exp_awarded'] ?? 0)) {
                if ($inc['type'] === 'bug')             { $rewardExp = 100; $rewardType = 'bug_resolved'; }
                elseif ($inc['type'] === 'recommendation') { $rewardExp = 50;  $rewardType = 'suggestion_resolved'; }
            }
        }

        if (empty($sets)) { echo json_encode(['success' => false]); exit; }
        $params[] = $id;
        $pdo->prepare("UPDATE incidents SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);

        // Award AFTER persisted + mark exp_awarded so it can never double-pay
        if ($rewardType && $rewardExp > 0) {
            try {
                $stmt = $pdo->prepare("SELECT reporter_id FROM incidents WHERE id = ?");
                $stmt->execute([$id]);
                $reporterUid = (int)($stmt->fetchColumn() ?: 0);
                // UID 10000 (root/owner) does not earn EXP from its own bug/suggestion reports.
                if ($reporterUid > 0 && $reporterUid !== 10000) {
                    $pdo->prepare("UPDATE incidents SET exp_awarded = 1 WHERE id = ?")->execute([$id]);
                    exp_add($reporterUid, $rewardExp, $rewardType, true, 'ticket:' . $id);
                }
            } catch (Exception $e) {
                // never break admin status update
            }
        }

        echo json_encode(['success' => true, 'exp_awarded' => $rewardType !== null]);
        break;

    case 'count':
        $countOpen = (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status = 'open'")->fetchColumn();
        echo json_encode(['success' => true, 'count' => $countOpen]);
        break;

    default:
        echo json_encode(['success' => false]);
}