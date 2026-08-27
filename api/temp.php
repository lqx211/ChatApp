<?php
/**
 * ChatApp - Flash Transfer (Temp Upload) API
 * upload / download (streaming + progress) / status / revoke / my
 */
require_once __DIR__ . '/config.php';

chatapp_session_start();
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}
header('Content-Type: application/json');

$me = $_SESSION['username'];
$pdo = db();
$myUid = 0;
$myUidStmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
$myUidStmt->execute([$me]);
$myUid = (int)($myUidStmt->fetchColumn() ?: 0);
if (!$myUid) {
    echo json_encode(['success' => false, 'error' => 'Invalid user']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// upload/revoke are state-changing → POST only.
chatapp_read_actions(['download', 'status', 'my'], $action);
function temp_dir(): string {
    $dir = __DIR__ . '/../data/sc';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

/**
 * Lazy cleanup: delete expired files (24h) + 2h-no-download files.
 * 同内容文件可被多条 temp_upload 记录共享（hash 非唯一）——删除记录后，
 * 只有当不再有任何记录引用该 hash 时才删磁盘文件，避免共享文件被误删。
 */
function temp_delete_row(PDO $pdo, int $id, string $hash): void {
    $pdo->prepare("DELETE FROM temp_uploads WHERE id = ?")->execute([$id]);
    if ($hash === '') return; // 占位记录可能没有 hash，无文件可删
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM temp_uploads WHERE hash = ?");
    $cnt->execute([$hash]);
    if ((int)$cnt->fetchColumn() === 0) {
        $fp = temp_dir() . '/' . $hash;
        if (is_file($fp)) @unlink($fp);
    }
}

function temp_cleanup(PDO $pdo): void {
    // 1) Hard expired (24h)
    $rows = $pdo->query("SELECT id, hash FROM temp_uploads WHERE expires_at < UTC_TIMESTAMP()")->fetchAll();
    foreach ($rows as $r) {
        temp_delete_row($pdo, (int)$r['id'], (string)$r['hash']);
    }
    // 2) Not downloaded for >2h since upload
    $rows2 = $pdo->query("SELECT id, hash FROM temp_uploads WHERE last_download_at IS NULL AND uploaded_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)")->fetchAll();
    foreach ($rows2 as $r) {
        temp_delete_row($pdo, (int)$r['id'], (string)$r['hash']);
    }
    // 3) 上传中断的占位记录（status=0 且超过 30 分钟没完成）
    $rows3 = $pdo->query("SELECT id, hash FROM temp_uploads WHERE status = 0 AND uploaded_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->fetchAll();
    foreach ($rows3 as $r) {
        temp_delete_row($pdo, (int)$r['id'], (string)$r['hash']);
    }
}

/**
 * Load temp_upload record by id.
 */
function temp_get(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM temp_uploads WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Access control for a flash-transfer file: owner, the message recipient, group
 * members, or an admin. Prevents enumerating/downloading other users' files.
 */
function temp_can_access(PDO $pdo, array $rec, int $myUid): bool {
    if ((int)$rec['owner_uid'] === $myUid) return true;
    $mid = (int)($rec['message_id'] ?? 0);
    if ($mid > 0) {
        $ms = $pdo->prepare("SELECT recipient_id, group_id FROM messages WHERE id = ?");
        $ms->execute([$mid]);
        $m = $ms->fetch();
        if ($m && (int)$m['recipient_id'] === $myUid) return true;
        if ($m && (int)$m['group_id'] > 0) {
            $gm = $pdo->prepare("SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?");
            $gm->execute([(int)$m['group_id'], $myUid]);
            if ((int)$gm->fetchColumn() > 0) return true;
        }
    }
    $role = chatapp_get_role($myUid);
    return ($role === 'root' || $role === 'admin');
}

switch ($action) {

    case 'create':
        // 占位：先登记一条"上传中"的记录（hash 暂空），让消息能立刻发出去、双方都能看到卡片
        $filename = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', trim(mb_substr($_POST['filename'] ?? '', 0, 255)));
        if ($filename === '') $filename = 'file.bin';
        $size = (int)($_POST['size'] ?? 0);
        $MAX_SIZE = 8 * 1024 * 1024 * 1024;
        if ($size <= 0) { echo json_encode(['success' => false, 'error' => 'Empty file']); exit; }
        if ($size > $MAX_SIZE) { echo json_encode(['success' => false, 'error' => 'File too large', 'max_size' => $MAX_SIZE]); exit; }

        // Lazy cleanup first
        temp_cleanup($pdo);

        // Check active upload limit: 3 per user
        $MAX_ACTIVE = 3;
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM temp_uploads WHERE owner_uid = ? AND revoked = 0");
        $cntStmt->execute([$myUid]);
        $activeCnt = (int)$cntStmt->fetchColumn();
        if ($activeCnt >= $MAX_ACTIVE) {
            echo json_encode(['success' => false, 'error' => 'Up to ' . $MAX_ACTIVE . ' active flash files. Delete or wait for expiry.', 'active_count' => $activeCnt, 'max_active' => $MAX_ACTIVE]);
            exit;
        }

        $expiresAt = gmdate('Y-m-d H:i:s', time() + 24 * 3600);
        $pdo->prepare("INSERT INTO temp_uploads (owner_uid, filename, size, ext, status, uploaded_bytes, hash, expires_at) VALUES (?,?,?,?,0,0,NULL,?)")
            ->execute([$myUid, $filename, $size, strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $expiresAt]);
        $tempId = (int)$pdo->lastInsertId();
        echo json_encode([
            'success' => true,
            'id' => $tempId,
            'filename' => $filename,
            'size' => $size,
            'status' => 'uploading',
            'max_size' => $MAX_SIZE,
            'active_count' => $activeCnt + 1,
        ]);
        exit;

    case 'upload':
        // 完成"占位"记录：写入文件字节 → hash + status=1(ready)
        $id = (int)($_POST['id'] ?? 0);
        $rec = $id ? temp_get($pdo, $id) : null;
        if (!$rec) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }
        if ((int)$rec['owner_uid'] !== $myUid && $myUid !== 10000) {
            echo json_encode(['success' => false, 'error' => 'No permission']);
            exit;
        }
        $MAX_SIZE = 8 * 1024 * 1024 * 1024;
        $hash = ''; $size = 0; $filePath = '';
        $multipart = isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name']);

        if ($multipart) {
            // multipart：原始文件直传（无 base64 33% 膨胀、内存友好）
            $size = (int)$_FILES['file']['size'];
            if ($size <= 0) { echo json_encode(['success' => false, 'error' => 'Empty file']); exit; }
            if ($size > $MAX_SIZE) { echo json_encode(['success' => false, 'error' => 'File too large', 'max_size' => $MAX_SIZE]); exit; }
            $hash = hash_file('sha256', $_FILES['file']['tmp_name']);
            $filePath = temp_dir() . '/' . $hash;
            if (!is_file($filePath)) {
                move_uploaded_file($_FILES['file']['tmp_name'], $filePath);
            }
        } else {
            // 兼容旧 base64 提交（data URL）
            $b64 = trim($_POST['file'] ?? '');
            if (!preg_match('/^data:([^;]+);base64,(.+)$/s', $b64, $m)) { echo json_encode(['success' => false, 'error' => 'Invalid file data']); exit; }
            $raw = base64_decode($m[2]);
            if ($raw === false || $raw === '') { echo json_encode(['success' => false, 'error' => 'Empty file']); exit; }
            $size = strlen($raw);
            if ($size > $MAX_SIZE) { echo json_encode(['success' => false, 'error' => 'File too large', 'max_size' => $MAX_SIZE]); exit; }
            $hash = hash('sha256', $raw);
            $filePath = temp_dir() . '/' . $hash;
            if (!is_file($filePath)) { file_put_contents($filePath, $raw); }
        }

        $pdo->prepare("UPDATE temp_uploads SET hash = ?, size = ?, uploaded_bytes = ?, status = 1 WHERE id = ?")
            ->execute([$hash, $size, $size, $id]);

        // EXP（按最终大小）
        $sizeMiB = $size / 1048576;
        $t = (int)round(log($sizeMiB) / log(2) - 0.5);
        if ($t < 4) $t = 4;
        exp_daily_incr($myUid, 'temp_upload', 10, $t, 'temp_upload');

        echo json_encode(['success' => true, 'id' => $id, 'hash' => $hash, 'size' => $size, 'status' => 'ready']);
        exit;

    case 'download':
        $id = (int)($_GET['id'] ?? 0);
        $rec = temp_get($pdo, $id);
        if (!$rec) { http_response_code(404); exit; }
        // Authorization: owner, message recipient/group member, or admin.
        if (!temp_can_access($pdo, $rec, $myUid)) { http_response_code(403); exit; }

        // Lazy cleanup + expiry check
        temp_cleanup($pdo);
        $rec = temp_get($pdo, $id);
        if (!$rec) { http_response_code(404); exit; }

        if ((int)$rec['revoked']) {
            http_response_code(403);
            echo json_encode(['error' => 'File revoked']);
            exit;
        }

        // 还没传完（占位中）→ 不能下载
        if ((int)$rec['status'] !== 1 || empty($rec['hash'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Still uploading']);
            exit;
        }

        $filePath = temp_dir() . '/' . $rec['hash'];
        if (!is_file($filePath)) {
            http_response_code(404);
            exit;
        }

        // Stream with progress tracking. Reset progress on new download start.
        $pdo->prepare("UPDATE temp_uploads SET download_started_at = IFNULL(download_started_at, NOW()), last_download_at = NOW(), download_complete = 0, downloaded_bytes = 0 WHERE id = ?")->execute([$id]);

        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $rec['size']);
        // Strip control chars from the download filename (HTTP header injection).
        $dlName = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', (string)$rec['filename']);
        header('Content-Disposition: attachment; filename="' . $dlName . '"');

        $fh = fopen($filePath, 'rb');
        if (!$fh) { exit; }
        $block = 128 * 1024;
        $downloaded = 0;
        while (!feof($fh) && !connection_aborted()) {
            $chunk = fread($fh, $block);
            if ($chunk === false) break;
            echo $chunk;
            flush();
            $downloaded += strlen($chunk);
            // Update progress every 128KB
            $pdo->prepare("UPDATE temp_uploads SET downloaded_bytes = ? WHERE id = ?")
                ->execute([$downloaded, $id]);
        }
        fclose($fh);

        // Mark complete when whole file sent
        if ($downloaded >= $rec['size'] && !connection_aborted()) {
            $pdo->prepare("UPDATE temp_uploads SET download_complete = 1, downloaded_bytes = ? WHERE id = ?")
                ->execute([$rec['size'], $id]);
        }
        exit;

    case 'status':
        $id = (int)($_GET['id'] ?? 0);
        $rec = temp_get($pdo, $id);
        if (!$rec) {
            echo json_encode(['success' => false, 'status' => 'expired']);
            exit;
        }
        // Authorization: owner, message recipient/group member, or admin.
        if (!temp_can_access($pdo, $rec, $myUid)) {
            echo json_encode(['success' => false, 'status' => 'forbidden']);
            exit;
        }
        // Lazy expiry check
        if (strtotime($rec['expires_at'] . ' UTC') < time()) {
            if (!empty($rec['hash'])) {
                $fp = temp_dir() . '/' . $rec['hash'];
                if (is_file($fp)) @unlink($fp);
            }
            $pdo->prepare("DELETE FROM temp_uploads WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => false, 'status' => 'expired']);
            exit;
        }

        $isOwner = ((int)$rec['owner_uid'] === $myUid);
        $isAdm = ($myUid === 10000 || chatapp_get_role($myUid) === 'admin');

        $status = 'not_started';
        if ((int)$rec['revoked']) $status = 'revoked';
        elseif ((int)$rec['download_complete']) $status = 'complete';
        elseif (!empty($rec['download_started_at'])) $status = 'in_progress';

        $resp = [
            'success' => true,
            'status' => $status,
            'expires_at' => $rec['expires_at'],
            'revoked' => (int)$rec['revoked'],
            // 上传状态：占位中(0) / 已就绪(1)
            'upload_status' => ((int)$rec['status'] === 1) ? 'ready' : 'uploading',
            'ready' => ((int)$rec['status'] === 1) ? 1 : 0,
            'uploaded_bytes' => (int)$rec['uploaded_bytes'],
            'size' => (int)$rec['size'],
        ];
        // Owner sees download progress & speed info; receiver sees only download state
        if ($isOwner || $isAdm) {
            $resp['downloaded_bytes'] = (int)$rec['downloaded_bytes'];
            $resp['download_complete'] = (int)$rec['download_complete'];
            if (!empty($rec['last_download_at'])) $resp['last_download_at'] = $rec['last_download_at'];
        }
        echo json_encode($resp);
        exit;

    case 'revoke':
        $id = (int)($_POST['id'] ?? 0);
        $rec = temp_get($pdo, $id);
        if (!$rec) {
            echo json_encode(['success' => false, 'error' => 'Not found']);
            exit;
        }
        $isOwner = ((int)$rec['owner_uid'] === $myUid);
        $isAdm = ($myUid === 10000 || chatapp_get_role($myUid) === 'admin');
        if (!$isOwner && !$isAdm) {
            echo json_encode(['success' => false, 'error' => 'No permission']);
            exit;
        }
        if ((int)$rec['revoked']) {
            $pdo->prepare("UPDATE temp_uploads SET revoked = 0 WHERE id = ?")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE temp_uploads SET revoked = 1 WHERE id = ?")->execute([$id]);
        }
        echo json_encode(['success' => true, 'revoked' => !((int)$rec['revoked'])]);
        exit;

    case 'my':
        temp_cleanup($pdo);
        $stmt = $pdo->prepare("SELECT id, filename, size, hash, expires_at, revoked, download_complete, downloaded_bytes, last_download_at FROM temp_uploads WHERE owner_uid = ? ORDER BY id DESC");
        $stmt->execute([$myUid]);
        echo json_encode(['success' => true, 'files' => $stmt->fetchAll()]);
        exit;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
}