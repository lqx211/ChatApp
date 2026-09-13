<?php
/**
 * ChatApp · 举报操作统一层 —— api/report_actions.php
 *
 * 从 api/report.php 的 submit 分支原样抽出（行为/文案/限流一字不改），
 * 让「网页举报按钮」和「AI 工具 ca_report_user」走同一条路径（含 e2ee 证据落库、首次举报 EXP）。
 */
require_once __DIR__ . '/config.php';

/**
 * 提交举报。
 * @param string $targetUser    被举报人用户名
 * @param string $reason        举报理由（最多 1000 字，可为空）
 * @param string $msgIdsJson    证据消息 ID（JSON 数组字符串）
 * @param string $decryptedJson 举报方客户端解密的 e2ee 证据 {"<msgId>":{"content":"...","md":0|1}}
 */
function report_action_submit(PDO $pdo, int $myUid, string $myUsername, string $targetUser, string $reason,
                              string $msgIdsJson = '[]', string $decryptedJson = '{}'): array {
    $targetUser = trim($targetUser);
    $reason = trim(mb_substr($reason, 0, 1000));
    $msgIdsJson = trim($msgIdsJson) !== '' ? trim($msgIdsJson) : '[]';
    $decryptedJson = trim($decryptedJson) !== '' ? trim($decryptedJson) : '{}';

    if (empty($targetUser)) return ['success' => false, 'error' => 'Invalid target.'];

    $stmtU = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
    $stmtU->execute([$targetUser]);
    $targetUid = (int)($stmtU->fetchColumn() ?: 0);
    if (!$myUid || !$targetUid || $myUid === $targetUid) return ['success' => false, 'error' => 'Invalid.'];

    // 10-minute window: same reporter -> same target, max 10 reports
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM incidents WHERE type='report' AND reporter_id=? AND target_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stmt->execute([$myUid, $targetUid]);
    if ((int)$stmt->fetchColumn() >= 10) {
        return ['success' => false, 'error' => t('msg_report_timeout')];
    }

    $subject = t('admin_restricted_status') . ': ' . $targetUser;
    $pdo->prepare("INSERT INTO incidents (type, reporter_id, target_id, subject, reason, message_ids, status) VALUES ('report', ?, ?, ?, ?, ?, 'open')")
        ->execute([$myUid, $targetUid, $subject, $reason ?: null, $msgIdsJson]);
    $ticketId = (int)$pdo->lastInsertId();

    // ---- 加密消息证据：把举报方解密后的明文写入 msg_crypt_temp（ticket_id=本次举报） ----
    $decrypted = json_decode($decryptedJson, true);
    if ($ticketId > 0 && is_array($decrypted) && count($decrypted) > 0) {
        $metaIds = [];
        foreach ($decrypted as $mid => $v) { $i = (int)$mid; if ($i > 0) $metaIds[$i] = 1; }
        $metaIds = array_keys($metaIds);
        $metaById = [];
        if (!empty($metaIds)) {
            try {
                $ph = implode(',', array_fill(0, count($metaIds), '?'));
                $mStmt = $pdo->prepare("SELECT id, sender_id, time FROM messages WHERE id IN ($ph)");
                $mStmt->execute($metaIds);
                while ($row = $mStmt->fetch()) $metaById[(int)$row['id']] = $row;
            } catch (\Throwable $e) {}
        }
        try {
            $ins = $pdo->prepare("INSERT INTO msg_crypt_temp (ticket_id, message_id, sender_id, msg_time, md, content)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE md = VALUES(md), content = VALUES(content)");
            foreach ($decrypted as $mid => $v) {
                $i = (int)$mid;
                if ($i <= 0) continue;
                $content = is_array($v) ? trim((string)($v['content'] ?? '')) : trim((string)$v);
                if ($content === '') continue;
                $meta = $metaById[$i] ?? null;
                $sender = $meta ? (int)$meta['sender_id'] : null;
                $time = $meta ? ((int)$meta['time'] ?: null) : null;
                $md = (is_array($v) && !empty($v['md'])) ? 1 : 0;
                $ins->execute([$ticketId, $i, $sender ?: null, $time, $md, $content]);
            }
        } catch (\Throwable $e) { /* 证据写入失败不阻断举报提交 */ }
    }

    // ---- Level system: first-ever report +20 exp (one-time) ----
    try {
        exp_bonus_claim($myUid, 'first_report', 20, 'bonus_report', 'target:' . $targetUser);
    } catch (Exception $e) { /* never break report */ }

    return ['success' => true, 'ticket_id' => $ticketId];
}
