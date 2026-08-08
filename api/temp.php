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
$myUid = (int)($pdo->query("SELECT user_id FROM users WHERE username='$me'")->fetchColumn() ?: 0);
if (!$myUid) {
    echo json_encode(['success' => false, 'error' => 'Invalid user']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * temp file dir: data/sc/
 */
function temp_dir(): string {
    $dir = __DIR__ . '/../data/sc';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

/**
 * Lazy cleanup: delete expired files (24h) + 2h-no-download files.
 */
function temp_cleanup(PDO $pdo): void {
    $now = gmdate('Y-m-d H:i:s');
    // 1) Hard expired (24h)
    $rows = $pdo->query("SELECT id, hash FROM temp_uploads WHERE expires_at < UTC_TIMESTAMP()")->fetchAll();
    foreach ($rows as $r) {
        $fp = temp_dir() . '/' . $r['hash'];
        if (is_file($fp)) @unlink($fp);
        $pdo->prepare("DELETE FROM temp_uploads WHERE id = ?")->execute([(int)$r['id']]);
    }
    // 2) Not downloaded for >2h since upload
    $rows2 = $pdo->query("SELECT id, hash FROM temp_uploads WHERE last_download_at IS NULL AND uploaded_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)")->fetchAll();
    foreach ($rows2 as $r) {
        $fp = temp_dir() . '/' . $r['hash'];
        if (is_file($fp)) @unlink($fp);
        $pdo->prepare("DELETE FROM temp_uploads WHERE id = ?")->execute([(int)$r['id']]);
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

switch ($action) {

    case 'upload':
        // File arrives as base64 data URL + original filename
        $b64 = trim($_POST['file'] ?? '');
        $filename = trim(mb_substr($_POST['filename'] ?? '', 0, 255));
        if (empty($b64)) {
            echo json_encode(['success' => false, 'error' => 'No file']);
            exit;
        }
        if (!preg_match('/^data:([^;]+);base64,(.+)$/s', $b64, $m)) {
            echo json_encode(['success' => false, 'error' => 'Invalid file data']);
            exit;
        }
        $raw = base64_decode($m[2]);
        if ($raw === false || $raw === '') {
            echo json_encode(['success' => false, 'error' => 'Empty file']);
            exit;
        }
        // 8GB product cap; if server config smaller, this will fail with 413 upstream anyway.
        $size = strlen($raw);
        if ($size > 8 * 1024 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'File too large']);
            exit;
        }

        // Lazy cleanup first
        temp_cleanup($pdo);

        // Check active upload limit: 3 per user
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM temp_uploads WHERE owner_uid = ? AND revoked = 0");
        $cntStmt->execute([$myUid]);
        if ((int)$cntStmt->fetchColumn() >= 3) {
            echo json_encode(['success' => false, 'error' => 'Up to 3 active flash files. Delete or wait for expiry.']);
            exit;
        }

        $hash = hash('sha256', $raw);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $filePath = temp_dir() . '/' . $hash;

        // Dedup: if file already exists, we still create a new record (fresh expiry)
        if (!is_file($filePath)) {
            file_put_contents($filePath, $raw);
        }

        $expiresAt = gmdate('Y-m-d H:i:s', time() + 24 * 3600);
        $pdo->prepare("INSERT INTO temp_uploads (hash, owner_uid, filename, size, ext, expires_at) VALUES (?,?,?,?,?,?)")
            ->execute([$hash, $myUid, $filename, $size, $ext, $expiresAt]);
        $tempId = (int)$pdo->lastInsertId();

        // EXP: t = round(log2(sizeMiB) - 0.5); t<4 → 4 else t
        $sizeMiB = $size / 1048576;
        $t = (int)round(log($sizeMiB) / log(2) - 0.5);
        if ($t < 4) $t = 4;
        exp_daily_incr($myUid, 'temp_upload', 10, $t, 'temp_upload');

        echo json_encode([
            'success' => true,
            'id' => $tempId,
            'hash' => $hash,
            'filename' => $filename,
            'size' => $size,
            'expires_at' => $expiresAt,
        ]);
        exit;

    case 'download':
        $id = (int)($_GET['id'] ?? 0);
        $rec = temp_get($pdo, $id);
        if (!$rec) { http_response_code(404); exit; }

        // Lazy cleanup + expiry check
        temp_cleanup($pdo);
        $rec = temp_get($pdo, $id);
        if (!$rec) { http_response_code(404); exit; }

        if ((int)$rec['revoked']) {
            http_response_code(403);
            echo json_encode(['error' => 'File revoked']);
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
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $rec['filename']) . '"');

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
        // Lazy expiry check
        if (strtotime($rec['expires_at'] . ' UTC') < time()) {
            $fp = temp_dir() . '/' . $rec['hash'];
            if (is_file($fp)) @unlink($fp);
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
        ];
        // Owner sees download progress & speed info; receiver sees only download state
        if ($isOwner || $isAdm) {
            $resp['downloaded_bytes'] = (int)$rec['downloaded_bytes'];
            $resp['size'] = (int)$rec['size'];
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