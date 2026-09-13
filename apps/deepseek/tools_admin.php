<?php
/**
 * ChatApp · DeepSeek AI Agent — 管理类工具处理器（apps/deepseek/tools_admin.php）
 *
 * 由 tools.php require；统一签名 fn(PDO $pdo, int $uid, string $username, array $args, array $userRow)。
 * 返回：成功 = 数据数组；业务失败 = ['ok'=>false,'error'=>'人话']（网关会抬成顶层 ok:false）。
 *
 * 权限铁律（与网页端同源）：
 *   - root = chatapp_get_role(uid)==='root'，实际只可能是 UID 10000 且用户名 admin。
 *   - 每个 root 工具在执行前再查一次（不依赖网关的 def 标记，防止将来改注册表时漏掉）。
 *   - 改用户/改配置全部写 chatapp_log_admin / security_logs 审计，和 admin.php 一致。
 */
require_once __DIR__ . '/../../api/incident_actions.php';
require_once __DIR__ . '/../../api/chat_actions.php';

/* =========================== 小工具 =========================== */

function ai_is_root(int $uid): bool { return chatapp_get_role($uid) === 'root'; }
function ai_is_admin(int $uid): bool {
    $r = chatapp_get_role($uid);
    return $r === 'root' || $r === 'admin';
}
/** root 专属工具的通用闸门：不是 root 一律拒绝（并给出明确话术） */
function ai_root_only(PDO $pdo, int $uid, string $username, string $tool): ?array {
    if (ai_is_root($uid)) return null;
    return ['ok' => false, 'error' => '这个工具仅站主（root）可用；当前账号不是 root。不要重试。'];
}

/* =========================== 关于（版本信息） =========================== */

function ai_tool_about(PDO $pdo, int $uid, string $username, array $a): array {
    $info = [];
    try { $info = include __DIR__ . '/../../config/info.php'; } catch (\Throwable $e) {}
    $ver = is_array($info) ? trim((string)($info['version'] ?? '')) : '';
    $build = is_array($info) ? trim((string)($info['build_date'] ?? '')) : '';
    return [
        'app' => 'ChatApp',
        'version' => $ver !== '' ? $ver : '未知',
        'build_date' => $build !== '' ? $build : '未知',
        'php_version' => PHP_VERSION,
        'server_time' => date('Y-m-d H:i:s'),
        'ticket_system' => chatapp_app_get('ai_ticket_enabled', '1') === '1' ? '开启' : '已关闭',
        'note' => '版本号 = git 短哈希，页面底部「Version xxx」与「设置 → 关于 ChatApp」显示的是同一个值',
    ];
}

/* =========================== 个人资料管理 =========================== */

function ai_tool_profile_data(PDO $pdo, int $uid, string $username, array $a): array {
    $method = (string)($a['method'] ?? 'list');
    if ($method === 'list') {
        $r = chat_action_my_content($pdo, $uid, [
            'type' => (string)($a['type'] ?? 'all'),
            'limit' => (int)($a['limit'] ?? 30),
            'offset' => 0,
        ]);
        if (empty($r['success'])) return ['ok' => false, 'error' => '读取失败'];
        $items = [];
        foreach (($r['items'] ?? []) as $it) {
            $items[] = [
                'id' => (int)$it['id'], 'kind' => $it['kind'] ?? '', 'name' => $it['name'] ?? '',
                'size' => $it['size'] ?? 0, 'time' => $it['time'] ?? '', 'url' => $it['url'] ?? '',
            ];
        }
        return ['count' => count($items), 'items' => $items,
                'note' => '这些是我自己发出去的图片/视频/文件；revoke 方法可以撤回（删除）其中某一条'];
    }
    if ($method === 'revoke') {
        $id = (int)($a['id'] ?? 0);
        if ($id <= 0) return ['ok' => false, 'error' => '需要参数 id（要撤回的消息 ID，先用 method=list 拿）'];
        $r = chat_action_revoke_own($pdo, $uid, ['message_id' => $id]);
        if (empty($r['success'])) return ['ok' => false, 'error' => '撤回失败：不是我的消息或消息不存在'];
        return ['revoked' => $id, 'note' => '已撤回（对所有人都不可见了）'];
    }
    return ['ok' => false, 'error' => 'method 只能是 list（列出）或 revoke（撤回）'];
}

/* =========================== 设置（不含 Account & Safety） =========================== */

