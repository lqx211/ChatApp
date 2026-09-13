<?php
/**
 * ChatApp · 空间（个人主页）统一读取层 —— api/space_read.php
 *
 * 为什么要单独一层：
 *   隐私过滤（说说 0–6 级可见性、留言板归属）是**安全逻辑**，散在两个地方写就会分叉。
 *   这里抽出来，让「网页 / 空间页」和「AI 工具」读的是同一份数据、同一套规则：
 *   - api/space.php 的 list / list_messages 直接调这里的函数
 *   - apps/deepseek/tools.php 的 ca_space 工具也调这里的函数（绝不自己写 SQL 读别人数据）
 *
 * 注意：本文件只做「读」，且所有过滤都以「请求者 $myUid 的视角」判断，
 *       不提供任何「以别人身份读」的入口。
 */
require_once __DIR__ . '/config.php';

/** 用户名 → uid（不存在返回 0） */
function space_find_uid(PDO $pdo, string $username): int {
    $username = trim($username);
    if ($username === '') return 0;
    $s = $pdo->prepare("SELECT user_id FROM users WHERE username=? AND deleted_at IS NULL");
    $s->execute([$username]);
    return (int)($s->fetchColumn() ?: 0);
}

/**
 * 读某人个人主页的「说说」（隐私过滤与空间页完全一致）。
 *
 * @param int $myUid     请求者（决定能看到哪些可见性）
 * @param int $targetUid 主页主人
 * @param int $limit     最多返回几条（1..200）
 * @return array 与 api/space.php?action=list 的 feeds 元素同构
 *   （本人可见 visibility / visible_to；他人不可见）
 */
function space_read_feeds(PDO $pdo, int $myUid, int $targetUid, int $limit = 200): array {
    if ($targetUid <= 0) return [];
    $limit = max(1, min(200, $limit));
    $isSelf = ($targetUid === $myUid);
    $isFriend = $isSelf || space_is_friend($pdo, $myUid, $targetUid);

    $stmt = $pdo->prepare("SELECT id, content, images, visibility, visible_to, likes, liked_by, created_at, edited_at
                           FROM space_feeds WHERE user_id=? AND enabled=1 ORDER BY id DESC LIMIT $limit");
    $stmt->execute([$targetUid]);
    $feeds = [];
    foreach ($stmt->fetchAll() as $f) {
        $vis = (int)$f['visibility'];
        if (!$isSelf) {
            if ($vis === 4) continue;                                          // 仅自己
            if ($vis === 1 && !$isFriend) continue;                            // 好友
            if ($vis === 2) { $vt = space_parse_ids($f['visible_to']); if (!in_array($myUid, $vt, true)) continue; } // 部分好友可见
            if ($vis === 3) { $vt = space_parse_ids($f['visible_to']); if (in_array($myUid, $vt, true)) continue; } // 部分好友不可见
            if ($vis === 5 && !space_me_in_flag($pdo, $targetUid, $myUid, 'pinned')) continue;   // 已置顶的朋友
            if ($vis === 6 && !space_me_in_flag($pdo, $targetUid, $myUid, 'special')) continue;  // 特别关心朋友
        }
        $likedBy = space_parse_ids($f['liked_by']);
        $feeds[] = [
            'id' => (int)$f['id'],
            'content' => (string)$f['content'],
            'images' => $f['images'] ? (json_decode($f['images'], true) ?: []) : [],
            'likes' => (int)$f['likes'],
            'liked' => in_array($myUid, $likedBy, true),
            'time' => space_fmt_full($f['created_at']),
            'edited' => !empty($f['edited_at']) ? space_fmt_full($f['edited_at']) : null,
            'visibility' => $isSelf ? $vis : null,
            'visible_to' => ($isSelf && ($vis === 2 || $vis === 3)) ? space_parse_ids($f['visible_to']) : [],
        ];
    }
    return $feeds;
}

/**
 * 读某人个人主页的「留言板」（公开留言，任何人可见；这里只读不写）。
 *
 * @return array{messages: array, i_am_owner: bool}
 */
function space_read_messages(PDO $pdo, int $myUid, int $targetUid, int $limit = 500): array {
    $empty = ['messages' => [], 'i_am_owner' => false];
    if ($targetUid <= 0) return $empty;
    $tu = $pdo->prepare("SELECT user_id FROM users WHERE user_id=?");
    $tu->execute([$targetUid]);
    if (!(int)$tu->fetchColumn()) return $empty;

    $limit = max(1, min(500, $limit));
    $s = $pdo->prepare("SELECT id, user_id, content, created_at FROM space_messages
                        WHERE to_uid=? AND enabled=1 ORDER BY id ASC LIMIT $limit");
    $s->execute([$targetUid]);
    $msgs = [];
    foreach ($s->fetchAll() as $m) {
        $msgs[] = [
            'id' => (int)$m['id'],
            'user_id' => (int)$m['user_id'],
            'content' => (string)$m['content'],
            'time' => space_fmt_time($m['created_at']),
            'card' => space_user_card($pdo, (int)$m['user_id']),
            'mine' => (int)$m['user_id'] === $myUid,
        ];
    }
    return ['messages' => $msgs, 'i_am_owner' => $targetUid === $myUid];
}

/** 取用户卡片（昵称/头像/用户名），静态缓存避免重复查询 */
function space_user_card(PDO $pdo, int $uid): array {
    static $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $s = $pdo->prepare("SELECT username, display_name, avatar FROM users WHERE user_id=?");
    $s->execute([$uid]);
    $r = $s->fetch();
    $card = ['uid' => $uid, 'username' => '', 'name' => '用户' . $uid, 'avatar' => ''];
    if ($r) {
        $card['username'] = (string)$r['username'];
        $card['name'] = ($r['display_name'] ?: $r['username']) ?: ('用户' . $uid);
        $card['avatar'] = chatapp_avatar_url($r['avatar'] ?? '', (string)$r['username']);
    }
    return $cache[$uid] = $card;
}
