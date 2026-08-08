<?php
/**
 * ChatApp — 共享消息业务逻辑（HTTP api/chat.php 与 WSS wss/wss_server.php 共用）
 *
 * 设计原则：
 *   - 零外部副作用依赖：不 require api/config.php / maintenance.php（CLI 与 Web 双环境安全）
 *   - PDO 由调用方注入（Web 端 db()、WSS 端 ws_db()）
 *   - 在 Web 环境若 api/config.php 已加载，复用其 level_limits/user_level/exp_* /t 函数；
 *     在 CLI 环境（WSS 服务器）自动使用内置 fallback，保证无需 Apache session 也能运行
 *   - send 支持附件；调用方可传 allow_attachment=false 强制拒绝附件（WSS 通道用），
 *     此时若请求包含附件/闪传，返回 FORCE_HTTP 错误，由前端自动降级回原始 HTTP
 */

if (!function_exists('chat_actions_lvconfig')) {
    /**
     * 级别系统配置（纯 return，无明显副作用，CLI 安全）
     */
    function chat_actions_lvconfig(): array {
        static $cfg = null;
        if ($cfg === null) {
            $__f = __DIR__ . '/../config/lvconfig.php';
            $cfg = is_file($__f) ? (include $__f) : [];
        }
        return is_array($cfg) ? $cfg : [];
    }
}

if (!function_exists('chat_actions_t')) {
    /**
     * 翻译：Web 环境复用全局 t()；CLI 环境回退为 key 本身
     */
    function chat_actions_t(string $key, ...$args): string {
        if (function_exists('t')) {
            return t($key, ...$args);
        }
        return $key;
    }
}

if (!function_exists('chat_actions_user_level')) {
    function chat_actions_user_level(PDO $pdo, int $uid): int {
        if (function_exists('user_level')) {
            return user_level($pdo, $uid);
        }
        $stmt = $pdo->prepare("SELECT level FROM users WHERE user_id = ?");
        $stmt->execute([$uid]);
        return max(1, min(100, (int)($stmt->fetchColumn() ?: 1)));
    }
}

if (!function_exists('chat_actions_level_limits')) {
    /**
     * Level-gated limits（与 api/config.php level_limits() 一致的自包含实现）
     */
    function chat_actions_level_limits(int $lv): array {
        if (function_exists('level_limits')) {
            return level_limits($lv);
        }
        $lookup = function (array $table, int $lvl): int {
            $v = 0;
            foreach ($table as $k => $val) {
                if ($lvl >= $k) $v = $val; else break;
            }
            return $v;
        };
        return [
            'max_attach_kb' => $lookup([1=>8192,5=>16384,10=>32768,15=>40960,20=>61440,25=>65536,30=>81920,35=>102400,40=>128000,45=>131072,50=>196608,55=>204800,60=>262144,70=>524288,80=>1048576,90=>1536000,100=>2097152], $lv),
            'max_groups' => $lookup([1=>5,10=>10,15=>20,20=>25,25=>30,30=>35,35=>48,40=>60,50=>80,60=>100,65=>110,70=>120,75=>130,80=>140,85=>150,90=>160,95=>180,100=>250], $lv),
            'max_contacts' => $lookup([1=>100,10=>120,20=>150,30=>200,40=>250,50=>400,60=>800,70=>2000,80=>3000,90=>5000,100=>10000], $lv),
        ];
    }
}

if (!function_exists('chat_actions_exp_add')) {
    function chat_actions_exp_add(PDO $pdo, int $uid, int $n, string $type, bool $log = false, ?string $detail = null): void {
        if (function_exists('exp_add')) {
            exp_add($uid, $n, $type, $log, $detail);
            return;
        }
        if ($n <= 0 || $uid <= 0) return;
        $pdo->prepare("UPDATE users SET exp = exp + ? WHERE user_id = ?")->execute([$n, $uid]);
        if ($log) {
            $pdo->prepare("INSERT INTO exp_log (user_id, exp, type, detail) VALUES (?,?,?,?)")
                ->execute([$uid, $n, $type, $detail]);
        }
    }
}