/* 键 => [列名, 类型]。类型：bool | enum:值1|值2 | tz | date | text:长度 */
function ai_settings_writeable(): array {
    return [
        /* 个人资料（Edit Profile） */
        'display_name'      => ['display_name', 'text:256'],
        'custom_title'      => ['custom_title', 'text:100'],   // 个人签名
        'gender'            => ['gender', 'enum:,0,1'],
        'gender_privacy'    => ['gender_privacy', 'enum:0,1,2'],
        'birthday'          => ['birthday', 'date'],
        'space_ears'        => ['space_ears', 'bool'],
        'sig_privacy'       => ['sig_privacy', 'enum:0,1,2'],
        'sig_no_friend'     => ['sig_no_friend', 'bool'],
        'sig_hidden_text'   => ['sig_hidden_text', 'text:100'],
        /* 通用（General） */
        'language'          => ['preferred_language', 'enum:en,zh,zh_egg,wyw,raw'],
        'timezone'          => ['timezone', 'tz'],
        'data_saver'        => ['data_saver', 'bool'],
        'local_cache_enabled' => ['local_cache_enabled', 'bool'],
        'auto_focus_input'  => ['auto_focus_input', 'bool'],
        'emoji_panel_mode'  => ['emoji_panel_mode', 'enum:dynamic,hover,static'],
        'emoji_chat_mode'   => ['emoji_chat_mode', 'enum:dynamic,static'],
        /* 通知 */
        'notif_system'      => ['notif_system', 'bool'],
        'notif_banner'      => ['notif_banner', 'bool'],
        'dnd'               => ['dnd', 'bool'],                // 勿扰
        /* 隐私（Privacy） */
        'typing_visible'      => ['typing_visible', 'bool'],
        'stranger_invite_group' => ['stranger_invite_group', 'bool'],
        'stranger_like'       => ['stranger_like', 'bool'],
        'anyone_add_friend'   => ['anyone_add_friend', 'bool'],
        'searchable'          => ['searchable', 'bool'],
        'searchable_by_uid'   => ['searchable_by_uid', 'bool'],
        'send_read_receipt'   => ['send_read_receipt', 'bool'],
        'view_read_receipt'   => ['view_read_receipt', 'bool'],
    ];
}

/** 把值校验成要写库的标量；不合法返回 ['error'=>...] */
function ai_settings_cast(string $type, string $v) {
    if ($type === 'bool') {
        $s = strtolower(trim($v));
        if (in_array($s, ['1', 'on', 'true', 'yes', '开', '是'], true)) return 1;
        if (in_array($s, ['0', 'off', 'false', 'no', '关', '否', ''], true)) return 0;
        return ['error' => '布尔值请用 on/off（或 1/0）'];
    }
    if ($type === 'tz') {
        if (!preg_match('/^[+-]\d{2}:\d{2}$/', $v)) return ['error' => '时区格式是 +08:00 这种（±HH:MM）'];
        return $v;
    }
    if ($type === 'date') {
        if ($v === '') return null;   // 清空生日
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return ['error' => '生日格式 YYYY-MM-DD'];
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
        if ($y < 1900 || $y > (int)date('Y') || !checkdate($mo, $d, $y)) return ['error' => '这个日期不存在或超出范围（1900 ~ 今年）'];
        return $v;
    }
    if (strpos($type, 'enum:') === 0) {
        $vals = explode(',', substr($type, 5));
        if (!in_array($v, $vals, true)) return ['error' => '可选值：' . implode(' / ', array_map(function ($x) { return $x === '' ? '(空)' : $x; }, $vals))];
        return $v;
    }
    if (strpos($type, 'text:') === 0) {
        $n = (int)substr($type, 5);
        $v = trim($v);
        if (mb_strlen($v) > $n) return ['error' => "最多 $n 个字"];
        return $v === '' ? null : $v;
    }
    return ['error' => '不支持的设置类型'];
}

