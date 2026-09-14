<?php
/**
 * ChatApp · 联系人操作统一层 —— api/contact_actions.php
 *
 * 从 api/contacts.php 原样抽出（行为/文案一字不改），让「网页按钮」和「AI 工具」走同一条路径：
 *   加好友申请 / 接受·拒绝 / 删除联系人 / 置顶开关 / 特别关心开关
 *
 * 约定：
 *   - 所有函数只操作「$myUid 自己」的数据（行方向：me→them 才是我对 ta 的备注/置顶/特别关心）
 *   - 返回数组与端点原本的 JSON 完全同构（['success'=>true] / ['success'=>false,'error'=>'...']）
 *   - $on 传 1/0 可做「显式设置」（AI 工具用，幂等）；不传 = 翻转（网页按钮用）
 */
require_once __DIR__ . '/config.php';

// 机器人列自愈（幂等，每请求只查一次）：旧库升级后可能缺 is_bot 等列，
// 本文件与下游查询会在缺列时直接 500 —— 先确保列存在再继续。
chatapp_ensure_bot_columns();

/** 用户名 → uid（排除软删除账号；不存在返回 0） */
function contact_find_uid(PDO $pdo, string $username): int {
    $username = trim($username);
    if ($username === '') return 0;
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND deleted_at IS NULL");
    $stmt->execute([$username]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * 发好友申请（原 api/contacts.php?action=send_request）。
 * 规则全部照旧：不能加机器人、被拉黑拒绝、对方关「允许任何人添加」拒绝、
 * 10 分钟最多 20 条 pending、已好友/已申请会报错。
 */
function contact_action_send_request(PDO $pdo, int $myUid, string $myUsername, string $toUser, string $msg = '', string $note = ''): array {
    $toUser = trim($toUser);
    $msg = trim(mb_substr($msg, 0, 200));
    $note = trim(mb_substr($note, 0, 500));
    if (empty($toUser) || $toUser === $myUsername) return ['success' => false, 'error' => 'Something went wrong.'];
    if (!$myUid) return ['success' => false, 'error' => 'Something went wrong.'];

    $stmt = $pdo->prepare("SELECT user_id, is_bot FROM users WHERE username = ?");
    $stmt->execute([$toUser]);
    $toRow = $stmt->fetch() ?: null;
    $toUid = (int)($toRow['user_id'] ?? 0);
    if (!$toUid || (int)($toRow['is_bot'] ?? 0) === 1) {
        return ['success' => false, 'error' => 'Something went wrong.'];
    }

    // 黑名单：对方拉黑则拒绝好友申请
    if (chatapp_is_blocked($myUid, $toUid)) return ['success' => false, 'error' => 'blocked'];

    // 对方关闭「允许任何人添加我为好友」
    $allowStmt = $pdo->prepare('SELECT anyone_add_friend FROM users WHERE user_id = ?');
    $allowStmt->execute([$toUid]);
    if ((int)$allowStmt->fetchColumn() === 0) return ['success' => false, 'error' => 'not_accepting'];

    // 好友申请节流：每 10 分钟最多 20 条 pending 请求（防批量骚扰）
    $rateStmt = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE user_from = ? AND status='pending' AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $rateStmt->execute([$myUid]);
    if ((int)$rateStmt->fetchColumn() >= 20) {
        return ['success' => false, 'error' => 'Too many friend requests. Please try again later.'];
    }

    $st = $pdo->prepare("SELECT id, status FROM contacts WHERE (user_from = ? AND user_to = ?) OR (user_from = ? AND user_to = ?)");
    $st->execute([$myUid, $toUid, $toUid, $myUid]);
    $ex = $st->fetch();

    if ($ex) {
        if ($ex['status'] === 'accepted') return ['success' => false, 'error' => 'Already friends.'];
        if ($ex['status'] === 'pending') return ['success' => false, 'error' => 'Request already pending.'];
        $pdo->prepare("UPDATE contacts SET status = 'pending', msg = ?, note = ?, created_at = NOW() WHERE id = ?")
            ->execute([$msg ?: null, $note ?: null, $ex['id']]);
        return ['success' => true];
    }

    $pdo->prepare("INSERT INTO contacts (user_from, user_to, status, msg, note) VALUES (?, ?, 'pending', ?, ?)")
        ->execute([$myUid, $toUid, $msg ?: null, $note ?: null]);
    return ['success' => true];
}

/**
 * 接受/拒绝好友申请（原 action=respond）。注意行方向语义：待处理行是对方(them→me)发来的，
 * 只能改它的状态；「我的备注」写进我(me→them)的行 —— 详见 i18n-settings 备忘里的历史 bug。
 */
function contact_action_respond(PDO $pdo, int $myUid, string $fromUser, string $resp, string $note = ''): array {
    $resp = trim($resp);
    if (!in_array($resp, ['accept', 'reject'], true)) return ['success' => false, 'error' => 'Something went wrong.'];
    $fromUid = contact_find_uid($pdo, $fromUser);
    if (!$fromUid) return ['success' => false, 'error' => 'Something went wrong.'];
    $ns = $resp === 'accept' ? 'accepted' : 'rejected';
    $note = trim(mb_substr($note, 0, 500));

    $stmt = $pdo->prepare("UPDATE contacts SET status = ? WHERE user_from = ? AND user_to = ? AND status = 'pending'");
    $stmt->execute([$ns, $fromUid, $myUid]);
    $ok = $stmt->rowCount() > 0;

    if ($ok && $ns === 'accepted') {
        // Level-gated contacts limit (jh.md Lv Limits: max_contacts)
        $maxContacts = level_limits(user_level($pdo, $myUid))['max_contacts'];
        $friendCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT c.user_to AS uid FROM contacts c JOIN users u ON u.user_id = c.user_to
                 WHERE c.user_from = $myUid AND c.status = 'accepted' AND u.is_bot = 0
                UNION
                SELECT c.user_from AS uid FROM contacts c JOIN users u ON u.user_id = c.user_from
                 WHERE c.user_to = $myUid AND c.status = 'accepted' AND u.is_bot = 0
             ) t"
        )->fetchColumn();
        if ($friendCount >= $maxContacts) {
            $pdo->prepare("UPDATE contacts SET status = 'pending' WHERE user_from = ? AND user_to = ?")
                ->execute([$fromUid, $myUid]);
            return ['success' => false, 'error' => 'Contact limit reached', 'max_contacts' => $maxContacts];
        }
        $rev = $pdo->prepare("SELECT id FROM contacts WHERE user_from = ? AND user_to = ?");
        $rev->execute([$myUid, $fromUid]);
        if ($rev->fetch()) {
            $pdo->prepare("UPDATE contacts SET status = 'accepted', note = ? WHERE user_from = ? AND user_to = ?")
                ->execute([$note ?: null, $myUid, $fromUid]);
        } else {
            $pdo->prepare("INSERT INTO contacts (user_from, user_to, status, note) VALUES (?, ?, 'accepted', ?)")
                ->execute([$myUid, $fromUid, $note ?: null]);
        }
    }
    return ['success' => $ok];
}