if (!function_exists('chat_actions_exp_daily_incr')) {
    /**
     * 每日限次 EXP 计数器（UTC+8）
     */
    function chat_actions_exp_daily_incr(PDO $pdo, int $uid, string $ctype, int $max, int $n, string $type, bool $log = false, ?string $detail = null): bool {
        if (function_exists('exp_daily_incr')) {
            return exp_daily_incr($uid, $ctype, $max, $n, $type, $log, $detail);
        }
        $today = gmdate('Y-m-d', time() + 8 * 3600);
        $stmt = $pdo->prepare("SELECT cnt FROM daily_counters WHERE user_id=? AND ddate=? AND ctype=?");
        $stmt->execute([$uid, $today, $ctype]);
        $cnt = (int)($stmt->fetchColumn() ?: 0);
        if ($cnt >= $max) return false;
        $upd = $pdo->prepare("INSERT INTO daily_counters (user_id, ddate, ctype, cnt) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE cnt = cnt + 1");
        $upd->execute([$uid, $today, $ctype]);
        chat_actions_exp_add($pdo, $uid, $n, $type, $log, $detail);
        return true;
    }
}

/**
 * 发消息 EXP（消息长度积分，冷却 + 每日上限）
 */
function chat_actions_msg_exp(PDO $pdo, int $senderUid, int $origLen): void {
    $cfg = chat_actions_lvconfig();
    $stmt = $pdo->prepare("SELECT last_exp_msg_at FROM users WHERE user_id = ?");
    $stmt->execute([$senderUid]);
    $lastMsgAt = $stmt->fetchColumn();
    $lastMsgTs = $lastMsgAt ? strtotime((string)$lastMsgAt) : 0;
    $cooldown = (int)($cfg['exp_msg_cooldown'] ?? 1);
    if ($lastMsgAt === false || $lastMsgAt === null || (time() - $lastMsgTs) >= $cooldown) {
        if ($origLen > 192)      $msgExp = 6;
        elseif ($origLen > 128)  $msgExp = 5;
        elseif ($origLen > 96)   $msgExp = 4;
        elseif ($origLen > 64)   $msgExp = 3;
        else                     $msgExp = 2;
        $pdo->prepare("UPDATE users SET last_exp_msg_at = NOW() WHERE user_id = ?")->execute([$senderUid]);
        chat_actions_exp_daily_incr($pdo, $senderUid, 'msg', (int)($cfg['exp_msg_max_daily'] ?? 500), $msgExp, 'msg');
    }
}

/**
 * 附件 EXP（类型/大小积分，冷却 + 每日上限 75）
 */
function chat_actions_attach_exp(PDO $pdo, int $senderUid, ?string $attachmentFilename): void {
    if (empty($attachmentFilename)) return;
    $cfg = chat_actions_lvconfig();
    $stmt = $pdo->prepare("SELECT last_exp_attach_at FROM users WHERE user_id = ?");
    $stmt->execute([$senderUid]);
    $lastAttAt = $stmt->fetchColumn();
    $lastAttTs = $lastAttAt ? strtotime((string)$lastAttAt) : 0;
    $cooldown = (int)($cfg['exp_attach_cooldown'] ?? 120);
    if ($lastAttAt === false || $lastAttAt === null || (time() - $lastAttTs) >= $cooldown) {
        $attName = '';
        $attSizeBytes = 0;
        $attJson = json_decode((string)$attachmentFilename, true);
        if (is_array($attJson) && !empty($attJson['file'])) {
            $attName = (string)$attJson['file'];
            $attSizeBytes = (int)($attJson['size'] ?? 0);
        } else {
            $attName = (string)$attachmentFilename;
        }
        $ext = strtolower(pathinfo($attName, PATHINFO_EXTENSION));
        if ($attSizeBytes <= 0) {
            $fpath = __DIR__ . '/../data/user/' . $senderUid . '/' . basename($attName);
            if (is_file($fpath)) $attSizeBytes = filesize($fpath);
        }
        $sKb = $attSizeBytes / 1024;
        $allowlist = ['jpeg','jpg','png','heif','gif','mp3','mp4','m4a','mov'];
        if (in_array($ext, $allowlist, true)) {
            if ($sKb > 4096)      $attExp = 10;
            elseif ($sKb > 1024)  $attExp = 8;
            elseif ($sKb > 512)   $attExp = 6;
            else                  $attExp = 4;
        } elseif ($ext === 'txt') {
            if ($sKb > 8) $attExp = 3;
            elseif ($sKb > 1) $attExp = 2;
            else $attExp = 1;
        } else {
            $attExp = 2;
        }
        $pdo->prepare("UPDATE users SET last_exp_attach_at = NOW() WHERE user_id = ?")->execute([$senderUid]);
        chat_actions_exp_daily_incr($pdo, $senderUid, 'attach', 75, $attExp, 'attach');
    }
}