function ai_tool_settings(PDO $pdo, int $uid, string $username, array $a): array {
    $map = ai_settings_writeable();
    $method = (string)($a['method'] ?? 'get');

    if ($method === 'get') {
        $cols = [];
        foreach ($map as $k => $def) $cols[] = $def[0];
        $cols = array_unique($cols);
        $stmt = $pdo->prepare('SELECT ' . implode(', ', array_map(function ($c) { return "`$c`"; }, $cols)) . ' FROM users WHERE user_id = ?');
        $stmt->execute([$uid]);
        $row = $stmt->fetch() ?: [];
        $out = [];
        foreach ($map as $k => $def) {
            $raw = $row[$def[0]] ?? null;
            $out[$k] = ($def[1] === 'bool') ? (int)($raw ? 1 : 0) : (string)($raw ?? '');
        }
        return ['settings' => $out,
                'note' => '改一项：method=set, key=<键名>, value=<新值>。不含 Account & Safety（密码/注销/二重密码）——那些必须本人在网页上改。'];
    }

    if ($method === 'blocks' || $method === 'block' || $method === 'unblock') {
        if ($method === 'blocks') {
            $st = $pdo->prepare("SELECT b.blocked_uid, u.username FROM user_blocks b LEFT JOIN users u ON u.user_id = b.blocked_uid WHERE b.user_id = ? ORDER BY b.blocked_uid");
            $st->execute([$uid]);
            return ['blocks' => $st->fetchAll()];
        }
        $target = trim((string)($a['user'] ?? ''));
        if ($target === '') return ['ok' => false, 'error' => '需要参数 user（用户名或 UID）'];
        $st = $pdo->prepare('SELECT user_id, username FROM users WHERE username = ? AND deleted_at IS NULL');
        $st->execute([$target]);
        $t = $st->fetch();
        if (!$t && ctype_digit($target)) {
            $st = $pdo->prepare('SELECT user_id, username FROM users WHERE user_id = ? AND deleted_at IS NULL');
            $st->execute([(int)$target]);
            $t = $st->fetch();
        }
        if (!$t) return ['ok' => false, 'error' => '找不到这个用户'];
        $tuid = (int)$t['user_id'];
        if ($tuid === $uid) return ['ok' => false, 'error' => '不能拉黑自己'];
        if ($method === 'block') {
            $pdo->prepare('INSERT IGNORE INTO user_blocks (user_id, blocked_uid) VALUES (?, ?)')->execute([$uid, $tuid]);
            return ['blocked' => $t['username']];
        }
        $pdo->prepare('DELETE FROM user_blocks WHERE user_id = ? AND blocked_uid = ?')->execute([$uid, $tuid]);
        return ['unblocked' => $t['username']];
    }

    if ($method !== 'set') return ['ok' => false, 'error' => 'method 只能是 get / set / blocks / block / unblock'];

    $key = trim((string)($a['key'] ?? ''));
    if (!isset($map[$key])) {
        $keys = array_keys($map);
        return ['ok' => false, 'error' => '没有这个设置项。可用：' . implode('、', $keys)];
    }
    $val = ai_settings_cast($map[$key][1], (string)($a['value'] ?? ''));
    if (is_array($val)) return ['ok' => false, 'error' => (string)$val['error']];
    $col = $map[$key][0];
    $pdo->prepare("UPDATE users SET `$col` = ? WHERE user_id = ?")->execute([$val, $uid]);
    return ['set' => $key, 'value' => $val === null ? '' : $val];
}

/* =========================== 工单（提交 / root 管理） =========================== */

