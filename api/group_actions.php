<?php
/**
 * ChatApp · 群操作统一层 —— api/group_actions.php
 *
 * 从 api/group.php 原样抽出（行为/文案一字不改），让「网页按钮」和「AI 工具」走同一条路径：
 *   建群（含等级 max_groups 限制）/ 加入或申请 / 群置顶开关
 *
 * 返回数组与端点原本的 JSON 同构。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/chat_actions.php';   // 级别限制相关（保持与 group.php 一致的加载顺序）

/** 生成不冲突的群号 */
function gen_group_id(PDO $pdo): int {
    for ($i = 0; $i < 20; $i++) {
        $id = random_int(1000000, 150000000);
        if (!$pdo->query("SELECT 1 FROM `groups` WHERE group_id=$id")->fetch()) {
            return $id;
        }
    }
    throw new Exception('Failed to generate unique group ID');
}

/** 群号或群名 → group_id（找不到返回 0）。数字当群号，否则按群名精确匹配（取最早创建的那个） */
function group_find_gid(PDO $pdo, string $key): int {
    $key = trim($key);
    if ($key === '') return 0;
    if (preg_match('/^\d+$/', $key)) {
        $s = $pdo->prepare("SELECT group_id FROM `groups` WHERE group_id = ?");
        $s->execute([(int)$key]);
        return (int)($s->fetchColumn() ?: 0);
    }
    $s = $pdo->prepare("SELECT group_id FROM `groups` WHERE name = ? ORDER BY group_id ASC LIMIT 1");
    $s->execute([$key]);
    return (int)($s->fetchColumn() ?: 0);
}

/** 我在群里的角色（不是成员返回 ''） */
function group_my_role(PDO $pdo, int $myUid, int $gid): string {
    $s = $pdo->prepare("SELECT role FROM group_members WHERE group_id = ? AND user_id = ?");
    $s->execute([$gid, $myUid]);
    return (string)($s->fetchColumn() ?: '');
}

/** 建群（原 action=create）：受等级 max_groups 限制；建完把自己加为 owner */
function group_action_create(PDO $pdo, int $myUid, string $name): array {
    $name = trim(mb_substr($name, 0, 50));
    if (empty($name)) return ['success' => false];

    // Level-gated owned-groups limit (jh.md Lv Limits: max_groups)
    $maxGroups = level_limits(user_level($pdo, $myUid))['max_groups'];
    $ownedCount = (int)$pdo->query("SELECT COUNT(*) FROM `groups` WHERE owner_id=$myUid")->fetchColumn();
    if ($ownedCount >= $maxGroups) {
        return ['success' => false, 'error' => 'Group limit reached', 'max_groups' => $maxGroups];
    }

    $gid = gen_group_id($pdo);
    $pdo->prepare("INSERT INTO `groups` (group_id, name, owner_id) VALUES (?, ?, ?)")->execute([$gid, $name, $myUid]);
    $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'owner')")->execute([$gid, $myUid]);
    return ['success' => true, 'group_id' => $gid, 'name' => $name];
}

/**
 * 加入群 / 申请进群。
 *  $mode = 'join'            公开群直接进；非公开群一律 {success:false}（原 action=join）
 *          'request'         一律发申请（原 action=request）
 *          'join_or_request' 公开群进、非公开群发申请（原 action=join_by_gid）
 */
function group_action_join(PDO $pdo, int $myUid, int $gid, string $mode = 'join_or_request'): array {
    if ($gid <= 0) return ['success' => false];
    $st = $pdo->prepare("SELECT * FROM `groups` WHERE group_id = ?");
    $st->execute([$gid]);
    $g = $st->fetch();

    if ($mode === 'join') {
        if (!$g || !$g['public']) return ['success' => false];
        try {
            $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")->execute([$gid, $myUid]);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Already a member.'];
        }
        return ['success' => true];
    }

    if (!$g) return ['success' => false, 'error' => 'Group not found.'];

    if ($mode === 'request') {
        $isMember = $pdo->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=?");
        $isMember->execute([$gid, $myUid]);
        if ($isMember->fetch()) return ['success' => false, 'error' => 'Already a member.'];
        try {
            $pdo->prepare("INSERT INTO group_requests (group_id, user_id) VALUES (?, ?)")->execute([$gid, $myUid]);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Already requested.'];
        }
        return ['success' => true];
    }

    /* join_or_request */
    if ($g['public']) {
        try {
            $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')")->execute([$gid, $myUid]);
        } catch (Exception $e) {
            return ['success' => false, 'error' => 'Already a member.'];
        }
        return ['success' => true, 'joined' => true];
    }
    try {
        $pdo->prepare("INSERT INTO group_requests (group_id, user_id) VALUES (?, ?)")->execute([$gid, $myUid]);
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Already requested.'];
    }
    return ['success' => true, 'requested' => true];
}

/** 群置顶开关（原 action=toggle_pin）；$on=1/0 显式设置，null=翻转 */
function group_action_toggle_pin(PDO $pdo, int $myUid, int $gid, ?int $on = null): array {
    if ($gid <= 0) return ['success' => false];
    // 注意：group_members 没有 id 列（主键是 group_id+user_id）——
    // 原 api/group.php 里写的是 SELECT id，会抛 1054，群置顶一直是坏的，这里改掉。
    $st = $pdo->prepare("SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?");
    $st->execute([$gid, $myUid]);
    if (!$st->fetch()) return ['success' => false];
    if ($on === null) {
        $pdo->prepare("UPDATE group_members SET pinned = 1 - pinned WHERE group_id = ? AND user_id = ?")->execute([$gid, $myUid]);
    } else {
        $pdo->prepare("UPDATE group_members SET pinned = ? WHERE group_id = ? AND user_id = ?")->execute([$on ? 1 : 0, $gid, $myUid]);
    }
    $now = $pdo->prepare("SELECT pinned FROM group_members WHERE group_id = ? AND user_id = ?");
    $now->execute([$gid, $myUid]);
    return ['success' => true, 'pinned' => (int)$now->fetchColumn()];
}
