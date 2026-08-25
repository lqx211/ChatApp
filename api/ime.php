<?php
/**
 * ChatApp — 拼音输入法用户习惯接口
 *   action=record   提交一个词（记录使用频次）  POST word, pinyin
 *   action=learned  返回当前用户的学习数据（跨设备同步）
 */
require_once __DIR__ . '/config.php';

chatapp_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']); exit;
}
$pdo = db();
$stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
$stmt->execute([$_SESSION['username']]);
$uid = (int)$stmt->fetchColumn();
if (!$uid) { echo json_encode(['success' => false, 'error' => 'No user']); exit; }

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

switch ($action) {
    case 'record':
        $word = trim((string)($_POST['word'] ?? ''));
        $pinyin = trim((string)($_POST['pinyin'] ?? ''));
        if ($word === '' || mb_strlen($word) > 100) { echo json_encode(['success' => false, 'error' => 'bad word']); exit; }
        if (mb_strlen($pinyin) > 255) $pinyin = mb_substr($pinyin, 0, 255);
        // 仅记录含汉字的中文词，避免把纯拼音/英文当习惯
        if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $word)) { echo json_encode(['success' => false]); exit; }
        $pdo->prepare(
            "INSERT INTO user_ime_learning (user_id, word, pinyin, count) VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1, pinyin = VALUES(pinyin), updated_at = NOW()"
        )->execute([$uid, $word, $pinyin]);
        echo json_encode(['success' => true]);
        break;

    case 'learned':
        $stmt = $pdo->prepare("SELECT word, pinyin, count, is_custom FROM user_ime_learning WHERE user_id = ? ORDER BY count DESC LIMIT 1000");
        $stmt->execute([$uid]);
        $items = [];
        foreach ($stmt->fetchAll() as $r) {
            $items[] = ['word' => $r['word'], 'pinyin' => $r['pinyin'], 'count' => (int)$r['count'], 'is_custom' => (int)$r['is_custom']];
        }
        echo json_encode(['success' => true, 'items' => $items]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'unknown_action']);
}