function ai_tool_ticket(PDO $pdo, int $uid, string $username, array $a): array {
    $method = (string)($a['method'] ?? 'list');
    $isRoot = ai_is_root($uid);
    $isAdmin = ai_is_admin($uid);

    /* ---------- 所有人：提交工单（必须给出像样的理由） ---------- */
    if ($method === 'submit') {
        $subject = trim((string)($a['subject'] ?? ''));
        $reason = trim((string)($a['reason'] ?? ''));
        if (mb_strlen($subject) < 2) return ['ok' => false, 'error' => '请先给工单起个标题（subject，至少 2 个字）'];
        if (mb_strlen($reason) < 10) return ['ok' => false, 'error' => '「必须合理理由」：reason 至少 10 个字，写清楚是什么问题/想要什么，管理员才好处理'];
        if (preg_match('/^(.)\1{9,}$/u', $reason)) return ['ok' => false, 'error' => 'reason 不能是重复字符（认真写一下）'];
        // 防刷：每人每小时最多 5 张
        $st = $pdo->prepare('SELECT COUNT(*) FROM incidents WHERE reporter_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() >= 5) return ['ok' => false, 'error' => '一小时最多提 5 张工单，请稍后再来'];
        $r = incident_action_create($pdo, $uid, [
            'type' => (string)($a['type'] ?? 'bug'),
            'subject' => $subject,
            'reason' => $reason,
            'priority' => (string)($a['priority'] ?? 'normal'),
        ]);
        if (empty($r['success'])) return ['ok' => false, 'error' => (string)($r['error'] ?? '提交失败')];
        return ['id' => (int)$r['id'], 'note' => '工单已提交，管理员处理后会在单子里回复；用户可以在网页「支持与Bug反馈」面板里看到'];
    }

    /* ---------- 以下全是 root 专属：管理全部工单 + 总开关 ---------- */
    $denied = ai_root_only($pdo, $uid, $username, 'ca_ticket');
    if ($denied) return $denied;

    if ($method === 'list') {
        $r = incident_action_list($pdo, $uid, true, [
            'status' => (string)($a['status'] ?? 'all'),
            'page' => 1,
            'per_page' => (int)($a['limit'] ?? 10),
            'search' => (string)($a['q'] ?? ''),
        ]);
        $rows = [];
        foreach (($r['incidents'] ?? []) as $t) {
            $rows[] = [
                'id' => (int)$t['id'], 'type' => $t['type'], 'status' => $t['status'], 'priority' => $t['priority'],
                'subject' => ai_slim($t['subject'], 80), 'reporter' => $t['reporter_name'],
                'created_at' => $t['created_at'],
                'reason' => ai_slim((string)($t['reason'] ?? ''), 200),
            ];
        }
        return ['total' => (int)($r['total'] ?? 0), 'tickets' => $rows];
    }

    if ($method === 'detail') {
        $id = (int)($a['id'] ?? 0);
        if ($id <= 0) return ['ok' => false, 'error' => '需要参数 id（工单号，先用 method=list 拿）'];
        $r = incident_action_detail($pdo, $uid, true, $id);
        if (empty($r['success'])) return ['ok' => false, 'error' => (string)($r['error'] ?? '读不到')];
        $t = $r['incident'];
        $responses = [];
        foreach (($t['responses'] ?? []) as $rp) {
            $responses[] = ['by' => $rp['username'], 'staff' => (int)$rp['is_staff'] === 1, 'message' => ai_slim($rp['message'], 400), 'at' => $rp['created_at']];
        }
        $out = [
            'id' => (int)$t['id'], 'type' => $t['type'], 'status' => $t['status'], 'priority' => $t['priority'],
            'subject' => $t['subject'], 'reporter' => $t['reporter_name'], 'created_at' => $t['created_at'],
            'reason' => ai_slim((string)($t['reason'] ?? ''), 1500),
            'images' => $t['images'] ? json_decode((string)$t['images'], true) : [],
            'responses' => $responses,
        ];
        if (($t['type'] ?? '') === 'report' && !empty($t['reported_messages'])) {
            $msgs = [];
            foreach ($t['reported_messages'] as $m) {
                $msgs[] = ['id' => (int)$m['id'], 'from' => $m['sender_name'], 'at' => $m['datetime'],
                           'revoked' => !empty($m['is_revoked']), 'message' => ai_slim((string)$m['message'], 300)];
            }
            $out['reported_messages'] = $msgs;
        }
        return $out;
    }

    if ($method === 'respond') {
        $id = (int)($a['id'] ?? 0);
        $message = trim((string)($a['message'] ?? ''));
        if ($id <= 0 || $message === '') return ['ok' => false, 'error' => '需要参数 id 和 message'];
        $r = incident_action_respond($pdo, $uid, true, $id, $message);
        if (empty($r['success'])) return ['ok' => false, 'error' => (string)($r['error'] ?? '回复失败')];
        return ['replied' => $id, 'note' => '已作为「工作人员回复」写入工单'];
    }

    if ($method === 'update_status') {
        $id = (int)($a['id'] ?? 0);
        if ($id <= 0) return ['ok' => false, 'error' => '需要参数 id'];
        $status = (string)($a['status'] ?? '');
        if ($status === 'none') $status = '';
        $priority = (string)($a['priority'] ?? '');
        if ($priority === 'none') $priority = '';
        $r = incident_action_update_status($pdo, true, $id, $status !== '' ? $status : null, $priority !== '' ? $priority : null);
        if (empty($r['success'])) return ['ok' => false, 'error' => (string)($r['error'] ?? '改状态失败')];
        return ['id' => $id, 'status' => $status !== '' ? $status : '(未改)', 'exp_awarded' => !empty($r['exp_awarded']),
                'note' => '切到 resolved 会自动给提交者发 EXP（只发一次）'];
    }

    if ($method === 'switch') {
        $on = (string)($a['on'] ?? 'on') === 'on' ? '1' : '0';
        if (!chatapp_app_set('ai_ticket_enabled', $on)) return ['ok' => false, 'error' => '写入失败'];
        chatapp_log_admin('ai_ticket_switch', null, null, ['enabled' => $on === '1']);
        return ['ticket_system' => $on === '1' ? '开启' : '已关闭',
                'note' => $on === '1' ? '现在所有人都能提交工单了' : '已关闭：普通用户提交会被拒绝（root 仍然可以建单）'];
    }

    if ($method === 'count') {
        $r = incident_action_count($pdo, $uid, $isAdmin);
        return $r;
    }

    return ['ok' => false, 'error' => 'method 只能是 submit / list / detail / respond / update_status / switch / count'];
}

/* =========================== 日志（admin+） =========================== */

function ai_tool_logs(PDO $pdo, int $uid, string $username, array $a): array {
    if (!ai_is_admin($uid)) return ['ok' => false, 'error' => '只有站主/管理员可以看日志'];
    $kind = (string)($a['kind'] ?? 'admin');
    if ($kind === 'security' && !ai_is_root($uid)) return ['ok' => false, 'error' => '安全日志（security_logs）仅站主（root）可看'];
    $q = trim((string)($a['q'] ?? ''));
    $page = max(1, (int)($a['page'] ?? 1));
    $per = max(1, min(50, (int)($a['limit'] ?? 15)));
    $offset = ($page - 1) * $per;

    if ($kind === 'login') {
        $where = 'WHERE 1=1'; $params = [];
        if ($q !== '') { $where .= ' AND (username LIKE ? OR user_id = ?)'; $params[] = "%$q%"; $params[] = (int)$q; }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM login_logs $where"); $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $st = $pdo->prepare("SELECT id,user_id,username,success,ip_address,created_at FROM login_logs $where ORDER BY id DESC LIMIT $per OFFSET $offset");
        $st->execute($params);
        return ['total' => $total, 'page' => $page, 'logs' => $st->fetchAll()];
    }
    if ($kind === 'exp') {
        $where = "WHERE type NOT IN ('msg','receive')"; $params = [];
        if ($q !== '') { $where .= ' AND (user_id = ? OR type = ? OR detail LIKE ?)'; $params[] = (int)$q; $params[] = $q; $params[] = "%$q%"; }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM exp_log $where"); $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $st = $pdo->prepare("SELECT l.id, l.user_id, COALESCE(u.username, '') AS username, l.exp, l.type, l.detail, l.created_at
            FROM exp_log l LEFT JOIN users u ON u.user_id = l.user_id $where ORDER BY l.id DESC LIMIT $per OFFSET $offset");
        $st->execute($params);
        return ['total' => $total, 'page' => $page, 'logs' => $st->fetchAll()];
    }
    if ($kind === 'security') {
        $where = 'WHERE 1=1'; $params = [];
        if ($q !== '') { $where .= ' AND (event_type LIKE ? OR ip_address LIKE ? OR target_path LIKE ? OR details LIKE ?)'; $params = array_fill(0, 4, "%$q%"); }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM security_logs $where"); $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $st = $pdo->prepare("SELECT id,event_type,ip_address,target_path,details,created_at FROM security_logs $where ORDER BY id DESC LIMIT $per OFFSET $offset");
        $st->execute($params);
        return ['total' => $total, 'page' => $page, 'logs' => $st->fetchAll()];
    }
    /* admin（默认） */
    $where = 'WHERE 1=1'; $params = [];
    if ($q !== '') { $where .= ' AND (admin_username LIKE ? OR action LIKE ? OR target_username LIKE ? OR details LIKE ?)'; $params = array_fill(0, 4, "%$q%"); }
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_logs $where"); $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $st = $pdo->prepare("SELECT id,admin_username,action,target_uid,target_username,details,ip_address,created_at FROM admin_logs $where ORDER BY id DESC LIMIT $per OFFSET $offset");
    $st->execute($params);
    return ['total' => $total, 'page' => $page, 'logs' => $st->fetchAll()];
}

/* =========================== 读数据库（root，只读） =========================== */

function ai_tool_db(PDO $pdo, int $uid, string $username, array $a): array {
    $sql = trim((string)($a['sql'] ?? ''));
    if ($sql === '') return ['ok' => false, 'error' => '需要参数 sql'];
    // 只允许单条、且必须以只读关键字开头
    $sql = rtrim($sql, "; \t\n\r");
    if (strpos($sql, ';') !== false) return ['ok' => false, 'error' => '只允许单条语句（不要分号、不要多语句）'];
    if (!preg_match('/^\s*(SELECT|SHOW|DESC|DESCRIBE|EXPLAIN)\b/i', $sql)) {
        return ['ok' => false, 'error' => '只允许只读查询：SELECT / SHOW / DESCRIBE / EXPLAIN'];
    }
    /* 只读语句里的危险动作：写文件、锁表、拖时间的函数 —— 一律拒绝 */
    foreach (['INTO OUTFILE', 'INTO DUMPFILE', 'FOR UPDATE', 'LOCK IN SHARE MODE', 'LOAD_FILE', 'SLEEP(', 'BENCHMARK(', 'GET_LOCK(', 'RELEASE_LOCK('] as $bad) {
        if (stripos($sql, $bad) !== false) return ['ok' => false, 'error' => "禁止：$bad"];
    }
    $limit = max(1, min(300, (int)($a['limit'] ?? 50)));
    $hasLimit = (bool)preg_match('/\bLIMIT\s+\d+/i', $sql);
    try {
        // 单条 SELECT 的最大执行时间（毫秒）；SHOW/DESCRIBE 不受它影响但也没什么风险
        try { $pdo->exec('SET SESSION max_execution_time = 8000'); } catch (\Throwable $e) {}
        $st = $pdo->query($hasLimit ? $sql : ($sql . ' LIMIT ' . ($limit + 1)));
        $rows = [];
        $cols = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            if (!$cols) $cols = array_keys($r);
            foreach ($r as $k => $v) { if (is_string($v) && mb_strlen($v) > 300) $r[$k] = ai_slim($v, 300); }
            $rows[] = $r;
            if (count($rows) > 500) break;
        }
        $truncated = count($rows) > $limit;
        if ($truncated) $rows = array_slice($rows, 0, $limit);
        return ['columns' => $cols ?: [], 'row_count' => count($rows), 'truncated' => $truncated, 'rows' => $rows,
                'note' => '只读查询；没写 LIMIT 会自动加 ' . $limit . ' 行上限'];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'SQL 出错：' . mb_substr($e->getMessage(), 0, 300)];
    }
}

