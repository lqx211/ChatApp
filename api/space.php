<?php
/**
 * ChatApp 个人空间 · 朋友圈 API
 * post          发布说说（content + visibility + visible_to + images）
 * list          拉取某人空间的说说（按可见性过滤，返回 JSON 供前端刷新/分页用）
 * delete        删除自己的说说
 * toggle_like   点赞 / 取消赞
 */
require_once __DIR__ . '/config.php';
chatapp_require_login();
ensure_space_feeds_table();
ensure_space_comments_table();
ensure_space_messages_table();
ensure_space_blogs_table();
ensure_space_mentions_table();
ensure_space_albums_table();
ensure_space_album_photos_table();
ensure_space_visits_table();
$pdo = db();
$me = chatapp_get_user();
$myUid = (int)($me['user_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'post':
        $content = trim((string)($_POST['content'] ?? ''));
        $visibility = (int)($_POST['visibility'] ?? 0);
        if ($visibility < 0 || $visibility > 6) $visibility = 0;
        $visible_to = null;
        if ($visibility === 2 || $visibility === 3) {
            $ids = space_parse_ids($_POST['visible_to'] ?? '');
            if (!$ids) $visibility = 1;              // 没选好友则降级为“好友可见”
            else $visible_to = json_encode($ids);
        }
        $images = null;
        $im = $_POST['images'] ?? '';
        if (is_string($im) && $im !== '' && $im[0] === '[') $images = $im;   // 预留：JSON 数组
        if ($content === '' && !$images) { echo json_encode(['success' => false, 'error' => 'empty']); exit; }
        $stmt = $pdo->prepare("INSERT INTO space_feeds (user_id, content, images, visibility, visible_to) VALUES (?,?,?,?,?)");
        $stmt->execute([$myUid, mb_substr($content, 0, 5000), $images, $visibility, $visible_to]);
        $feedId = (int)$pdo->lastInsertId();
        // 自动把朋友圈图片/视频加入「动态」相册（不存在则创建，默认私密）；sync_featured 控制是否同步精选
        $imgArr = $images ? (json_decode($images, true) ?: []) : [];
        $imgArr = array_values(array_filter((array)$imgArr, 'is_string'));
        if ($imgArr) {
            $dyn = $pdo->prepare("SELECT id FROM space_albums WHERE user_id=? AND is_dynamic=1 AND enabled=1 LIMIT 1");
            $dyn->execute([$myUid]);
            $dynId = (int)$dyn->fetchColumn();
            if (!$dynId) {
                $dins = $pdo->prepare("INSERT INTO space_albums (user_id, name, description, type, visibility, is_dynamic) VALUES (?,?,?,?,?,1)");
                $dins->execute([$myUid, '动态', '发朋友圈自动同步的相册', 'personal', 4]);
                $dynId = (int)$pdo->lastInsertId();
            }
            $featured = (($_POST['sync_featured'] ?? '1') === '1') ? 1 : 0;
            $phStmt = $pdo->prepare("INSERT INTO space_album_photos (album_id, user_id, media, featured) VALUES (?,?,?,?)");
            foreach ($imgArr as $im) {
                $im = trim((string)$im);
                if ($im === '') continue;
                $phStmt->execute([$dynId, $myUid, $im, $featured]);
            }
        }
        // 艾特通知：写入 space_mentions（只保留有效用户，排除自己，按 uid 去重）
        $mRaw = $_POST['mentions'] ?? '';
        if (is_string($mRaw) && $mRaw !== '' && $mRaw[0] === '[') {
            $arr = json_decode($mRaw, true);
            if (is_array($arr)) {
                $mUids = [];
                foreach ($arr as $v) { $v = (int)$v; if ($v > 0 && $v !== $myUid) $mUids[$v] = $v; }
                if ($mUids) {
                    $uStmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id IN (" . implode(',', array_map('intval', array_keys($mUids))) . ") AND enabled = 1 AND placeholder = 0");
                    $uStmt->execute();
                    foreach ($uStmt->fetchAll(PDO::FETCH_COLUMN) as $validUid) { $validUid = (int)$validUid; if ($validUid && $validUid !== $myUid) $mUids[$validUid] = $validUid; }
                    $mStmt = $pdo->prepare("INSERT IGNORE INTO space_mentions (feed_id, mentioned_uid, by_uid) VALUES (?,?,?)");
                    foreach ($mUids as $muid) $mStmt->execute([$feedId, $muid, $myUid]);
                }
            }
        }
        echo json_encode(['success' => true, 'id' => $feedId]);
        break;

    /* ============ 编辑动态（内容 + 可见权限） ============ */
    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        $content = trim((string)($_POST['content'] ?? ''));
        $visibility = (int)($_POST['visibility'] ?? 0);
        if ($visibility < 0 || $visibility > 6) $visibility = 0;
        $visible_to = null;
        if ($visibility === 2 || $visibility === 3) {
            $ids = space_parse_ids($_POST['visible_to'] ?? '');
            if (!$ids) $visibility = 1;
            else $visible_to = json_encode($ids);
        }
        if (!$id) { echo json_encode(['success' => false, 'error' => 'id']); break; }
        // 仅本人可编辑自己的动态
        $own = $pdo->prepare("SELECT id FROM space_feeds WHERE id=? AND user_id=? AND enabled=1");
        $own->execute([$id, $myUid]);
        if (!$own->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'denied']); break; }
        $images = null;
        $im = $_POST['images'] ?? '';
        if (is_string($im) && $im !== '' && $im[0] === '[') $images = $im;   // 编辑时同步覆盖图片
        $up = $pdo->prepare("UPDATE space_feeds SET content=?, images=?, visibility=?, visible_to=?, edited_at=NOW() WHERE id=? AND user_id=?");
        $up->execute([mb_substr($content, 0, 5000), $images, $visibility, $visible_to, $id, $myUid]);
        echo json_encode(['success' => true]);
        break;

    /* ============ 相册 ============ */
    case 'album_list':
        // 列出某用户可见的相册（uid / user / 缺省本人），带封面与照片数
        $targetUid = (int)($_GET['uid'] ?? 0);
        if ($targetUid <= 0) {
            $un = trim((string)($_GET['user'] ?? ''));
            if ($un !== '') {
                $us = $pdo->prepare("SELECT user_id FROM users WHERE username=?");
                $us->execute([$un]);
                $targetUid = (int)$us->fetchColumn();
            }
        }
        if ($targetUid <= 0) $targetUid = $myUid;
        $isSelf = $targetUid === $myUid;
        $stmt = $pdo->prepare("SELECT * FROM space_albums WHERE user_id=? AND enabled=1 ORDER BY is_dynamic DESC, id ASC");
        $stmt->execute([$targetUid]);
        $albums = [];
        foreach ($stmt->fetchAll() as $a) {
            if (!$isSelf && !space_album_allowed($pdo, $myUid, $a)) continue;
            $cov = $pdo->prepare("SELECT media FROM space_album_photos WHERE album_id=? AND enabled=1 ORDER BY id DESC LIMIT 1");
            $cov->execute([$a['id']]);
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM space_album_photos WHERE album_id=? AND enabled=1");
            $cnt->execute([$a['id']]);
            $albums[] = [
                'id' => (int)$a['id'],
                'name' => (string)$a['name'],
                'description' => (string)($a['description'] ?? ''),
                'type' => (string)$a['type'],
                'visibility' => (int)$a['visibility'],
                'visible_to' => $a['visible_to'] ? (json_decode($a['visible_to'], true) ?: []) : [],
                'is_dynamic' => (int)$a['is_dynamic'],
                'cover' => (string)$cov->fetchColumn(),
                'count' => (int)$cnt->fetchColumn(),
            ];
        }
        echo json_encode(['success' => true, 'albums' => $albums]);
        break;

    case 'album_create':
        $name = trim((string)($_POST['name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $type = trim((string)($_POST['type'] ?? 'personal'));
        $vis = (int)($_POST['visibility'] ?? 0);
        if ($vis < 0 || $vis > 4) $vis = 0;
        if ($name === '') { echo json_encode(['success' => false, 'error' => 'name_required']); break; }
        if (mb_strlen($name) > 30) { echo json_encode(['success' => false, 'error' => 'name_len']); break; }
        if (!in_array($type, ['personal', 'multi', 'couple', 'family', 'travel', 'other'], true)) $type = 'personal';
        // 相册名不能与已有相册重复
        $chk = $pdo->prepare("SELECT COUNT(*) FROM space_albums WHERE user_id=? AND name=? AND enabled=1");
        $chk->execute([$myUid, $name]);
        if ((int)$chk->fetchColumn() > 0) { echo json_encode(['success' => false, 'error' => 'name_exists']); break; }
        $visible_to = null;
        if ($vis === 2 || $vis === 3) {
            $ids = space_parse_ids($_POST['visible_to'] ?? '');
            if (!$ids) { echo json_encode(['success' => false, 'error' => 'friends_required']); break; }
            $visible_to = json_encode($ids);
        }
        $desc = mb_substr($desc, 0, 200);
        $ins = $pdo->prepare("INSERT INTO space_albums (user_id, name, description, type, visibility, visible_to, is_dynamic) VALUES (?,?,?,?,?,?,0)");
        $ins->execute([$myUid, $name, $desc, $type, $vis, $visible_to]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;

    case 'album_delete':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false]); break; }
        $pdo->prepare("UPDATE space_albums SET enabled=0 WHERE id=? AND user_id=?")->execute([$id, $myUid]);
        echo json_encode(['success' => true]);
        break;

    case 'album_photos':
        $aid = (int)($_GET['id'] ?? 0);
        if (!$aid) { echo json_encode(['success' => false]); break; }
        $stmt = $pdo->prepare("SELECT * FROM space_albums WHERE id=? AND enabled=1");
        $stmt->execute([$aid]);
        $album = $stmt->fetch();
        if (!$album) { echo json_encode(['success' => false, 'error' => 'no_album']); break; }
        if (!space_album_allowed($pdo, $myUid, $album)) { echo json_encode(['success' => false, 'error' => 'denied']); break; }
        $ps = $pdo->prepare("SELECT id, user_id, media, created_at FROM space_album_photos WHERE album_id=? AND enabled=1 ORDER BY id DESC");
        $ps->execute([$aid]);
        $photos = [];
        foreach ($ps->fetchAll() as $p) {
            $photos[] = [
                'id' => (int)$p['id'],
                'media' => (string)$p['media'],
                'time' => space_fmt_time($p['created_at']),
                'mine' => ((int)$p['user_id'] === $myUid) ? 1 : 0,
            ];
        }
        echo json_encode(['success' => true,
            'album' => [
                'id' => (int)$album['id'], 'name' => (string)$album['name'],
                'description' => (string)($album['description'] ?? ''), 'type' => (string)$album['type'],
                'visibility' => (int)$album['visibility'], 'is_dynamic' => (int)$album['is_dynamic'],
                'user_id' => (int)$album['user_id'],
            ],
            'photos' => $photos]);
        break;

    /* ============ 删除相册照片（仅本人） ============ */
    case 'delete_photo':
        $pid = (int)($_POST['id'] ?? 0);
        if (!$pid) { echo json_encode(['success' => false, 'error' => 'id']); break; }
        $st = $pdo->prepare("SELECT id, album_id FROM space_album_photos WHERE id=? AND user_id=? AND enabled=1");
        $st->execute([$pid, $myUid]);
        $row = $st->fetch();
        if (!$row) { echo json_encode(['success' => false, 'error' => 'denied']); break; }
        $pdo->prepare("UPDATE space_album_photos SET enabled=0 WHERE id=? AND user_id=?")->execute([$pid, $myUid]);
        echo json_encode(['success' => true]);
        break;

    /* ============ 访客 ============ */
    case 'visitor_list':
        // type: me=谁看过我(我被人看)  you=我看过谁(我看别人)  refuse=被挡访客(预留空)
        $type = trim($_GET['type'] ?? 'me');
        if ($type === 'refuse') {
            $q = null;   // 被挡访客功能未开放，预留空列表
        } elseif ($type === 'you') {
            $q = $pdo->query("SELECT target_uid AS other_uid, MAX(created_at) AS at FROM space_visits WHERE viewer_uid=$myUid GROUP BY target_uid ORDER BY at DESC LIMIT 30");
        } else {
            $q = $pdo->query("SELECT viewer_uid AS other_uid, MAX(created_at) AS at FROM space_visits WHERE target_uid=$myUid AND hidden=0 GROUP BY viewer_uid ORDER BY at DESC LIMIT 30");
        }
        $list = [];
        if ($q) {
            foreach ($q->fetchAll() as $r) {
            $ouid = (int)$r['other_uid'];
            if ($ouid <= 0) continue;
            $u = $pdo->prepare("SELECT user_id, username, display_name, avatar, gender, birthday FROM users WHERE user_id=? AND enabled=1 AND placeholder=0 AND deleted_at IS NULL");
            $u->execute([$ouid]);
            $uu = $u->fetch();
            if (!$uu) continue;
            $special = (int)($pdo->query("SELECT special FROM contacts WHERE user_from=$myUid AND user_to=$ouid")->fetchColumn() ?: 0);
            $list[] = [
                'uid' => $ouid,
                'username' => (string)$uu['username'],
                'name' => (string)($uu['display_name'] ?: $uu['username']),
                'avatar' => chatapp_avatar_url($uu['avatar'] ?? '', $uu['username'], $ouid),
                'gender' => (int)$uu['gender'],
                'zodiac' => space_zodiac((string)($uu['birthday'] ?? '')),
                'common' => ($type === 'me') ? space_common_friends($pdo, $myUid, $ouid) : 0,
                'special' => $special,
                'time' => space_fmt_time($r['at']),
            ];
            }
        }
        echo json_encode(['success' => true, 'list' => $list]);
        break;

    case 'visit_count':
        $today = date('Y-m-d 00:00:00');
        $t = $pdo->prepare("SELECT COUNT(DISTINCT viewer_uid) FROM space_visits WHERE target_uid=? AND created_at>=?");
        $t->execute([$myUid, $today]);
        $total = $pdo->prepare("SELECT COUNT(DISTINCT viewer_uid) FROM space_visits WHERE target_uid=?");
        $total->execute([$myUid]);
        echo json_encode(['success' => true, 'today' => (int)$t->fetchColumn(), 'total' => (int)$total->fetchColumn()]);
        break;

    case 'visitor_delete':
        $ouid = (int)($_POST['uid'] ?? 0);
        if ($ouid > 0) $pdo->prepare("DELETE FROM space_visits WHERE target_uid=? AND viewer_uid=?")->execute([$myUid, $ouid]);
        echo json_encode(['success' => true]);
        break;

    case 'visitor_hide':
        $ouid = (int)($_POST['uid'] ?? 0);
        if ($ouid > 0) $pdo->prepare("UPDATE space_visits SET hidden=1 WHERE target_uid=? AND viewer_uid=?")->execute([$myUid, $ouid]);
        echo json_encode(['success' => true]);
        break;

    case 'list':
        $user = trim((string)($_GET['user'] ?? $_POST['user'] ?? ''));
        $targetUid = 0;
        if ($user !== '') {
            $s = $pdo->prepare("SELECT user_id FROM users WHERE username=?");
            $s->execute([$user]);
            $targetUid = (int)$s->fetchColumn();
        } else {
            $targetUid = (int)($_GET['uid'] ?? $_POST['uid'] ?? $myUid);
        }
        if (!$targetUid) $targetUid = $myUid;
        $isSelf = ($targetUid === $myUid);
        $isFriend = $isSelf || space_is_friend($pdo, $myUid, $targetUid);
        $stmt = $pdo->prepare("SELECT id, content, images, visibility, visible_to, likes, liked_by, created_at, edited_at FROM space_feeds WHERE user_id=? AND enabled=1 ORDER BY id DESC LIMIT 200");
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
                'time' => space_fmt_time($f['created_at']),
                'edited' => !empty($f['edited_at']) ? space_fmt_time($f['edited_at']) : null,
                'visibility' => $isSelf ? $vis : null,
                'visible_to' => ($isSelf && ($vis === 2 || $vis === 3)) ? space_parse_ids($f['visible_to']) : [],
            ];
        }
        echo json_encode(['success' => true, 'feeds' => $feeds]);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'id']); break; }
        $stmt = $pdo->prepare("DELETE FROM space_feeds WHERE id=? AND user_id=?");
        $stmt->execute([$id, $myUid]);
        if ($stmt->rowCount()) {
            // 连带清理该说说的艾特通知
            $pdo->prepare("DELETE FROM space_mentions WHERE feed_id=?")->execute([$id]);
        }
        echo json_encode(['success' => (bool)$stmt->rowCount()]);
        break;

    case 'toggle_like':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'id']); break; }
        $stmt = $pdo->prepare("SELECT likes, liked_by FROM space_feeds WHERE id=? AND enabled=1");
        $stmt->execute([$id]);
        $f = $stmt->fetch();
        if (!$f) { echo json_encode(['success' => false]); break; }
        $likedBy = space_parse_ids($f['liked_by']);
        $idx = array_search($myUid, $likedBy, true);
        $liked = false;
        if ($idx === false) { $likedBy[] = $myUid; $liked = true; }
        else { array_splice($likedBy, $idx, 1); }
        $pdo->prepare("UPDATE space_feeds SET likes=?, liked_by=? WHERE id=?")
            ->execute([count($likedBy), $likedBy ? json_encode($likedBy) : null, $id]);
        // 赞/取消赞 → 写入/清理「赞了我的说说」通知
        $oStmt = $pdo->prepare("SELECT user_id FROM space_feeds WHERE id=?");
        $oStmt->execute([$id]);
        $owner = (int)$oStmt->fetchColumn();
        if ($owner && $owner !== $myUid) {
            if ($liked) {
                $dup = $pdo->prepare("SELECT id FROM space_mentions WHERE feed_id=? AND mentioned_uid=? AND by_uid=? AND type='like' LIMIT 1");
                $dup->execute([$id, $owner, $myUid]);
                if (!$dup->fetchColumn()) {
                    $pdo->prepare("INSERT INTO space_mentions (feed_id, mentioned_uid, by_uid, type) VALUES (?,?,?,'like')")->execute([$id, $owner, $myUid]);
                }
            } else {
                $pdo->prepare("DELETE FROM space_mentions WHERE feed_id=? AND mentioned_uid=? AND by_uid=? AND type='like'")
                    ->execute([$id, $owner, $myUid]);
            }
        }
        echo json_encode(['success' => true, 'liked' => $liked, 'likes' => count($likedBy)]);
        break;

    /* ============ 艾特通知（与我相关） ============ */
    case 'mentions':
        // 我收到的通知列表（@/赞/评论），含发送者、对应说说、评论内容
        $mStmt = $pdo->prepare("SELECT m.id, m.feed_id, m.by_uid, m.type, m.comment_id, m.is_read, m.created_at,
            u.username AS by_username, COALESCE(u.display_name, u.username) AS by_display, u.avatar AS by_avatar,
            f.content AS feed_content, f.enabled AS feed_enabled,
            c.content AS comment_content
            FROM space_mentions m
            JOIN users u ON u.user_id = m.by_uid
            LEFT JOIN space_feeds f ON f.id = m.feed_id
            LEFT JOIN space_comments c ON c.id = m.comment_id
            WHERE m.mentioned_uid = ?
            ORDER BY m.id DESC LIMIT 100");
        $mStmt->execute([$myUid]);
        $list = [];
        foreach ($mStmt->fetchAll() as $m) {
            $list[] = [
                'id' => (int)$m['id'],
                'feed_id' => (int)$m['feed_id'],
                'by_uid' => (int)$m['by_uid'],
                'type' => (string)($m['type'] ?? 'mention'),
                'by_username' => $m['by_username'],
                'by_display' => $m['by_display'],
                'by_avatar' => chatapp_avatar_url($m['by_avatar'] ?? '', (string)($m['by_username'] ?? ''), (int)$m['by_uid']),
                'feed_content' => (string)($m['feed_content'] ?? ''),
                'comment_content' => (string)($m['comment_content'] ?? ''),
                'feed_enabled' => (int)($m['feed_enabled'] ?? 0),
                'is_read' => (int)$m['is_read'],
                'time' => space_fmt_time((string)$m['created_at']),
            ];
        }
        echo json_encode(['success' => true, 'mentions' => $list]);
        break;

    case 'mention_count':
        // 未读 @ 数
        $cStmt = $pdo->prepare("SELECT COUNT(*) FROM space_mentions WHERE mentioned_uid=? AND is_read=0");
        $cStmt->execute([$myUid]);
        echo json_encode(['success' => true, 'count' => (int)$cStmt->fetchColumn()]);
        break;

    case 'mention_read':
        // 标记已读（id 为空则全部）
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE space_mentions SET is_read=1 WHERE id=? AND mentioned_uid=?")->execute([$id, $myUid]);
        } else {
            $pdo->prepare("UPDATE space_mentions SET is_read=1 WHERE mentioned_uid=? AND is_read=0")->execute([$myUid]);
        }
        echo json_encode(['success' => true]);
        break;

    /* ============ 动态中心聚合流（我的/好友/特别关心） ============ */
    case 'stream':
        // filter: mine=我的动态  friends=所有好友  special=特别关心好友  user=指定用户(TA的动态)
        $filter = trim($_GET['filter'] ?? 'mine');
        $targetUid = (int)($_GET['uid'] ?? 0);   // 仅 filter=user 时有效
        $authorIds = [];
        if ($filter === 'mine') {
            $authorIds = [$myUid];
        } elseif ($filter === 'user') {
            $authorIds = $targetUid > 0 ? [$targetUid] : [];
        } else {
            $fq = $pdo->query("SELECT user_to AS uid FROM contacts WHERE user_from=$myUid AND status='accepted' "
                . "UNION SELECT user_from AS uid FROM contacts WHERE user_to=$myUid AND status='accepted'");
            $authorIds = array_map('intval', array_filter($fq->fetchAll(PDO::FETCH_COLUMN)));
            if ($filter === 'special') {
                $spq = $pdo->query("SELECT user_to AS uid FROM contacts WHERE user_from=$myUid AND status='accepted' AND special=1");
                $authorIds = array_map('intval', array_filter($spq->fetchAll(PDO::FETCH_COLUMN)));
            }
        }
        // 「TA的动态」非本人时：仅好友可见(vis=1)的动态需好友关系校验
        $isFriendTarget = ($filter === 'user' && $targetUid > 0 && $targetUid !== $myUid)
            ? space_is_friend($pdo, $myUid, $targetUid) : true;
        $authorIds = array_values(array_unique($authorIds));
        // 我特别关心的好友集合
        $specialSet = [];
        $spq = $pdo->query("SELECT user_to AS uid FROM contacts WHERE user_from=$myUid AND status='accepted' AND special=1");
        foreach ($spq->fetchAll(PDO::FETCH_COLUMN) as $s) $specialSet[(int)$s] = 1;
        $feeds = [];
        if ($authorIds) {
            $in = implode(',', $authorIds);
            $aSt = $pdo->prepare("SELECT user_id, username, display_name, avatar FROM users WHERE user_id IN ($in)");
            $aSt->execute();
            $authors = [];
            foreach ($aSt->fetchAll() as $au) {
                $auid = (int)$au['user_id'];
                $authors[$auid] = [
                    'uid' => $auid,
                    'username' => $au['username'],
                    'display_name' => $au['display_name'] ?: $au['username'],
                    'avatar' => chatapp_avatar_url($au['avatar'] ?? '', $au['username'], $auid),
                ];
            }
            $fSt = $pdo->prepare("SELECT id, user_id, content, images, visibility, visible_to, likes, liked_by, created_at, edited_at "
                . "FROM space_feeds WHERE user_id IN ($in) AND enabled=1 ORDER BY id DESC LIMIT 300");
            $fSt->execute();
            foreach ($fSt->fetchAll() as $f) {
                $auid = (int)$f['user_id'];
                $vis = (int)$f['visibility'];
                // 可见度过滤（viewer = myUid）
                if ($vis === 4 && $auid !== $myUid) continue;                          // 仅自己
                if ($vis === 1 && $auid !== $myUid && !$isFriendTarget) continue;      // 仅好友可见
                if ($vis === 2) { $vt = space_parse_ids($f['visible_to']); if (!in_array($myUid, $vt, true)) continue; }  // 部分好友可见
                if ($vis === 3) { $vt = space_parse_ids($f['visible_to']); if (in_array($myUid, $vt, true)) continue; }  // 部分好友不可见
                if ($vis === 5 && $auid !== $myUid && !space_me_in_flag($pdo, $auid, $myUid, 'pinned')) continue;  // 已置顶的朋友
                if ($vis === 6 && $auid !== $myUid && !space_me_in_flag($pdo, $auid, $myUid, 'special')) continue;  // 特别关心朋友
                $likedBy = space_parse_ids($f['liked_by']);
                $feeds[] = [
                    'id' => (int)$f['id'],
                    'user_id' => $auid,
                    'author' => $authors[$auid]['display_name'],
                    'username' => $authors[$auid]['username'],
                    'avatar' => $authors[$auid]['avatar'],
                    'special' => isset($specialSet[$auid]) ? 1 : 0,
                    'content' => (string)$f['content'],
                    'images' => $f['images'] ? (json_decode($f['images'], true) ?: []) : [],
                    'likes' => (int)$f['likes'],
                    'liked' => in_array($myUid, $likedBy, true),
                    'vis' => $vis,
                    'time' => space_fmt_time($f['created_at']),
                    'edited' => !empty($f['edited_at']) ? space_fmt_time($f['edited_at']) : null,
                    'ts' => strtotime($f['created_at']),
                ];
            }
            // 时间排序，Latest 在最上面
            usort($feeds, function ($a, $b) { return $b['ts'] - $a['ts']; });
            foreach ($feeds as &$fd) unset($fd['ts']);
            unset($fd);
        }
        echo json_encode(['success' => true, 'feeds' => $feeds, 'filter' => $filter]);
        break;

    case 'special_state':
        // 某用户是否被我特别关心（空间页按钮/联系人右键菜单状态）
        $targetUid = (int)($_GET['uid'] ?? 0);
        if ($targetUid <= 0) {
            $un = trim((string)($_GET['username'] ?? ''));
            if ($un !== '') {
                $us = $pdo->prepare("SELECT user_id FROM users WHERE username=?");
                $us->execute([$un]);
                $targetUid = (int)$us->fetchColumn();
            }
        }
        if ($targetUid <= 0) { echo json_encode(['success' => false]); break; }
        $st = $pdo->prepare("SELECT special FROM contacts WHERE user_from=? AND user_to=? LIMIT 1");
        $st->execute([$myUid, $targetUid]);
        echo json_encode(['success' => true, 'special' => (int)$st->fetchColumn() ? 1 : 0]);
        break;

    /* ============ 图片上传（朋友圈/日志配图） ============ */
    case 'upload_image':
        // 支持 name=images 单文件，或 name=images[] 多文件
        $files = $_FILES['images'] ?? null;
        if (!$files || empty($files['name'])) { echo json_encode(['success' => false, 'error' => 'no_files']); break; }
        if (!is_array($files['name'])) {
            $files = [
                'name' => [$files['name']], 'tmp_name' => [$files['tmp_name']],
                'size' => [$files['size']], 'error' => [$files['error']],
            ];
        }
        $urls = [];
        $maxFiles = 9;
        foreach ($files['name'] as $i => $name) {
            if (count($urls) >= $maxFiles) break;
            if ((int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            if ((int)($files['size'][$i] ?? 0) > 10 * 1024 * 1024) continue;
            $raw = @file_get_contents($files['tmp_name'][$i]);
            if ($raw === false || $raw === '') continue;
            $info = @getimagesizefromstring($raw);
            if (!$info || !isset($info['mime'])) continue;
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$info['mime']] ?? '';
            if ($ext === '') continue;
            $dir = __DIR__ . '/../data/user/' . $myUid . '/space';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $nameFile = date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 10) . '.' . $ext;
            if (@file_put_contents($dir . '/' . $nameFile, $raw) === false) continue;
            @chmod($dir . '/' . $nameFile, 0644);
            $urls[] = '../../api/file.php?u=' . $myUid . '&f=space/' . $nameFile;
        }
        if (!$urls) { echo json_encode(['success' => false, 'error' => 'invalid']); break; }
        echo json_encode(['success' => true, 'urls' => $urls]);
        break;

    /* ============ 评论（朋友圈说说） ============ */
    case 'add_comment':
        $feedId = (int)($_POST['feed_id'] ?? 0);
        $content = trim((string)($_POST['content'] ?? ''));
        $parentId = (int)($_POST['parent_id'] ?? 0);
        if (!$feedId || $content === '') { echo json_encode(['success' => false, 'error' => 'empty']); break; }
        $content = mb_substr($content, 0, 500);
        $s = $pdo->prepare("SELECT user_id FROM space_feeds WHERE id=? AND enabled=1");
        $s->execute([$feedId]);
        $feedOwner = (int)$s->fetchColumn();
        if (!$feedOwner) { echo json_encode(['success' => false, 'error' => 'no_feed']); break; }
        if ($parentId) {
            $s2 = $pdo->prepare("SELECT id FROM space_comments WHERE id=? AND feed_id=? AND enabled=1");
            $s2->execute([$parentId, $feedId]);
            if (!$s2->fetchColumn()) $parentId = 0;
        }
        $ins = $pdo->prepare("INSERT INTO space_comments (feed_id, user_id, parent_id, content) VALUES (?,?,?,?)");
        $ins->execute([$feedId, $myUid, $parentId, $content]);
        $commentId = (int)$pdo->lastInsertId();
        // 评论通知：通知被评论说说的作者（非自己）
        if ($feedOwner !== $myUid) {
            ensure_space_mentions_table();
            $pdo->prepare("INSERT INTO space_mentions (feed_id, mentioned_uid, by_uid, type, comment_id) VALUES (?,?,?,'comment',?)")
                ->execute([$feedId, $feedOwner, $myUid, $commentId]);
        }
        echo json_encode(['success' => true, 'id' => $commentId, 'parent_id' => $parentId, 'card' => space_user_card($pdo, $myUid), 'time' => space_fmt_time(date('Y-m-d H:i:s'))]);
        break;

    case 'list_comments':
        $feedId = (int)($_GET['feed_id'] ?? $_POST['feed_id'] ?? 0);
        if (!$feedId) { echo json_encode(['success' => false, 'error' => 'id']); break; }
        $s = $pdo->prepare("SELECT user_id FROM space_feeds WHERE id=? AND enabled=1");
        $s->execute([$feedId]);
        $feedOwner = (int)$s->fetchColumn();
        if (!$feedOwner) { echo json_encode(['success' => false]); break; }
        $cs = $pdo->prepare("SELECT id, user_id, parent_id, content, created_at FROM space_comments WHERE feed_id=? AND enabled=1 ORDER BY id ASC LIMIT 500");
        $cs->execute([$feedId]);
        $comments = [];
        foreach ($cs->fetchAll() as $c) {
            $comments[] = [
                'id' => (int)$c['id'],
                'user_id' => (int)$c['user_id'],
                'parent_id' => (int)$c['parent_id'],
                'content' => (string)$c['content'],
                'time' => space_fmt_time($c['created_at']),
                'card' => space_user_card($pdo, (int)$c['user_id']),
                'mine' => (int)$c['user_id'] === $myUid,
            ];
        }
        echo json_encode(['success' => true, 'comments' => $comments, 'feed_owner' => $feedOwner, 'i_am_owner' => $feedOwner === $myUid]);
        break;

    case 'delete_comment':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'id']); break; }
        $s = $pdo->prepare("SELECT user_id, (SELECT user_id FROM space_feeds WHERE id = feed_id) AS owner FROM space_comments WHERE id=? AND enabled=1");
        $s->execute([$id]);
        $r = $s->fetch();
        if (!$r) { echo json_encode(['success' => false]); break; }
        if ((int)$r['user_id'] !== $myUid && (int)$r['owner'] !== $myUid) { echo json_encode(['success' => false, 'error' => 'denied']); break; }
        $pdo->prepare("UPDATE space_comments SET enabled=0 WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    /* ============ 留言板 ============ */
    case 'add_message':
        $toUid = (int)($_POST['to_uid'] ?? 0);
        $content = trim((string)($_POST['content'] ?? ''));
        if (!$toUid || $content === '') { echo json_encode(['success' => false, 'error' => 'empty']); break; }
        $content = mb_substr($content, 0, 500);
        $s = $pdo->prepare("SELECT user_id FROM users WHERE user_id=? AND enabled=1 AND placeholder=0");
        $s->execute([$toUid]);
        if (!(int)$s->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'no_user']); break; }
        $ins = $pdo->prepare("INSERT INTO space_messages (to_uid, user_id, content) VALUES (?,?,?)");
        $ins->execute([$toUid, $myUid, $content]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'card' => space_user_card($pdo, $myUid), 'time' => space_fmt_time(date('Y-m-d H:i:s'))]);
        break;

    case 'list_messages':
        $toUid = (int)($_GET['to_uid'] ?? $_POST['to_uid'] ?? 0);
        // 目标用户无效/不存在 → 留言板为空（不回落为本人）
        if ($toUid <= 0) { echo json_encode(['success' => true, 'messages' => [], 'i_am_owner' => false]); break; }
        $tu = $pdo->prepare("SELECT user_id FROM users WHERE user_id=?");
        $tu->execute([$toUid]);
        if (!(int)$tu->fetchColumn()) { echo json_encode(['success' => true, 'messages' => [], 'i_am_owner' => false]); break; }
        $s = $pdo->prepare("SELECT id, user_id, content, created_at FROM space_messages WHERE to_uid=? AND enabled=1 ORDER BY id ASC LIMIT 500");
        $s->execute([$toUid]);
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
        echo json_encode(['success' => true, 'messages' => $msgs, 'i_am_owner' => $toUid === $myUid]);
        break;

    case 'delete_message':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'id']); break; }
        $s = $pdo->prepare("SELECT user_id, to_uid FROM space_messages WHERE id=? AND enabled=1");
        $s->execute([$id]);
        $r = $s->fetch();
        if (!$r) { echo json_encode(['success' => false]); break; }
        if ((int)$r['user_id'] !== $myUid && (int)$r['to_uid'] !== $myUid) { echo json_encode(['success' => false, 'error' => 'denied']); break; }
        $pdo->prepare("UPDATE space_messages SET enabled=0 WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    /* ============ 日志 ============ */
    case 'add_blog':
        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $visibility = (int)($_POST['visibility'] ?? 0);
        if ($visibility < 0 || $visibility > 4) $visibility = 0;
        $visible_to = null;
        if ($visibility === 2 || $visibility === 3) {
            $ids = space_parse_ids($_POST['visible_to'] ?? '');
            if (!$ids) $visibility = 1;
            else $visible_to = json_encode($ids);
        }
        if ($title === '' || $content === '') { echo json_encode(['success' => false, 'error' => 'empty']); break; }
        $ins = $pdo->prepare("INSERT INTO space_blogs (user_id, title, content, visibility, visible_to) VALUES (?,?,?,?,?)");
        $ins->execute([$myUid, mb_substr($title, 0, 200), mb_substr($content, 0, 20000), $visibility, $visible_to]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;

    case 'list_blogs':
        $user = trim((string)($_GET['user'] ?? $_POST['user'] ?? ''));
        $targetUid = 0;
        if ($user !== '') {
            $s = $pdo->prepare("SELECT user_id FROM users WHERE username=?");
            $s->execute([$user]);
            $targetUid = (int)$s->fetchColumn();
        } else {
            $targetUid = (int)($_GET['uid'] ?? $_POST['uid'] ?? $myUid);
        }
        if (!$targetUid) $targetUid = $myUid;
        $isSelf = ($targetUid === $myUid);
        $isFriend = $isSelf || space_is_friend($pdo, $myUid, $targetUid);
        $s = $pdo->prepare("SELECT id, title, content, visibility, visible_to, views, created_at FROM space_blogs WHERE user_id=? AND enabled=1 ORDER BY id DESC LIMIT 200");
        $s->execute([$targetUid]);
        $blogs = [];
        foreach ($s->fetchAll() as $b) {
            $vis = (int)$b['visibility'];
            if (!$isSelf) {
                if ($vis === 4) continue;
                if ($vis === 1 && !$isFriend) continue;
                $vt = space_parse_ids($b['visible_to']);
                if ($vis === 2 && !in_array($myUid, $vt, true)) continue;
                if ($vis === 3 && in_array($myUid, $vt, true)) continue;
            }
            $blogs[] = [
                'id' => (int)$b['id'],
                'title' => (string)$b['title'],
                'summary' => mb_substr(strip_tags((string)$b['content']), 0, 120),
                'views' => (int)$b['views'],
                'time' => space_fmt_time($b['created_at']),
                'visibility' => $isSelf ? $vis : null,
            ];
        }
        echo json_encode(['success' => true, 'blogs' => $blogs]);
        break;

    case 'get_blog':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'id']); break; }
        $s = $pdo->prepare("SELECT user_id, title, content, visibility, visible_to, views, created_at FROM space_blogs WHERE id=? AND enabled=1");
        $s->execute([$id]);
        $b = $s->fetch();
        if (!$b) { echo json_encode(['success' => false]); break; }
        $owner = (int)$b['user_id'];
        $isSelf = ($owner === $myUid);
        $isFriend = $isSelf || space_is_friend($pdo, $myUid, $owner);
        $vis = (int)$b['visibility'];
        $canView = $isSelf;
        if (!$canView) {
            $vt = space_parse_ids($b['visible_to']);
            if ($vis === 0) $canView = true;
            elseif ($vis === 1) $canView = $isFriend;
            elseif ($vis === 2) $canView = in_array($myUid, $vt, true);
            elseif ($vis === 3) $canView = !in_array($myUid, $vt, true);
        }
        if (!$canView) { echo json_encode(['success' => false, 'error' => 'denied']); break; }
        $pdo->prepare("UPDATE space_blogs SET views=views+1 WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true, 'blog' => [
            'id' => (int)$b['id'],
            'title' => (string)$b['title'],
            'content' => (string)$b['content'],
            'views' => (int)$b['views'] + 1,
            'time' => space_fmt_time($b['created_at']),
            'owner' => $owner,
            'i_am_owner' => $isSelf,
            'visibility' => $isSelf ? $vis : null,
        ]]);
        break;

    case 'delete_blog':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'id']); break; }
        $s = $pdo->prepare("SELECT id FROM space_blogs WHERE id=? AND user_id=?");
        $s->execute([$id, $myUid]);
        if (!$s->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'denied']); break; }
        $pdo->prepare("DELETE FROM space_blogs WHERE id=? AND user_id=?")->execute([$id, $myUid]);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'unknown action']);
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