/** 删除联系人（原 action=delete）：双向删行（我和 ta 的关系整条没了） */
function contact_action_remove(PDO $pdo, int $myUid, string $myUsername, string $targetUser): array {
    $targetUser = trim($targetUser);
    if (empty($targetUser) || $targetUser === $myUsername) return ['success' => false, 'error' => 'Something went wrong.'];
    $targetUid = contact_find_uid($pdo, $targetUser);
    if (!$targetUid) return ['success' => false, 'error' => 'Something went wrong.'];
    $stmt = $pdo->prepare("DELETE FROM contacts WHERE (user_from = ? AND user_to = ?) OR (user_from = ? AND user_to = ?)");
    $stmt->execute([$myUid, $targetUid, $targetUid, $myUid]);
    return ['success' => $stmt->rowCount() > 0];
}

/** 会话置顶开关（原 action=toggle_pin，作用于我→ta 那一行）；$on=1/0 显式设置，null=翻转 */
function contact_action_toggle_pin(PDO $pdo, int $myUid, string $myUsername, string $targetUser, ?int $on = null): array {
    $targetUser = trim($targetUser);
    if (empty($targetUser) || $targetUser === $myUsername) return ['success' => false, 'error' => 'Something went wrong.'];
    $targetUid = contact_find_uid($pdo, $targetUser);
    if (!$targetUid) return ['success' => false, 'error' => 'Something went wrong.'];
    $st = $pdo->prepare("SELECT id, pinned FROM contacts WHERE user_from = ? AND user_to = ?");
    $st->execute([$myUid, $targetUid]);
    $row = $st->fetch();
    if (!$row) return ['success' => false, 'error' => 'Contact relationship not found.'];
    if ($on === null) {
        $pdo->prepare("UPDATE contacts SET pinned = 1 - pinned WHERE user_from = ? AND user_to = ?")->execute([$myUid, $targetUid]);
    } else {
        $pdo->prepare("UPDATE contacts SET pinned = ? WHERE user_from = ? AND user_to = ?")->execute([$on ? 1 : 0, $myUid, $targetUid]);
    }
    $now = $pdo->prepare("SELECT pinned FROM contacts WHERE user_from = ? AND user_to = ?");
    $now->execute([$myUid, $targetUid]);
    return ['success' => true, 'pinned' => (int)$now->fetchColumn()];
}

