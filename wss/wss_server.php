<?php
/**
 * ChatApp — WebSocket 服务器（纯 PHP，零依赖，stream_select 事件循环）
 *
 * 功能:
 *   1. WebSocket 握手 + token 鉴权（token 由 api/ws_token.php 签发）
 *   2. 消息实时推送：每 WSS_POLL_MS 查一次 DB 增量，推送私聊/公告/群消息
 *   3. 在线状态广播：连接/断开时通知其好友
 *   4. 心跳：收到客户端 ping 时同步游标（l/glast/groups），不维护 last_ping
 *   5. 打字状态转发（HTTP typing 为主，WS 同步游标为辅助）
 *
 * 职责边界（用户确认方案）:
 *   - WS 只负责：接收新消息（私聊/公告/群）+ 在线广播 + 心跳游标同步
 *   - HTTP 负责：发送消息、ping(5s 维护 last_ping)、checkOnline(3s)、typing、
 *     好友请求、历史记录、搜索等一切其他操作
 *   - 前端 WS 断线时自动降级回 HTTP 轮询（pm 30s / 群 30s / ping 5s / check 3s）
 *
 * 运行:
 *   php wss/wss_server.php          # 前台
 *   nohup php wss/wss_server.php &  # 后台
 *   ./wss/start.sh                  # 包装脚本
 *
 * 协议（与客户端 wss_client.js 约定）:
 *   - 连接:   wss://wss.lqx211.com/?token=xxx
 *   - 客户端 -> 服务端 JSON:
 *       {"type":"ping","l":123,"glast":456,"groups":[1,2,3]}
 *          l=已收私聊/公告最大id, glast=已收群消息最大id, groups=已加入群组id列表
 *       {"type":"typing","to":"username"}
 *          to=正在输入的对方用户名
 *   - 服务端 -> 客户端 JSON:
 *       {"type":"pong"}
 *       {"type":"msg","messages":[...], "latest_id":123}
 *       {"type":"group_msg","messages":[...], "glast":123}
 *       {"type":"presence","online":{"u1":1},"dnd":{"u2":1},"offline":["u3"]}
 *       {"type":"typing","from":"username"}
 *       {"type":"friend_req","count":3}
 *       {"type":"revoked","id":123,"channel":"dm"|"announcement"|"group:5"}
 */

// 兼容: 脚本可能在任意 CWD 被调用
$__dir = __DIR__;
if (!defined('WSS_PORT')) {
    require_once __DIR__ . '/wss_config.php';
}

// 共享业务逻辑（零依赖：不 require api/config.php，CLI 安全）
require_once __DIR__ . '/../api/chat_actions.php';

// ==================== 日志 ====================
function ws_log(string $msg, bool $stderrOnly = false): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    if (!$stderrOnly && defined('WSS_LOG_FILE')) {
        @file_put_contents(WSS_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }
    fwrite(STDERR, $line);
}

// ==================== 数据库 ====================
function ws_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . WSS_DB_HOST . ';dbname=' . WSS_DB_NAME . ';charset=' . WSS_DB_CHARSET,
            WSS_DB_USER,
            WSS_DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

// ==================== WebSocket 协议（移植自 ws_test/ws_echo_server.py） ====================
const WS_MAGIC = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

function ws_accept_key(string $key): string {
    return base64_encode(sha1($key . WS_MAGIC, true));
}

/**
 * 构造服务端帧（无掩码）
 */
function ws_encode_frame(string $payload, int $opcode = 0x1): string {
    $b0 = 0x80 | $opcode; // FIN=1
    $len = strlen($payload);
    $header = chr($b0);
    if ($len < 126) {
        $header .= chr($len);
    } elseif ($len < 65536) {
        $header .= chr(126) . pack('n', $len);
    } else {
        $header .= chr(127) . pack('J', $len);
    }
    return $header . $payload;
}

function ws_encode_text(string $json): string {
    return ws_encode_frame($json, 0x1);
}

function ws_encode_close(int $code = 1000, string $reason = ''): string {
    $payload = pack('n', $code) . $reason;
    return ws_encode_frame($payload, 0x8);
}

/**
 * 从 stream 读取恰好 n 字节
 */
function ws_read_exact($sock, int $n): ?string {
    $buf = '';
    while (strlen($buf) < $n) {
        $chunk = fread($sock, $n - strlen($buf));
        if ($chunk === false || $chunk === '') return null;
        $buf .= $chunk;
    }
    return $buf;
}

/**
 * 解析客户端帧（带掩码）。返回 [opcode, payload] 或 null。
 */
