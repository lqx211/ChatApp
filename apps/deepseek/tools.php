<?php
/**
 * ChatApp · DeepSeek AI Agent — 服务端工具网关（apps/deepseek/tools.php）
 *
 * 前端只能「请求」执行工具，真正的权限判断全部在这里做完（前端开关只是收窄，永远不作数）：
 *   - 必须已登录（chatapp_require_login）；账号被封禁/限制的一律拒绝
 *   - 账号级权限是一个**档位** users.ai_level（本人自选，默认 0）：
 *       0 off   关闭：只允许本地工具 + 公共数据（排行榜/表情搜索）
 *       1 read  仅读：+ 我的资料/等级/好友/群/在线/工单/会话/聊天记录
 *       2 write 读写：+ 代我发私聊（额外限流）
 *       3 all   允许所有：+ 管理员工具（仍需管理员身份）与以后新增的工具
 *   - 工具白名单 + 每个工具的 scope/min_level：
 *       self   任何登录用户，但只能读「自己」的数据（SQL 里 hard-code 自己的 uid）
 *       public 公开数据（排行榜等）
 *       write 写操作：需要档位 ≥ 2，额外限流
 *       admin 仅站主/管理员（chatapp_get_role ∈ root|admin）且档位 = 3
 *   - 参数逐项校验（类型/长度/枚举），全部走预处理语句，不拼接 SQL
 *   - 频率限制：全部工具 40 次/分钟；写操作 3 次/5 分钟
 *   - 每次调用写入 ai_tool_logs（审计 + 限流依据）
 *
 * 请求：POST JSON {action:'run'|'prefs'|'set_prefs', tool?, args?, prefs:{ai_level}}
 * 响应：JSON {ok:true,data:{...}} | {ok:false,error:'...'}
 */
require_once __DIR__ . '/../../api/config.php';
require_once __DIR__ . '/../../api/chat_actions.php';
require_once __DIR__ . '/../../api/contact_actions.php';   // 联系人写操作（与网页同一份）
require_once __DIR__ . '/../../api/group_actions.php';     // 群操作（与网页同一份）
require_once __DIR__ . '/../../api/report_actions.php';    // 举报（与网页同一份）
require_once __DIR__ . '/../../api/space_read.php';        // 空间读取层（隐私过滤与空间页同一份）
require_once __DIR__ . '/../../config/lvconfig.php';
require_once __DIR__ . '/../../maintenance.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function ai_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function ai_err(string $msg, array $extra = []): void { ai_out(array_merge(['ok' => false, 'error' => $msg], $extra)); }
function ai_slim($s, int $n): string { $s = (string)$s; return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s; }
/* 写审计：成功的执行、被拒的尝试、限流命中都记一笔（被拒也算进限流基数，防提示注入刷接口） */
function ai_log(PDO $pdo, int $uid, string $username, string $tool, bool $ok, int $ms = 0, $summary = ''): void {
    try {
        if (!is_string($summary)) $summary = json_encode($summary, JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO ai_tool_logs (user_id, username, tool, ok, ms, args_summary) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$uid, $username, mb_substr((string)$tool, 0, 40), $ok ? 1 : 0, $ms, ai_slim((string)$summary, 255)]);
    } catch (\Throwable $e) { /* 审计失败不影响主流程 */ }
}

$raw = file_get_contents('php://input');
// CLI 自测钩子：Web 请求永远不会命中（PHP_SAPI 不可能是 cli）
if ($raw === '' && PHP_SAPI === 'cli' && isset($GLOBALS['AI_CLI_INPUT'])) $raw = (string)$GLOBALS['AI_CLI_INPUT'];
$in = json_decode((string)$raw, true);
if (!is_array($in)) ai_err('请求格式错误');
$action = (string)($in['action'] ?? 'run');

/* 未登录：
   - action=run → 401（真的被拒绝了）
   - 其他（prefs 等状态查询）→ 200 + need_login，避免未登录访客控制台报 401 噪声
   注意不要用 chatapp_require_login（它会 302 到 login.php，fetch 侧只会看到「HTTP 302」） */
chatapp_session_start();
if (!isset($_SESSION['username'])) {
    if ($action === 'run') http_response_code(401);
    ai_out(['ok' => false, 'error' => '未登录 ChatApp，请先登录再使用工具', 'need_login' => true, 'logged_in' => false]);
}

$pdo = db();
$me = (string)($_SESSION['username'] ?? '');

