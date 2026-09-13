<?php
/**
 * ChatApp · 工单统一操作层（api/incident.php 端点 与 AI 工具 ca_ticket 共用）
 *
 * 规则收敛在这里，避免「网页一套、AI 一套」：
 *   - create：字段白名单 + 长度限制 + 图片校验（data URL，真图片才收）+ EXP 奖励/冷却
 *     （含工单系统总开关 app_settings.ai_ticket_enabled，root 例外）
 *   - list/detail/respond：非管理员只能碰自己的单；type='report' 的举报单仅管理员可见
 *   - update_status：仅管理员；切到 resolved 时发 EXP（exp_awarded 去重，绝不重发）
 *
 * 返回约定：成功 ['success'=>true, ...]；失败 ['success'=>false, 'error'=>人话]。
 * 字段名与旧端点保持一致（前端 JS 靠这些键判断），不要随意改名。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../config/lvconfig.php';   // exp_add / exp_bonus_claim

function incident_action_create(PDO $pdo, int $myUid, array $in): array {
    /* 工单系统总开关：root 可在 AI 工具 ca_ticket 里切 on/off；关掉后普通用户不能建单，
       root 仍然可以（方便自己记录/测试）。 */
    if (chatapp_app_get('ai_ticket_enabled', '1') !== '1' && chatapp_get_role($myUid) !== 'root') {
        return ['success' => false, 'error' => '工单系统暂时关闭（维护中），稍后再试'];
    }

    $type = trim((string)($in['type'] ?? 'bug'));
    if (!in_array($type, ['bug', 'recommendation', 'account_issue'], true)) $type = 'bug';
    $subject = trim(mb_substr((string)($in['subject'] ?? ''), 0, 500));
    $reason = trim(mb_substr((string)($in['reason'] ?? ''), 0, 5000));
    $priority = trim((string)($in['priority'] ?? 'normal'));
    $allowedP = ['task', 'low', 'normal', 'medium', 'high', 'urgent', 'critical', 'nopriority'];
    if (!in_array($priority, $allowedP, true)) $priority = 'normal';
    if ($subject === '') return ['success' => false, 'error' => 'Subject required.'];

    /* 图片：数组（或 JSON 字符串），元素是 data:image/...;base64,...；最多 5 张。
       MIME 子类型不信客户端 —— 白名单 js 只用扩展名 + getimagesizefromstring 兜底。 */
    $imagesJson = null;
    $images = $in['images'] ?? null;
    if (is_string($images)) { $decoded = json_decode($images, true); $images = is_array($decoded) ? $decoded : null; }
    if (is_array($images) && count($images) > 0 && count($images) <= 5) {
        $ticketDir = __DIR__ . '/../data/ticket';
        if (!is_dir($ticketDir)) @mkdir($ticketDir, 0755, true);
        $savedPaths = [];
        foreach ($images as $b64) {
            if (!is_string($b64)) continue;
            if (!preg_match('/^data:image\/(\w+);base64,(.+)$/s', $b64, $m)) continue;
            $imgExt = strtolower($m[1]);
            if ($imgExt === 'jpeg') $imgExt = 'jpg';
            if (!in_array($imgExt, ['jpg', 'png', 'gif', 'webp'], true)) continue;
            $bin = base64_decode($m[2]);
            if ($bin === false || strlen($bin) > 8 * 1024 * 1024) continue;
            if (@getimagesizefromstring($bin) === false) continue;
            $hash = substr(hash('sha256', $bin), 0, 16);
            $filename = $hash . '.' . $imgExt;
            @file_put_contents($ticketDir . '/' . $filename, $bin);
            $savedPaths[] = 'ticket/' . $filename;
        }
        if (count($savedPaths) > 0) $imagesJson = json_encode($savedPaths);
    }

    $pdo->prepare("INSERT INTO incidents (type, reporter_id, subject, reason, priority, images, status) VALUES (?, ?, ?, ?, ?, ?, 'open')")
        ->execute([$type, $myUid, $subject, $reason ?: null, $priority, $imagesJson]);
    $newIncidentId = (int)$pdo->lastInsertId();

    /* ---- Level system: ticket creation EXP（入库后发放）----
       UID 10000（root/站主）自己报的 bug/建议不发 EXP。 */
    try {
        if ($myUid !== 10000) {
            if ($type === 'bug') {
                // Bug: +20，12 小时冷却；历史第一单额外 +75（只一次）
                $stmt = $pdo->prepare("SELECT last_exp_bug_at FROM users WHERE user_id = ?");
                $stmt->execute([$myUid]);
                $lastBugAt = $stmt->fetchColumn();
                $lastBugTs = $lastBugAt ? strtotime((string)$lastBugAt) : 0;
                if (!$lastBugAt || (time() - $lastBugTs) >= 12 * 3600) {
                    $pdo->prepare("UPDATE users SET last_exp_bug_at = NOW() WHERE user_id = ?")->execute([$myUid]);
                    exp_add($myUid, 20, 'bug', true, 'ticket:' . $newIncidentId);
                }
                exp_bonus_claim($myUid, 'first_bug', 75, 'bonus_first_bug', 'ticket:' . $newIncidentId);
            } elseif ($type === 'recommendation') {
                // Suggestion: +10，12 小时冷却
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

    return ['success' => true, 'id' => $newIncidentId];
}

function incident_action_list(PDO $pdo, int $myUid, bool $isAdmin, array $p): array {
    $status = (string)($p['status'] ?? 'open');
    $page = max(1, (int)($p['page'] ?? 1));
    $perPage = max(5, min(100, (int)($p['per_page'] ?? 15)));
    $search = trim((string)($p['search'] ?? ''));
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

    return ['success' => true, 'incidents' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}

function incident_action_detail(PDO $pdo, int $myUid, bool $isAdmin, int $id): array {
    $stmt = $pdo->prepare("SELECT i.*, COALESCE(u.display_name, u.username) AS reporter_name
        FROM incidents i JOIN users u ON u.user_id = i.reporter_id WHERE i.id = ?");
    $stmt->execute([$id]);
    $incident = $stmt->fetch();
    if (!$incident) return ['success' => false, 'error' => '工单不存在'];
    if (!$isAdmin && (int)$incident['reporter_id'] !== $myUid) return ['success' => false, 'error' => '无权查看这张工单'];

    $resStmt = $pdo->prepare("SELECT ir.*, COALESCE(u.display_name, u.username) AS username FROM incident_responses ir
        JOIN users u ON u.user_id = ir.user_id WHERE ir.incident_id = ? ORDER BY ir.created_at ASC");
    $resStmt->execute([$id]);
    $incident['responses'] = $resStmt->fetchAll();

    /* 举报单（type='report'）：给管理员还原被举报的消息（撤回了也给看；
       E2EE 有举报时落下的 msg_crypt_temp 明文证据优先）。 */
    $incident['reported_messages'] = [];
    if ($isAdmin && $incident['type'] === 'report' && !empty($incident['message_ids'])) {
        $msgIds = json_decode($incident['message_ids'], true);
        if (is_array($msgIds) && count($msgIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($msgIds), '?'));
            $msgStmt = $pdo->prepare("SELECT m.id, m.message, m.msg_type, m.datetime, m.deleted_at, COALESCE(u.display_name, u.username) AS sender_name,
                    t.content AS dec_content, t.md AS dec_md
                FROM messages m JOIN users u ON u.user_id = m.sender_id
                LEFT JOIN msg_crypt_temp t ON t.message_id = m.id AND t.ticket_id = ?
                WHERE m.id IN ($placeholders) ORDER BY m.id ASC");
            $msgStmt->execute(array_merge([$id], array_map('intval', $msgIds)));
            while ($msg = $msgStmt->fetch()) {
                $msg['is_revoked'] = ($msg['deleted_at'] !== null);
                if ($msg['dec_content'] !== null && $msg['dec_content'] !== '') {
                    $msg['message'] = $msg['dec_content'];
                    $msg['md'] = (int)$msg['dec_md'];
                    $msg['decrypted'] = true;
                }
                unset($msg['dec_content'], $msg['dec_md']);
                $incident['reported_messages'][] = $msg;
            }
        }
    }

    return ['success' => true, 'incident' => $incident];
}

function incident_action_respond(PDO $pdo, int $myUid, bool $isAdmin, int $id, string $message): array {
    $message = trim(mb_substr($message, 0, 5000));
    if ($message === '') return ['success' => false, 'error' => '回复内容不能为空'];
    $stmt = $pdo->prepare("SELECT id, reporter_id FROM incidents WHERE id = ?");
    $stmt->execute([$id]);
    $inc = $stmt->fetch();
    if (!$inc) return ['success' => false, 'error' => '工单不存在'];
    if (!$isAdmin && (int)$inc['reporter_id'] !== $myUid) return ['success' => false, 'error' => '无权回复这张工单'];
    $pdo->prepare("INSERT INTO incident_responses (incident_id, user_id, message, is_staff) VALUES (?, ?, ?, ?)")
        ->execute([$id, $myUid, $message, $isAdmin ? 1 : 0]);
    return ['success' => true];
}

function incident_action_update_status(PDO $pdo, bool $isAdmin, int $id, ?string $status, ?string $priority): array {
    if (!$isAdmin) return ['success' => false, 'error' => '只有管理员可以改工单状态'];
    $status = $status !== null ? trim($status) : '';
    $priority = $priority !== null ? trim($priority) : '';
    if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) $status = '';
    if (!in_array($priority, ['task', 'low', 'normal', 'medium', 'high', 'urgent', 'critical', 'nopriority'], true)) $priority = '';
    $sets = []; $params = [];
    if ($status !== '') { $sets[] = 'status = ?'; $params[] = $status; }
    if ($priority !== '') { $sets[] = 'priority = ?'; $params[] = $priority; }
    if (empty($sets)) return ['success' => false, 'error' => '没有任何要改的字段（status / priority 至少给一个，且值要合法）'];

    /* ---- Level system: 切到 resolved（不是 closed）时才发奖励 ----
       先查再看，入库后用 exp_awarded 标记，绝不会重复发。 */
    $rewardType = null; $rewardExp = 0;
    if ($status === 'resolved') {
        $stmt = $pdo->prepare("SELECT type, exp_awarded FROM incidents WHERE id = ?");
        $stmt->execute([$id]);
        $inc = $stmt->fetch();
        if ($inc && !(int)($inc['exp_awarded'] ?? 0)) {
            if ($inc['type'] === 'bug')                  { $rewardExp = 100; $rewardType = 'bug_resolved'; }
            elseif ($inc['type'] === 'recommendation')   { $rewardExp = 50;  $rewardType = 'suggestion_resolved'; }
        }
    }

    $params[] = $id;
    $affected = $pdo->prepare("UPDATE incidents SET " . implode(', ', $sets) . " WHERE id = ?");
    $affected->execute($params);
    if ($affected->rowCount() === 0) return ['success' => false, 'error' => '工单不存在或状态没有变化'];

    if ($rewardType && $rewardExp > 0) {
        try {
            $stmt = $pdo->prepare("SELECT reporter_id FROM incidents WHERE id = ?");
            $stmt->execute([$id]);
            $reporterUid = (int)($stmt->fetchColumn() ?: 0);
            if ($reporterUid > 0 && $reporterUid !== 10000) {
                $pdo->prepare("UPDATE incidents SET exp_awarded = 1 WHERE id = ?")->execute([$id]);
                exp_add($reporterUid, $rewardExp, $rewardType, true, 'ticket:' . $id);
            }
        } catch (Exception $e) {
            // never break admin status update
        }
    }

    return ['success' => true, 'exp_awarded' => $rewardType !== null];
}

function incident_action_count(PDO $pdo, int $myUid, bool $isAdmin): array {
    if ($isAdmin) {
        $t = function (string $type) use ($pdo): int {
            $st = $pdo->prepare("SELECT COUNT(*) FROM incidents WHERE status = 'open' AND type = ?");
            $st->execute([$type]);
            return (int)$st->fetchColumn();
        };
        return ['success' => true, 'is_admin' => true, 'bugs' => $t('bug'), 'recommendation' => $t('recommendation'),
                'account_issue' => $t('account_issue'), 'report' => $t('report')];
    }
    $st = $pdo->prepare("SELECT COUNT(DISTINCT i.id) FROM incidents i JOIN incident_responses r ON r.incident_id = i.id AND r.user_id != i.reporter_id WHERE i.reporter_id = ?");
    $st->execute([$myUid]);
    return ['success' => true, 'is_admin' => false, 'reply_count' => (int)$st->fetchColumn()];
}