/* =========================== WebSocket 配置（root） =========================== */

function ai_tool_wss(PDO $pdo, int $uid, string $username, array $a): array {
    $denied = ai_root_only($pdo, $uid, $username, 'ca_wss');
    if ($denied) return $denied;
    $method = (string)($a['method'] ?? 'get');
    if ($method === 'get') {
        $cfg = chatapp_wss_config();
        return ['local' => $cfg['local'], 'private' => $cfg['private'], 'public' => $cfg['public'],
                'urls' => ['local' => chatapp_wss_url($cfg['local']), 'private' => chatapp_wss_url($cfg['private']), 'public' => chatapp_wss_url($cfg['public'])],
                'note' => '网页「WebSocket Settings」面板（root）改的是同一份配置（config/wss_server.php）'];
    }
    if ($method === 'set') {
        $cur = chatapp_wss_config();
        $new = [
            'local' => trim((string)($a['local'] ?? '')) !== '' ? trim((string)$a['local']) : $cur['local'],
            'private' => trim((string)($a['private'] ?? '')) !== '' ? trim((string)$a['private']) : $cur['private'],
            'public' => trim((string)($a['public'] ?? '')) !== '' ? trim((string)$a['public']) : $cur['public'],
        ];
        $err = chatapp_wss_save($new);
        if ($err !== null) return ['ok' => false, 'error' => $err];
        chatapp_log_admin('ai_wss_set', null, null, $new);
        return ['saved' => true] + $new + ['note' => '已写入 config/wss_server.php；前端刷新页面后生效'];
    }
    return ['ok' => false, 'error' => 'method 只能是 get 或 set'];
}

