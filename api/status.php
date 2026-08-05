<?php
/**
 * ChatApp - Status API (MySQL-backed)
 * Online presence and typing indicators
 */

require_once __DIR__ . '/config.php';

chatapp_session_start();

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false]);
    exit;
}

header('Content-Type: application/json');
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pdo = db();
$me = $_SESSION['username'];

// Ensure columns exist
db_add_column_if_missing('users', 'last_ping', "DATETIME DEFAULT NULL");
db_add_column_if_missing('users', 'typing_to', "VARCHAR(20) DEFAULT NULL");
db_add_column_if_missing('users', 'typing_at', "DATETIME DEFAULT NULL");

switch ($action) {

    case 'ping':
        // Heartbeat: update last_ping timestamp (online if within 15 seconds)
        $pdo->prepare("UPDATE users SET last_ping = NOW() WHERE username = ?")->execute([$me]);
        // Also store DND status for check
        $dndStmt = $pdo->prepare("SELECT dnd FROM users WHERE username = ?");
        $dndStmt->execute([$me]);
        $dnd = (int)($dndStmt->fetchColumn() ?: 0);
        echo json_encode(['success' => true, 'dnd' => $dnd]);
        break;

    case 'typing':
        $to = trim($_POST['to'] ?? '');
        if (empty($to)) {
            echo json_encode(['success' => false]);
            exit;
        }
        // Store typing indicator with timestamp
        $pdo->prepare("UPDATE users SET typing_to = ?, typing_at = NOW() WHERE username = ?")->execute([$to, $me]);
        echo json_encode(['success' => true]);
        break;

    case 'check':
        // Check online status for a list of users
        $users = $_GET['users'] ?? '';
        $userList = $users ? explode(',', $users) : [];
        $result = ['online' => [], 'typing' => [], 'dnd' => []];

        if (count($userList) > 0) {
            $placeholders = implode(',', array_fill(0, count($userList), '?'));
            $stmt = $pdo->prepare("SELECT username, last_ping, dnd, enabled, restricted, typing_to, typing_at 
                FROM users WHERE username IN ($placeholders)");
            $stmt->execute($userList);
            $rows = $stmt->fetchAll();

            $now = time();
            foreach ($rows as $r) {
                $pingTime = $r['last_ping'] ? strtotime($r['last_ping']) : 0;
                $result['online'][$r['username']] = ($now - $pingTime < 15);
                $result['dnd'][$r['username']] = (bool)$r['dnd'];
                $result['enabled'][$r['username']] = (bool)$r['enabled'];
                $result['restricted'][$r['username']] = (bool)$r['restricted'];

                // Typing: check if this user is typing TO me, and within last 4 seconds
                $typingTo = $r['typing_to'];
                $typingAt = $r['typing_at'] ? strtotime($r['typing_at']) : 0;
                $result['typing'][$r['username']] = ($typingTo === $me && ($now - $typingAt < 4));
            }
        }

        echo json_encode(['success' => true, 'status' => $result]);
        break;

    default:
        echo json_encode(['success' => false]);
}