function ws_decode_frame($sock): ?array {
    $h2 = ws_read_exact($sock, 2);
    if ($h2 === null) return null;
    $b0 = ord($h2[0]);
    $b1 = ord($h2[1]);
    $opcode = $b0 & 0x0F;
    $masked = ($b1 >> 7) & 1;
    $len = $b1 & 0x7F;

    if ($len === 126) {
        $ext = ws_read_exact($sock, 2);
        if ($ext === null) return null;
        $len = unpack('n', $ext)[1];
    } elseif ($len === 127) {
        $ext = ws_read_exact($sock, 8);
        if ($ext === null) return null;
        $len = unpack('J', $ext)[1];
    }

    $maskKey = '';
    if ($masked) {
        $maskKey = ws_read_exact($sock, 4);
        if ($maskKey === null) return null;
    }

    $payload = '';
    $remaining = $len;
    while ($remaining > 0) {
        $chunk = fread($sock, min($remaining, 65536));
        if ($chunk === false || $chunk === '') return null;
        $payload .= $chunk;
        $remaining -= strlen($chunk);
    }

    if ($masked && $maskKey !== '') {
        $unmasked = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
        }
        $payload = $unmasked;
    }

    return [$opcode, $payload];
}

// ==================== 连接管理 ====================
/**
 * $clients: 连接 id => [
 *   'sock' => resource,
 *   'user_id' => int,
 *   'username' => string,
 *   'l' => int,        // 已收私聊/公告消息最大 id
 *   'glast' => int,    // 已收群消息最大 id
 *   'group_ids' => [], // 已加入群组集合
 *   'last_seen' => float, // 最后活跃时间戳
 * ]
 */
$GLOBALS['clients'] = [];
$GLOBALS['id_count'] = 0;
// 全局游标：WS 服务自己查过的消息最大 id（跨所有类型）
$GLOBALS['poll_latest'] = 0;
// 闪传状态指纹缓存：cid => [temp_id => fingerprint]，仅推送变化的闪传状态
$GLOBALS['temp_fp'] = [];

function ws_clients(): array {
    return $GLOBALS['clients'];
}

/**
 * 向单个连接发送 JSON
 */
function ws_send_json(int $cid, array $data): bool {
    $cl = $GLOBALS['clients'] ?? [];
    if (!isset($cl[$cid])) return false;
    $sock = $cl[$cid]['sock'];
    if (!is_resource($sock)) return false;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $frame = ws_encode_text($json);
    $written = @fwrite($sock, $frame);
    if ($written === false || $written < strlen($frame)) {
        return false; // 写失败视为连接断开
    }
    return true;
}

/**
 * 向某用户所有连接广播
 */
function ws_send_to_user(string $username, array $data): void {
    foreach ($GLOBALS['clients'] ?? [] as $cid => $cl) {
        if ($cl['username'] === $username) {
            ws_send_json($cid, $data);
        }
    }
}

/**
 * 向多个指定用户广播
 */
function ws_send_to_users(array $usernames, array $data): void {
    if (empty($usernames)) return;
    $set = array_flip($usernames);
    foreach ($GLOBALS['clients'] ?? [] as $cid => $cl) {
        if (isset($set[$cl['username']])) {
            ws_send_json($cid, $data);
        }
    }
}

// ==================== 消息处理（复用 chat.php proc() 逻辑） ====================
/**
 * 处理消息行 -> 前端渲染格式（私聊/公告）。与 api/chat.php proc() 一致。
 */
function ws_proc_messages(PDO $pdo, array $msgs, array $replyMap = []): array {
    $out = [];
    foreach ($msgs as $m) {
        $m['id'] = (int)$m['id'];
        $m['username'] = $m['username'] ?? 'Unknown';
        $m['display_name'] = $m['display_name'] ?? $m['username'];
        $m['recipient'] = $m['recipient_name'] ?? null;
        $m['avatar'] = $m['avatar'] ?? null;
        $m['msg_type'] = $m['msg_type'] ?? null;
        $m['is_markdown'] = ($m['msg_type'] === 'md');
        $m['is_deleted'] = ($m['deleted_at'] !== null);
        if ($m['is_deleted']) {
            $m['message'] = '[This message has been revoked]';
            $m['attachment_url'] = null;
        }
        unset($m['deleted_at']);
        $m['attachment_name'] = null;
        $m['attachment_size'] = null;
        $senderId = (int)($m['sender_id'] ?? $m['user_id'] ?? 0);
        if (!$m['is_deleted'] && !empty($m['attachment']) && !empty($m['msg_type'])) {
            if ($m['msg_type'] === 'file') {
                $meta = json_decode($m['attachment'], true);
                if (is_array($meta) && !empty($meta['file'])) {
                    $m['attachment_url'] = '../api/file.php?u=' . $senderId . '&f=' . rawurlencode($meta['file']) . '&name=' . rawurlencode($meta['name'] ?? 'file');
                    $m['attachment_name'] = $meta['name'] ?? 'file';
                    $m['attachment_size'] = isset($meta['size']) ? (int)$meta['size'] : null;
                } else {
                    $m['attachment_url'] = null;
                }
            } elseif ($m['msg_type'] === 'temp') {
                $meta = is_array(json_decode($m['attachment'], true)) ? json_decode($m['attachment'], true) : [];
                $m['temp_upload_id'] = (int)($meta['file'] ?? $m['temp_upload_id'] ?? 0);
                $m['attachment_name'] = $meta['name'] ?? 'file';
                $m['attachment_size'] = isset($meta['size']) ? (int)$meta['size'] : null;
                $m['attachment_url'] = null;
                if ($m['temp_upload_id'] > 0) {
                    $tStmt = $pdo->prepare("SELECT revoked, expires_at FROM temp_uploads WHERE id = ?");
                    $tStmt->execute([(int)$m['temp_upload_id']]);
                    $tmps = $tStmt->fetch();
                    $m['temp_revoked'] = $tmps ? (int)$tmps['revoked'] : 0;
                    $m['temp_expires'] = $tmps ? $tmps['expires_at'] : null;
                } else {
                    $m['temp_revoked'] = 0;
                    $m['temp_expires'] = null;
                }
            } else {
                $m['attachment_url'] = '../api/file.php?u=' . $senderId . '&f=' . $m['attachment'];
            }
        } elseif (!$m['is_deleted']) {
            $m['attachment_url'] = null;
        }
        if (!$m['is_deleted'] && !empty($m['attachment']) && empty($m['msg_type']) && strpos($m['attachment'], 'data:') === 0) {
            $m['attachment_url'] = $m['attachment'];
        }
        $m['reply_data'] = (!empty($m['reply_to']) && isset($replyMap[(int)$m['reply_to']])) ? $replyMap[(int)$m['reply_to']] : null;
        unset($m['reply_to'], $m['sender_id'], $m['recipient_id'], $m['user_id'], $m['recipient_name'], $m['recipient_display'], $m['recipient_uid']);
        $out[] = $m;
    }
    return $out;
}

