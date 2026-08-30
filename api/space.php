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

    default:
        echo json_encode(['success' => false, 'error' => 'unknown action']);
}