/** 特别关心开关（原 action=toggle_special）；$on=1/0 显式设置，null=翻转 */
function contact_action_toggle_special(PDO $pdo, int $myUid, string $myUsername, string $targetUser, ?int $on = null): array {
    $targetUser = trim($targetUser);
    if (empty($targetUser) || $targetUser === $myUsername) return ['success' => false, 'error' => 'Something went wrong.'];
    $targetUid = contact_find_uid($pdo, $targetUser);
    if (!$targetUid) return ['success' => false, 'error' => 'Something went wrong.'];

    // 必须是好友（任一方向 accepted）
    $rel = $pdo->prepare("SELECT id, status FROM contacts WHERE (user_from = ? AND user_to = ?) OR (user_from = ? AND user_to = ?) LIMIT 1");
    $rel->execute([$myUid, $targetUid, $targetUid, $myUid]);
    $ex = $rel->fetch();
    if (!$ex || $ex['status'] !== 'accepted') return ['success' => false, 'error' => 'Contact relationship not found.'];

    // 标记写在我→对方的那一行；没有则补建
    $mine = $pdo->prepare("SELECT id, special FROM contacts WHERE user_from = ? AND user_to = ?");
    $mine->execute([$myUid, $targetUid]);
    $row = $mine->fetch();
    if ($row) {
        if ($on === null) {
            $pdo->prepare("UPDATE contacts SET special = 1 - special WHERE user_from = ? AND user_to = ?")->execute([$myUid, $targetUid]);
        } else {
            $pdo->prepare("UPDATE contacts SET special = ? WHERE user_from = ? AND user_to = ?")->execute([$on ? 1 : 0, $myUid, $targetUid]);
        }
    } else {
        $pdo->prepare("INSERT INTO contacts (user_from, user_to, status, special) VALUES (?, ?, 'accepted', ?)")
            ->execute([$myUid, $targetUid, $on === null ? 1 : ($on ? 1 : 0)]);
    }
    $st = $pdo->prepare("SELECT special FROM contacts WHERE user_from = ? AND user_to = ?");
    $st->execute([$myUid, $targetUid]);
    return ['success' => true, 'special' => (int)$st->fetchColumn()];
}
