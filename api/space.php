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
$pdo = db();
$me = chatapp_get_user();
$myUid = (int)($me['user_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'post':
        $content = trim((string)($_POST['content'] ?? ''));
        $visibility = (int)($_POST['visibility'] ?? 0);
        if ($visibility < 0 || $visibility > 4) $visibility = 0;
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
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
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
        $stmt = $pdo->prepare("SELECT id, content, images, visibility, visible_to, likes, liked_by, created_at FROM space_feeds WHERE user_id=? AND enabled=1 ORDER BY id DESC LIMIT 200");
        $stmt->execute([$targetUid]);
        $feeds = [];
        foreach ($stmt->fetchAll() as $f) {
            $vis = (int)$f['visibility'];
            if (!$isSelf) {
                if ($vis === 4) continue;                                          // 仅自己
                if ($vis === 1 && !$isFriend) continue;                            // 好友
                if ($vis === 2) { $vt = space_parse_ids($f['visible_to']); if (!in_array($myUid, $vt, true)) continue; } // 部分好友可见
                if ($vis === 3) { $vt = space_parse_ids($f['visible_to']); if (in_array($myUid, $vt, true)) continue; } // 部分好友不可见
            }
            $likedBy = space_parse_ids($f['liked_by']);
            $feeds[] = [
                'id' => (int)$f['id'],
                'content' => (string)$f['content'],
                'images' => $f['images'] ? (json_decode($f['images'], true) ?: []) : [],
                'likes' => (int)$f['likes'],
                'liked' => in_array($myUid, $likedBy, true),
                'time' => space_fmt_time($f['created_at']),
                'visibility' => $isSelf ? $vis : null,
            ];
        }
        echo json_encode(['success' => true, 'feeds' => $feeds]);
        break;

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'id']); break; }
        $stmt = $pdo->prepare("DELETE FROM space_feeds WHERE id=? AND user_id=?");
        $stmt->execute([$id, $myUid]);
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
        echo json_encode(['success' => true, 'liked' => $liked, 'likes' => count($likedBy)]);
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
        if (!(int)$s->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'no_feed']); break; }
        if ($parentId) {
            $s2 = $pdo->prepare("SELECT id FROM space_comments WHERE id=? AND feed_id=? AND enabled=1");
            $s2->execute([$parentId, $feedId]);
            if (!$s2->fetchColumn()) $parentId = 0;
        }
        $ins = $pdo->prepare("INSERT INTO space_comments (feed_id, user_id, parent_id, content) VALUES (?,?,?,?)");
        $ins->execute([$feedId, $myUid, $parentId, $content]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'parent_id' => $parentId, 'card' => space_user_card($pdo, $myUid), 'time' => space_fmt_time(date('Y-m-d H:i:s'))]);
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
        if (!$toUid) $toUid = $myUid;
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