/* =========================== 用户管理（root；镜像 admin.php 的「All Users」） =========================== */

function ai_tool_usermgmt(PDO $pdo, int $uid, string $username, array $a): array {
    $denied = ai_root_only($pdo, $uid, $username, 'ca_usermgmt');
    if ($denied) return $denied;

    $method = (string)($a['method'] ?? 'list');
    $target = trim((string)($a['username'] ?? ''));
    $uid_of = function (string $u) use ($pdo): int {
        $st = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $st->execute([$u]);
        return (int)($st->fetchColumn() ?: 0);
    };

    if ($method === 'list') {
        $search = trim((string)($a['q'] ?? ''));
        $regex = (int)($a['regex'] ?? 0) === 1;
        $deleted = (int)($a['deleted'] ?? 0) === 1;
        $page = max(1, (int)($a['page'] ?? 1));
        $per = 15; $offset = ($page - 1) * $per;
        $sortMap = ['user_id' => 'user_id', 'username' => 'username', 'enabled' => 'enabled', 'last_login' => 'last_login', 'created_at' => 'created_at'];
        $sort = $sortMap[(string)($a['sort'] ?? 'user_id')] ?? 'user_id';
        $dir = strtolower((string)($a['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $where = $deleted ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
        $params = [];
        if ($search !== '') {
            $where .= $regex ? ' AND username REGEXP ?' : ' AND username LIKE ?';
            $params[] = $regex ? $search : "%$search%";
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $st = $pdo->prepare("SELECT user_id,username,display_name,enabled,placeholder,restricted,role,created_at,last_login FROM users WHERE $where ORDER BY $sort $dir LIMIT $per OFFSET $offset");
        $st->execute($params);
        return ['total' => $total, 'page' => $page, 'per_page' => $per, 'users' => $st->fetchAll()];
    }

    if ($method === 'detail') {
        if ($target === '') return ['ok' => false, 'error' => '需要参数 username'];
        $st = $pdo->prepare("SELECT username,display_name,user_id,enabled,restricted,restricted_reason,placeholder,dnd,role,created_at,last_login,exp,level,failed_attempts,locked_until FROM users WHERE username = ?");
        $st->execute([$target]);
        $t = $st->fetch();
        if (!$t) return ['ok' => false, 'error' => '找不到这个用户'];
        $t['status_label'] = !empty($t['placeholder']) ? 'Placeholder' : (!empty($t['restricted']) ? 'Restricted' : (!empty($t['enabled']) ? 'Enabled' : 'Disabled'));
        $t['locked'] = ($t['locked_until'] && strtotime((string)$t['locked_until']) > time());
        if ((int)$t['user_id'] === 10000) $t['role'] = 'root';
        return $t;
    }

    if ($method === 'roles') {
        $st = $pdo->query('SELECT role_name, permissions, editable FROM role_defs ORDER BY role_name');
        $rows = $st->fetchAll();
        foreach ($rows as &$r) { $r['editable'] = (int)$r['editable']; }
        return ['roles' => $rows];
    }

    if ($method === 'add_user' || $method === 'add_placeholder') {
        $newName = trim((string)($a['new_username'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $newName)) return ['ok' => false, 'error' => '用户名要 3-20 位字母/数字/下划线'];
        $lang = (string)($a['language'] ?? 'en');
        if (!in_array($lang, ['en', 'zh', 'zh_egg', 'wyw', 'raw'], true)) $lang = 'en';
        try {
            if ($method === 'add_placeholder') {
                $pdo->prepare("INSERT INTO users (username, password, enabled, placeholder, role, preferred_language, created_at) VALUES (?, ?, 0, 1, 'user', 'en', NOW())")
                    ->execute([$newName, password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT)]);
            } else {
                $pw = (string)($a['new_password'] ?? '');
                $err = chatapp_validate_password($pw);
                if ($err) return ['ok' => false, 'error' => t($err)];
                $pdo->prepare("INSERT INTO users (username, password, preferred_language, role, created_at) VALUES (?, ?, ?, 'user', NOW())")
                    ->execute([$newName, password_hash($pw, PASSWORD_BCRYPT), $lang]);
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => '创建失败（用户名可能已存在）'];
        }
        chatapp_log_admin($method, (int)$pdo->lastInsertId(), $newName);
        return ['created' => $newName, 'note' => $method === 'add_placeholder' ? '占位号：不能登录，可被改名/接管' : '新用户仅角色 user'];
    }

    /* ---------- 以下都需要目标用户 ---------- */
    if ($target === '') return ['ok' => false, 'error' => '需要参数 username'];
    $tuid = $uid_of($target);
    if ($tuid <= 0) return ['ok' => false, 'error' => '找不到这个用户'];
    $targetRole = chatapp_get_role($tuid);
    /* 和 admin.php 一致：改别的 admin 需要 root —— 本工具本来就是 root 专属，这里只是双保险 */
    if (($targetRole === 'admin' || $targetRole === 'root') && !ai_is_root($uid)) {
        return ['ok' => false, 'error' => '改管理员需要 root'];
    }
    $protect10000 = ['toggle', 'delete', 'delete_permanently', 'change_username', 'change_display_name', 'toggle_dnd', 'change_status',
                     'set_restrict_reason', 'clear_duress', 'expire_tokens', 'set_role', 'change_password'];
    if (in_array($method, $protect10000, true) && $tuid === 10000) return ['ok' => false, 'error' => '不能动 UID 10000（站主）'];
    if (in_array($method, ['toggle', 'delete', 'delete_permanently', 'set_role', 'toggle_dnd', 'clear_duress'], true) && $tuid === $uid) {
        return ['ok' => false, 'error' => '不能对自己做这个操作'];
    }

    switch ($method) {
        case 'toggle': {
            $st = $pdo->prepare('SELECT enabled FROM users WHERE user_id = ?');
            $st->execute([$tuid]);
            $newState = ((int)$st->fetchColumn()) ? 0 : 1;
            $pdo->prepare('UPDATE users SET enabled = ?, token_reset = IF(? = 0, NOW(), token_reset) WHERE user_id = ?')
                ->execute([$newState, $newState, $tuid]);
            chatapp_log_admin('toggle', $tuid, $target, ['enabled' => (bool)$newState]);
            return ['username' => $target, 'enabled' => $newState];
        }
        case 'delete': {
            $pdo->prepare("UPDATE users SET username = CONCAT('deleted_', user_id), enabled = 0, placeholder = 1, deleted_at = NOW() WHERE user_id = ?")->execute([$tuid]);
            chatapp_log_admin('delete', $tuid, $target);
            return ['username' => $target, 'deleted' => true, 'note' => '软删除（可恢复）；彻底删除用 delete_permanently'];
        }
        case 'delete_permanently': {
            chatapp_destroy_user($tuid, $target, false);
            chatapp_log_admin('delete_permanently', $tuid, $target);
            return ['username' => $target, 'destroyed' => true];
        }
        case 'change_password': {
            $pw = (string)($a['new_password'] ?? '');
            $err = chatapp_validate_password($pw);
            if ($err) return ['ok' => false, 'error' => t($err)];
            $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?')->execute([password_hash($pw, PASSWORD_BCRYPT), $tuid]);
            chatapp_log_admin('change_password', $tuid, $target);
            return ['username' => $target, 'password_changed' => true];
        }
        case 'change_username': {
            $newName = trim((string)($a['new_username'] ?? ''));
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $newName)) return ['ok' => false, 'error' => '新用户名要 3-20 位字母/数字/下划线'];
            $st = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
            $st->execute([$newName]);
            if ($st->fetchColumn()) return ['ok' => false, 'error' => '这个用户名已被占用'];
            $pdo->prepare('UPDATE users SET username = ? WHERE user_id = ?')->execute([$newName, $tuid]);
            chatapp_log_admin('change_username', $tuid, $target, ['new' => $newName]);
            return ['from' => $target, 'to' => $newName];
        }
        case 'change_display_name': {
            $dn = trim((string)($a['display_name'] ?? ''));
            $dn = $dn === '' ? null : mb_substr($dn, 0, 256);
            $pdo->prepare('UPDATE users SET display_name = ? WHERE user_id = ?')->execute([$dn, $tuid]);
            chatapp_log_admin('change_display_name', $tuid, $target, ['new' => $dn]);
            return ['username' => $target, 'display_name' => $dn];
        }
        case 'toggle_dnd': {
            $pdo->prepare('UPDATE users SET dnd = NOT dnd WHERE user_id = ?')->execute([$tuid]);
            chatapp_log_admin('toggle_dnd', $tuid, $target);
            return ['username' => $target, 'dnd_toggled' => true];
        }
        case 'change_status': {
            $status = (string)($a['status'] ?? '');
            if (!in_array($status, ['enabled', 'disabled', 'restricted', 'placeholder'], true)) {
                return ['ok' => false, 'error' => 'status 只能是 enabled / disabled / restricted / placeholder'];
            }
            $pdo->prepare('UPDATE users SET enabled = ?, restricted = ?, placeholder = ? WHERE user_id = ?')
                ->execute([($status === 'enabled' || $status === 'restricted') ? 1 : 0, $status === 'restricted' ? 1 : 0, $status === 'placeholder' ? 1 : 0, $tuid]);
            if ($status === 'placeholder') {
                $pdo->prepare('UPDATE users SET password = ? WHERE user_id = ?')->execute([password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), $tuid]);
            }
            chatapp_log_admin('change_status', $tuid, $target, ['new' => $status]);
            return ['username' => $target, 'status' => $status];
        }
        case 'unlock': {
            $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?')->execute([$tuid]);
            chatapp_log_admin('unlock_account', $tuid, $target, []);
            return ['username' => $target, 'unlocked' => true];
        }
        case 'set_restrict_reason': {
            $reason = trim(mb_substr((string)($a['reason'] ?? ''), 0, 1000));
            $pdo->prepare('UPDATE users SET restricted_reason = ? WHERE user_id = ?')->execute([$reason ?: null, $tuid]);
            chatapp_log_admin('set_restrict_reason', $tuid, $target, ['reason' => $reason]);
            return ['username' => $target, 'restricted_reason' => $reason];
        }
        case 'adjust_level': {
            $lv = (int)($a['level'] ?? 0);
            if ($lv < 1 || $lv > 100) return ['ok' => false, 'error' => 'level 必须是 1-100'];
            $pdo->prepare('UPDATE users SET level = ? WHERE user_id = ?')->execute([$lv, $tuid]);
            chatapp_log_admin('adjust_level', $tuid, $target, ['new_level' => $lv]);
            return ['username' => $target, 'level' => $lv];
        }
        case 'adjust_exp': {
            $exp = max(0, (int)($a['exp'] ?? 0));
            $pdo->prepare('UPDATE users SET exp = ? WHERE user_id = ?')->execute([$exp, $tuid]);
            chatapp_log_admin('adjust_exp', $tuid, $target, ['new_exp' => $exp]);
            return ['username' => $target, 'exp' => $exp];
        }
        case 'reset_exp': {
            $pdo->prepare('UPDATE users SET exp = 0 WHERE user_id = ?')->execute([$tuid]);
            chatapp_log_admin('reset_exp', $tuid, $target);
            return ['username' => $target, 'exp' => 0];
        }
        case 'expire_tokens': {
            $pdo->prepare('UPDATE users SET token_reset = NOW() WHERE user_id = ?')->execute([$tuid]);
            $pdo->prepare('DELETE FROM ws_tokens WHERE username = ?')->execute([$target]);
            chatapp_log_admin('expire_tokens', $tuid, $target);
            return ['username' => $target, 'tokens_expired' => true, 'note' => '该用户所有已登录会话会在下一次请求时失效'];
        }
        case 'clear_duress': {
            $pdo->prepare('UPDATE users SET duress_password = NULL WHERE user_id = ?')->execute([$tuid]);
            chatapp_log_admin('clear_duress', $tuid, $target);
            return ['username' => $target, 'duress_cleared' => true];
        }
        case 'set_role': {
            $role = (string)($a['role'] ?? '');
            if (!in_array($role, ['admin', 'user'], true)) return ['ok' => false, 'error' => 'role 只能是 admin / user'];
            $pdo->prepare('UPDATE users SET role = ? WHERE user_id = ?')->execute([$role, $tuid]);
            chatapp_log_admin('set_role', $tuid, $target, ['role' => $role]);
            return ['username' => $target, 'role' => $role];
        }
    }
    return ['ok' => false, 'error' => '不支持的 method。可用：list / detail / roles / add_user / add_placeholder / toggle / delete / delete_permanently / change_password / change_username / change_display_name / toggle_dnd / change_status / unlock / set_restrict_reason / adjust_level / adjust_exp / reset_exp / expire_tokens / clear_duress / set_role'];
}