/**
 * 发送消息（私聊 + 公告）。
 *
 * @param PDO    $pdo        数据库连接
 * @param int    $senderUid  发送者 user_id
 * @param string $username   发送者用户名
 * @param array  $p          参数: message/recipient/attachment/filename/temp_upload_id/reply_to/md/message_raw
 * @param bool   $allowAttachment 是否允许附件（WSS 传 false，强制 HTTP）
 * @return array  ['success'=>true,'message_id'=>N] | ['success'=>false,'error'=>...]
 */
function chat_action_send(PDO $pdo, int $senderUid, string $username, array $p, bool $allowAttachment = true): array {
    if ($senderUid <= 0) return ['success' => false, 'error' => 'Not logged in'];

    $message     = trim((string)($p['message'] ?? ''));
    $attachmentB64 = trim((string)($p['attachment'] ?? ''));
    $tempUploadId = (int)($p['temp_upload_id'] ?? 0);
    $recipientName = trim((string)($p['recipient'] ?? ''));

    // WSS 通道禁止附件/闪传（避免阻塞单线程事件循环，强制走 HTTP）
    if (!$allowAttachment && (!empty($attachmentB64) || $tempUploadId > 0)) {
        return ['success' => false, 'error' => 'FORCE_HTTP'];
    }

    if (empty($message) && empty($attachmentB64) && $tempUploadId <= 0) {
        return ['success' => false, 'error' => 'Empty'];
    }
    if (mb_strlen($message) > 1000) {
        return ['success' => false, 'error' => 'Too long'];
    }

    $msgType = null;
    $attachmentFilename = null;

    if (!empty($attachmentB64)) {
        if (!preg_match('/^data:([^;]+);base64,(.+)$/s', $attachmentB64, $m)) {
            return ['success' => false, 'error' => 'Invalid attachment'];
        }
        $mediaMainType = strtolower($m[1]);
        $binary = base64_decode($m[2]);
        $maxAttachKb = chat_actions_level_limits(chat_actions_user_level($pdo, $senderUid))['max_attach_kb'];
        $maxAttachBytes = $maxAttachKb * 1024;
        if ($binary === false || strlen($binary) > $maxAttachBytes) {
            return ['success' => false, 'error' => 'Too large', 'max_attach_kb' => $maxAttachKb];
        }

        $hash = hash('sha256', $binary);
        $ext = 'bin';
        if (preg_match('/^image\/(\w+)$/', $mediaMainType, $em)) {
            $ext = strtolower($em[1]);
            if ($ext === 'jpeg') $ext = 'jpg';
        } elseif (preg_match('/^video\/(\w+)$/', $mediaMainType, $em)) {
            $ext = strtolower($em[1]);
            if ($ext === 'quicktime') $ext = 'mov';
        } elseif (preg_match('/^audio\/(\w+)$/', $mediaMainType, $em)) {
            $ext = strtolower($em[1]);
            if ($ext === 'mpeg') $ext = 'mp3';
            elseif ($ext === 'x-m4a') $ext = 'm4a';
            elseif ($ext === 'x-wav' || $ext === 'wav') $ext = 'wav';
            elseif ($ext === 'x-flac') $ext = 'flac';
            elseif ($ext === 'ogg') $ext = 'ogg';
        } elseif (preg_match('/^text\/(\w+)$/', $mediaMainType, $em)) {
            $ext = strtolower($em[1]);
        } elseif (preg_match('/^application\/([\w.+-]+)$/', $mediaMainType, $em)) {
            $ext = strtolower($em[1]);
            if ($ext === 'msword') $ext = 'doc';
            elseif ($ext === 'vnd.ms-excel') $ext = 'xls';
            elseif ($ext === 'pdf') $ext = 'pdf';
            elseif ($ext === 'zip') $ext = 'zip';
        }

        $filename = $hash . '.' . $ext;
        $dir = __DIR__ . '/../data/user/' . $senderUid;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . $filename, $binary);

        if (strpos($mediaMainType, 'image/') === 0) {
            $msgType = 'photo';
            $attachmentFilename = $filename;
        } elseif (strpos($mediaMainType, 'video/') === 0) {
            $msgType = 'photo';
            $attachmentFilename = $filename;
        } elseif (strpos($mediaMainType, 'audio/') === 0) {
            $msgType = 'audio';
            $attachmentFilename = $filename;
        } else {
            $msgType = 'file';
            $origName = isset($p['filename']) ? mb_substr(trim((string)$p['filename']), 0, 255) : ('file.' . $ext);
            $attachmentFilename = json_encode([
                'file' => $filename,
                'name' => $origName,
                'size' => strlen($binary),
            ], JSON_UNESCAPED_UNICODE);
        }
        if (empty($message)) $message = '';
    }

    $replyTo = (int)($p['reply_to'] ?? 0);
    $isMd = (($p['md'] ?? '') === '1' || ($p['md'] ?? '') === 1 || ($p['md'] ?? '') === true);
    if ($isMd) {
        $msg = strip_tags($message);
        $msgType = 'md';
    } else {
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $msgType = $msgType ?? null;
    }
    $time = gmdate('Y/m/d H:i:s');
    $recipientId = null;

    if (!empty($recipientName)) {
        $rStmt = $pdo->prepare('SELECT restricted, user_id FROM users WHERE username = ?');
        $rStmt->execute([$recipientName]);
        $rUser = $rStmt->fetch();
        if ($rUser && $rUser['restricted']) {
            return ['success' => false, 'error' => 'restricted'];
        }
    }

    // ---- Flash transfer (temp upload) message ----
    if ($tempUploadId > 0) {
        $tmpStmt = $pdo->prepare("SELECT id, filename, size, revoked FROM temp_uploads WHERE id = ?");
        $tmpStmt->execute([$tempUploadId]);
        $tmpRow = $tmpStmt->fetch();
        if ($tmpRow && !(int)$tmpRow['revoked']) {
            $msgType = 'temp';
            $tempMeta = json_encode([
                'file' => (int)$tempUploadId,
                'name' => $tmpRow['filename'],
                'size' => (int)$tmpRow['size'],
            ], JSON_UNESCAPED_UNICODE);
            $attachmentFilename = $tempMeta;
        } else {
            $tempUploadId = 0;
        }
    }

    try {
        $isAdmin = ($username === 'admin');
        if (!$isAdmin || !empty($recipientName)) {
            $uidStmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
            $uidStmt->execute([$recipientName]);
            $recipientId = (int)($uidStmt->fetchColumn() ?: 0);
            if (!$recipientId) return ['success' => false];

            // ---- Security: 私聊必须互为好友，防止骚扰 ----
            // 公告（recipient_id 为 NULL）无需校验；
            // 自己给自己发送（如转发给自己）无需好友关系。
            if ($recipientId !== $senderUid) {
                $frStmt = $pdo->prepare(
                    "SELECT 1 FROM contacts
                     WHERE status = 'accepted' AND (
                         (user_from = ? AND user_to = ?) OR (user_from = ? AND user_to = ?)
                     ) LIMIT 1"
                );
                $frStmt->execute([$senderUid, $recipientId, $recipientId, $senderUid]);
                if (!$frStmt->fetch()) {
                    return ['success' => false, 'error' => 'not_friends'];
                }
            }
        }
        $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, message, msg_type, attachment, reply_to, time, datetime, temp_upload_id) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)')
            ->execute([$senderUid, $recipientId, $msg, $msgType, $attachmentFilename, $replyTo ?: null, $time, $tempUploadId ?: null]);
        $newMsgId = (int)$pdo->lastInsertId();

        if ($tempUploadId > 0) {
            $pdo->prepare("UPDATE temp_uploads SET message_id = ? WHERE id = ? AND message_id IS NULL")
                ->execute([$newMsgId, $tempUploadId]);
        }

        // ---- Level system: EXP 只在消息落库后颁发（失败绝不阻断发送）----
        try {
            $origLen = isset($p['message_raw']) ? strlen((string)$p['message_raw']) : strlen($message);
            chat_actions_msg_exp($pdo, $senderUid, $origLen);
            if (!empty($attachmentFilename)) {
                chat_actions_attach_exp($pdo, $senderUid, $attachmentFilename);
            }
        } catch (\Throwable $e) {
            // EXP award must never break message send
        }

        return ['success' => true, 'message_id' => $newMsgId];
    } catch (\Throwable $e) {
        return ['success' => false];
    }
}

