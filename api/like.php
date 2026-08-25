<?php
/**
 * ChatApp - 点赞 API（个人主页心心系统）
 *
 * POST action=like&username=<target>
 * 规则：
 *   1. 点赞同一个人每天最多 10 次（daily_counters ctype=like:<uid>，按 UTC+8 日期）
 *   2. 被赞者每个赞 exp+1 且 likes+1
 *   3. 朋友关系时插入 msg_type='like' 系统消息（聊天中间灰色字），WSS 自动推送
 */

require_once __DIR__ . '/config.php';

chatapp_session_start();
isset($_SESSION['username']) or die(json_encode(['success' => false]));

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = db();
$me = $_SESSION['username'];
$myUid = (int)($pdo->query("SELECT user_id FROM users WHERE username = " . $pdo->quote($me))->fetchColumn() ?: 0);
if ($myUid <= 0) { echo json_encode(['success' => false]); exit; }

$target = trim($_POST['username'] ?? '');
if (!preg_match('/^[a-zA-Z0-9_]+$/', $target)) {
    echo json_encode(['success' => false, 'error' => 'Invalid username.']);
    exit;
}

$stmt = $pdo->prepare('SELECT user_id, username, stranger_like FROM users WHERE username = ? AND deleted_at IS NULL');
$stmt->execute([$target]);
$t = $stmt->fetch();
if (!$t) { echo json_encode(['success' => false, 'error' => 'User not found.']); exit; }

$tid = (int)$t['user_id'];
if ($tid === $myUid) {
    echo json_encode(['success' => false, 'error' => 'You cannot like yourself.']);
    exit;
}

// 好友关系？
$fStmt = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE status='accepted' AND ((user_from=? AND user_to=?) OR (user_from=? AND user_to=?))");
$fStmt->execute([$myUid, $tid, $tid, $myUid]);
$isFriend = (int)$fStmt->fetchColumn() > 0;

// 陌生人点赞需对方允许
if (!$isFriend && !(int)($t['stranger_like'] ?? 1)) {
    echo json_encode(['success' => false, 'error' => 'This user does not allow likes from strangers.']);
    exit;
}

// 每天对同一人最多 10 次（UTC+8 自然日）
$today = gmdate('Y-m-d', time() + 8 * 3600);
$ctype = 'like:' . $tid;
$cStmt = $pdo->prepare('SELECT cnt FROM daily_counters WHERE user_id=? AND ddate=? AND ctype=?');
$cStmt->execute([$myUid, $today, $ctype]);
$cnt = (int)($cStmt->fetchColumn() ?: 0);
if ($cnt >= 10) {
    echo json_encode(['success' => false, 'error' => 'You can like this user at most 10 times per day.', 'likes' => (int)$pdo->query("SELECT likes FROM users WHERE user_id=$tid")->fetchColumn()]);
    exit;
}

$ins = $pdo->prepare('INSERT INTO daily_counters (user_id, ddate, ctype, cnt) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE cnt = cnt + 1');
$ins->execute([$myUid, $today, $ctype]);

// 被赞者 exp+1、likes+1
exp_add($tid, 1, 'like', false);
$pdo->prepare('UPDATE users SET likes = likes + 1 WHERE user_id = ?')->execute([$tid]);
$likes = (int)$pdo->query("SELECT likes FROM users WHERE user_id=$tid")->fetchColumn();

// 朋友关系 → 系统消息（聊天中间灰色字），WSS 轮询会自动推给双方
if ($isFriend) {
    // 若上一条消息就是我对对方的点赞（未撤回、中间无新消息）→ 次数+1 合并进原行，不新增行
    $lastStmt = $pdo->prepare("SELECT id, sender_id, msg_type, deleted_at, attachment FROM messages WHERE group_id IS NULL AND ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)) ORDER BY id DESC LIMIT 1");
    $lastStmt->execute([$myUid, $tid, $tid, $myUid]);
    $last = $lastStmt->fetch();
    if ($last && $last['msg_type'] === 'like' && (int)$last['sender_id'] === $myUid && $last['deleted_at'] === null) {
        $meta = json_decode((string)($last['attachment'] ?? ''), true);
        $n = is_array($meta) ? (int)($meta['n'] ?? 1) : 1;
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE messages SET attachment=?, datetime=?, time=? WHERE id=?")->execute([json_encode(['n' => $n + 1]), $now, $now, (int)$last['id']]);
        echo json_encode(['success' => true, 'likes' => $likes, 'merged' => true, 'msg_id' => (int)$last['id'], 'n' => $n + 1]);
        exit;
    }
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, message, msg_type, attachment, time, datetime) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$myUid, $tid, $target, 'like', json_encode(['n' => 1]), $now, $now]);
}

echo json_encode(['success' => true, 'likes' => $likes, 'remaining' => 9 - $cnt]);
