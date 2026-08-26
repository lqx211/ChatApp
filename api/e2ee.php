<?php
/**
 * ChatApp - E2EE Key & Status API
 *
 * 端到端加密（X3DH + Double Ratchet，Signal 级）的密钥注册/分发与开关状态。
 * 服务器只存公钥包与开关状态，绝不接触任何私钥或明文。
 *
 * 公钥格式：客户端以 base64（nacl.util.encodeBase64）传输，服务器解码成原始字节
 * 存 BLOB，返回时再编码成 base64。
 */

require_once __DIR__ . '/config.php';

chatapp_session_start();
if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
    exit;
}
header('Content-Type: application/json');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// 读操作允许 GET；其余（注册密钥/发布一次性密钥/开关状态）仅 POST。
chatapp_read_actions(['get_bundle', 'get_status', 'partner_status', 'my_keys'], $action);

/** 确保密钥表存在（幂等）。 */
function e2ee_ensure_tables(): void {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        key_id VARCHAR(64) NOT NULL DEFAULT '',
        ik_pub BLOB NOT NULL,
        sig_pub BLOB NOT NULL,
        spk_pub BLOB NOT NULL,
        spk_sig BLOB NOT NULL,
        spk_key_id VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_one_time_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        opk_id VARCHAR(64) NOT NULL,
        opk_pub BLOB NOT NULL,
        used TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_used (user_id, used)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 每对话每用户的 E2EE 开关（user_id 对 peer_uid 的对话）
    $pdo->exec("CREATE TABLE IF NOT EXISTS dm_e2ee (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        peer_uid INT NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pair (user_id, peer_uid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // e2ee_enabled 开关列（默认关）。不用 db_add_column_if_missing（其
    // SHOW COLUMNS + rowCount 在原生 prepare 下不可靠），改用 information_schema 计数。
    $colSt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'e2ee_enabled'");
    if ((int)$colSt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN e2ee_enabled TINYINT(1) NOT NULL DEFAULT 0");
    }
}

function e2ee_uid(string $username): int {
    $st = db()->prepare('SELECT user_id FROM users WHERE username = ?');
    $st->execute([$username]);
    return (int)($st->fetchColumn() ?: 0);
}

/** E2EE 开关是“套用于对话”的共享状态：按规范化 pair（小 uid 在前）存一行，
 *  双方读写的是同一个值。一方开 → 该对话整体开；任何一方可关回去。 */
function e2ee_pair(int $a, int $b): array {
    return $a < $b ? [$a, $b] : [$b, $a];
}

function e2ee_b64len(string $s, int $bytes): bool {
    if ($s === '') return false;
    $raw = base64_decode($s, true);
    return $raw !== false && strlen($raw) === $bytes;
}

function e2ee_b64decode(string $s): string {
    return base64_decode($s, true);
}

switch ($action) {

    // ---- 注册/更新完整密钥包（身份钥 + 签名钥 + 签名预密钥 + 一次性预密钥池）----
    case 'register_keys':
        $keyId     = trim(mb_substr($_POST['key_id'] ?? '', 0, 64));
        $ikPub     = $_POST['ik_pub'] ?? '';
        $sigPub    = $_POST['sig_pub'] ?? '';
        $spkPub    = $_POST['spk_pub'] ?? '';
        $spkSig    = $_POST['spk_sig'] ?? '';
        $spkKeyId  = trim(mb_substr($_POST['spk_key_id'] ?? '', 0, 64));
        $opks      = $_POST['opks'] ?? [];

        if ($keyId === '' || $spkKeyId === ''
            || !e2ee_b64len($ikPub, 32) || !e2ee_b64len($sigPub, 32)
            || !e2ee_b64len($spkPub, 32) || !e2ee_b64len($spkSig, 64)) {
            echo json_encode(['success' => false, 'error' => 'Invalid key bundle.']); exit;
        }
        if (!is_array($opks) || count($opks) > 200) {
            echo json_encode(['success' => false, 'error' => 'Invalid prekeys.']); exit;
        }

        e2ee_ensure_tables();
        $pdo = db();
        $uid = e2ee_uid($_SESSION['username']);
        if (!$uid) { echo json_encode(['success' => false]); exit; }

        // upsert 主密钥包
        $pdo->prepare("INSERT INTO user_keys (user_id, key_id, ik_pub, sig_pub, spk_pub, spk_sig, spk_key_id)
                       VALUES (?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE
                         key_id = VALUES(key_id), ik_pub = VALUES(ik_pub), sig_pub = VALUES(sig_pub),
                         spk_pub = VALUES(spk_pub), spk_sig = VALUES(spk_sig), spk_key_id = VALUES(spk_key_id)")
            ->execute([$uid, $keyId, e2ee_b64decode($ikPub), e2ee_b64decode($sigPub),
                       e2ee_b64decode($spkPub), e2ee_b64decode($spkSig), $spkKeyId]);

        // 替换一次性预密钥池
        $pdo->prepare('DELETE FROM user_one_time_keys WHERE user_id = ? AND used = 0')->execute([$uid]);
        $ins = $pdo->prepare('INSERT INTO user_one_time_keys (user_id, opk_id, opk_pub) VALUES (?,?,?)');
        $ok = 0;
        foreach ($opks as $opk) {
            $opkId = trim((string)($opk['id'] ?? ''));
            $opkPub = (string)($opk['pub'] ?? '');
            if ($opkId === '' || !e2ee_b64len($opkPub, 32)) continue;
            $ins->execute([$uid, mb_substr($opkId, 0, 64), e2ee_b64decode($opkPub)]);
            $ok++;
        }
        echo json_encode(['success' => true, 'registered' => $ok]);
        break;

    // ---- 补充一次性预密钥（池快用完时调用）----
    case 'publish_prekeys':
        $opks = $_POST['opks'] ?? [];
        if (!is_array($opks) || count($opks) > 200) {
            echo json_encode(['success' => false, 'error' => 'Invalid prekeys.']); exit;
        }
        e2ee_ensure_tables();
        $pdo = db();
        $uid = e2ee_uid($_SESSION['username']);
        if (!$uid) { echo json_encode(['success' => false]); exit; }
        $ins = $pdo->prepare('INSERT INTO user_one_time_keys (user_id, opk_id, opk_pub) VALUES (?,?,?)');
        $ok = 0;
        foreach ($opks as $opk) {
            $opkId = trim((string)($opk['id'] ?? ''));
            $opkPub = (string)($opk['pub'] ?? '');
            if ($opkId === '' || !e2ee_b64len($opkPub, 32)) continue;
            $ins->execute([$uid, mb_substr($opkId, 0, 64), e2ee_b64decode($opkPub)]);
            $ok++;
        }
        echo json_encode(['success' => true, 'published' => $ok]);
        break;

    // ---- 获取对方密钥包（X3DH 用；消耗一个一次性预密钥）----
    case 'get_bundle':
        $partner = trim($_GET['username'] ?? '');
        if ($partner === '') { echo json_encode(['success' => false]); exit; }
        e2ee_ensure_tables();
        $pdo = db();
        $pid = e2ee_uid($partner);
        if (!$pid) { echo json_encode(['success' => false, 'error' => 'User not found.']); exit; }
        $st = $pdo->prepare('SELECT key_id, ik_pub, sig_pub, spk_pub, spk_sig, spk_key_id FROM user_keys WHERE user_id = ?');
        $st->execute([$pid]);
        $row = $st->fetch();
        if (!$row) { echo json_encode(['success' => false, 'error' => 'User has no E2EE keys.']); exit; }
        // 消耗一个未用 OPK（优先最旧的）
        $opkSt = $pdo->prepare("SELECT id, opk_id, opk_pub FROM user_one_time_keys WHERE user_id = ? AND used = 0 ORDER BY id ASC LIMIT 1");
        $opkSt->execute([$pid]);
        $opk = $opkSt->fetch();
        $opkPub = null; $opkId = null;
        if ($opk) {
            $opkPub = base64_encode($opk['opk_pub']);
            $opkId  = $opk['opk_id'];
            $pdo->prepare('UPDATE user_one_time_keys SET used = 1 WHERE id = ?')->execute([$opk['id']]);
        }
        echo json_encode([
            'success' => true,
            'key_id' => $row['key_id'],
            'ik_pub' => base64_encode($row['ik_pub']),
            'sig_pub' => base64_encode($row['sig_pub']),
            'spk_pub' => base64_encode($row['spk_pub']),
            'spk_sig' => base64_encode($row['spk_sig']),
            'spk_key_id' => $row['spk_key_id'],
            'opk_pub' => $opkPub,
            'opk_id' => $opkId,
        ]);
        break;

    // ---- 我的 E2EE 状态 ----
    case 'my_keys':
        e2ee_ensure_tables();
        $pdo = db();
        $uid = e2ee_uid($_SESSION['username']);
        $st = $pdo->prepare('SELECT 1 FROM user_keys WHERE user_id = ?');
        $st->execute([$uid]);
        $hasKeys = (bool)$st->fetchColumn();
        $kid = null;
        if ($hasKeys) {
            $kSt = $pdo->prepare('SELECT key_id FROM user_keys WHERE user_id = ?');
            $kSt->execute([$uid]);
            $kid = $kSt->fetchColumn();
        }
        $cntSt = $pdo->prepare('SELECT COUNT(*) FROM user_one_time_keys WHERE user_id = ? AND used = 0');
        $cntSt->execute([$uid]);
        echo json_encode(['success' => true, 'has_keys' => $hasKeys, 'key_id' => $kid, 'opk_left' => (int)$cntSt->fetchColumn()]);
        break;

    // ---- 某对话的 E2EE 状态（共享：username=对方）----
    case 'get_status':
        $peer = trim($_GET['username'] ?? '');
        if ($peer === '') { echo json_encode(['success' => false]); exit; }
        e2ee_ensure_tables();
        $pdo = db();
        $myUid = e2ee_uid($_SESSION['username']);
        $peerUid = e2ee_uid($peer);
        if (!$peerUid) { echo json_encode(['success' => false]); exit; }
        list($a, $b) = e2ee_pair($myUid, $peerUid);
        $st = $pdo->prepare('SELECT enabled FROM dm_e2ee WHERE user_id = ? AND peer_uid = ?');
        $st->execute([$a, $b]);
        echo json_encode(['success' => true, 'enabled' => (int)($st->fetchColumn() ?: 0) === 1]);
        break;

    // ---- 对方视角：同一对话的共享状态（与 get_status 相同值）----
    case 'partner_status':
        $partner = trim($_GET['username'] ?? '');
        if ($partner === '') { echo json_encode(['success' => false]); exit; }
        e2ee_ensure_tables();
        $pdo = db();
        $myUid = e2ee_uid($_SESSION['username']);
        $peerUid = e2ee_uid($partner);
        if (!$peerUid) { echo json_encode(['success' => false]); exit; }
        list($a, $b) = e2ee_pair($myUid, $peerUid);
        $st = $pdo->prepare('SELECT enabled FROM dm_e2ee WHERE user_id = ? AND peer_uid = ?');
        $st->execute([$a, $b]);
        echo json_encode(['success' => true, 'enabled' => (int)($st->fetchColumn() ?: 0) === 1]);
        break;

    // ---- 开关某个对话的 E2EE（共享：任一方开/关即整体开/关；默认关）----
    case 'set_status':
        $peer = trim($_POST['username'] ?? '');
        $on = ($_POST['on'] ?? '') === '1';
        if ($peer === '' || $peer === $_SESSION['username']) { echo json_encode(['success' => false]); exit; }
        e2ee_ensure_tables();
        $pdo = db();
        $myUid = e2ee_uid($_SESSION['username']);
        $peerUid = e2ee_uid($peer);
        if (!$peerUid) { echo json_encode(['success' => false, 'error' => 'User not found.']); exit; }
        list($a, $b) = e2ee_pair($myUid, $peerUid);
        $pdo->prepare("INSERT INTO dm_e2ee (user_id, peer_uid, enabled) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)")->execute([$a, $b, $on ? 1 : 0]);
        echo json_encode(['success' => true, 'enabled' => $on]);
        break;

    // ---- 通知对方本对话的 E2EE 状态变化（系统提示消息，双方会话都会显示）----
    case 'notify_status':
        $peer = trim($_POST['username'] ?? '');
        $on = ($_POST['on'] ?? '') === '1';
        if ($peer === '' || $peer === $_SESSION['username']) { echo json_encode(['success' => false]); exit; }
        e2ee_ensure_tables();
        $pdo = db();
        $myUid = e2ee_uid($_SESSION['username']);
        $peerUid = e2ee_uid($peer);
        if (!$peerUid) { echo json_encode(['success' => false]); exit; }
        $pdo->prepare("INSERT INTO messages (sender_id, recipient_id, message, msg_type, time, datetime)
                       VALUES (?,?,?,'sys_e2ee',?,NOW())")->execute([$myUid, $peerUid, $on ? 'on' : 'off', time()]);
        echo json_encode(['success' => true, 'message_id' => (int)$pdo->lastInsertId()]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'The script may be seriously broken or corrupted as you see this message.']);
}
