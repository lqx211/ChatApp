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
    $doodle      = trim((string)($p['doodle'] ?? ''));
    $isChatlog   = (($p['msg_type'] ?? '') === 'chatlog');
    // 幂等键：客户端在 WSS 超时降级 HTTP 重试时共用同一键，服务端据此去重，
    // 避免同一条消息被插入两次（对方看到两条相同消息）
    $clientMsgId = trim((string)($p['client_msg_id'] ?? ''));
    if (strlen($clientMsgId) > 64) $clientMsgId = substr($clientMsgId, 0, 64);

    // WSS 通道禁止附件/闪传（避免阻塞单线程事件循环，强制走 HTTP）
    if (!$allowAttachment && (!empty($attachmentB64) || $tempUploadId > 0)) {
        return ['success' => false, 'error' => 'FORCE_HTTP'];
    }

    if (empty($message) && empty($attachmentB64) && $tempUploadId <= 0 && empty($doodle) && !$isChatlog) {
        return ['success' => false, 'error' => 'Empty'];
    }
    if (mb_strlen($message) > 32767) { // 2^15 - 1
        return ['success' => false, 'error' => 'Too long'];
    }

    $msgType = null;
    $attachmentFilename = null;
    // E2EE：message 是 base64 JSON envelope（无 HTML 活性内容），存原样、不 htmlspecialchars
    $isE2ee = (($p['msg_type'] ?? '') === 'e2ee');

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
        // Strict MIME → extension allowlist. Never derive an extension from the
        // client-supplied MIME subtype directly (previously data:text/php could
        // produce a .php file inside the docroot → RCE). Unknown types → safe 'bin'.
        $mimeExtMap = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
            'video/ogg' => 'ogg', 'video/mov' => 'mov',
            'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
            'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/flac' => 'flac',
            'audio/ogg' => 'ogg', 'audio/webm' => 'webm',
            'application/pdf' => 'pdf', 'application/zip' => 'zip',
            'application/msword' => 'doc', 'application/vnd.ms-excel' => 'xls',
        ];
        $ext = $mimeExtMap[$mediaMainType] ?? 'bin';
        // Content sanity check for image types: bytes must actually decode as an
        // image so HTML/script cannot be stored under an image extension.
        if (in_array($ext, ['jpg', 'png', 'gif', 'webp'], true) && @getimagesizefromstring($binary) === false) {
            return ['success' => false, 'error' => 'Invalid image'];
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
        // Defense-in-depth: strip_tags is not a safe HTML sanitizer. Reject
        // messages carrying script/active-content tokens; the client renderer
        // is also hardened (URL allowlist + attribute escaping).
        if (preg_match('/(?:<script|<iframe|<object|<embed|<form|<svg|<math|<style|<link|<meta|<base)\b/i', $message)
            || preg_match('/\b(?:onerror|onload|onclick|ondblclick|oncontextmenu|onmouseover|onmouseout|onmousedown|onmouseup|onfocus|onblur|onchange|oninput|onkeydown|onkeyup|onsubmit|ondragstart|ondrop|onanimationstart|ontransitionend)\s*=/i', $message)
            || preg_match('/\b(?:javascript|vbscript)\s*:/i', $message)) {
            return ['success' => false, 'error' => 'Unsupported content'];
        }
        $msg = strip_tags($message);
        $msgType = 'md';
    } elseif ($isE2ee) {
        // E2EE 密文：JSON envelope（base64 字段，无 <>& 活性内容），原样存储
        $msg = $message;
        $msgType = 'e2ee';
    } else {
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $msgType = $msgType ?? null;
    }

    // ---- 聊天记录卡片（chatlog）：attachment 存 JSON 消息列表，message 留空 ----
    if ($isChatlog) {
        $cl = (string)($p['chatlog'] ?? '');
        $clArr = json_decode($cl, true);
        if (!is_array($clArr) || !isset($clArr['msgs']) || !is_array($clArr['msgs']) || count($clArr['msgs']) > 100 || strlen($cl) > 300000) {
            return ['success' => false, 'error' => 'Invalid chatlog'];
        }
        $msg = '';
        $msgType = 'chatlog';
        $attachmentFilename = $cl;
    }

    // ---- Doodle 涂鸦消息（矢量笔迹，存 JSON 点数据，不是文件） ----
    if (!empty($doodle)) {
        $doodleArr = json_decode($doodle, true);
        if (!is_array($doodleArr) || count($doodleArr) > 200 || strlen($doodle) > 300000) {
            return ['success' => false, 'error' => 'Invalid doodle'];
        }
        foreach ($doodleArr as $stroke) {
            if (!is_array($stroke) || empty($stroke['points']) || !is_array($stroke['points'])) {
                return ['success' => false, 'error' => 'Invalid doodle'];
            }
        }
        $msgType = 'doodle';
        $attachmentFilename = $doodle;
        $message = '';
        $msg = '';
    }
    $time = time(); // messages.time 列：BIGINT，UNIX 秒级 UTC 时间戳（前端 new Date(ts*1000)）
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
        // Announcement broadcast is reserved for the root account (uid 10000).
        $isAdmin = ($senderUid === 10000);
        if (!$isAdmin || !empty($recipientName)) {
            $uidStmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
            $uidStmt->execute([$recipientName]);
            $recipientId = (int)($uidStmt->fetchColumn() ?: 0);
            if (!$recipientId) return ['success' => false];

            // ---- Security: 私聊必须互为好友，防止骚扰 ----
            // 公告（recipient_id 为 NULL）无需校验；
            // 自己给自己发送（如转发给自己）无需好友关系。
            if ($recipientId !== $senderUid) {
                // 黑名单：任一方向拉黑则禁止私聊
                $blStmt = $pdo->prepare(
                    "SELECT 1 FROM user_blocks
                     WHERE (user_id = ? AND blocked_uid = ?) OR (user_id = ? AND blocked_uid = ?) LIMIT 1"
                );
                $blStmt->execute([$senderUid, $recipientId, $recipientId, $senderUid]);
                if ($blStmt->fetch()) {
                    return ['success' => false, 'error' => 'blocked'];
                }
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
        // ---- 幂等去重：同一发送者 + 同一 client_msg_id 在 120s 内已插入 → 直接返回既有 id，不再插第二次 ----
        if ($clientMsgId !== '') {
            $dupStmt = $pdo->prepare(
                "SELECT id FROM messages WHERE sender_id = ? AND client_msg_id = ? AND datetime > NOW() - INTERVAL 120 SECOND LIMIT 1"
            );
            $dupStmt->execute([$senderUid, $clientMsgId]);
            $dupId = (int)$dupStmt->fetchColumn();
            if ($dupId) return ['success' => true, 'message_id' => $dupId];
        }
        $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, message, msg_type, attachment, reply_to, time, datetime, temp_upload_id, client_msg_id) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)')
            ->execute([$senderUid, $recipientId, $msg, $msgType, $attachmentFilename, $replyTo ?: null, $time, $tempUploadId ?: null, $clientMsgId !== '' ? $clientMsgId : null]);
        $newMsgId = (int)$pdo->lastInsertId();

        if ($tempUploadId > 0) {
            $pdo->prepare("UPDATE temp_uploads SET message_id = ? WHERE id = ? AND message_id IS NULL")
                ->execute([$newMsgId, $tempUploadId]);
        }

        // ---- Level system: EXP 只在消息落库后颁发（失败绝不阻断发送）----
        try {
            $origLen = isset($p['message_raw']) ? strlen((string)$p['message_raw']) : strlen($message);
            chat_actions_msg_exp($pdo, $senderUid, $origLen);
            // doodle 的 attachment 是笔迹 JSON，不是文件，不给附件 EXP
            if (!empty($attachmentFilename) && $msgType !== 'doodle') {
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
 * 个人资料管理：列出自己发送的附件消息（相片/影片/文件/音频），按类型过滤
 * 注意：视频在 send 里也是 msg_type='photo'，靠扩展名区分相片/影片
 */
function chat_action_my_content(PDO $pdo, int $uid, array $p): array {
    if ($uid <= 0) return ['success' => false];
    $type = trim((string)($p['type'] ?? 'all'));
    if (!in_array($type, ['all', 'photo', 'video', 'file', 'audio'], true)) $type = 'all';
    $limit = min(200, max(1, (int)($p['limit'] ?? 100)));
    $offset = max(0, (int)($p['offset'] ?? 0));

    $stmt = $pdo->prepare(
        "SELECT id, msg_type, attachment, datetime FROM messages
         WHERE sender_id = ? AND deleted_at IS NULL
           AND msg_type IN ('photo','file','audio')
           AND attachment IS NOT NULL AND attachment <> ''
         ORDER BY id DESC LIMIT $limit OFFSET $offset"
    );
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $r) {
        $att = (string)$r['attachment'];
        $kind = 'file';
        $meta = null;
        if ($r['msg_type'] === 'file') {
            $meta = json_decode($att, true);
            if (!is_array($meta) || empty($meta['file'])) continue;
            $kind = 'file';
        } else {
            $ext = strtolower(pathinfo($att, PATHINFO_EXTENSION));
            if (in_array($ext, ['mp4', 'webm', 'mov', 'ogg'], true)) $kind = 'video';
            elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) $kind = 'photo';
            elseif (in_array($ext, ['mp3', 'm4a', 'wav', 'flac', 'aac', 'opus'], true)) $kind = 'audio';
            else $kind = 'file';
        }
        if ($type !== 'all' && $kind !== $type) continue;

        if ($r['msg_type'] === 'file' && $meta) {
            $url = '../../api/file.php?u=' . $uid . '&f=' . rawurlencode($meta['file']) . '&name=' . rawurlencode($meta['name'] ?? 'file');
            $name = $meta['name'] ?? 'file';
            $size = isset($meta['size']) ? (int)$meta['size'] : null;
        } else {
            $url = '../../api/file.php?u=' . $uid . '&f=' . rawurlencode($att);
            $name = $att;
            $size = null;
        }
        $items[] = [
            'id'      => (int)$r['id'],
            'kind'    => $kind,
            'url'     => $url,
            'name'    => $name,
            'size'    => $size,
            'time'    => $r['datetime'],
        ];
    }
    return ['success' => true, 'items' => $items];
}