/**
 * 解析 reply_to 引用（与 chat.php proc() 一致）
 */
function ws_resolve_replies(PDO $pdo, array $msgs): array {
    $replyIds = [];
    foreach ($msgs as $m) {
        if (!empty($m['reply_to'])) $replyIds[] = (int)$m['reply_to'];
    }
    $replyMap = [];
    if (!empty($replyIds)) {
        $placeholders = implode(',', array_fill(0, count($replyIds), '?'));
        $rst = $pdo->prepare("SELECT m.id, m.message, m.deleted_at, u.username, COALESCE(u.display_name,u.username) AS display_name 
            FROM messages m LEFT JOIN users u ON u.user_id = m.sender_id 
            WHERE m.id IN ($placeholders)");
        $rst->execute($replyIds);
        while ($r = $rst->fetch()) {
            $replyMap[$r['id']] = [
                'id' => (int)$r['id'],
                'username' => $r['username'],
                'display_name' => $r['display_name'],
                'message' => ($r['deleted_at'] !== null) ? '[This message has been revoked]' : mb_substr($r['message'], 0, 80),
            ];
        }
    }
    return $replyMap;
}

/**
 * 群消息处理（前端 loadGroupMessages 直接渲染需要的字段）
 */
function ws_proc_group_message(PDO $pdo, array $m): array {
    $m['id'] = (int)$m['id'];
    $m['is_markdown'] = ($m['msg_type'] === 'md');
    $m['is_deleted'] = ($m['deleted_at'] !== null);
    if ($m['is_deleted']) $m['message'] = '[This message has been revoked]';
    unset($m['deleted_at']);
    if (!empty($m['attachment'])) {
        $sendingUid = (int)$m['sender_id'];
        if ($m['msg_type'] === 'file') {
            $meta = json_decode($m['attachment'], true);
            if (is_array($meta) && !empty($meta['file'])) {
                $m['attachment_url'] = '../api/file.php?u=' . $sendingUid . '&f=' . rawurlencode($meta['file']) . '&name=' . rawurlencode($meta['name'] ?? 'file');
            } else $m['attachment_url'] = null;
        } elseif ($m['msg_type'] === 'photo' || $m['msg_type'] === 'audio') {
            $m['attachment_url'] = '../api/file.php?u=' . $sendingUid . '&f=' . $m['attachment'];
        }
    }
    return $m;
}

// ==================== 在线状态广播 ====================
/**
 * 获取某用户所有好友用户名
 */
function ws_get_friends(PDO $pdo, int $uid): array {
    $stmt = $pdo->prepare("SELECT CASE WHEN user_from = ? THEN u2.username ELSE u1.username END AS name
        FROM contacts c
        JOIN users u1 ON u1.user_id = c.user_from
        JOIN users u2 ON u2.user_id = c.user_to
        WHERE c.status = 'accepted' AND (c.user_from = ? OR c.user_to = ?)");
    $stmt->execute([$uid, $uid, $uid]);
    return array_column($stmt->fetchAll(), 'name');
}

/**
 * 向在线好友广播上下线
 */
function ws_broadcast_presence(PDO $pdo, int $uid, string $username, bool $online): void {
    $friends = ws_get_friends($pdo, $uid);
    if (empty($friends)) return;
    $payload = ['type' => 'presence'];
    if ($online) {
        $payload['online'] = [$username => 1];
    } else {
        $payload['offline'] = [$username];
    }
    ws_send_to_users($friends, $payload);
}

// ==================== 好友请求通知 ====================
function ws_pending_count(PDO $pdo, int $uid): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE user_to = ? AND status = 'pending'");
    $stmt->execute([$uid]);
    return (int)$stmt->fetchColumn();
}

// ==================== 连接关闭 ====================
function ws_close_conn(int $cid, int $code = 1000): void {
    $cl = $GLOBALS['clients'][$cid] ?? null;
    if (!$cl) return;
    try {
        @fwrite($cl['sock'], ws_encode_close($code));
    } catch (\Throwable $e) {}
    @fclose($cl['sock']);
    $username = $cl['username'];
    $uid = $cl['user_id'];
    unset($GLOBALS['clients'][$cid]);
    // 清理该连接的闪传指纹缓存
    unset($GLOBALS['temp_fp'][(string)$cid]);
    ws_log("断开连接 #$cid ($username)");
    // 该用户是否还有别的连接？
    $stillOnline = false;
    foreach ($GLOBALS['clients'] as $other) {
        if ($other['user_id'] === $uid) { $stillOnline = true; break; }
    }
    if (!$stillOnline) {
        try {
            ws_broadcast_presence(ws_db(), $uid, $username, false);
        } catch (\Throwable $e) {}
    }
}

// ==================== 握手 ====================
function ws_handshake($sock, string $request): ?array {
    // 解析请求头
    if (!preg_match('#^GET ([^ ]+) HTTP/1\.1#', $request, $m)) return null;
    $path = $m[1];
    $headers = [];
    foreach (explode("\r\n", $request) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    $key = $headers['sec-websocket-key'] ?? '';
    if (!$key || !isset($headers['upgrade']) || stripos($headers['upgrade'], 'websocket') === false) {
        @fwrite($sock, "HTTP/1.1 400 Bad Request\r\n\r\nNot a WebSocket request");
        return null;
    }

    // 解析 token（query 或 header）
    $token = '';
    if (preg_match('/[?&]token=([0-9a-f]{64})/i', $path, $tm)) {
        $token = strtolower($tm[1]);
    }

    // 校验 token
    $pdo = ws_db();
    $stmt = $pdo->prepare("SELECT user_id, username FROM ws_tokens WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $userRow = $stmt->fetch();
    if (!$userRow) {
        @fwrite($sock, "HTTP/1.1 401 Unauthorized\r\n\r\nInvalid token");
        return null;
    }
    $uid = (int)$userRow['user_id'];
    $username = $userRow['username'];

    // 校验用户可用
    $ust = $pdo->prepare("SELECT enabled, restricted, deleted_at FROM users WHERE user_id = ?");
    $ust->execute([$uid]);
    $urow = $ust->fetch();
    if (!$urow || (int)$urow['enabled'] !== 1 || $urow['deleted_at'] !== null) {
        @fwrite($sock, "HTTP/1.1 403 Forbidden\r\n\r\nAccount not available");
        return null;
    }

    // token 一次性使用：消费掉
    $pdo->prepare("DELETE FROM ws_tokens WHERE token = ?")->execute([$token]);

    // 响应 101
    $accept = ws_accept_key($key);
    $response = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: {$accept}\r\n"
        . "Sec-WebSocket-Protocol: chat\r\n"
        . "\r\n";
    @fwrite($sock, $response);

    return ['user_id' => $uid, 'username' => $username];
}

// ==================== 消息推送逻辑 ====================
/**
 * 每轮循环：查增量消息并推送给相关在线用户
 */
function ws_poll_messages(): void {
    $pdo = ws_db();
    $clients = $GLOBALS['clients'];
    if (empty($clients)) {
        // 无人在线，只推进全局游标避免积压查询
        try {
            $GLOBALS['poll_latest'] = (int)($pdo->query("SELECT MAX(id) FROM messages")->fetchColumn() ?? 0);
        } catch (\Throwable $e) {}
        return;
    }

    // 各连接上报的最老游标：全局查一次，覆盖需要推送的连接
    $minL = PHP_INT_MAX;
    $minGlast = PHP_INT_MAX;
    $userNeedL = [];   // username => [cid => l]
    $userNeedG = [];   // username => [cid => glast]
    $groupSubs = [];   // group_id => [username]
    foreach ($clients as $cid => $cl) {
        if ($cl['l'] < $minL) $minL = $cl['l'];
        if ($cl['glast'] < $minGlast) $minGlast = $cl['glast'];
        $userNeedL[$cl['username']][$cid] = $cl['l'];
        $userNeedG[$cl['username']][$cid] = $cl['glast'];
        foreach ($cl['group_ids'] as $gid) {
            $groupSubs[$gid][$cl['username']] = true;
        }
    }
    if ($minL === PHP_INT_MAX) $minL = 0;
    if ($minGlast === PHP_INT_MAX) $minGlast = 0;

    // ---------- 私聊 + 公告增量 ----------
    if ($minL > 0) {
        $stmt = $pdo->prepare("SELECT m.id, m.sender_id, su.username, su.display_name, su.avatar,
            m.recipient_id, ru.username AS recipient_name,
            m.message, m.msg_type, m.attachment, m.time, m.datetime, m.deleted_at, m.reply_to, m.temp_upload_id
            FROM messages m
            LEFT JOIN users su ON su.user_id = m.sender_id
            LEFT JOIN users ru ON ru.user_id = m.recipient_id
            WHERE m.id > ? AND m.group_id IS NULL
            ORDER BY m.id ASC LIMIT 100");
        $stmt->execute([$minL]);
        $rows = $stmt->fetchAll();
        if (!empty($rows)) {
            $replyMap = ws_resolve_replies($pdo, $rows);
            // 按收件人分组：公告(recipient null)推全部在线；私聊推相关双方
            $byRecipient = [];
            foreach ($rows as $r) {
                if ($r['recipient_name'] === null) {
                    $byRecipient['__announce__'][] = $r;
                } else {
                    $byRecipient['both:' . $r['username'] . ':' . $r['recipient_name']][] = $r;
                }
            }
            // 发一轮到每个连接
            foreach ($clients as $cid => $cl) {
                $push = [];
                $maxId = $cl['l'];
                foreach ($rows as $r) {
                    $isAnnounce = ($r['recipient_name'] === null);
                    $isRelated = $isAnnounce
                        || ($r['username'] === $cl['username'])
                        || ($r['recipient_name'] === $cl['username']);
                    if ($isRelated && $r['id'] > $cl['l']) {
                        $push[] = $r;
                        if ($r['id'] > $maxId) $maxId = $r['id'];
                    }
                }
                if (!empty($push)) {
                    $processed = ws_proc_messages($pdo, $push, $replyMap);
                    if (ws_send_json($cid, ['type' => 'msg', 'messages' => $processed, 'latest_id' => $maxId])) {
                        $GLOBALS['clients'][$cid]['l'] = $maxId;
                    }
                }
            }
        }
        // 推进全局 L 游标
        try {
            $GLOBALS['poll_latest'] = (int)($pdo->query("SELECT MAX(id) FROM messages WHERE group_id IS NULL")->fetchColumn() ?? 0);
        } catch (\Throwable $e) {}
    }

    // ---------- 群消息增量 ----------
    if ($minGlast > 0 && !empty($groupSubs)) {
        $gids = array_keys($groupSubs);
        $placeholders = implode(',', array_fill(0, count($gids), '?'));
        $stmt = $pdo->prepare("SELECT m.*, COALESCE(u.display_name, u.username) AS display_name, u.username, u.avatar
            FROM messages m JOIN users u ON u.user_id = m.sender_id
            WHERE m.group_id IN ($placeholders) AND m.id > ? AND m.id <= ?
            ORDER BY m.id ASC LIMIT 100");
        $stmt->execute(array_merge($gids, [$minGlast, $GLOBALS['poll_latest']]));
        $groupRows = $stmt->fetchAll();
        if (!empty($groupRows)) {
            // 按群分组
            $byGroup = [];
            foreach ($groupRows as $r) {
                $byGroup[(int)$r['group_id']][] = $r;
            }
            foreach ($clients as $cid => $cl) {
                $push = [];
                $maxId = $cl['glast'];
                foreach ($cl['group_ids'] as $gid) {
                    if (!isset($byGroup[$gid])) continue;
                    foreach ($byGroup[$gid] as $r) {
                        // 群里也要排除自己刚发的（避免重复），但前端 seenMsgIds 会去重
                        $push[] = $r;
                        if ($r['id'] > $maxId) $maxId = (int)$r['id'];
                    }
                }
                if (!empty($push)) {
                    // 去重（同一消息可能已在多个群列表里出现，但 group 归属唯一）
                    $seenP = [];
                    $uniq = [];
                    foreach ($push as $r) {
                        if (!isset($seenP[$r['id']])) {
                            $seenP[$r['id']] = 1;
                            $uniq[] = ws_proc_group_message($pdo, $r);
                        }
                    }
                    usort($uniq, fn($a, $b) => $a['id'] <=> $b['id']);
                    if (ws_send_json($cid, ['type' => 'group_msg', 'messages' => $uniq, 'glast' => $maxId])) {
                        $GLOBALS['clients'][$cid]['glast'] = $maxId;
                    }
                }
            }
        }
    }
}

/**
 * 闪传状态推送：检测 temp_uploads 变化，仅在有变更时推送 type=temp_status 给相关用户。
 * 用于替代前端 2s HTTP 轮询（WSS 在线时）。
 * 采用全局指纹缓存：只有状态/进度/撤销发生变化才推送，避免每轮循环重复发送。
 */
function ws_poll_temp_status(): void {
    $clients = $GLOBALS['clients'] ?? [];
    if (empty($clients)) return;
    $pdo = ws_db();

    // 为控制查询量，只查询有状态/进度的记录（not_started 无变化无需推送）
    try {
        $stmt = $pdo->query("SELECT id, owner_uid, revoked, download_complete, downloaded_bytes, size, expires_at, last_download_at
            FROM temp_uploads
            WHERE revoked = 1 OR download_complete = 1 OR downloaded_bytes > 0 OR download_started_at IS NOT NULL
            ORDER BY id DESC LIMIT 200");
        $rows = $stmt->fetchAll();
    } catch (\Throwable $e) {
        return;
    }
    if (empty($rows)) return;

    // 构建 id => row 的映射
    $tempById = [];
    foreach ($rows as $r) {
        $tempById[(int)$r['id']] = $r;
    }

    // 对每个在线用户：找到与他们相关的 temp（自己是 owner，或消息中包含该 temp）
    foreach ($clients as $cid => $cl) {
        $uid = (int)$cl['user_id'];
        $userTemps = [];

        // 自己是 owner 的 temp
        foreach ($tempById as $tid => $tr) {
            if ((int)$tr['owner_uid'] === $uid) {
                $userTemps[$tid] = $tr;
            }
        }

        // 收到的消息中引用该 temp（recipient 是我 或 我是 sender）
        $tidList = array_keys($tempById);
        if ($tidList) {
            $placeholders = implode(',', array_fill(0, count($tidList), '?'));
            try {
                $stmt = $pdo->prepare("SELECT DISTINCT temp_upload_id FROM messages
                    WHERE temp_upload_id IN ($placeholders)
                      AND (sender_id = ? OR recipient_id = ? OR recipient_id IS NULL)
                    ORDER BY id DESC LIMIT 50");
                $stmt->execute(array_merge($tidList, [$uid, $uid]));
                foreach ($stmt->fetchAll() as $mt) {
                    $tid = (int)$mt['temp_upload_id'];
                    if (isset($tempById[$tid])) {
                        $userTemps[$tid] = $tempById[$tid];
                    }
                }
            } catch (\Throwable $e) {}
        }

        if (empty($userTemps)) continue;

        // 收集变更项：与上次推送的指纹比对，仅推送有变化的
        $statuses = [];
        $newFingerprint = $GLOBALS['temp_fp'][(string)$cid] ?? [];
        foreach ($userTemps as $tid => $tr) {
            $isOwner = ((int)$tr['owner_uid'] === $uid);
            $status = 'not_started';
            if ((int)$tr['revoked']) $status = 'revoked';
            elseif ((int)$tr['download_complete']) $status = 'complete';
            elseif (!empty($tr['download_started_at'])) $status = 'in_progress';
            $item = [
                'id' => $tid,
                'status' => $status,
                'revoked' => (int)$tr['revoked'],
                'expires_at' => $tr['expires_at'],
            ];
            if ($isOwner) {
                $item['downloaded_bytes'] = (int)$tr['downloaded_bytes'];
                $item['size'] = (int)$tr['size'];
                $item['download_complete'] = (int)$tr['download_complete'];
                if (!empty($tr['last_download_at'])) $item['last_download_at'] = $tr['last_download_at'];
            }

            // 指纹：status + revoked + (owner 时 downloaded_bytes)
            $fp = $status . '|' . (int)$tr['revoked'] . '|' . ($isOwner ? (int)$tr['downloaded_bytes'] : '');
            if (($newFingerprint[$tid] ?? null) === $fp) continue; // 无变化跳过

            $newFingerprint[$tid] = $fp;
            $statuses[] = $item;
        }

        // 保存新指纹并推送变更
        $GLOBALS['temp_fp'][(string)$cid] = $newFingerprint;
        if (!empty($statuses)) {
            ws_send_json($cid, ['type' => 'temp_status', 'items' => $statuses]);
        }
    }
}

/**
 * 请求消息刷新（客户端刚上线时）
 */
function ws_refresh_client(int $cid): void {
    $cl = $GLOBALS['clients'][$cid] ?? null;
    if (!$cl) return;
    $pdo = ws_db();

    // 私聊+公告：从 l 之后拉
    $stmt = $pdo->prepare("SELECT m.id, m.sender_id, su.username, su.display_name, su.avatar,
        m.recipient_id, ru.username AS recipient_name,
        m.message, m.msg_type, m.attachment, m.time, m.datetime, m.deleted_at, m.reply_to, m.temp_upload_id
        FROM messages m
        LEFT JOIN users su ON su.user_id = m.sender_id
        LEFT JOIN users ru ON ru.user_id = m.recipient_id
        WHERE m.id > ? AND m.group_id IS NULL
        AND (m.recipient_id IS NULL OR m.recipient_id = ? OR m.sender_id = ?)
        ORDER BY m.id ASC LIMIT 100");
    $stmt->execute([$cl['l'], $cl['user_id'], $cl['user_id']]);
    $rows = $stmt->fetchAll();
    if (!empty($rows)) {
        $replyMap = ws_resolve_replies($pdo, $rows);
        $processed = ws_proc_messages($pdo, $rows, $replyMap);
        $maxId = (int)end($rows)['id'];
        // 只推非自己的消息（自己的消息已在发送时本地渲染；恢复历史用 all API）
        $filtered = [];
        foreach ($processed as $m) {
            if ($m['username'] !== $cl['username']) $filtered[] = $m;
        }
        if (!empty($filtered)) {
            ws_send_json($cid, ['type' => 'msg', 'messages' => $filtered, 'latest_id' => $maxId]);
        }
        $GLOBALS['clients'][$cid]['l'] = $maxId;
    }
}

// ==================== 心跳 & 在线状态 ====================
function ws_handle_ping(int $cid, array $data): void {
    $cl = $GLOBALS['clients'][$cid] ?? null;
    if (!$cl) return;
    $GLOBALS['clients'][$cid]['last_seen'] = microtime(true);

    // 更新游标
    if (isset($data['l']) && (int)$data['l'] > $cl['l']) $GLOBALS['clients'][$cid]['l'] = (int)$data['l'];
    if (isset($data['glast']) && (int)$data['glast'] > $cl['glast']) $GLOBALS['clients'][$cid]['glast'] = (int)$data['glast'];
    if (isset($data['groups']) && is_array($data['groups'])) {
        $gids = [];
        foreach ($data['groups'] as $g) {
            $gid = (int)$g;
            if ($gid > 0) $gids[$gid] = true;
        }
        $GLOBALS['clients'][$cid]['group_ids'] = array_keys($gids);
    }

    // 在线状态由 HTTP ping（5s）维护 last_ping，WS 心跳只做游标同步，不碰 last_ping
    ws_send_json($cid, ['type' => 'pong']);
}

function ws_handle_typing(int $cid, array $data): void {
    $cl = $GLOBALS['clients'][$cid] ?? null;
    if (!$cl) return;
    $to = trim($data['to'] ?? '');
    if ($to === '') return;
    try {
        $pdo = ws_db();
        $pdo->prepare("UPDATE users SET typing_to = ?, typing_at = NOW() WHERE user_id = ?")
            ->execute([$to, $cl['user_id']]);
        ws_send_to_user($to, ['type' => 'typing', 'from' => $cl['username']]);
    } catch (\Throwable $e) {}
}

// ==================== 客户端请求-响应（request/response） ====================
/**
 * 处理客户端主动请求 {"type":"request","id":N,"action":"...","params":{...}}
 *
 * 设计：
 *   - 身份直接取自 WSS 连接（握手时 ws_tokens 表验证，比 HTTP cookie 更可靠）
 *   - 复用 api/chat_actions.php 共享逻辑，与 HTTP api/chat.php 完全一致
 *   - send 传 allow_attachment=false：附件/闪传返回 FORCE_HTTP，前端自动降级回原始 HTTP
 *   - 响应统一 {"type":"response","id":N,...业务字段}
 */
function ws_handle_request(int $cid, array $data): void {
    $cl = $GLOBALS['clients'][$cid] ?? null;
    if (!$cl) return;
    $id = $data['id'] ?? null;
    if ($id === null) return; // 无 id 无法关联响应
    $action = (string)($data['action'] ?? '');
    $params = is_array($data['params'] ?? null) ? $data['params'] : [];
    $pdo = ws_db();
    $uid = (int)$cl['user_id'];
    $username = (string)$cl['username'];

    $result = ['success' => false];
    try {
        switch ($action) {
            case 'send':
                // WSS 通道禁止附件/闪传（避免阻塞单线程事件循环，客户端收到 FORCE_HTTP 后自动降级 HTTP）
                $result = chat_action_send($pdo, $uid, $username, $params, false);
                break;
            case 'revoke':
                $result = chat_action_revoke($pdo, $uid, $params);
                break;
            case 'mark_read':
                $result = chat_action_mark_read($pdo, $uid, $username, $params);
                break;
            case 'unread_counts':
                // GET 类操作，无额外参数
                $result = chat_action_unread_counts($pdo, $uid, $username);
                break;
            default:
                $result = ['success' => false, 'error' => 'unknown_action'];
                ws_log("未知 request action: $action (from $username)");
        }
    } catch (\Throwable $e) {
        $result = ['success' => false, 'error' => 'server_error'];
        ws_log("request $action 异常: " . $e->getMessage());
    }

    ws_send_json($cid, array_merge(['type' => 'response', 'id' => $id], $result));
}

// ==================== 主事件循环 ====================
function ws_main(): void {
    $port = WSS_PORT;
    $host = '0.0.0.0';

    $server = stream_socket_server("tcp://$host:$port", $errno, $errstr);
    if (!$server) {
        ws_log("❌ 无法监听 $host:$port — $errstr ($errno)", true);
        exit(1);
    }
    stream_set_blocking($server, false);
    ws_log("=====================================================");
    ws_log("  ChatApp WebSocket Server 已启动");
    ws_log("  监听: $host:$port");
    ws_log("  地址: wss://wss.lqx211.com/ (经 Cloudflare Tunnel)");
    ws_log("  心跳超时: " . WSS_HEARTBEAT_TIMEOUT_S . "s  轮询: " . WSS_POLL_MS . "ms");
    ws_log("  按 Ctrl+C 退出");
    ws_log("=====================================================");

    // 初始化全局游标
    try {
        $GLOBALS['poll_latest'] = (int)(ws_db()->query("SELECT MAX(id) FROM messages")->fetchColumn() ?? 0);
    } catch (\Throwable $e) {}

    $nextPollTime = microtime(true);
    $nextTempPollTime = microtime(true);  // 闪传状态推送独立调度（2s）
    $lastCleanup = microtime(true);

    while (true) {
        $now = microtime(true);
        $timeoutSec = (1000000 - intval((microtime(true) * 1000000) % 1000000)) / 1000000; // 微调度

        // 构建 select 集合
        $read = [$server];
        foreach ($GLOBALS['clients'] as $cl) {
            if (is_resource($cl['sock'])) $read[] = $cl['sock'];
        }
        $write = null;
        $except = null;

        $tvSec = 1;
        $tvUsec = 0;
        @stream_select($read, $write, $except, $tvSec, $tvUsec);

        // ---- 新连接 ----
        if ($server && in_array($server, $read, true)) {
            $conn = @stream_socket_accept($server, 0);
            if ($conn) {
                // 读取握手请求（HTTP 头部）
                $request = '';
                $deadline = microtime(true) + 5;
                while (strpos($request, "\r\n\r\n") === false && microtime(true) < $deadline) {
                    $chunk = fread($conn, 4096);
                    if ($chunk === false || $chunk === '') break;
                    $request .= $chunk;
                    if (strlen($request) > 65536) break;
                }
                if (strpos($request, "\r\n\r\n") !== false) {
                    $auth = ws_handshake($conn, $request);
                    if ($auth) {
                        $GLOBALS['id_count']++;
                        $cid = $GLOBALS['id_count'];
                        $GLOBALS['clients'][$cid] = [
                            'sock' => $conn,
                            'user_id' => $auth['user_id'],
                            'username' => $auth['username'],
                            'l' => 0,
                            'glast' => 0,
                            'group_ids' => [],
                            'last_seen' => microtime(true),
                        ];
                        stream_set_blocking($conn, false);
                        ws_log("新连接 #$cid ({$auth['username']})");
                        // 广播好友上线
                        try {
                            ws_broadcast_presence(ws_db(), $auth['user_id'], $auth['username'], true);
                        } catch (\Throwable $e) {}
                        // 上线后立刻刷新一次（客户端游标初始为 0，会拉增量）
                        ws_refresh_client($cid);
                    }
                } else {
                    @fwrite($conn, "HTTP/1.1 400 Bad Request\r\n\r\nInvalid request");
                    @fclose($conn);
                }
            }
        }

        // ---- 客户端数据 ----
        foreach ($read as $sock) {
            if ($sock === $server) continue;
            // 找 cid
            $cid = null;
            foreach ($GLOBALS['clients'] as $k => $cl) {
                if ($cl['sock'] === $sock) { $cid = $k; break; }
            }
            if ($cid === null) continue;

            $frame = ws_decode_frame($sock);
            if ($frame === null) {
                ws_close_conn($cid, 1001);
                continue;
            }
            [$opcode, $payload] = $frame;

            if ($opcode === 0x8) { // close
                ws_close_conn($cid, 1000);
                continue;
            }
            if ($opcode === 0x9) { // ping
                @fwrite($sock, ws_encode_frame($payload, 0xA));
                continue;
            }
            if ($opcode === 0xA) { // pong
                continue;
            }
            if ($opcode === 0x1) { // text
                $data = json_decode($payload, true);
                if (is_array($data)) {
                    $type = $data['type'] ?? '';
                    switch ($type) {
                        case 'ping':
                            ws_handle_ping($cid, $data);
                            break;
                        case 'typing':
                            ws_handle_typing($cid, $data);
                            break;
                        case 'request':
                            ws_handle_request($cid, $data);
                            break;
                        case 'fetch_group':
                            // 客户端刚打开群聊时：把群游标对齐到当前群最新
                            $gid = (int)($data['group_id'] ?? 0);
                            $cl = $GLOBALS['clients'][$cid] ?? null;
                            if ($gid && $cl) {
                                try {
                                    $pdo = ws_db();
                                    $glast = (int)($pdo->query("SELECT MAX(id) FROM messages WHERE group_id = $gid")->fetchColumn() ?? 0);
                                    if ($glast > $cl['glast']) $GLOBALS['clients'][$cid]['glast'] = $glast;
                                } catch (\Throwable $e) {}
                            }
                            break;
                        default:
                            ws_log("未知消息类型: $type (from #{$GLOBALS['clients'][$cid]['username']})");
                    }
                }
            }
        }

        // ---- 消息轮询 ----
        $now = microtime(true);
        if ($now >= $nextPollTime) {
            try {
                ws_poll_messages();
            } catch (\Throwable $e) {
                ws_log("轮询异常: " . $e->getMessage());
            }
            $nextPollTime = microtime(true) + (WSS_POLL_MS / 1000);
        }

        // ---- 闪传状态轮询（2s，替代前端每 2s HTTP 轮询） ----
        if ($now >= $nextTempPollTime) {
            try {
                ws_poll_temp_status();
            } catch (\Throwable $e) {
                ws_log("闪传状态轮询异常: " . $e->getMessage());
            }
            $nextTempPollTime = microtime(true) + 2;
        }

        // ---- 心跳超时清理 ----
        if ($now - $lastCleanup >= 5) {
            foreach (array_keys($GLOBALS['clients']) as $cid) {
                $cl = $GLOBALS['clients'][$cid];
                if (($now - $cl['last_seen']) > WSS_HEARTBEAT_TIMEOUT_S) {
                    ws_log("心跳超时，断开 #$cid ({$cl['username']})");
                    ws_close_conn($cid, 1001);
                }
            }
            $lastCleanup = $now;
        }
    }
}

// 启动
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "WebSocket server must be run from CLI: php wss/wss_server.php";
    exit;
}
ws_main();