// ---- 惰性补列 / 建表（幂等；与本仓库既有做法一致）----
// 旧的布尔开关保留为「投影」（由 ai_level 推导），供历史代码/日志兼容
$aiLevelExisted = false;
try { $r0 = $pdo->query("SHOW COLUMNS FROM users LIKE 'ai_level'"); $aiLevelExisted = ($r0 && $r0->fetch() !== false); } catch (\Throwable $e) {}
db_add_column_if_missing('users', 'ai_level', "TINYINT NOT NULL DEFAULT 0");
db_add_column_if_missing('users', 'ai_send_dm', "TINYINT(1) NOT NULL DEFAULT 0");
db_add_column_if_missing('users', 'ai_read_chats', "TINYINT(1) NOT NULL DEFAULT 0");
if (!$aiLevelExisted) {
    // 首次上线新列：从旧的两个开关推导初始档位（只做一次，绝不覆盖用户后来的选择）
    try {
        $pdo->exec("UPDATE users SET ai_level = CASE WHEN ai_send_dm = 1 THEN 2 WHEN ai_read_chats = 1 THEN 1 ELSE 0 END");
    } catch (\Throwable $e) {}
}
$pdo->exec("CREATE TABLE IF NOT EXISTS ai_tool_logs (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    username VARCHAR(64) NOT NULL,
    tool VARCHAR(40) NOT NULL,
    ok TINYINT(1) NOT NULL DEFAULT 1,
    ms INT NOT NULL DEFAULT 0,
    args_summary VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ai_log_user (user_id, created_at),
    KEY idx_ai_log_tool (tool, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $pdo->prepare('SELECT user_id, username, enabled, restricted, deleted_at, ai_send_dm, ai_read_chats, ai_level FROM users WHERE username = ?');
$stmt->execute([$me]);
$u = $stmt->fetch();
if (!$u || !empty($u['deleted_at'])) ai_err('账号状态异常，请重新登录');
$myUid = (int)$u['user_id'];
$myUsername = (string)$u['username'];
if ((int)$u['enabled'] === 0) ai_err('账号已被禁用');
$role = chatapp_get_role($myUid);
$isAdmin = ($role === 'root' || $role === 'admin');

/* 账号级 AI 权限档位：0 关闭 / 1 仅读 / 2 读写 / 3 允许所有 */
$AI_LEVEL_NAMES = [0 => '关闭', 1 => '仅读', 2 => '读写', 3 => '允许所有'];
$aiLevel = (int)($u['ai_level'] ?? 0);
if ($aiLevel < 0) $aiLevel = 0;
if ($aiLevel > 3) $aiLevel = 3;

$action = (string)($in['action'] ?? 'run');

/* ============================ 偏好（账号级权限档位） ============================ */
if ($action === 'prefs') {
    ai_out(['ok' => true, 'data' => [
        'username' => $myUsername,
        'uid' => $myUid,
        'is_admin' => $isAdmin,
        'role' => $role,
        'ai_level' => $aiLevel,
        'ai_level_name' => $AI_LEVEL_NAMES[$aiLevel],
        // 兼容旧字段（旧客户端 / 历史日志）：由档位推导
        'ai_read_chats' => $aiLevel >= 1 ? 1 : 0,
        'ai_send_dm' => $aiLevel >= 2 ? 1 : 0,
    ]]);
}

if ($action === 'set_prefs') {
    $p = is_array($in['prefs'] ?? null) ? $in['prefs'] : [];
    if (!array_key_exists('ai_level', $p)) ai_err('没有可设置的项');
    $lvl = (int)$p['ai_level'];
    if ($lvl < 0) $lvl = 0;
    if ($lvl > 3) $lvl = 3;
    // 只能改自己这一行（uid 来自 session，不接受任何客户端传入）
    $pdo->prepare('UPDATE users SET ai_level = ?, ai_read_chats = ?, ai_send_dm = ? WHERE user_id = ?')
        ->execute([$lvl, $lvl >= 1 ? 1 : 0, $lvl >= 2 ? 1 : 0, $myUid]);
    chatapp_log('security_logs', [
        'event_type' => 'ai_pref_change',
        'target_path' => mb_substr($myUsername, 0, 500),
        'details' => json_encode(['ai_level' => $lvl, 'name' => $AI_LEVEL_NAMES[$lvl]], JSON_UNESCAPED_UNICODE),
    ]);
    ai_out(['ok' => true, 'data' => [
        'ai_level' => $lvl,
        'ai_read_chats' => $lvl >= 1 ? 1 : 0,
        'ai_send_dm' => $lvl >= 2 ? 1 : 0,
    ]]);
}

/* ============================ 工具注册表（白名单） ============================
   每个参数用显式类型描述：str(min/max) | int(min/max/default) | enum(values) | strlist(max)
   未列出的参数一律丢弃（客户端多传也没用）。 */
$TOOL_DEFS = [
    /* min_level：0 关闭即可用（公共数据）/ 1 仅读 / 2 读写 / 3 允许所有 */
    'ca_profile' => ['scope' => 'self', 'min_level' => 1, 'args' => [], 'fn' => 'ai_tool_profile'],
    'ca_level' => ['scope' => 'self', 'min_level' => 1, 'args' => [], 'fn' => 'ai_tool_level'],
    'ca_leaderboard' => ['scope' => 'public', 'min_level' => 0, 'args' => [
        'limit' => ['type' => 'int', 'min' => 1, 'max' => 50, 'default' => 10],
    ], 'fn' => 'ai_tool_leaderboard'],
    'ca_find_user' => ['scope' => 'self', 'min_level' => 1, 'args' => [
        'q' => ['type' => 'str', 'min' => 1, 'max' => 64],
    ], 'fn' => 'ai_tool_find_user'],
    'ca_conversations' => ['scope' => 'self', 'min_level' => 1, 'args' => [
        'limit' => ['type' => 'int', 'min' => 1, 'max' => 30, 'default' => 10],
    ], 'fn' => 'ai_tool_conversations'],
    'ca_groups' => ['scope' => 'self', 'min_level' => 1, 'args' => [
        'limit' => ['type' => 'int', 'min' => 1, 'max' => 30, 'default' => 10],
    ], 'fn' => 'ai_tool_groups'],
    'ca_online' => ['scope' => 'self', 'min_level' => 1, 'args' => [
        'users' => ['type' => 'strlist', 'max' => 20],
    ], 'fn' => 'ai_tool_online'],
    'ca_tickets' => ['scope' => 'self', 'min_level' => 1, 'args' => [
        'status' => ['type' => 'enum', 'values' => ['open', 'closed', 'all'], 'default' => 'open'],
        'limit' => ['type' => 'int', 'min' => 1, 'max' => 30, 'default' => 10],
    ], 'fn' => 'ai_tool_tickets'],
    'ca_send_dm' => ['scope' => 'write', 'min_level' => 2, 'write_quota' => 3, 'args' => [
        'to' => ['type' => 'str', 'min' => 1, 'max' => 64],
        'text' => ['type' => 'str', 'min' => 1, 'max' => 500],
    ], 'fn' => 'ai_tool_send_dm'],
    /* 读聊天内容：档位 ≥ 1（仅读）即可 */
    'ca_history' => ['scope' => 'self', 'min_level' => 1, 'args' => [
        'with' => ['type' => 'str', 'min' => 0, 'max' => 64],
        'group' => ['type' => 'str', 'min' => 0, 'max' => 64],
        'keyword' => ['type' => 'str', 'min' => 0, 'max' => 64],
        'limit' => ['type' => 'int', 'min' => 1, 'max' => 50, 'default' => 20],
        'before_id' => ['type' => 'int', 'min' => 0, 'max' => 2147483647, 'default' => 0],
    ], 'fn' => 'ai_tool_history'],
    'ca_message' => ['scope' => 'self', 'min_level' => 1, 'args' => [
        'id' => ['type' => 'int', 'min' => 1, 'max' => 2147483647],
    ], 'fn' => 'ai_tool_message'],
    /* 别人的个人主页（说说 / 留言板）—— 隐私过滤走 api/space_read.php，与空间页同一份规则 */
    'ca_space' => ['scope' => 'self', 'min_level' => 1, 'args' => [
        'user' => ['type' => 'str', 'min' => 1, 'max' => 64],
        'kind' => ['type' => 'enum', 'values' => ['feeds', 'messages', 'both'], 'default' => 'both'],
        'limit' => ['type' => 'int', 'min' => 1, 'max' => 30, 'default' => 10],
        'with_images' => ['type' => 'int', 'min' => 0, 'max' => 1, 'default' => 0],
    ], 'fn' => 'ai_tool_space'],
    /* 搜我自己的聊天记录（全局 / 某人 / 某群）—— 复用 chat_action_search_messages */
    'ca_search_messages' => ['scope' => 'self', 'min_level' => 1, 'args' => [
        'q' => ['type' => 'str', 'min' => 2, 'max' => 64],
        'with' => ['type' => 'str', 'min' => 0, 'max' => 64],
        'group' => ['type' => 'str', 'min' => 0, 'max' => 64],
        'limit' => ['type' => 'int', 'min' => 1, 'max' => 30, 'default' => 10],
    ], 'fn' => 'ai_tool_search_messages'],
    /* ---------------- 会真正改动数据的操作（需要「读写」档位，每个还有 5 分钟限流） ---------------- */
    'ca_group_create' => ['scope' => 'write', 'min_level' => 2, 'args' => [
        'name' => ['type' => 'str', 'min' => 1, 'max' => 50],
    ], 'fn' => 'ai_tool_group_create'],
    'ca_group_join' => ['scope' => 'write', 'min_level' => 2, 'args' => [
        'group' => ['type' => 'str', 'min' => 1, 'max' => 64],
    ], 'fn' => 'ai_tool_group_join'],
    'ca_contact_add' => ['scope' => 'write', 'min_level' => 2, 'args' => [
        'to' => ['type' => 'str', 'min' => 1, 'max' => 64],
        'msg' => ['type' => 'str', 'min' => 0, 'max' => 200],
    ], 'fn' => 'ai_tool_contact_add'],
    'ca_contact_remove' => ['scope' => 'write', 'min_level' => 2, 'args' => [
        'to' => ['type' => 'str', 'min' => 1, 'max' => 64],
    ], 'fn' => 'ai_tool_contact_remove'],
    'ca_pin' => ['scope' => 'write', 'min_level' => 2, 'args' => [
        'kind' => ['type' => 'enum', 'values' => ['contact', 'group'], 'default' => 'contact'],
        'target' => ['type' => 'str', 'min' => 1, 'max' => 64],
        'on' => ['type' => 'enum', 'values' => ['on', 'off', 'toggle'], 'default' => 'toggle'],
    ], 'fn' => 'ai_tool_pin'],
    'ca_special_care' => ['scope' => 'write', 'min_level' => 2, 'args' => [
        'user' => ['type' => 'str', 'min' => 1, 'max' => 64],
        'on' => ['type' => 'enum', 'values' => ['on', 'off', 'toggle'], 'default' => 'toggle'],
    ], 'fn' => 'ai_tool_special_care'],
    'ca_report_user' => ['scope' => 'write', 'min_level' => 2, 'args' => [
        'to' => ['type' => 'str', 'min' => 1, 'max' => 64],
        'reason' => ['type' => 'str', 'min' => 2, 'max' => 500],
    ], 'fn' => 'ai_tool_report_user'],
    /* 内置表情包搜索（公开数据） */
    'ca_emoji' => ['scope' => 'public', 'min_level' => 0, 'args' => [
        'q' => ['type' => 'str', 'min' => 0, 'max' => 32],
        'limit' => ['type' => 'int', 'min' => 1, 'max' => 40, 'default' => 12],
    ], 'fn' => 'ai_tool_emoji'],
    'ca_admin_stats' => ['scope' => 'admin', 'min_level' => 3, 'args' => [], 'fn' => 'ai_tool_admin_stats'],
];
/* 旧名字（历史对话里可能还在用）→ 新名字 */
$TOOL_ALIAS = [
    'my_profile' => 'ca_profile', 'my_level' => 'ca_level', 'leaderboard' => 'ca_leaderboard',
    'find_user' => 'ca_find_user', 'my_conversations' => 'ca_conversations', 'my_groups' => 'ca_groups',
    'check_online' => 'ca_online', 'my_tickets' => 'ca_tickets', 'get_time' => 'now',
];

if ($action !== 'run') ai_err('未知操作');
$tool = trim((string)($in['tool'] ?? ''));
if (isset($TOOL_ALIAS[$tool])) $tool = $TOOL_ALIAS[$tool];
if (!isset($TOOL_DEFS[$tool])) ai_err('这个工具不存在或不允许调用', ['tool' => $tool]);
$def = $TOOL_DEFS[$tool];
$args = is_array($in['args'] ?? null) ? $in['args'] : [];
$argsSummary = ai_slim(json_encode($args, JSON_UNESCAPED_UNICODE), 255);

/* ---------------------------- 限流 ---------------------------- */
/* 先限流再判权限：被拒的尝试也走这里、也会被计入（防提示注入狂刷） */
$st = $pdo->prepare('SELECT COUNT(*) FROM ai_tool_logs WHERE user_id = ? AND created_at > (NOW() - INTERVAL 60 SECOND)');
$st->execute([$myUid]);
if ((int)$st->fetchColumn() >= 40) {
    ai_log($pdo, $myUid, $myUsername, $tool, false, 0, ['deny' => 'rate', 'args' => $argsSummary]);
    ai_err('工具调用太频繁（每分钟最多 40 次），休息一下再来');
}

/* ---------------------------- 权限闸门 ---------------------------- */
if ($def['scope'] === 'admin' && !$isAdmin) {
    ai_log($pdo, $myUid, $myUsername, $tool, false, 0, ['deny' => 'not_admin', 'args' => $argsSummary]);
    ai_err('只有站主/管理员可以使用这个工具');
}
$need = (int)($def['min_level'] ?? 0);
if ($aiLevel < $need) {
    ai_log($pdo, $myUid, $myUsername, $tool, false, 0, ['deny' => 'level=' . $aiLevel . '<' . $need, 'args' => $argsSummary]);
    if ($need >= 3) {
        ai_err('这个工具需要「允许所有」档位：请让用户到 Deepseek 页的「设置 → AI 权限」里选择（当前：' . $AI_LEVEL_NAMES[$aiLevel] . '）');
    } elseif ($need >= 2) {
        ai_err('这是写操作，需要「读写」或更高的 AI 权限档位（当前：' . $AI_LEVEL_NAMES[$aiLevel] . '）。不要重试，告诉用户去「设置 → AI 权限」里调高即可');
    } else {
        ai_err('读取用户的 ChatApp 数据需要先开启 AI 权限档位（现在是「关闭」）。告诉用户去「设置 → AI 权限」选「仅读」或更高即可，不要重试');
    }
}

/* ---------------------------- 参数校验 ---------------------------- */
$clean = [];
foreach ($def['args'] as $k => $spec) {
    $type = $spec['type'] ?? 'str';
    $v = $args[$k] ?? null;

    if ($type === 'strlist') {
        if (is_string($v)) {
            $decoded = json_decode($v, true);
            $v = is_array($decoded) ? $decoded : preg_split('/[,，\s]+/u', $v);
        }
        if (!is_array($v)) ai_err("参数 $k 需要是字符串数组");
        $list = [];
        foreach ($v as $item) {
            $s = trim((string)$item);
            if ($s !== '') $list[] = mb_substr($s, 0, 64);
            if (count($list) >= (int)($spec['max'] ?? 20)) break;
        }
        $clean[$k] = $list;
        continue;
    }
    if ($type === 'int') {
        if ($v === null || $v === '') {
            if (!isset($spec['default'])) ai_err("缺少参数 $k");
            $n = (int)$spec['default'];
        } else {
            if (!is_numeric($v)) ai_err("参数 $k 必须是数字");
            $n = (int)$v;
        }
        if (isset($spec['min']) && $n < $spec['min']) $n = (int)$spec['min'];
        if (isset($spec['max']) && $n > $spec['max']) $n = (int)$spec['max'];
        $clean[$k] = $n;
        continue;
    }
    if ($type === 'enum') {
        $v = (string)($v === null ? ($spec['default'] ?? '') : $v);
        if (!in_array($v, $spec['values'], true)) ai_err("参数 $k 只能是：" . implode(' / ', $spec['values']));
        $clean[$k] = $v;
        continue;
    }
    /* str */
    $v = trim((string)($v === null ? '' : $v));
    if ($v === '' && isset($spec['default'])) { $clean[$k] = (string)$spec['default']; continue; }
    if (mb_strlen($v) < (int)($spec['min'] ?? 1)) ai_err("缺少参数 $k");
    if (isset($spec['max']) && mb_strlen($v) > (int)$spec['max']) $v = mb_substr($v, 0, (int)$spec['max']);
    $clean[$k] = $v;
}

/* ---------------------------- 写操作限流 ---------------------------- */
if ($def['scope'] === 'write') {
    // 每个写操作独立额度（默认 5 次/5 分钟；发消息保持最严的 3 条）
    $quota = (int)($def['write_quota'] ?? 5);
    $st = $pdo->prepare('SELECT COUNT(*) FROM ai_tool_logs WHERE user_id = ? AND tool = ? AND ok = 1 AND created_at > (NOW() - INTERVAL 300 SECOND)');
    $st->execute([$myUid, $tool]);
    if ((int)$st->fetchColumn() >= $quota) {
        ai_log($pdo, $myUid, $myUsername, $tool, false, 0, ['deny' => 'write_rate', 'args' => $argsSummary]);
        ai_err('写操作限流：5 分钟内最多 ' . $quota . ' 次，请稍后再试');
    }
}

/* ---------------------------- 执行 + 审计 ---------------------------- */
$t0 = microtime(true);
$ok = true;
$errMsg = '';
try {
    $data = call_user_func($def['fn'], $pdo, $myUid, $myUsername, $clean, $u);
} catch (\Throwable $e) {
    $ok = false;
    $errMsg = '执行失败：' . mb_substr($e->getMessage(), 0, 200);
    $data = null;
}
/* 工具内部把「业务失败」表达成 ['ok'=>false,'error'=>...]（比如查不到人、不是好友）——
   这里统一抬成顶层 ok:false，模型一眼能看出失败，不会被 data.ok=false 绕进去。 */
if ($ok && is_array($data) && isset($data['ok']) && $data['ok'] === false) {
    $ok = false;
    $errMsg = (string)($data['error'] ?? '执行失败');
    $data = null;
}
if ($ok && is_array($data) && array_key_exists('ok', $data)) unset($data['ok'], $data['error']);
$ms = (int)round((microtime(true) - $t0) * 1000);
ai_log($pdo, $myUid, $myUsername, $tool, $ok, $ms, $ok ? $clean : ['error' => $errMsg, 'args' => $argsSummary]);

if ($ok) ai_out(['ok' => true, 'data' => $data, '_ms' => $ms]);
ai_out(['ok' => false, 'error' => $errMsg !== '' ? $errMsg : '执行失败', '_ms' => $ms]);

/* ============================== 各工具实现 ============================== */
/* 约定：只能读「$uid 自己」的数据；不接受任何"读别人"的参数。 */

function ai_tool_profile(PDO $pdo, int $uid): array {
    $st = $pdo->prepare('SELECT username, display_name, gender, birthday, timezone, custom_title, level, exp,
                                searchable, searchable_by_uid, dnd, restricted, preferred_language, created_at, last_login
                         FROM users WHERE user_id = ?');
    $st->execute([$uid]);
    $r = $st->fetch() ?: [];
    return [
        'username' => $r['username'] ?? null,
        'uid' => $uid,
        'display_name' => ($r['display_name'] ?? '') !== '' ? $r['display_name'] : ($r['username'] ?? null),
        'gender' => ($r['gender'] === '1') ? '男' : (($r['gender'] === '0') ? '女' : '未设置'),
        'birthday' => $r['birthday'] ?: null,
        'timezone' => $r['timezone'] ?: null,
        'custom_title' => $r['custom_title'] ?: null,
        'level' => (int)($r['level'] ?? 1),
        'exp' => (int)($r['exp'] ?? 0),
        'discoverable' => (bool)(int)($r['searchable'] ?? 0),
        'searchable_by_uid' => (bool)(int)($r['searchable_by_uid'] ?? 0),
        'dnd' => (bool)(int)($r['dnd'] ?? 0),
        'restricted' => (bool)(int)($r['restricted'] ?? 0),
        'language' => $r['preferred_language'] ?: null,
        'registered_at' => $r['created_at'] ?: null,
        'last_login' => $r['last_login'] ?: null,
    ];
}

function ai_tool_level(PDO $pdo, int $uid, string $username): array {
    $st = $pdo->prepare('SELECT exp, level, last_sign_date, sign_streak FROM users WHERE user_id = ?');
    $st->execute([$uid]);
    $r = $st->fetch() ?: [];
    $exp = (int)($r['exp'] ?? 0);
    $manual = max(1, min(100, (int)($r['level'] ?? 1)));
    $maxLv = min(100, (int)level_info($exp)['level']);
    $cur = $manual === 1 ? 0 : level_cumulative($manual - 2);
    $need = max(0, level_cumulative($manual - 1) - $cur);
    $progress = max(0, $exp - $cur);
    $today = gmdate('Y-m-d', time() + 8 * 3600);
    $signedToday = (($r['last_sign_date'] ?? null) === $today);
    return [
        'username' => $username,
        'level' => $manual,
        'max_level' => $maxLv,
        'can_upgrade' => $manual < $maxLv,
        'exp' => $exp,
        'upgrade_progress_percent' => $need > 0 ? min(100, round(100 * $progress / $need, 1)) : 100,
        'signed_today' => $signedToday,
        'sign_streak' => (int)($r['sign_streak'] ?? 0),
        'limits' => level_limits($manual),
    ];
}

function ai_tool_leaderboard(PDO $pdo, int $uid, string $username, array $a): array {
    $n = max(1, min(50, (int)($a['limit'] ?? 10)));
    $rows = $pdo->query("SELECT user_id, username, display_name, exp, level FROM users
                         WHERE deleted_at IS NULL AND placeholder = 0 AND is_bot = 0
                         ORDER BY level DESC, exp DESC, user_id ASC LIMIT 50")->fetchAll();
    $list = [];
    $rank = 1;
    foreach ($rows as $r) {
        if (count($list) >= $n) break;
        $list[] = [
            'rank' => $rank++,
            'username' => $r['username'],
            'display_name' => ($r['display_name'] ?? '') !== '' ? $r['display_name'] : $r['username'],
            'level' => max(1, min(100, (int)($r['level'] ?? 1))),
            'exp' => (int)$r['exp'],
        ];
    }
    return ['count' => count($list), 'list' => $list];
}

function ai_tool_find_user(PDO $pdo, int $uid, string $username, array $a): array {
    $q = (string)$a['q'];
    if (is_numeric($q)) {
        $st = $pdo->prepare('SELECT username, user_id FROM users WHERE user_id = ? AND username != ? AND searchable = 1 AND searchable_by_uid = 1 AND is_bot = 0 LIMIT 1');
        $st->execute([(int)$q, $username]);
    } else {
        $st = $pdo->prepare('SELECT username, user_id FROM users WHERE username = ? AND username != ? AND searchable = 1 AND is_bot = 0 LIMIT 1');
        $st->execute([$q, $username]);
    }
    $users = [];
    foreach ($st->fetchAll() as $r) {
        $tid = (int)$r['user_id'];
        $rel = $pdo->prepare('SELECT status FROM contacts WHERE (user_from = ? AND user_to = ?) OR (user_from = ? AND user_to = ?)');
        $rel->execute([$uid, $tid, $tid, $uid]);
        $users[] = ['username' => $r['username'], 'uid' => $tid, 'relation' => ($rel->fetchColumn() ?: '无好友关系')];
    }
    return [
        'query' => $q,
        'found' => count($users),
        'users' => $users,
        'hint' => $users ? null : '精确匹配查不到：对方可能不存在、或关闭了「可被搜索」',
    ];
}

function ai_tool_conversations(PDO $pdo, int $uid, string $username, array $a, array $u): array {
    $n = max(1, min(30, (int)($a['limit'] ?? 10)));
    $res = chat_action_conversations($pdo, $uid);   // 复用既有逻辑（自带权限边界：只查自己参与的会话）
    $rows = array_slice($res['conversations'] ?? [], 0, $n);
    // 这个工具本身已要求「仅读」档位，所以消息预览直接给
    $preview = true;
    $out = [];
    foreach ($rows as $c) {
        $o = ['username' => $c['username'], 'display_name' => $c['display_name'] ?: $c['username'],
              'last_time' => $c['last_datetime'] ?: $c['last_time'], 'unread' => (int)$c['unread']];
        if ($preview) $o['last_message_preview'] = ai_slim((string)$c['last_message'], 60);
        $out[] = $o;
    }
    return ['count' => count($out), 'conversations' => $out,
            'note' => $preview ? null : '账号未开启「允许 AI 读取会话摘要」，故不含消息内容'];
}

function ai_tool_groups(PDO $pdo, int $uid, string $username, array $a): array {
    $n = max(1, min(30, (int)($a['limit'] ?? 10)));
    $st = $pdo->prepare('SELECT g.group_id, g.name, gm.role, gm.muted, gm.pinned, g.public
                         FROM `groups` g JOIN group_members gm ON gm.group_id = g.group_id
                         WHERE gm.user_id = ? ORDER BY gm.pinned DESC, g.created_at DESC LIMIT ' . $n);
    $st->execute([$uid]);
    $out = [];
    foreach ($st->fetchAll() as $g) {
        $out[] = ['gid' => (int)$g['group_id'], 'name' => $g['name'], 'role' => $g['role'],
                  'muted' => (bool)(int)$g['muted'], 'pinned' => (bool)(int)$g['pinned'], 'public' => (bool)(int)$g['public']];
    }
    return ['count' => count($out), 'groups' => $out];
}

function ai_tool_online(PDO $pdo, int $uid, string $username, array $a): array {
    $users = array_slice(array_unique(array_map('strval', (array)($a['users'] ?? []))), 0, 20);
    if (!$users) return ['checked' => [], 'online' => [], 'offline' => []];
    $ph = implode(',', array_fill(0, count($users), '?'));
    $st = $pdo->prepare("SELECT username, last_ping, dnd, typing_to FROM users WHERE username IN ($ph) AND deleted_at IS NULL");
    $st->execute($users);
    $online = $dnd = $typing = [];
    foreach ($st->fetchAll() as $r) {
        $fresh = !empty($r['last_ping']) && (time() - strtotime($r['last_ping']) <= 15);
        $online[] = $r['username'];
        if ((int)$r['dnd'] === 1) $dnd[] = $r['username'];
        if (!empty($r['typing_to']) && (time() - strtotime($r['last_ping'] ?? '') <= 15)) $typing[] = $r['username'];
    }
    return ['checked' => $users, 'online' => $online,
            'offline' => array_values(array_diff($users, $online)), 'dnd' => $dnd, 'typing' => $typing];
}

function ai_tool_tickets(PDO $pdo, int $uid, string $username, array $a): array {
    $status = (string)($a['status'] ?? 'open');
    $n = max(1, min(30, (int)($a['limit'] ?? 10)));
    // 非管理员只看自己提交的、且不是「举报」类型（与 incident.php 的规则一致）
    $where = "WHERE reporter_id = ? AND type != 'report'";
    $params = [$uid];
    if ($status === 'open') $where .= " AND status IN ('open','in_progress')";
    elseif ($status === 'closed') $where .= " AND status IN ('resolved','closed')";
    $st = $pdo->prepare("SELECT id, subject, type, priority, status, created_at FROM incidents $where ORDER BY created_at DESC LIMIT " . $n);
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll() as $t) {
        $rows[] = ['id' => (int)$t['id'], 'subject' => $t['subject'], 'type' => $t['type'],
                   'priority' => $t['priority'], 'status' => $t['status'], 'created_at' => $t['created_at']];
    }
    return ['status' => $status, 'count' => count($rows), 'tickets' => $rows];
}

function ai_tool_send_dm(PDO $pdo, int $uid, string $username, array $a): array {
    $to = (string)$a['to'];
    $text = (string)$a['text'];
    if ($to === $username) return ['ok' => false, 'error' => '不能给自己发私聊'];
    // 与网页发送完全同一条管线：好友关系、黑名单、账号限制、XSS 校验、EXP 等全部照旧
    $res = chat_action_send($pdo, $uid, $username, [
        'message' => $text,
        'message_raw' => $text,
        'recipient' => $to,
        'client_msg_id' => 'ai-' . bin2hex(random_bytes(8)),
    ], true);
    if (!empty($res['error'])) {
        $map = [
            'not_friends' => '对方不是你的好友，不能发私聊',
            'blocked' => '对方已把你拉黑',
            'restricted' => '你的账号处于受限状态，不能发送消息',
            'Too long' => '消息太长',
            'Empty' => '内容为空',
            'Unsupported content' => '内容包含不允许的标记',
        ];
        return ['ok' => false, 'error' => $map[$res['error']] ?? ('发送失败：' . $res['error'])];
    }
    return ['ok' => !empty($res['success']), 'to' => $to, 'message_id' => $res['message_id'] ?? null,
            'sent_text' => ai_slim($text, 200), 'sent_at' => date('Y-m-d H:i:s')];
}

/* ---------------- 聊天记录（需 ai_read_chats；只能读自己参与的对话） ---------------- */
function ai_msg_row(array $r, int $uid): array {
    $type = (string)($r['msg_type'] ?? '');
    $out = [
        'id' => (int)$r['id'],
        'from' => (string)($r['sender_name'] ?? '?'),
        'me' => ((int)$r['sender_id'] === $uid),
        'time' => (string)($r['datetime'] ?? ''),
    ];
    if (!empty($r['deleted_at'])) {
        $out['text'] = '[已撤回]';
        $out['revoked'] = true;
    } elseif ($type === 'e2ee') {
        $out['text'] = '[端到端加密消息，AI 读不到内容]';
    } elseif ($type === 'chatlog') {
        $out['text'] = '[聊天记录卡片]';
    } else {
        $txt = html_entity_decode((string)$r['message'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = preg_replace('/\[emoji:[a-f0-9]{32}\]/', '[自定义表情]', $txt);
        $out['text'] = ai_slim(strip_tags($txt), 400);
    }
    if ($type !== '' && $type !== 'e2ee') $out['type'] = $type;
    if (!empty($r['attachment'])) {
        $out['attachment'] = $type === 'photo' ? '图片/视频' : ($type === 'audio' ? '语音' : ($type === 'file' ? '文件' : '附件'));
    }
    if (!empty($r['reply_to'])) $out['reply_to'] = (int)$r['reply_to'];
    if (!empty($r['group_id'])) $out['group_gid'] = (int)$r['group_id'];
    return $out;
}

function ai_tool_history(PDO $pdo, int $uid, string $username, array $a): array {
    $with = trim((string)($a['with'] ?? ''));
    $group = trim((string)($a['group'] ?? ''));
    $kw = trim((string)($a['keyword'] ?? ''));
    $limit = max(1, min(50, (int)($a['limit'] ?? 20)));
    $before = (int)($a['before_id'] ?? 0);
    if ($with === '' && $group === '') return ['ok' => false, 'error' => '需要给出 with（对方用户名）或 group（群名 / GID）'];

    $where = ['m.deleted_at IS NULL'];
    $params = [];
    $scope = '';
    if ($with !== '') {
        $st = $pdo->prepare('SELECT user_id FROM users WHERE username = ? AND deleted_at IS NULL');
        $st->execute([$with]);
        $peer = (int)($st->fetchColumn() ?: 0);
        if ($peer <= 0) return ['ok' => false, 'error' => '找不到用户 ' . $with];
        $where[] = 'm.group_id IS NULL';
        $where[] = '((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))';
        array_push($params, $uid, $peer, $peer, $uid);
        $scope = '与 ' . $with . ' 的私聊';
    } else {
        // 只能读自己所在群的记录（子查询强制成员身份）
        $gst = $pdo->prepare('SELECT g.group_id, g.name FROM `groups` g
                              WHERE (g.group_id = ? OR g.name = ?)
                                AND g.group_id IN (SELECT group_id FROM group_members WHERE user_id = ?) LIMIT 1');
        $gst->execute([(int)$group, $group, $uid]);
        $grow = $gst->fetch();
        if (!$grow) return ['ok' => false, 'error' => '找不到你有权限的群：' . $group];
        $where[] = 'm.group_id = ?';
        $params[] = (int)$grow['group_id'];
        $scope = '群聊「' . $grow['name'] . '」(GID ' . (int)$grow['group_id'] . ')';
    }
    if ($before > 0) { $where[] = 'm.id < ?'; $params[] = $before; }
    if ($kw !== '') { $where[] = 'm.message LIKE ?'; $params[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%'; }

    $sql = 'SELECT m.id, m.sender_id, m.recipient_id, m.group_id, m.message, m.msg_type, m.attachment,
                   m.datetime, m.deleted_at, m.reply_to, u.username AS sender_name
            FROM messages m JOIN users u ON u.user_id = m.sender_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY m.id DESC LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $items = [];
    foreach ($st->fetchAll() as $r) $items[] = ai_msg_row($r, $uid);
    return [
        'scope' => $scope,
        'count' => count($items),
        'order' => '按时间倒序（最新在前）',
        'messages' => $items,
        'next_before_id' => (count($items) === $limit && $items) ? $items[count($items) - 1]['id'] : null,
        'hint' => $items ? '要继续往前翻就再调用一次并把 before_id 设为 next_before_id' : '这段对话里没有匹配的消息',
    ];
}

function ai_tool_message(PDO $pdo, int $uid, string $username, array $a): array {
    $id = (int)$a['id'];
    $st = $pdo->prepare('SELECT m.*, u.username AS sender_name FROM messages m JOIN users u ON u.user_id = m.sender_id WHERE m.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return ['ok' => false, 'error' => '消息不存在（或已被删除）'];
    $allowed = ((int)$r['sender_id'] === $uid) || ((int)$r['recipient_id'] === $uid);
    if (!$allowed && !empty($r['group_id'])) {
        $gs = $pdo->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND user_id = ?');
        $gs->execute([(int)$r['group_id'], $uid]);
        $allowed = (int)$gs->fetchColumn() > 0;
    }
    if (!$allowed) return ['ok' => false, 'error' => '这条消息不在你参与的对话里（权限不足）'];
    return ['message' => ai_msg_row($r, $uid), 'hint' => '需要上下文可以用 ca_history'];
}

/* ---------------- 内置表情包搜索 ---------------- */
function ai_tool_emoji(PDO $pdo, int $uid, string $username, array $a): array {
    $q = trim((string)($a['q'] ?? ''));
    $limit = max(1, min(40, (int)($a['limit'] ?? 12)));
    $path = __DIR__ . '/../../data/res/emoji/default_config.json';
    if (!is_file($path)) return ['ok' => false, 'error' => '服务端没有表情库配置'];
    $raw = json_decode((string)file_get_contents($path), true);
    $out = [];
    foreach (($raw['normalPanelResult']['SysEmojiGroupList'] ?? []) as $g) {
        $gname = (string)($g['groupName'] ?? '');
        foreach (($g['SysEmojiList'] ?? []) as $e) {
            if (!empty($e['isHide'])) continue;
            $code = (string)($e['describe'] ?? '');
            if ($code === '') continue;
            if ($q !== '' && mb_stripos($code, $q) === false && mb_stripos($gname, $q) === false) continue;
            $out[] = ['code' => $code, 'group' => $gname];
            if (count($out) >= $limit) break 2;
        }
    }
    return [
        'query' => $q, 'count' => count($out), 'emojis' => $out,
        'usage' => '把 code 直接写进回复里（例如「今天好累 /流泪」）就会渲染成表情图片；一次别用太多',
    ];
}

function ai_tool_admin_stats(PDO $pdo): array {
    $one = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
    return [
        'users_total' => $one('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_bot = 0'),
        'users_placeholder' => $one('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND placeholder = 1'),
        'users_bots' => $one('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND is_bot = 1'),
        'users_online_15s' => $one('SELECT COUNT(*) FROM users WHERE last_ping > (NOW() - INTERVAL 15 SECOND)'),
        'messages_total' => $one('SELECT COUNT(*) FROM messages'),
        'messages_today' => $one('SELECT COUNT(*) FROM messages WHERE datetime >= CURDATE()'),
        'groups_total' => $one('SELECT COUNT(*) FROM `groups`'),
        'tickets_open' => $one("SELECT COUNT(*) FROM incidents WHERE status IN ('open','in_progress')"),
        'as_of' => date('Y-m-d H:i:s'),
    ];
}

/* ==================== 别人的个人主页（说说 / 留言板） ====================
   「读别人数据」是全站最敏感的事，所以这里**一行 SQL 都没有**：
   全部走 api/space_read.php —— 与个人空间页同一个函数、同一套可见性过滤
   （仅自己/好友/部分可见/部分不可见/置顶的朋友/特别关心朋友 0–6 级）。
   过滤掉的内容 AI 根本看不到，也不会知道存在。 */
function ai_tool_space(PDO $pdo, int $uid, string $username, array $a): array {
    $target = trim((string)$a['user']);
    $tuid = space_find_uid($pdo, $target);
    if ($tuid <= 0) return ['ok' => false, 'error' => '找不到用户「' . $target . '」（用户名要写完整，或被对方注销了）'];
    $kind = (string)($a['kind'] ?? 'both');
    $limit = max(1, min(30, (int)($a['limit'] ?? 10)));
    $withImages = ((int)($a['with_images'] ?? 0)) === 1;
    $card = space_user_card($pdo, $tuid);
    $out = [
        'user' => ['uid' => $tuid, 'username' => $card['username'], 'name' => $card['name']],
        'is_self' => ($tuid === $uid),
        'privacy' => '结果已按对方隐私设置过滤：看不到的说说不会出现在这里，也不会提示“有几条被隐藏”',
    ];
    $feedsForImages = [];
    if ($kind === 'feeds' || $kind === 'both') {
        $feeds = space_read_feeds($pdo, $uid, $tuid, $limit);
        $out['feeds'] = array_map(function ($f) {
            return [
                'time' => $f['time'],
                'text' => ai_slim(preg_replace('/\s+/u', ' ', strip_tags((string)$f['content'])), 300),
                'images' => is_array($f['images']) ? count($f['images']) : 0,
                'likes' => (int)$f['likes'],
                'edited' => $f['edited'],
            ];
        }, $feeds);
        $out['feeds_count'] = count($out['feeds']);
        $feedsForImages = $feeds;
    }
    /* 图片：同样只取「已经过隐私过滤」的那些说说里的图，且只允许站内两种路径形态。
       真正的字节下载走 api/file.php（它会再查一次空间可见性），所以即使 AI 拿到 URL
       也拿不到没权限看的图。前端还会上一次「是否允许读图」的确认。 */
    if ($withImages) {
        if ($kind === 'messages') $feedsForImages = space_read_feeds($pdo, $uid, $tuid, 30);
        $imgs = [];
        foreach ($feedsForImages as $f) {
            foreach ((array)($f['images'] ?? []) as $u) {
                $url = ai_space_img_url((string)$u, $tuid);
                if ($url === null) continue;
                $imgs[] = ['url' => $url, 'from' => $card['name'], 'time' => $f['time'], 'post_id' => (int)$f['id']];
                if (count($imgs) >= 6) break 2;
            }
        }
        $out['images'] = $imgs;
        $out['images_count'] = count($imgs);
        $out['images_note'] = $imgs
            ? '这些 URL 已经过隐私过滤；每个 URL 下载时 api/file.php 会再验一次可见性。前端会先问用户是否允许读图，允许后图片才会真的交给我看。'
            : '（能看到的说说里没有配图，或者对方把图那几条设置成你看不到的可见性了）';
    }
    if ($kind === 'messages' || $kind === 'both') {
        $res = space_read_messages($pdo, $uid, $tuid, 500);
        $msgs = array_slice($res['messages'], -1 * $limit);
        $out['guestbook'] = array_map(function ($m) {
            return [
                'from' => $m['card']['name'] ?? ('用户' . $m['user_id']),
                'mine' => !empty($m['mine']),
                'time' => $m['time'],
                'text' => ai_slim(strip_tags((string)$m['content']), 200),
            ];
        }, $msgs);
        $out['guestbook_count'] = count($out['guestbook']);
        $out['guestbook_note'] = '留言板是公开内容（给主人留言），按时间正序取最近几条';
    }
    return $out;
}

/**
 * 说说里的图片地址 → 站内根相对 URL（只允许空间图片与内置资源两种形态，其它一律丢弃）。
 * 入库时存的是 ../../api/file.php?u=<uid>&f=space/<file> 或 ../../data/res/...
 */
function ai_space_img_url(string $raw, int $ownerUid): ?string {
    $u = trim($raw);
    if ($u === '') return null;
    if (preg_match('#^\.\./\.\./api/file\.php\?u=' . $ownerUid . '&f=space/[A-Za-z0-9_.\-]+$#', $u)) {
        return '/' . substr($u, 6);                       // 去掉 ../../ → /api/file.php?...
    }
    if (preg_match('#^\.\./\.\./data/res/[A-Za-z0-9_./\-]+$#', $u)) {
        return '/' . substr($u, 6);
    }
    return null;
}

/* ==================== 搜我自己的聊天记录（全局 / 某人 / 某群） ====================
   走 api/chat_actions.php 的 chat_action_search_messages —— 和网页搜索框同一个函数，
   只能搜「我参与的会话」；e2ee 消息只回占位符。 */
function ai_tool_search_messages(PDO $pdo, int $uid, string $username, array $a): array {
    $q = trim((string)$a['q']);
    $with = trim((string)($a['with'] ?? ''));
    $group = trim((string)($a['group'] ?? ''));
    $limit = max(1, min(30, (int)($a['limit'] ?? 10)));

    $gid = 0;
    if ($group !== '') {
        $gid = group_find_gid($pdo, $group);
        if (!$gid) return ['ok' => false, 'error' => '找不到群「' . $group . '」（可以给群名或 GID）'];
    }
    if ($with !== '' && $gid > 0) return ['ok' => false, 'error' => 'with 和 group 只能给一个'];

    $res = chat_action_search_messages($pdo, $uid, [
        'q' => $q, 'dm' => $with, 'group_id' => $gid, 'page' => 1, 'per_page' => $limit,
    ]);
    if (empty($res['success'])) return ['ok' => false, 'error' => (string)($res['error'] ?? '搜索失败')];

    $scope = $gid > 0 ? ('群 ' . $group) : ($with !== '' ? ('与 ' . $with . ' 的私聊') : '我的全部私聊');
    $rows = array_reverse($res['rows']);                 // 新→旧
    return [
        'query' => $q,
        'scope' => $scope,
        'total' => (int)$res['total'],
        'count' => count($rows),
        'messages' => chat_action_search_digest($pdo, $rows, $limit),
        'note' => '只搜得到你自己参与的会话；端到端加密的消息内容 AI 读不到',
    ];
}

/* ==================== 会改数据的操作（全部走网页同一套函数） ==================== */

/** 建群：等级上限、群号生成、群主身份都跟网页建群一模一样 */
function ai_tool_group_create(PDO $pdo, int $uid, string $username, array $a): array {
    $name = trim((string)$a['name']);
    $r = group_action_create($pdo, $uid, $name);
    if (empty($r['success'])) {
        if (($r['error'] ?? '') === 'Group limit reached') {
            return ['ok' => false, 'error' => '你的等级最多能拥有 ' . (int)$r['max_groups'] . ' 个群，已达上限'];
        }
        return ['ok' => false, 'error' => '群名不能为空'];
    }
    return [
        'group_id' => (int)$r['group_id'], 'name' => $r['name'], 'role' => 'owner',
        'hint' => '群已建好，GID 是 ' . (int)$r['group_id'] . '；把 GID 告诉好友即可加入（群默认非公开，别人加入需要你审批）',
    ];
}

/** 加入群：公开群直接进；非公开群发申请等群主/管理员批 */
function ai_tool_group_join(PDO $pdo, int $uid, string $username, array $a): array {
    $key = trim((string)$a['group']);
    $gid = group_find_gid($pdo, $key);
    if (!$gid) return ['ok' => false, 'error' => '找不到群「' . $key . '」——可以给群名或数字 GID'];
    $st = $pdo->prepare('SELECT name, public FROM `groups` WHERE group_id = ?');
    $st->execute([$gid]);
    $g = $st->fetch() ?: ['name' => '', 'public' => 0];
    $r = group_action_join($pdo, $uid, $gid, 'join_or_request');
    if (empty($r['success'])) {
        $e = (string)($r['error'] ?? '');
        if ($e === 'Already a member.') return ['ok' => false, 'error' => '你已经在这个群里了'];
        if ($e === 'Already requested.') return ['ok' => false, 'error' => '之前已经申请过，还在等群主审批'];
        return ['ok' => false, 'error' => '加入失败：' . $e];
    }
    if (!empty($r['requested'])) {
        return ['group_id' => $gid, 'name' => $g['name'], 'requested' => true, 'hint' => '这是非公开群，已替你发加入申请，等群主/管理员通过'];
    }
    return ['group_id' => $gid, 'name' => $g['name'], 'joined' => true, 'hint' => '已加入群聊'];
}

/** 加联系人：对方的黑名单/「允许任何人添加」/节流规则全部照旧生效 */
function ai_tool_contact_add(PDO $pdo, int $uid, string $username, array $a): array {
    $to = trim((string)$a['to']);
    $msg = trim((string)($a['msg'] ?? ''));
    $r = contact_action_send_request($pdo, $uid, $username, $to, $msg);
    if (empty($r['success'])) {
        $map = [
            'blocked' => '发不出去：对方把你拉黑了',
            'not_accepting' => '对方关闭了「允许任何人添加我为好友」，加不了',
            'Already friends.' => '你们已经是好友了',
            'Request already pending.' => '申请已经发过了，还在等对方通过',
            'Too many friend requests. Please try again later.' => '好友申请太频繁了，过一会儿再试',
        ];
        $e = (string)($r['error'] ?? '');
        return ['ok' => false, 'error' => $map[$e] ?? '找不到这个用户'];
    }
    return ['to' => $to, 'sent' => true, 'hint' => '好友申请已发出，要等对方同意才成为好友'];
}

/** 删除联系人：双向删行，和网页「删除好友」一样 */
function ai_tool_contact_remove(PDO $pdo, int $uid, string $username, array $a): array {
    $to = trim((string)$a['to']);
    $r = contact_action_remove($pdo, $uid, $username, $to);
    if (empty($r['success'])) return ['ok' => false, 'error' => '删除失败：你们不是好友，或用户名不对'];
    return ['to' => $to, 'removed' => true, 'hint' => '已删除该联系人（聊天记录不会被删）'];
}

/** 置顶 / 取消置顶：kind=contact 是会话置顶，kind=group 是群置顶 */
function ai_tool_pin(PDO $pdo, int $uid, string $username, array $a): array {
    $kind = (string)($a['kind'] ?? 'contact');
    $target = trim((string)$a['target']);
    $on = (string)($a['on'] ?? 'toggle');
    $flag = $on === 'on' ? 1 : ($on === 'off' ? 0 : null);

    if ($kind === 'group') {
        $gid = group_find_gid($pdo, $target);
        if (!$gid) return ['ok' => false, 'error' => '找不到群「' . $target . '」'];
        $r = group_action_toggle_pin($pdo, $uid, $gid, $flag);
        if (empty($r['success'])) return ['ok' => false, 'error' => '置顶失败：你不在这个群里'];
        return ['kind' => 'group', 'target' => $target, 'group_id' => $gid, 'pinned' => (int)$r['pinned'], 'on' => (bool)$r['pinned']];
    }
    $r = contact_action_toggle_pin($pdo, $uid, $username, $target, $flag);
    if (empty($r['success'])) return ['ok' => false, 'error' => '置顶失败：你们还不是好友，或名字写错了'];
    return ['kind' => 'contact', 'target' => $target, 'pinned' => (int)$r['pinned'], 'on' => (bool)$r['pinned']];
}

/** 特别关心开关（对方发说说时你能收到提醒的那个标记） */
function ai_tool_special_care(PDO $pdo, int $uid, string $username, array $a): array {
    $user = trim((string)$a['user']);
    $on = (string)($a['on'] ?? 'toggle');
    $flag = $on === 'on' ? 1 : ($on === 'off' ? 0 : null);
    $r = contact_action_toggle_special($pdo, $uid, $username, $user, $flag);
    if (empty($r['success'])) return ['ok' => false, 'error' => '设置失败：必须是好友才能设特别关心'];
    return [
        'user' => $user, 'special' => (int)$r['special'], 'on' => (bool)$r['special'],
        'hint' => $r['special'] ? '已把它加入特别关心' : '已取消特别关心',
    ];
}

/** 举报用户：进 incidents 表给管理员处理，规则与网页举报完全一致（10 分钟最多 10 次） */
function ai_tool_report_user(PDO $pdo, int $uid, string $username, array $a): array {
    $to = trim((string)$a['to']);
    $reason = trim((string)$a['reason']);
    $r = report_action_submit($pdo, $uid, $username, $to, $reason);
    if (empty($r['success'])) {
        $e = (string)($r['error'] ?? '');
        if ($e === 'Invalid target.') return ['ok' => false, 'error' => '找不到被举报的用户'];
        if ($e === 'Invalid.') return ['ok' => false, 'error' => '不能举报自己'];
        return ['ok' => false, 'error' => $e !== '' ? $e : '举报提交失败'];
    }
    return ['to' => $to, 'submitted' => true, 'ticket_id' => (int)($r['ticket_id'] ?? 0), 'hint' => '举报已提交给管理员，处理结果在工单/举报列表里看'];
}