/**
 * 个人资料管理：撤回自己发送的任意一条消息（不受 120 秒限制；仅本人）
 */
function chat_action_revoke_own(PDO $pdo, int $uid, array $p): array {
    if ($uid <= 0) return ['success' => false];
    $messageId = (int)($p['message_id'] ?? 0);
    if ($messageId <= 0) return ['success' => false];
    $stmt = $pdo->prepare("SELECT sender_id FROM messages WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$messageId]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['sender_id'] !== $uid) return ['success' => false];
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

/**
 * 会话列表（手机端）：与我有过私聊的对象 + 最后一条消息 + 未读数。
 * 只含私聊（group_id IS NULL 且 recipient 非空），按最后消息时间倒序。
 */
function chat_action_conversations(PDO $pdo, int $uid): array {
    $uid = (int)$uid;
    if ($uid <= 0) return ['success' => true, 'conversations' => []];
    $sql = "SELECT conv.partner_id,
                   u.username, COALESCE(u.display_name, u.username) AS display_name, u.avatar,
                   lm.message AS last_message, lm.msg_type AS last_type,
                   lm.time AS last_time, lm.datetime AS last_datetime, lm.deleted_at AS last_deleted,
                   (SELECT COUNT(*) FROM messages uq
                     WHERE uq.sender_id = conv.partner_id AND uq.recipient_id = {$uid}
                       AND uq.read_at IS NULL AND uq.deleted_at IS NULL) AS unread
            FROM (
                SELECT CASE WHEN sender_id = {$uid} THEN recipient_id ELSE sender_id END AS partner_id,
                       MAX(id) AS last_id
                FROM messages
                WHERE group_id IS NULL AND recipient_id IS NOT NULL
                  AND (sender_id = {$uid} OR recipient_id = {$uid})
                GROUP BY partner_id
            ) conv
            JOIN users u ON u.user_id = conv.partner_id
            JOIN messages lm ON lm.id = conv.last_id
            WHERE u.deleted_at IS NULL
            ORDER BY lm.datetime DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $list = [];
    foreach ($rows as $r) {
        $isDeleted = ($r['last_deleted'] !== null);
        $avatar = $r['avatar'];
        if (!empty($avatar) && strpos($avatar, 'data:') !== 0 && preg_match('/^[0-9a-zA-Z_]+\\.(png|jpg|jpeg|gif|webp)$/i', $avatar)) {
            $avatar = '../../api/avatar.php?u=' . urlencode($r['username']);
        }
        $list[] = [
            'uid' => (int)$r['partner_id'],
            'username' => $r['username'],
            'display_name' => $r['display_name'],
            'avatar' => $avatar,
            'last_message' => $isDeleted ? '[revoked]' : $r['last_message'],
            'last_type' => $r['last_type'],
            'last_time' => $r['last_time'],
            'last_datetime' => $r['last_datetime'],
            'unread' => (int)$r['unread'],
        ];
    }
    return ['success' => true, 'conversations' => $list];
}