/**
 * 撤回消息（仅本人、120 秒内）
 */
function chat_action_revoke(PDO $pdo, int $uid, array $p): array {
    if ($uid <= 0) return ['success' => false];
    $messageId = (int)($p['message_id'] ?? 0);
    if ($messageId <= 0) return ['success' => false];
    $stmt = $pdo->prepare("SELECT sender_id, datetime FROM messages WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$messageId]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['sender_id'] !== $uid) return ['success' => false];
    if ((time() - strtotime($row['datetime'])) > 120) return ['success' => false];
    $pdo->prepare("UPDATE messages SET deleted_at = NOW() WHERE id = ?")->execute([$messageId]);
    return ['success' => true];
}

/**
 * 标记已读（含收消息 EXP：每条 +1，每日上限）
 */
function chat_action_mark_read(PDO $pdo, int $uid, string $username, array $p): array {
    if ($uid <= 0) return ['success' => false, 'error' => 'Something went wrong.'];
    $fromUser = trim((string)($p['from'] ?? ''));
    if (empty($fromUser)) return ['success' => false, 'error' => 'Something went wrong.'];
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
    $stmt->execute([$fromUser]);
    $fromUid = (int)($stmt->fetchColumn() ?: 0);
    if (!$fromUid) return ['success' => false, 'error' => 'Something went wrong.'];

    $aff = $pdo->prepare("UPDATE messages SET read_at = NOW() WHERE sender_id = ? AND recipient_id = ? AND read_at IS NULL");
    $aff->execute([$fromUid, $uid]);
    $marked = $aff->rowCount();

    if ($marked > 0) {
        try {
            $cfg = chat_actions_lvconfig();
            $dailyMax = (int)($cfg['exp_msg_max_daily'] ?? 500);
            for ($i = 0; $i < $marked; $i++) {
                if (!chat_actions_exp_daily_incr($pdo, $uid, 'receive', $dailyMax, 1, 'receive')) break;
            }
        } catch (\Throwable $e) {
            // never break read flow
        }
    }

    return ['success' => true, 'marked' => $fromUser, 'count' => $marked];
}

/**
 * 未读数（按发送者用户名）
 */
function chat_action_unread_counts(PDO $pdo, int $uid, string $username): array {
    if ($uid <= 0) return ['success' => true, 'counts' => []];
    $stmt = $pdo->prepare("SELECT u.username, COUNT(*) AS cnt
        FROM messages m
        JOIN users u ON u.user_id = m.sender_id
        WHERE m.recipient_id = ? AND m.read_at IS NULL AND m.deleted_at IS NULL AND u.username != ?
        GROUP BY u.username");
    $stmt->execute([$uid, $username]);
    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['username']] = (int)$row['cnt'];
    }
    return ['success' => true, 'counts' => $counts];
}