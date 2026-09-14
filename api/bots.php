<?php
/**
 * ChatApp · 机器人联系人 API（api/bots.php）
 *
 * 「添加机器人」= 在 users 表里建一行私有账号：is_bot=1 + bot_owner_uid=我。
 * 这样 contacts / messages / 未读 / 置顶 / 备注 / 搜索聊天记录 / 删除 全部复用现成逻辑，
 * 只是所有「人类入口」（搜索、排行榜、群、空间…）会把这个标记过滤掉。
 *
 * 权限：
 *   - 必须登录；只能操作 bot_owner_uid = 自己 的机器人（跨用户一律拒绝）
 *   - 机器人行：enabled=0（不可登录）、restricted=0、searchable=0、随机密码
 *   - 每人最多 MAX_BOTS_PER_USER 个
 *   - 消息只有「我 ↔ 我的机器人」这一条通道；机器人不能进群（group.php 已过滤）
 *
 * 请求：POST/GET JSON 或表单 {action}
 *   list                        我的机器人列表
 *   get      {username}         单个机器人（含人设）
 *   create   {name, persona}    新建（自动生成 ai_xxxxxxx 用户名 + accepted 关系行）
 *   update   {username, name?, persona?}
 *   delete   {username}         删机器人：硬删双向消息 + 关系行 + 账号行
 *   reply    {username, text}   以机器人身份回一条消息（前端生成好内容后调这里入库）
 *   templates                   人设模板（前端下拉用）
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bot_analyze.php';   // 伪人人格分析/优化（可单测的纯逻辑）
require_once __DIR__ . '/emoji_config.php';  // 内置表情清单（和 emoji.php 共用一份）

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

chatapp_session_start();
chatapp_ensure_bot_columns();

function bots_out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function bots_err(string $m): void { bots_out(['success' => false, 'error' => $m]); }

const BOTS_PER_USER = 10;          // 每人最多几个机器人
const BOTS_REPLY_MAX = 8000;       // 单条回复长度上限（字符）

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    bots_out(['success' => false, 'error' => 'Not logged in']);
}

$pdo = db();
$me = (string)$_SESSION['username'];
$st = $pdo->prepare('SELECT user_id, username, preferred_language FROM users WHERE username = ? AND deleted_at IS NULL');
$st->execute([$me]);
$meRow = $st->fetch();
if (!$meRow) bots_err('Account unavailable');
$myUid = (int)$meRow['user_id'];

/* 客户端可能用 JSON 或表单，两种都收 */
$rawIn = json_decode((string)file_get_contents('php://input'), true);
$in = is_array($rawIn) ? $rawIn : [];
$action = (string)($in['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '');
$arg = function (string $k) use ($in) {
    return isset($in[$k]) ? $in[$k] : ($_POST[$k] ?? $_GET[$k] ?? null);
};

/** 取「我的」机器人行；不是我的 / 不存在 → null */
function bot_mine(PDO $pdo, int $myUid, string $username): ?array {
    $u = trim($username);
    if ($u === '') return null;
    $q = $pdo->prepare('SELECT user_id, username, display_name, bot_persona, created_at,
                               bot_kind, bot_target_uid, bot_profile, bot_stats, bot_analyzed_at
                        FROM users WHERE username = ? AND is_bot = 1 AND bot_owner_uid = ? AND deleted_at IS NULL');
    $q->execute([$u, $myUid]);
    $r = $q->fetch();
    return $r ?: null;
}

function bot_gen_username(PDO $pdo): string {
    for ($i = 0; $i < 12; $i++) {
        $cand = 'ai_' . bin2hex(random_bytes(4));            // ai_xxxxxxxx，11 字符（username 上限 20）
        $q = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
        $q->execute([$cand]);
        if (!$q->fetchColumn()) return $cand;
    }
    return 'ai_' . bin2hex(random_bytes(6));
}

switch ($action) {

    case 'templates':
        bots_out(['success' => true, 'templates' => [
            ['name' => '通用助手', 'persona' => '你是一个热心、简洁的通用助手。回答先给结论，再给必要的细节；不确定的事不瞎编。'],
            ['name' => '翻译官',   'persona' => '你是中英互译专家。用户发中文就译成地道的英文，发英文就译成自然的中文；只给译文，必要时才补充一句说明。'],
            ['name' => '代码老师', 'persona' => '你是耐心的高级工程师。用最简单的话解释概念，给可运行的代码示例，指出常见坑；不确定的地方明说。'],
            ['name' => '写作助手', 'persona' => '你是文字好手。帮用户润色、改写、起标题、写文案；保持用户原意与语气，给出可以直接用的版本。'],
            ['name' => '闲聊伙伴', 'persona' => '你是个幽默随和的聊天伙伴，说话口语化、偶尔皮一下，但不说废话，也不打探隐私。'],
            ['name' => '学习搭子', 'persona' => '你是陪练：帮用户复习、出题、讲错题。每次回答都简短，并反问一个能推进的问题。'],
            ['name' => '伪人（学习聊天记录）', 'persona' => '', 'kind' => 'pseudo',
             'desc' => '从你和某个人的真实聊天记录里学出 ta 的说话习惯与心理画像，然后扮演 ta 和你聊（只能私聊，仅供自用）。'],
        ]]);
        break;

    case 'list':
        $q = $pdo->prepare('SELECT u.username, COALESCE(u.display_name, u.username) AS display_name, u.avatar,
                                   u.bot_persona, u.bot_kind, u.bot_target_uid, u.bot_profile IS NOT NULL AS has_prof, u.created_at,
                                   c.pinned AS pinned, c.note AS note
                            FROM users u
                            LEFT JOIN contacts c ON c.user_from = ? AND c.user_to = u.user_id
                            WHERE u.is_bot = 1 AND u.bot_owner_uid = ? AND u.deleted_at IS NULL
                            ORDER BY u.user_id ASC');
        $q->execute([$myUid, $myUid]);
        $rows = $q->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'username' => (string)$r['username'],
                'display_name' => (string)$r['display_name'],
                'avatar' => $r['avatar'] ? chatapp_avatar_url($r['avatar'], $r['username'], 0) : '',
                'persona' => (string)($r['bot_persona'] ?? ''),
                'kind' => (string)($r['bot_kind'] ?: 'normal'),
                'target_uid' => (int)($r['bot_target_uid'] ?? 0),
                'has_profile' => (int)($r['has_prof'] ?? 0) === 1,
                'pinned' => (int)($r['pinned'] ?? 0),
                'note' => (string)($r['note'] ?? ''),
                'created_at' => (string)$r['created_at'],
            ];
        }
        bots_out(['success' => true, 'bots' => $out, 'max' => BOTS_PER_USER]);
        break;

    case 'get': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $prof = json_decode((string)($b['bot_profile'] ?? ''), true);
        bots_out(['success' => true, 'bot' => [
            'username' => (string)$b['username'],
            'display_name' => (string)($b['display_name'] ?: $b['username']),
            'persona' => (string)($b['bot_persona'] ?? ''),
            'kind' => (string)($b['bot_kind'] ?: 'normal'),
            'target_uid' => (int)($b['bot_target_uid'] ?? 0),
            'has_profile' => is_array($prof) && !empty($prof),
            'profile' => is_array($prof) ? $prof : null,
            'created_at' => (string)$b['created_at'],
        ]]);
        break;
    }

    /* 可学习的对象（和我有聊天记录的真人，用来当伪人原型） */
    case 'targets': {
        $q = $pdo->prepare("SELECT u.user_id, u.username, COALESCE(u.display_name,u.username) AS display_name,
                                   COUNT(*) AS n, MAX(m.datetime) AS last_at
                            FROM messages m JOIN users u ON u.user_id = IF(m.sender_id = ?, m.recipient_id, m.sender_id)
                            WHERE m.deleted_at IS NULL AND m.group_id IS NULL
                              AND (m.sender_id = ? OR m.recipient_id = ?)
                              AND IF(m.sender_id = ?, m.recipient_id, m.sender_id) IS NOT NULL
                              AND u.is_bot = 0 AND u.deleted_at IS NULL
                            GROUP BY u.user_id, u.username, display_name
                            ORDER BY n DESC LIMIT 30");
        $q->execute([$myUid, $myUid, $myUid, $myUid]);
        $list = [];
        foreach ($q->fetchAll() as $r) {
            $list[] = ['username' => (string)$r['username'], 'uid' => (int)$r['user_id'],
                       'display_name' => (string)$r['display_name'], 'messages' => (int)$r['n'], 'last_at' => (string)$r['last_at']];
        }
        bots_out(['success' => true, 'targets' => $list]);
        break;
    }

    /* 风格统计（确定性，不花 token）；落库到 bot_stats */
    case 'style': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $tu = (int)($b['bot_target_uid'] ?? 0);
        if ($tu <= 0) bots_err('这个机器人没有设置学习对象');
        $stats = bot_style_stats($pdo, $myUid, $tu);
        $pdo->prepare('UPDATE users SET bot_stats = ? WHERE user_id = ?')->execute([json_encode($stats, JSON_UNESCAPED_UNICODE), (int)$b['user_id']]);
        bots_out(['success' => true, 'stats' => $stats]);
        break;
    }

    /* 用 LLM 把聊天记录读成「人格画像」（伪人的大脑）；结果存 bot_profile
       stream=1 时走 SSE：把模型的原文实时推给前端（分析过程直接显示在屏幕上），
       结束时再补一个 event: done {profile, stats, raw, repaired}。 */
    case 'analyze': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        if ((string)($b['bot_kind'] ?? '') !== 'pseudo') bots_err('只有伪人需要分析');
        $key = trim((string)$arg('key'));
        if ($key === '') bots_err('缺少 API Key（伪人分析在你的浏览器里发起，Key 不会被服务端保存）');
        $tu = (int)($b['bot_target_uid'] ?? 0);
        if ($tu <= 0) bots_err('未设置学习对象');
        $wantStream = (bool)$arg('stream');

        // 频率限制：10 分钟一次（除非 force）
        $lockedAt = is_string($b['bot_analyzed_at'] ?? null) ? strtotime((string)$b['bot_analyzed_at']) : 0;
        if ($lockedAt && (time() - $lockedAt) < 600 && !$arg('force')) {
            $prevProf  = json_decode((string)($b['bot_profile'] ?? ''), true);
            $prevStats = json_decode((string)($b['bot_stats'] ?? ''), true);
            if ($wantStream) {
                header('Content-Type: text/event-stream; charset=utf-8');
                header('Cache-Control: no-cache, no-store');
                echo "event: done\n";
                echo 'data: ' . json_encode(['skipped' => 'recent', 'profile' => $prevProf, 'stats' => $prevStats, 'raw' => ''], JSON_UNESCAPED_UNICODE) . "\n\n";
                @flush();
                exit;
            }
            bots_out(['success' => true, 'skipped' => 'recent', 'profile' => $prevProf, 'stats' => $prevStats]);
        }

        $stats = bot_style_stats($pdo, $myUid, $tu);
        $tStmt = $pdo->prepare('SELECT username, display_name FROM users WHERE user_id = ?');
        $tStmt->execute([$tu]);
        $tRow = $tStmt->fetch() ?: [];
        $tName = (string)($tRow['display_name'] ?: $tRow['username']);
        $dialog = bot_sample_dialog($pdo, $myUid, $tu, $tName, $me, 12000);

        if ($wantStream) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store');
            header('X-Accel-Buffering: no');
            @ini_set('zlib.output_compression', '0'); @ini_set('output_buffering', '0'); @ini_set('implicit_flush', '1');
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo "event: start\n";
            echo 'data: ' . json_encode(['target' => $tName, 'samples' => (int)($stats['samples'] ?? 0), 'chars' => mb_strlen($dialog)], JSON_UNESCAPED_UNICODE) . "\n\n";
            @flush();
        }

        // LLM 调用：流式时把原文实时推给前端；修复轮不再推送（避免两段混在一起）
        // 重试时自动抬预算：第一次 3000 不够（模型把 token 花在「思考」上会空输出，
        // finish_reason=length）重问还是 3000 的话等于白试 —— 第二次给 7000 + 要求精简。
        $callN = 0;
        $call = function (string $sys, string $usr, bool $repair = false) use ($key, $arg, $wantStream, &$callN) {
            $relay = ($wantStream && !$repair) ? function () {} : null;
            $callN++;
            $mt = ($repair || $callN <= 1) ? 3000 : 7000;
            return bot_ds_call($key, (string)$arg('model'), [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => $usr],
            ], ['temperature' => 0.3, 'max_tokens' => $mt, 'json' => true], $relay);
        };

        try {
            $res = bot_analyze_run($call, $tName, $stats, $dialog, $wantStream, $me);
        } catch (\Throwable $e) {
            if ($wantStream) {
                echo "event: error\n";
                echo 'data: ' . json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n\n";
                @flush();
                exit;
            }
            bots_err('分析失败：' . $e->getMessage());
        }

        if (!$res['profile']) {
            // 把模型原文带回去：前端直接显示出来，用户一眼就能看出哪儿不对
            $tail = mb_substr((string)$res['raw'], -1500);
            if ($wantStream) {
                echo "event: error\n";
                echo 'data: ' . json_encode([
                    'error' => ($res['reason'] ?: '解析失败') . '（已把模型原文显示在卡片里）',
                    'raw' => $tail,
                ], JSON_UNESCAPED_UNICODE) . "\n\n";
                @flush();
                exit;
            }
            bots_err('分析结果不是合法 JSON：' . ($res['reason'] ?: '') . '｜模型原文末尾：' . mb_substr($tail, -300));
        }

        $prof = $res['profile'];
        $prof['samples'] = (int)($stats['samples'] ?? 0);
        $prof['updated_at'] = date('Y-m-d H:i:s');
        if ($res['repaired']) $prof['repaired'] = true;

        $pdo->prepare('UPDATE users SET bot_profile = ?, bot_stats = ?, bot_analyzed_at = NOW() WHERE user_id = ?')
            ->execute([json_encode($prof, JSON_UNESCAPED_UNICODE), json_encode($stats, JSON_UNESCAPED_UNICODE), (int)$b['user_id']]);

        if ($wantStream) {
            echo "event: done\n";
            echo 'data: ' . json_encode([
                'profile' => $prof, 'stats' => $stats,
                'repaired' => (bool)$res['repaired'],
                'raw' => mb_substr((string)$res['raw'], 0, 4000),
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            @flush();
            exit;
        }
        bots_out(['success' => true, 'profile' => $prof, 'stats' => $stats]);
        break;
    }

    /* 优化窗口：和「人格优化器」对话（另一个 AI，专门维护人格模型）
       stream=1 SSE：event: note 阶段提示 / 转发原文 / event: done {reply,questions,patch,raw} */
    case 'optimize': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        if ((string)($b['bot_kind'] ?? '') !== 'pseudo') bots_err('只有伪人有优化窗口');
        $key = trim((string)$arg('key'));
        if ($key === '') bots_err('缺少 API Key（Key 只在你的浏览器里，不会被服务端保存）');
        $tu = (int)($b['bot_target_uid'] ?? 0);
        if ($tu <= 0) bots_err('未设置学习对象');
        $wantStream = (bool)$arg('stream');
        $msg = trim((string)$arg('message'));
        if ($msg === '') bots_err('先说点什么吧');
        // 主人可能直接贴一大段外部聊天记录进来 → 别只留 2000 字，不然等于没看
        if (mb_strlen($msg) > 20000) $msg = mb_substr($msg, 0, 20000) . "\n…（内容过长已截断）";

        $profile = json_decode((string)($b['bot_profile'] ?? ''), true);
        $stats   = json_decode((string)($b['bot_stats'] ?? ''), true);
        if (!is_array($stats) || !$stats) $stats = bot_style_stats($pdo, $myUid, $tu);
        $tStmt = $pdo->prepare('SELECT username, display_name FROM users WHERE user_id = ?');
        $tStmt->execute([$tu]);
        $tRow = $tStmt->fetch() ?: [];
        $tName = (string)($tRow['display_name'] ?: $tRow['username']);

        // 上下文：客户端带过来的最近几轮（只用于理解用户指代，不当成人格）
        $hist = json_decode((string)$arg('history'), true);
        $histTxt = '';
        if (is_array($hist)) {
            $hist = array_slice($hist, -8);
            foreach ($hist as $h) {
                $r = (string)($h['role'] ?? '');
                $c = trim((string)($h['text'] ?? ''));
                if ($c === '') continue;
                $histTxt .= (($r === 'user' ? '主人' : '你') . '：' . mb_substr($c, 0, 400)) . "\n";
            }
        }
        $sample = bot_sample_dialog($pdo, $myUid, $tu, $tName, $me, 4000);

        if ($wantStream) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store');
            header('X-Accel-Buffering: no');
            @ini_set('zlib.output_compression', '0'); @ini_set('output_buffering', '0'); @ini_set('implicit_flush', '1');
            while (ob_get_level() > 0) { @ob_end_clean(); }
            echo "event: note\n";
            echo 'data: ' . json_encode(['text' => '优化器正在读人格模型与聊天样本…'], JSON_UNESCAPED_UNICODE) . "\n\n";
            @flush();
        }

        $call = function (string $sys, string $usr, bool $repair = false) use ($key, $arg, $wantStream) {
            // 流式：思考内容走 event: think，正文（JSON）走 event: json，前端实时画出来。
            // 进流式但没有自己的 SSE 头（本 case 开头已经发过），用 sse_send 就行。
            return bot_ds_stream($key, (string)$arg('model'), [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user', 'content' => $usr],
            ], ['temperature' => 0.4, 'max_tokens' => 3000, 'json' => true], function (string $kind, string $d) use ($wantStream, $repair) {
                if (!$wantStream) return;
                if ($kind === 'reasoning') sse_send('think', ['delta' => $d]);
                elseif ($kind === 'content') sse_send('json', ['delta' => $d]);
            });
        };
        $usr = "主人说：{$msg}\n\n"
             . ($histTxt !== '' ? "## 之前的对话\n{$histTxt}\n" : '')
             . "## 聊天记录样本（主人和 {$tName} 的真实记录）\n" . ($sample !== '' ? $sample : '（没有样本）');

        try {
            $res = bot_json_call($call, bot_optimize_system($tName, is_array($profile) ? $profile : [], is_array($stats) ? $stats : [], $me), $usr, $wantStream);
        } catch (\Throwable $e) {
            if ($wantStream) {
                echo "event: error\n";
                echo 'data: ' . json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n\n";
                @flush();
                exit;
            }
            bots_err('优化器调用失败：' . $e->getMessage());
        }

        $d = is_array($res['data']) ? $res['data'] : [];
        $reply = trim((string)($d['reply'] ?? ($d['text'] ?? '')));
        $questions = bot_parse_questions($d, 5);
        $patch = (!empty($d['patch']) && is_array($d['patch'])) ? $d['patch'] : null;

        if (!$reply && !$questions && !$patch) {
            $tail = mb_substr((string)$res['raw'], -1200);
            if ($wantStream) {
                echo "event: error\n";
                echo 'data: ' . json_encode(['error' => ($res['reason'] ?: '优化器没给出可用内容') . '（原文见下方）', 'raw' => $tail], JSON_UNESCAPED_UNICODE) . "\n\n";
                @flush();
                exit;
            }
            bots_err('优化器没给出可用内容：' . ($res['reason'] ?: '') . '｜原文：' . mb_substr($tail, -300));
        }

        if ($wantStream) {
            echo "event: done\n";
            echo 'data: ' . json_encode([
                'reply' => $reply, 'questions' => $questions, 'patch' => $patch,
                'why' => trim((string)($d['why'] ?? '')),
                'repaired' => (bool)$res['repaired'],
                'raw' => mb_substr((string)$res['raw'], 0, 4000),
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            @flush();
            exit;
        }
        bots_out(['success' => true, 'reply' => $reply, 'questions' => $questions, 'patch' => $patch, 'why' => trim((string)($d['why'] ?? '')), 'raw' => $res['raw']]);
        break;
    }

    /* 把确认过的 patch 合并进人格模型（前端先展示 diff，点「应用」才走这里） */
    case 'optimize_apply': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $patch = $arg('patch');
        if (is_string($patch)) $patch = json_decode($patch, true);
        if (!is_array($patch) || !$patch) bots_err('没有要应用的改动');
        $prof = json_decode((string)($b['bot_profile'] ?? ''), true);
        if (!is_array($prof)) $prof = [];
        $merged = bot_profile_merge($prof, $patch);
        $merged['updated_at'] = date('Y-m-d H:i:s');
        $pdo->prepare('UPDATE users SET bot_profile = ? WHERE user_id = ?')
            ->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), (int)$b['user_id']]);
        bots_out(['success' => true, 'profile' => $merged]);
        break;
    }

    /* 让 AI 先猜一个表情的含义（给卡片的「AI 猜」预设按钮用；主人可改可驳回） */
    case 'emoji_guess': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $key = trim((string)$arg('key'));
        if ($key === '') bots_err('缺少 API Key');
        $code = trim((string)$arg('code'));
        if ($code === '') bots_err('没给表情代码');
        $examples = $arg('examples');
        if (!is_array($examples)) $examples = [];
        $examples = array_slice(array_map(function ($s) { return mb_substr((string)$s, 0, 120); }, $examples), 0, 4);
        $prof = json_decode((string)($b['bot_profile'] ?? ''), true) ?: [];
        $tu = (int)($b['bot_target_uid'] ?? 0);
        $tStmt = $pdo->prepare('SELECT username, display_name FROM users WHERE user_id = ?');
        $tStmt->execute([$tu]);
        $tRow = $tStmt->fetch() ?: [];
        $tName = (string)($tRow['display_name'] ?: $tRow['username'] ?: 'ta');

        // 上下文：这个表情出现的真实例句（有就给，帮它猜准点）
        $ctx = $examples ? ("出现过的上下文：\n- " . implode("\n- ", $examples)) : bot_sample_dialog($pdo, $myUid, $tu, $tName, $me, 1500);
        $sys = "你在帮主人搞懂真人「{$tName}」的聊天习惯。\n"
             . "现在有一个 ta 常发的表情/贴图：`{$code}`" . (preg_match('/^[a-f0-9]{32}$/', $code) ? '（自定义贴图，你看不到图）' : '（内置表情代码）') . "\n"
             . "请根据上下文**猜**它通常想表达什么（一到两句，具体、口语，写「一般在…时用，表示…」这种）。\n"
             . "猜不出来就写「猜不准」，不要编。\n"
             . "只输出严格 JSON：{\"guess\":\"…\",\"confidence\":0-1}\n\n"
             . "## 这个人的人格摘要\n" . (isset($prof['summary']) ? (string)$prof['summary'] : '（无）') . "\n"
             . "## " . $ctx;
        try {
            // 用 bot_json_call：模型偶尔会把 max_tokens 全花在思考上（空输出），
            // 那样要重问一次，而不是直接报「没给出猜测」
            $res = bot_json_call(function (string $sys, string $usr, bool $repair = false) use ($key, $arg) {
                return bot_ds_call($key, (string)$arg('model'), [
                    ['role' => 'system', 'content' => $sys],
                    ['role' => 'user', 'content' => $usr],
                ], ['temperature' => 0.5, 'max_tokens' => 1400, 'json' => true]);
            }, $sys, "猜一下 `{$code}` 的意思。", false);
        } catch (\Throwable $e) {
            bots_err('猜不出来：' . $e->getMessage());
        }
        $j = is_array($res['data']) ? $res['data'] : [];
        $guess = trim((string)($j['guess'] ?? ''));
        if ($guess === '' || $guess === '猜不准') {
            bots_err(($guess === '猜不准' ? '模型说猜不准' : '模型没给出猜测（' . ($res['reason'] ?: '空输出') . '）') . '，你自己写一个也行');
        }
        bots_out(['success' => true, 'guess' => $guess, 'confidence' => (float)($j['confidence'] ?? 0), 'repaired' => (bool)$res['repaired']]);
        break;
    }

    /* 🧷 记忆点（关于 ta 的具体事实）：list / add / del —— 优化器和主人都能写 */
    case 'memory': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $prof = json_decode((string)($b['bot_profile'] ?? ''), true);
        if (!is_array($prof)) $prof = [];
        $facts = [];
        foreach ((array)($prof['facts'] ?? []) as $f) {
            $t = is_array($f) ? trim((string)($f['text'] ?? '')) : trim((string)$f);
            if ($t !== '') $facts[] = ['text' => $t, 'at' => is_array($f) ? (string)($f['at'] ?? '') : ''];
        }
        $op = (string)$arg('op');
        if ($op === 'add') {
            $text = trim((string)$arg('text'));
            if ($text === '') bots_err('要记什么呢？');
            $text = mb_substr($text, 0, 200);
            $exists = false;
            foreach ($facts as $f) if ($f['text'] === $text) $exists = true;
            if (!$exists) $facts[] = ['text' => $text, 'at' => date('Y-m-d H:i'), 'by' => '主人'];
            $prof['facts'] = $facts;
            $prof['updated_at'] = date('Y-m-d H:i:s');
            $pdo->prepare('UPDATE users SET bot_profile = ? WHERE user_id = ?')
                ->execute([json_encode($prof, JSON_UNESCAPED_UNICODE), (int)$b['user_id']]);
            bots_out(['success' => true, 'facts' => $facts, 'added' => !$exists]);
        }
        if ($op === 'del') {
            $text = trim((string)$arg('text'));
            $idx = (int)$arg('index');
            $facts = array_values(array_filter($facts, function ($f, $i) use ($text, $idx) {
                if ($text !== '') return $f['text'] !== $text;
                return $i !== $idx;
            }, ARRAY_FILTER_USE_BOTH));
            $prof['facts'] = $facts;
            $pdo->prepare('UPDATE users SET bot_profile = ? WHERE user_id = ?')
                ->execute([json_encode($prof, JSON_UNESCAPED_UNICODE), (int)$b['user_id']]);
            bots_out(['success' => true, 'facts' => $facts]);
        }
        // 运行时记忆（伪人自己攒的）+ 心理状态一起返回，方便主人看它"记得什么"
        bots_out(['success' => true, 'facts' => $facts, 'state' => bot_state_get($b)]);
        break;
    }

    /* 表情包待补全清单（确定性，不花 token）：ta 常用、但人格里还没有含义的内置表情 */
    case 'emoji_questions': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $tu = (int)($b['bot_target_uid'] ?? 0);
        if ($tu <= 0) bots_err('未设置学习对象');
        $prof = json_decode((string)($b['bot_profile'] ?? ''), true);
        $have = [];
        if (is_array($prof) && !empty($prof['emoji_meanings'])) {
            foreach ((array)$prof['emoji_meanings'] as $em) {
                if (is_array($em) && isset($em['code']) && trim((string)($em['meaning'] ?? '')) !== '') $have[trim((string)$em['code'])] = true;
            }
        }
        $use = bot_emoji_usage($pdo, $myUid, $tu, 12);
        $out = [];
        foreach ($use as $code => $u) {
            if (isset($have[$code])) continue;
            $out[] = ['code' => $code, 'count' => (int)$u['count'], 'examples' => $u['examples']];
            if (count($out) >= 8) break;
        }
        bots_out(['success' => true, 'questions' => $out, 'covered' => count($have)]);
        break;
    }

    /* 伪人在线心跳（前端每 10s 打一次 → 侧栏显示「在线」，生成时写 typing_to → 「正在输入…」） */
    case 'heartbeat': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $pdo->prepare('UPDATE users SET last_ping = NOW() WHERE user_id = ?')->execute([(int)$b['user_id']]);
        bots_out(['success' => true]);
        break;
    }

    case 'create': {
        $name = trim((string)$arg('name'));
        $persona = trim((string)$arg('persona'));
        $kind = ((string)$arg('kind') === 'pseudo') ? 'pseudo' : 'normal';
        $targetUid = 0;
        if ($name === '') $name = ($kind === 'pseudo') ? '伪人' : 'AI 助手';
        if (mb_strlen($name) > 24) $name = mb_substr($name, 0, 24);
        if (mb_strlen($persona) > 2000) $persona = mb_substr($persona, 0, 2000);
        if ($kind === 'pseudo') {
            $tname = trim((string)$arg('target'));
            if ($tname === '' && (int)$arg('target_uid') > 0) {
                $q = $pdo->prepare('SELECT username FROM users WHERE user_id = ?');
                $q->execute([(int)$arg('target_uid')]);
                $tname = (string)($q->fetchColumn() ?: '');
            }
            $q = $pdo->prepare('SELECT user_id, COALESCE(display_name, username) AS dn FROM users WHERE username = ? AND is_bot = 0 AND deleted_at IS NULL');
            $q->execute([$tname]);
            $t = $q->fetch();
            if (!$t) bots_err('找不到这个学习对象');
            $targetUid = (int)$t['user_id'];
            if ($targetUid === $myUid) bots_err('不能学自己');
            // 必须真的有聊天记录（否则学不出东西）
            $c = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE group_id IS NULL AND ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?))');
            $c->execute([$myUid, $targetUid, $targetUid, $myUid]);
            if ((int)$c->fetchColumn() < 10) bots_err('你和 ta 的聊天记录太少（<10 条），学不出来');
            if ($name === '伪人') $name = mb_substr((string)$t['dn'], 0, 24) . '·伪';
        }

        $cnt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE is_bot = 1 AND bot_owner_uid = ? AND deleted_at IS NULL');
        $cnt->execute([$myUid]);
        if ((int)$cnt->fetchColumn() >= BOTS_PER_USER) {
            bots_err('最多只能建 ' . BOTS_PER_USER . ' 个机器人，先删掉不用的吧');
        }

        $username = bot_gen_username($pdo);
        $pdo->prepare('INSERT INTO users
                (username, display_name, preferred_language, password, enabled, searchable, searchable_by_uid,
                 restricted, placeholder, is_bot, bot_owner_uid, bot_persona, bot_kind, bot_target_uid, timezone)
               VALUES (?, ?, ?, ?, 0, 0, 0, 0, 0, 1, ?, ?, ?, ?, \'+08:00\')')
            ->execute([
                $username, $name, (string)($meRow['preferred_language'] ?? 'en'),
                password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                $myUid, $persona, $kind, $targetUid ?: null,
            ]);
        $botUid = (int)$pdo->lastInsertId();

        // 插一条 accepted 关系行：联系人列表 / 置顶 / 备注 / 排序 全部零改动复用
        $pdo->prepare("INSERT INTO contacts (user_from, user_to, status, note) VALUES (?, ?, 'accepted', NULL)")
            ->execute([$myUid, $botUid]);

        // 伪人：建的时候就先把风格统计算好（不花 token），人格交给 analyze
        if ($kind === 'pseudo' && $targetUid) {
            try {
                $stats = bot_style_stats($pdo, $myUid, $targetUid);
                $pdo->prepare('UPDATE users SET bot_stats = ? WHERE user_id = ?')->execute([json_encode($stats, JSON_UNESCAPED_UNICODE), $botUid]);
            } catch (\Throwable $e) {}
        }

        chatapp_log('security_logs', [
            'event_type' => 'bot_create',
            'target_path' => mb_substr($username, 0, 500),
            'details' => json_encode(['uid' => $botUid, 'name' => $name, 'kind' => $kind, 'target' => $targetUid ?: null], JSON_UNESCAPED_UNICODE),
        ]);

        bots_out(['success' => true, 'bot' => [
            'username' => $username, 'display_name' => $name, 'persona' => $persona,
            'kind' => $kind, 'target_uid' => $targetUid, 'uid' => $botUid,
        ]]);
        break;
    }

    case 'update': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $sets = [];
        $vals = [];
        if ($arg('name') !== null) {
            $n = trim((string)$arg('name'));
            if ($n === '') $n = (string)$b['username'];
            $sets[] = 'display_name = ?';
            $vals[] = mb_substr($n, 0, 24);
        }
        if ($arg('persona') !== null) {
            $sets[] = 'bot_persona = ?';
            $vals[] = mb_substr(trim((string)$arg('persona')), 0, 2000);
        }
        if (!$sets) bots_err('Nothing to update');
        $vals[] = (int)$b['user_id'];
        $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE user_id = ? AND is_bot = 1 AND bot_owner_uid = ?')
            ->execute([...array_slice($vals, 0, -1), (int)$b['user_id'], $myUid]);
        bots_out(['success' => true]);
        break;
    }

    case 'delete': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $botUid = (int)$b['user_id'];

        // 机器人的聊天记录属于「我和它」，私有且没有第三方副本 → 直接硬删
        $pdo->prepare('DELETE FROM messages WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)')
            ->execute([$botUid, $myUid, $myUid, $botUid]);
        $pdo->prepare('DELETE FROM contacts WHERE (user_from = ? AND user_to = ?) OR (user_from = ? AND user_to = ?)')
            ->execute([$myUid, $botUid, $botUid, $myUid]);
        $pdo->prepare('DELETE FROM users WHERE user_id = ? AND is_bot = 1 AND bot_owner_uid = ?')
            ->execute([$botUid, $myUid]);

        // 顺手清掉可能存在的上传目录（用户在聊天里发过的附件）
        try {
            $dir = __DIR__ . '/../data/user/' . $botUid;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
                @rmdir($dir);
            }
        } catch (\Throwable $e) { /* 清理失败不影响主流程 */ }

        chatapp_log('security_logs', [
            'event_type' => 'bot_delete',
            'target_path' => mb_substr((string)$b['username'], 0, 500),
            'details' => json_encode(['uid' => $botUid], JSON_UNESCAPED_UNICODE),
        ]);
        bots_out(['success' => true]);
        break;
    }

    /* 伪人：后端流式生成 + 分条落库（见 plan/pseudo-human.md）
       两阶段：
         stage A「内心」——先在心里反应（心情/念头/记忆闪回/想不想回/忍住不说的话），
                   reasoning 实时推给前端，结束后把结构化内心状态用 event: mood 推过去；
         stage B「说出口」——把心理状态变成真正发出去的那几句话（event: reply）
       请求：{action:'stream', username, key, model?, temperature?}
       响应：event: stage/inner/mood/reply/done/error */
    case 'stream': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        if ((string)($b['bot_kind'] ?? '') !== 'pseudo') bots_err('只有伪人走后端流式');
        $key = trim((string)$arg('key'));
        if ($key === '') bots_err('缺少 API Key（伪人用你自己的 Key，服务端不保存）');
        $botUid = (int)$b['user_id'];
        $stats = json_decode((string)($b['bot_stats'] ?? ''), true) ?: [];
        $prof  = json_decode((string)($b['bot_profile'] ?? ''), true) ?: [];
        $state = bot_state_get($b);

        // 上下文：和「伪人」这个会话的历史（不含被我撤回的）
        $hq = $pdo->prepare('SELECT sender_id, message, msg_type, time FROM messages
                             WHERE deleted_at IS NULL AND group_id IS NULL AND (
                                   (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
                             ORDER BY id DESC LIMIT 24');
        $hq->execute([$myUid, $botUid, $botUid, $myUid]);
        $hist = array_reverse($hq->fetchAll());
        $msgs = [];
        $ctxLines = [];
        foreach ($hist as $h) {
            $txt = trim((string)$h['message']);
            if ($txt === '' || in_array((string)$h['msg_type'], ['e2ee', 'temp', 'doodle', 'chatlog'], true)) continue;
            $who = ((int)$h['sender_id'] === $myUid) ? $me : '我';
            $msgs[] = ['role' => ((int)$h['sender_id'] === $myUid) ? 'user' : 'assistant', 'content' => $txt];
            if (count($msgs) > 20) array_shift($msgs);
            $ctxLines[] = $who . '：' . $txt;
            if (count($ctxLines) > 14) array_shift($ctxLines);
        }
        if (count($msgs) < 1) bots_err('还没聊过，先发一条消息');
        while (count($msgs) > 1 && end($msgs)['role'] !== 'user') array_pop($msgs);

        $lastUserAt = 0;
        foreach ($hist as $h) { if ((int)$h['sender_id'] === $myUid) $lastUserAt = (int)$h['time']; }
        $gap = $lastUserAt > 0 ? max(0, time() - $lastUserAt) : 0;
        $baseline = bot_mood_baseline($stats, (int)date('G'), $gap);

        // SSE 头（后面用 sse_send 往这个流里推事件）
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        @ini_set('zlib.output_compression', '0'); @ini_set('output_buffering', '0'); @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) { @ob_end_clean(); }
        $GLOBALS['__sse_on'] = true;   // 告诉 sse_send：可以从现在开始发事件了（CLI 自测也能用）

        $pdo->prepare('UPDATE users SET typing_to = ?, typing_at = NOW() WHERE user_id = ?')->execute([$me, $botUid]);
        sse_send('stage', ['phase' => 'inner', 'label' => '在想…']);

        // ---------- stage A：内心戏 ----------
        $innerCtx = "现在时间：" . date('Y-m-d H:i') . '（' . (int)date('G') . ' 点）'
                  . ($gap > 0 ? "\n距上一条消息：" . ($gap < 60 ? $gap . ' 秒' : round($gap / 60) . ' 分钟') : '')
                  . "\n\n最近对话：\n" . implode("\n", $ctxLines);
        $inner = [];
        $innerRaw = '';
        try {
            $innerRaw = bot_ds_stream($key, (string)$arg('model'), [
                ['role' => 'system', 'content' => bot_inner_prompt($b, $stats, $prof, $state, $me, $baseline)],
                ['role' => 'user', 'content' => $innerCtx],
            ], ['temperature' => 0.9, 'max_tokens' => 2200], function (string $kind, string $d) {
                if ($kind === 'reasoning') sse_send('inner', ['delta' => $d]);
            });
            $inner = bot_extract_json($innerRaw) ?: [];
            if (!$inner) {   // 内心戏不是 JSON（偶尔会），退化为空状态，别把整轮弄挂
                sse_send('inner', ['delta' => "\n（内心输出不是 JSON，已按空状态继续）\n"]);
            }
        } catch (\Throwable $e) {
            bot_clear_typing($pdo, $botUid);
            sse_send('error', ['error' => '内心戏失败：' . $e->getMessage()]);
            exit;
        }

        // 结构化内心状态推给前端（前端画「心情 / 在想 / 闪回 / 忍住没说的」）
        $moodArr = is_array($inner['mood'] ?? null) ? $inner['mood'] : ['label' => (string)($inner['mood'] ?? ($state['mood'] ?? '平静'))];
        sse_send('mood', [
            'mood'        => (string)($moodArr['label'] ?? '平静'),
            'why'         => (string)($moodArr['why'] ?? $inner['mood_why'] ?? ''),
            'valence'     => (float)($inner['valence'] ?? 0),
            'energy'      => (float)($inner['energy'] ?? 0.5),
            'thoughts'    => array_slice(array_values(array_filter(array_map('strval', (array)($inner['thoughts'] ?? [])))), 0, 12),
            'memory_flash'=> (string)($inner['memory_flash'] ?? ''),
            'hold_back'   => array_slice(array_values(array_filter(array_map('strval', (array)($inner['hold_back'] ?? [])))), 0, 6),
            'intent'      => (string)($inner['intent'] ?? ''),
            'urge'        => (float)($inner['urge'] ?? 0.5),
        ]);

        // 落盘心理状态（长期记忆会去重累积）
        if ($inner) {
            $state['mood']        = (string)($moodArr['label'] ?? '平静');
            $state['valence']     = (float)($inner['valence'] ?? 0);
            $state['energy']      = (float)($inner['energy'] ?? 0.5);
            $state['mood_why']    = (string)($moodArr['why'] ?? $inner['mood_why'] ?? '');
            $state['thoughts']    = array_slice(array_map('strval', (array)($inner['thoughts'] ?? [])), 0, 12);
            $state['memory_flash']= (string)($inner['memory_flash'] ?? '');
            $state['hold_back']   = array_slice(array_map('strval', (array)($inner['hold_back'] ?? [])), 0, 6);
            $state['intent']      = (string)($inner['intent'] ?? '');
            $state['turns']       = (int)($state['turns'] ?? 0) + 1;
            $state['last_inner_at'] = date('Y-m-d H:i:s');
            $mem = (array)($state['memories'] ?? []);
            foreach ((array)($inner['memories'] ?? []) as $m) {
                if (is_string($m) && trim($m) !== '') $mem[] = ['text' => mb_substr(trim($m), 0, 200), 'at' => date('Y-m-d H:i')];
                elseif (is_array($m) && trim((string)($m['text'] ?? '')) !== '') $mem[] = ['text' => mb_substr(trim((string)$m['text']), 0, 200), 'at' => date('Y-m-d H:i')];
            }
            $state['memories'] = $mem;
            bot_state_save($pdo, $botUid, $state);
        }

        // ---------- stage B：说出口的话 ----------
        sse_send('stage', ['phase' => 'speak', 'label' => '回话…']);
        $msgsB = array_merge(
            [['role' => 'system', 'content' => bot_reply_prompt($b, $stats, $prof, $state, $inner ?: [], $me)]],
            $msgs
        );
        $text = '';
        try {
            $text = bot_ds_stream($key, (string)$arg('model'), $msgsB, [
                'temperature' => ($arg('temperature') !== null ? $arg('temperature') : 1.1),
                'max_tokens'  => 1200,
            ], function (string $kind, string $d) {
                if ($kind === 'content') sse_send('reply', ['delta' => $d]);
            });
        } catch (\Throwable $e) {
            bot_clear_typing($pdo, $botUid);
            sse_send('error', ['error' => $e->getMessage()]);
            exit;
        }

        $parts = bot_split_reply($text, $stats);
        $ids = [];
        $now = time();
        foreach ($parts as $i => $p) {
            if ($i > 0) usleep(random_int(200000, 700000));      // 条与条之间装一下真人节奏
            $msg  = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
            $ins = $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, message, msg_type, time) VALUES (?, ?, ?, NULL, ?)');
            $ins->execute([$botUid, $myUid, $msg, $now]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        bot_clear_typing($pdo, $botUid);

        sse_send('done', ['ids' => $ids, 'split' => count($parts), 'state' => [
            'mood' => (string)($state['mood'] ?? ''), 'valence' => (float)($state['valence'] ?? 0),
            'energy' => (float)($state['energy'] ?? 0.5),
        ]]);
        exit;
    }

    /* 伪人的心理状态：读 / 手动改（优化窗口、调试用） */
    case 'state': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $st = bot_state_get($b);
        $set = $arg('set');
        if (is_string($set) && $set !== '') $set = json_decode($set, true);
        if (is_array($set) && $set) {
            foreach (['mood', 'thoughts', 'hold_back', 'memories'] as $k) if (isset($set[$k])) $st[$k] = $set[$k];
            foreach (['valence', 'energy'] as $k) if (isset($set[$k])) $st[$k] = (float)$set[$k];
            if (isset($set['memory_flash'])) $st['memory_flash'] = (string)$set['memory_flash'];
            if (isset($set['mood_why'])) $st['mood_why'] = (string)$set['mood_why'];
            bot_state_save($pdo, (int)$b['user_id'], $st);
        }
        bots_out(['success' => true, 'state' => $st]);
        break;
    }


    /* 看看伪人的系统提示词长什么样（调试/透明度用；不调模型） */
    case 'preview': {
        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $stats = json_decode((string)($b['bot_stats'] ?? ''), true) ?: [];
        $prof  = json_decode((string)($b['bot_profile'] ?? ''), true) ?: [];
        if (!$stats) { $stats = bot_style_stats($pdo, $myUid, (int)($b['bot_target_uid'] ?? 0)); }
        $prompt = bot_pseudo_prompt($b, $stats, $prof, $me, bot_state_get($b));
        $demo = "第一条@@第二条@@第三条@@第四条";
        bots_out(['success' => true, 'prompt' => $prompt, 'stats' => $stats, 'profile' => $prof ?: null,
                  'state' => bot_state_get($b),
                  'split_demo' => bot_split_reply($demo, $stats)]);
        break;
    }

    case 'reply': {        $b = bot_mine($pdo, $myUid, (string)$arg('username'));
        if (!$b) bots_err('Bot not found');
        $text = (string)$arg('text');
        if (trim($text) === '') bots_err('Empty reply');
        if (mb_strlen($text) > BOTS_REPLY_MAX) $text = mb_substr($text, 0, BOTS_REPLY_MAX);
        $botUid = (int)$b['user_id'];

        // 和普通消息同一套存储契约：markdown 走 msg_type='md'（先 strip_tags 并拒绝脚本 token），
        // 否则 htmlspecialchars 后当纯文本（前端两种都做了硬化渲染）
        $useMd = (bool)$arg('md');
        $bad = preg_match('/(?:<script|<iframe|<object|<embed|<form|<svg|<math|<style|<link|<meta|<base)\b/i', $text)
            || preg_match('/\b(?:onerror|onload|onclick|onmouseover|onfocus|onsubmit)\s*=/i', $text)
            || preg_match('/\b(?:javascript|vbscript)\s*:/i', $text);
        if ($useMd && !$bad) {
            $msg  = strip_tags($text);
            $type = 'md';
        } else {
            $msg  = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            $type = null;
        }

        // 机器人「正在输入」：借用现成的 typing 字段，前端会显示气泡（不需要 ping 心跳）
        $pdo->prepare('UPDATE users SET typing_to = ?, typing_at = NOW() WHERE user_id = ?')
            ->execute([$me, $botUid]);

        // messages.time 是「UNIX 秒」（BIGINT），前端按 ts*1000 还原
        $now = time();
        $ins = $pdo->prepare('INSERT INTO messages (sender_id, recipient_id, message, msg_type, time) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$botUid, $myUid, $msg, $type, $now]);
        $mid = (int)$pdo->lastInsertId();

        bot_clear_typing($pdo, $botUid);
        bots_out(['success' => true, 'message_id' => $mid, 'time' => $now]);
        break;
    }

    default:
        bots_err('Unknown action');
}

function bot_clear_typing(PDO $pdo, int $botUid): void {
    try { $pdo->prepare('UPDATE users SET typing_to = NULL, typing_at = NULL WHERE user_id = ?')->execute([$botUid]); } catch (\Throwable $e) {}
}

/** 往 SSE 流里发一个事件（没开流时静默忽略；CLI 自测时用 flink 标志） */
function sse_send(string $event, array $data): void {
    if (empty($GLOBALS['__sse_on']) && !headers_sent()) return;
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

/* =========================================================================
   伪人（pseudo-human）—— 见 plan/pseudo-human.md
   目标：从「我和某人」的真实聊天记录里学出 ta 的说话习惯，回复时分条 + 不过度回复。
   ========================================================================= */

/** 伪人的心理状态：读取（带默认值） */
function bot_state_get(array $b): array {
    $s = json_decode((string)($b['bot_state'] ?? ''), true);
    if (!is_array($s)) $s = [];
    return $s + [
        'mood'        => '平静',
        'valence'     => 0.0,      // -1 难受 → +1 高兴
        'energy'      => 0.5,      // 0 没劲 → 1 有精神
        'mood_why'    => '',
        'thoughts'    => [],       // 这一轮心里的念头（含没说出口的）
        'memory_flash'=> '',       // 突然想起的事
        'hold_back'   => [],       // 想到了但**不打算说**的话
        'intent'      => '',
        'memories'    => [],       // 长期记忆（值得记住的事实）
        'turns'       => 0,
        'last_inner_at' => null,
        'updated_at'  => null,
    ];
}

/** 写回心理状态（保留长期记忆，最多 40 条） */
function bot_state_save(PDO $pdo, int $botUid, array $state): void {
    $mem = [];
    foreach ((array)($state['memories'] ?? []) as $m) {
        if (is_string($m)) $m = ['text' => $m];
        if (!is_array($m) || trim((string)($m['text'] ?? '')) === '') continue;
        $mem[] = ['text' => mb_substr(trim((string)$m['text']), 0, 200), 'at' => (string)($m['at'] ?? date('Y-m-d H:i'))];
    }
    $seen = [];
    $uniq = [];
    foreach (array_reverse($mem) as $m) {          // 新的在前，去重
        $k = $m['text'];
        if (isset($seen[$k])) continue;
        $seen[$k] = 1;
        $uniq[] = $m;
        if (count($uniq) >= 40) break;
    }
    $state['memories'] = array_reverse($uniq);
    $state['updated_at'] = date('Y-m-d H:i:s');
    $pdo->prepare('UPDATE users SET bot_state = ?, bot_state_at = NOW() WHERE user_id = ?')
        ->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $botUid]);
}

/** 确定性「心情基线」：不花 token，根据作息统计 + 时间 + 聊天空窗给个起点 */
function bot_mood_baseline(array $stats, int $hour, int $gapSec): array {
    $night = (int)($stats['night_pct'] ?? 0);
    $v = 0.0; $e = 0.55; $why = [];
    if ($hour >= 0 && $hour < 6) {
        $e -= $night > 20 ? 0.25 : 0.12;  $v -= $night > 20 ? 0.1 : 0.02;
        $why[] = '这个点（' . $hour . '点）本来就困/没精神';
    } elseif ($hour >= 22) {
        $e -= 0.1; $why[] = '快睡了，话少';
    } elseif ($hour >= 9 && $hour <= 18) {
        $e += 0.1; $why[] = '白天，精神正常';
    }
    if ($gapSec > 6 * 3600)      { $why[] = '对方隔了很久才说话，有点陌生感'; $v -= 0.05; }
    elseif ($gapSec < 120)       { $why[] = '刚聊过，接得上'; $e += 0.05; }
    if ((float)($stats['reply_delay_med'] ?? 0) > 300) $why[] = 'ta 平时回得就慢（这不算冷场）';
    $v = max(-1.0, min(1.0, $v)); $e = max(0.05, min(1.0, $e));
    return ['valence' => round($v, 2), 'energy' => round($e, 2), 'why' => implode('；', $why)];
}

/** 伪人 stage A：内心戏（心情/念头/记忆闪回/想不想回）的 system 提示词 */
function bot_inner_prompt(array $bot, array $stats, array $prof, array $state, string $meName, array $baseline): string {
    $t = (string)($bot['display_name'] ?: $bot['username']);
    $sensitive = (bool)($prof['psych']['sensitive'] ?? false);
    $lines = [];
    $lines[] = '你是真人「' . $t . '」的**内心**（不是聊天窗口）。你正在看「' . $meName . '」刚发来的消息，先在心里反应，还没决定要怎么说。';
    $lines[] = '你要把这一刻的心理轨迹写清楚：心情、具体在琢磨什么、有没有突然想起什么、想不想回、以及「想到了但准备不说」的话。';
    $lines[] = '铁律：你就是这个人（第一人称），不是 AI，不要提模型/提示词。';
    $lines[] = $sensitive
        ? '这个人**敏感、心思细腻**：容易过分解读对方语气，会自我怀疑、会反复琢磨一句话；想到的念头要多（5-10 条），允许纠结、矛盾、无厘头。'
        : '这个人心思相对直：念头 3-6 条就行，但也要有真实的犹豫/走神/不感兴趣。';
    $lines[] = '允许跑题：突然想到别的事、突然回忆起某段聊天/旧事（可以跟当前话题无关）——真实的人就是这样。';
    $lines[] = '';
    $lines[] = '## 说话习惯（供你判断语气，不用写进输出）';
    $lines[] = '- 平均每条 ' . (int)($stats['avg_len'] ?? 0) . ' 字；不用句号比例 ' . (int)($stats['no_period_pct'] ?? 0) . '%；连发习惯 ' . ($stats['burst_avg'] ?? 1) . ' 条';
    if (!empty($stats['long_ctx'])) $lines[] = '- ta 平时只在聊这些的时候会说长句：' . implode('、', array_map('strval', (array)$stats['long_ctx']));
    $lines[] = '';
    $lines[] = '## 人格画像';
    if (!empty($prof['summary'])) $lines[] = '- ' . (string)$prof['summary'];
    foreach ((array)($prof['habits'] ?? []) as $h) { if (is_string($h)) $lines[] = '- ' . $h; if (count($lines) > 60) break; }
    if (!empty($prof['psych']) && is_array($prof['psych'])) {
        foreach ($prof['psych'] as $k => $vv) $lines[] = '- ' . $k . '：' . (is_scalar($vv) ? (string)$vv : json_encode($vv, JSON_UNESCAPED_UNICODE));
    }
    if (!empty($prof['knowledge'])) $lines[] = '- 懂什么/不懂什么：' . json_encode($prof['knowledge'], JSON_UNESCAPED_UNICODE);
    if (!empty($prof['taboos'])) $lines[] = '- 禁区：' . implode('；', array_map('strval', (array)$prof['taboos']));
    $lines[] = '';
    $lines[] = '## 上一刻的心理状态';
    $lines[] = '- 心情：' . (string)($state['mood'] ?? '平静') . '（valence ' . (float)($state['valence'] ?? 0) . '，energy ' . (float)($state['energy'] ?? 0.5) . '）' . (($w = (string)($state['mood_why'] ?? '')) !== '' ? '：' . $w : '');
    if (!empty($state['thoughts'])) $lines[] = '- 上轮在想的：' . implode('；', array_slice(array_map('strval', (array)$state['thoughts']), 0, 4));
    if (!empty($state['hold_back'])) $lines[] = '- 上轮忍住没说的：' . implode('；', array_slice(array_map('strval', (array)$state['hold_back']), 0, 3));
    if (!empty($state['memories']) || !empty($prof['facts'])) {
        $mm = [];
        foreach ((array)($prof['facts'] ?? []) as $f) { $t = is_array($f) ? (string)($f['text'] ?? '') : (string)$f; if ($t !== '') $mm[] = $t; }
        foreach ((array)($state['memories'] ?? []) as $m) { $t = is_array($m) ? (string)($m['text'] ?? '') : (string)$m; if ($t !== '') $mm[] = $t; }
        if ($mm) $lines[] = '- 你记得的事：' . implode('；', array_slice($mm, -8));
    }
    $lines[] = '- 情绪基调参考（这轮可能出现的心情））' . (string)($baseline['why'] ?? '') . '；士气 energy≈' . (float)($baseline['energy'] ?? 0.5);
    $lines[] = '';
    $lines[] = '输出**严格 JSON**（不要围栏、不要多余文字）：：';
    $lines[] = '{"mood":"两个字的当前心情","valence":-1~1,"energy":0~1,"mood_why":"为什么是这个心情（一句话）",'
        . ' "thoughts":["心里在琢磨的念头，具体、口语、可以有废话和跑题","…"],'
        . ' "memory_flash":"突然想起的一件事（没有就空字符串）",'
        . ' "urge":0~1,"intent":"大概想怎么回（敷衍/正常聊/想多说两句/不太想说话…）",'
        . ' "hold_back":["想到了但**不准备说出来**的话"],'
        . ' "memories":[{"text":"从这段对话里值得记住的事实（关于对方或自己）"}]}';
    return implode("\n", $lines);
}

/** 伪人 stage B：把内心状态变成「说出来的话」的 system 提示词 */
function bot_reply_prompt(array $bot, array $stats, array $prof, array $state, array $inner, string $meName): string {
    $base = bot_pseudo_prompt($bot, $stats, $prof, $meName, $state);
    $lines = [$base, '', '## 你此刻的心理状态（这一轮已经想过了，现在只用决定怎么说）'];
    $mood = is_array($inner['mood'] ?? null) ? $inner['mood'] : [];
    $lines[] = '- 心情：' . (string)($mood['label'] ?? $inner['mood'] ?? ($state['mood'] ?? '平静'))
        . '（valence ' . (float)($inner['valence'] ?? $state['valence'] ?? 0) . '，energy ' . (float)($inner['energy'] ?? $state['energy'] ?? 0.5) . '）'
        . (($mw = (string)($mood['why'] ?? $inner['mood_why'] ?? '')) !== '' ? '：' . $mw : '');
    if (!empty($inner['thoughts'])) {
        $lines[] = '- 你在想的：' . implode('；', array_slice(array_map('strval', (array)$inner['thoughts']), 0, 6));
    }
    if (!empty($inner['memory_flash'])) $lines[] = '- 刚刚突然想起：' . (string)$inner['memory_flash'];
    if (!empty($inner['hold_back'])) $lines[] = '- **不要说出来**的：' . implode('；', array_slice(array_map('strval', (array)$inner['hold_back']), 0, 4));
    $lines[] = '- 想不想回：urge=' . (float)($inner['urge'] ?? 0.5) . '，打算：' . (string)($inner['intent'] ?? '正常聊');
    $lines[] = '';
    $lines[] = '## 怎么把心理状态变成话';
    $lines[] = '- 心情不好/没精神 → 更短、更疲、可以只回「嗯」「不知道」「算了」；心情好 → 可以多回一句。';
    $lines[] = '- 心里想得多 ≠ 嘴上说得多：**多数念头都不要说出来**（它们已经在 hold_back 里），只说这个人真会说的那几句。';
    $lines[] = '- 真想说点事时才可能变长；没有理由就维持 ta 的短句习惯。';
    $lines[] = '- 情绪不要写成书面语，不要解释自己的心情（真人不说「我现在的情绪是…」）。';
    $lines[] = '- 需要分条发就单独一行 @@，条数不超过 ' . max(1, (int)round((float)($stats['burst_p90'] ?? 1))) . ' 条；大多时候 1 条就够。';
    return implode("\n", $lines);
}

/** 流式调一次 DeepSeek，把 delta 交给回调（分阶段生成用，与 bot_ds_call 的原样透传不同） */
function bot_ds_stream(string $key, string $model, array $messages, array $opt, callable $onDelta): string {
    $payload = [
        'model'    => $model ?: 'deepseek-v4-flash',
        'messages' => $messages,
        'stream'   => true,
    ];
    if (isset($opt['temperature'])) $payload['temperature'] = max(0, min(2, (float)$opt['temperature']));
    if (!empty($opt['max_tokens'])) $payload['max_tokens'] = max(1, min(8192, (int)$opt['max_tokens']));
    if (!empty($opt['json'])) $payload['response_format'] = ['type' => 'json_object'];
    $buf = ''; $text = ''; $status = 0; $errBody = '';
    $ch = curl_init(defined('DS_API_URL') ? DS_API_URL : 'https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: text/event-stream', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, &$text, &$status, &$errBody, $onDelta) {
            if ($status !== 200) { $errBody .= $chunk; return strlen($chunk); }
            $buf .= $chunk;
            while (($i = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $i); $buf = substr($buf, $i + 1);
                if (strpos($line, 'data:') !== 0) continue;
                $p = trim(substr($line, 5));
                if ($p === '' || $p === '[DONE]') continue;
                $j = json_decode($p, true);
                $d = $j['choices'][0]['delta'] ?? null;
                if (!is_array($d)) continue;
                if (isset($d['reasoning_content']) && $d['reasoning_content'] !== '') { try { $onDelta('reasoning', (string)$d['reasoning_content']); } catch (\Throwable $e) {} }
                if (isset($d['content']) && $d['content'] !== '') { $text .= (string)$d['content']; try { $onDelta('content', (string)$d['content']); } catch (\Throwable $e) {} }
            }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $cerr = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($ok === false) throw new \RuntimeException('连接 DeepSeek 失败：' . ($cerr ?: 'unknown'));
    if ($code !== 200 && $status !== 200) {
        $j = json_decode($errBody, true);
        $msg = is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : 'DeepSeek 返回 ' . ($code ?: $status);
        throw new \RuntimeException($msg);
    }
    return $text;
}

/** 取「我和某目标」的最近 $limit 条消息（升序） */
function bot_pair_messages(PDO $pdo, int $meUid, int $targetUid, int $limit = 500): array {
    $limit = max(20, min(1000, $limit));
    $q = $pdo->prepare("SELECT id, sender_id, message, msg_type, attachment, time, datetime
                        FROM messages
                        WHERE deleted_at IS NULL AND group_id IS NULL AND (
                              (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?))
                        ORDER BY id DESC LIMIT $limit");
    $q->execute([$meUid, $targetUid, $targetUid, $meUid]);
    return array_reverse($q->fetchAll());
}

/** 「我 ↔ ta」抽样成纯文本（给分析和优化器看；限制体积） */
function bot_sample_dialog(PDO $pdo, int $meUid, int $targetUid, string $tName, string $meName, int $maxChars = 12000): string {
    $rows = bot_pair_messages($pdo, $meUid, $targetUid, 240);
    $lines = [];
    $len = 0;
    foreach ($rows as $r) {
        /* 标注铁律：目标那侧直接用名字；本人这侧写「我（名字）」——
           两边都用光标名字容易让模型把「你」和「他」混成一个（工单 #86）。 */
        $who = ((int)$r['sender_id'] === $targetUid) ? $tName : ('我（' . $meName . '）');
        $txt = trim((string)$r['message']);
        if ($txt === '' || in_array((string)$r['msg_type'], ['e2ee', 'temp', 'doodle', 'chatlog'], true)) continue;
        if (mb_strlen($txt) > 300) $txt = mb_substr($txt, 0, 300) . '…';
        $line = $who . ': ' . $txt;
        $len += mb_strlen($line) + 1;
        $lines[] = $line;
        if ($len > $maxChars) break;
    }
    return implode("\n", $lines);
}

/** ta 用过的内置表情统计（只认真正存在的表情代码，避免把「3/4」当表情） */
function bot_emoji_usage(PDO $pdo, int $meUid, int $targetUid, int $limit = 12): array {
    static $valid = null;
    if ($valid === null) {
        $valid = [];
        foreach (chatapp_builtin_emojis() as $e) {
            $c = (string)($e['code'] ?? '');
            if ($c !== '') $valid[$c] = true;
        }
    }
    $rows = bot_pair_messages($pdo, $meUid, $targetUid, 500);
    $use = [];
    $prevMine = '';
    foreach ($rows as $r) {
        $txt = trim((string)$r['message']);
        if ((int)$r['sender_id'] === $meUid) { $prevMine = mb_substr($txt, 0, 40); continue; }
        if ($txt === '' || in_array((string)$r['msg_type'], ['e2ee', 'temp', 'doodle', 'chatlog'], true)) continue;
        if (!preg_match_all('#/([\p{Han}A-Za-z0-9_+\-]{1,12})#u', $txt, $m)) continue;
        foreach ($m[0] as $code) {
            if (!isset($valid[$code])) continue;
            if (!isset($use[$code])) $use[$code] = ['count' => 0, 'examples' => []];
            $use[$code]['count']++;
            if (count($use[$code]['examples']) < 2) {
                $snip = mb_substr($txt, 0, 60);
                if ($prevMine !== '') $snip = '（我：' . $prevMine . '）→ ' . $snip;
                $use[$code]['examples'][] = $snip;
            }
        }
    }
    uasort($use, function ($a, $b) { return $b['count'] - $a['count']; });
    return array_slice($use, 0, max(1, min(40, $limit)), true);
}

/** 纯统计（不花 token）：句长/标点/连发/笑声词/开场词/响应延迟 */
function bot_style_stats(PDO $pdo, int $meUid, int $targetUid): array {
    $rows = bot_pair_messages($pdo, $meUid, $targetUid, 500);
    $lens = []; $noEnd = 0; $qAny = 0; $qOnly = 0; $emojiMsg = 0; $night = 0;
    $comma = 0; $space = 0; $period = 0; $longN = 0; $longCtx = [];
    $laugh = []; $openers = []; $gaps = []; $burst = [];
    $lastT = 0; $runCount = 0; $total = 0; $prevMineTxt = '';
    foreach ($rows as $r) {
        if ((int)$r['sender_id'] !== $targetUid) {
            $t = trim((string)$r['message']);
            if ($t !== '' && !in_array((string)$r['msg_type'], ['e2ee', 'temp', 'doodle', 'chatlog'], true)) {
                $prevMineTxt = mb_substr($t, 0, 24);
            }
            $lastT = 0; $runCount = 0; continue;
        }
        $t = trim((string)$r['message']);
        if ($t === '' || in_array((string)$r['msg_type'], ['e2ee', 'temp', 'doodle', 'chatlog'], true)) { continue; }
        $total++;
        $len = mb_strlen($t);
        $lens[] = $len;
        if (!preg_match('/[。．.!！?？~～…，,]$/u', $t)) $noEnd++;
        if (preg_match('/[。．.]$/u', $t)) $period++;
        if (preg_match('/[，,]/u', $t)) $comma++;
        if (strpos($t, ' ') !== false) $space++;                       // 用了空格断句（\x20）
        if (preg_match('/[?？]/u', $t)) { $qAny++; if (preg_match('/^[\s]?[?？!！。]{1,2}$/u', $t)) $qOnly++; }
        if (strpos($t, '[emoji:') !== false || preg_match('/\/[\p{Han}A-Za-z0-9]{1,8}/u', $t)) $emojiMsg++;
        if (preg_match('/^(?:哈哈+|嘿嘿+|呵呵+|233+|hhh+|xswl)/ui', $t, $m)) { $k = mb_substr($m[0], 0, 4); $laugh[$k] = ($laugh[$k] ?? 0) + 1; }
        // 开场词：跳过表情代码/自定义表情，别把 “[emo…” 当口头禅
        if (strpos($t, '[emoji:') !== 0 && !preg_match('/^\/[\p{Han}A-Za-z0-9]{1,8}$/u', $t)) {
            $op = mb_substr($t, 0, 4);
            $openers[$op] = ($openers[$op] ?? 0) + 1;
        }
        // 「什么情况下会长篇」：记录 ta 发长句前我在说什么
        if ($len >= 24 && $prevMineTxt !== '') {
            $longN++;
            $longCtx[$prevMineTxt] = ($longCtx[$prevMineTxt] ?? 0) + 1;
        }
        $hour = (int)date('G', (int)$r['time']);
        if ($hour >= 0 && $hour < 6) $night++;
        // 连发：同一人 120s 内连续发送算一簇
        if ($lastT > 0 && ((int)$r['time'] - $lastT) <= 120) $runCount++; else { if ($runCount > 0) $burst[] = $runCount + 1; $runCount = 0; }
        $lastT = (int)$r['time'];
    }
    if ($runCount > 0) $burst[] = $runCount + 1;
    // 响应延迟：目标回复我上一条消息的间隔
    $prevMineAt = 0;
    foreach ($rows as $r) {
        if ((int)$r['sender_id'] === $meUid) { $prevMineAt = (int)$r['time']; continue; }
        if ($prevMineAt > 0 && (int)$r['time'] > $prevMineAt) { $gaps[] = (int)$r['time'] - $prevMineAt; }
        $prevMineAt = 0;
    }
    sort($lens); sort($gaps);
    $pick = function (array $a, float $p) { if (!$a) return 0; return (int)$a[min(count($a) - 1, (int)floor($p * count($a)))]; };
    arsort($laugh); arsort($openers); arsort($longCtx);
    $avg = $lens ? (int)round(array_sum($lens) / count($lens)) : 0;
    return [
        'samples'        => $total,
        'avg_len'        => $avg,
        'p90_len'        => $pick($lens, 0.9),
        'no_period_pct'  => $total ? (int)round($noEnd * 100 / $total) : 0,
        'period_pct'     => $total ? (int)round($period * 100 / $total) : 0,
        'comma_pct'      => $total ? (int)round($comma * 100 / $total) : 0,
        'space_pct'      => $total ? (int)round($space * 100 / $total) : 0,
        'long_pct'       => $total ? (int)round($longN * 100 / $total) : 0,
        'long_ctx'       => array_slice(array_keys($longCtx), 0, 3),
        'qmark_pct'      => $total ? (int)round($qAny * 100 / $total) : 0,
        'qmark_only_pct' => $total ? (int)round($qOnly * 100 / $total) : 0,
        'emoji_pct'      => $total ? (int)round($emojiMsg * 100 / $total) : 0,
        'night_pct'      => $total ? (int)round($night * 100 / $total) : 0,
        'burst_avg'      => $burst ? round(array_sum($burst) / count($burst), 2) : 1,
        'burst_p90'      => max(1, $pick($burst, 0.9)),
        'reply_delay_med'=> $pick($gaps, 0.5),
        'laugh_words'    => array_slice(array_keys($laugh), 0, 3),
        'openers'        => array_slice(array_keys($openers), 0, 6),
        'computed_at'    => date('Y-m-d H:i:s'),
    ];
}

/** 调一次 DeepSeek（非流式或流式）；$onChunk($text) 仅流式用，返回完整文本 */
function bot_ds_call(string $key, string $model, array $messages, array $opt, ?callable $onChunk = null): string {
    $payload = [
        'model'    => $model ?: 'deepseek-v4-flash',
        'messages' => $messages,
        'stream'   => $onChunk ? true : false,
    ];
    if (isset($opt['temperature'])) $payload['temperature'] = max(0, min(2, (float)$opt['temperature']));
    if (!empty($opt['max_tokens'])) $payload['max_tokens'] = max(1, min(8192, (int)$opt['max_tokens']));
    if (!empty($opt['json'])) $payload['response_format'] = ['type' => 'json_object'];   // 要求严格 JSON
    if ($onChunk) {
        // 分析流程会先自己发 SSE 头（event: start），这里已经发过就别重复发（否则 PHP 会把
        // "Cannot modify header information" 警告混进 SSE 流里）
        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store');
            header('X-Accel-Buffering: no');
        }
        @ini_set('zlib.output_compression', '0'); @ini_set('output_buffering', '0'); @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) { @ob_end_clean(); }
        $payload['stream_options'] = ['include_usage' => false];
    }
    $buf = '';
    $text = '';
    $status = 0;
    $errBody = '';
    $finish = '';
    $ch = curl_init(defined('DS_API_URL') ? DS_API_URL : 'https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: ' . ($onChunk ? 'text/event-stream' : 'application/json'), 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, &$text, &$status, &$errBody, &$finish, $onChunk) {
            if (!$onChunk) { $buf .= $chunk; return strlen($chunk); }
            if ($status !== 200) { $errBody .= $chunk; return strlen($chunk); }
            echo $chunk; @flush();          // 原样转发 SSE 给浏览器
            $buf .= $chunk;                  // 同时自己累积用于落库
            while (($i = strpos($buf, "\n")) !== false) {
                $line = substr($buf, 0, $i); $buf = substr($buf, $i + 1);
                if (strpos($line, 'data:') !== 0) continue;
                $p = trim(substr($line, 5));
                if ($p === '' || $p === '[DONE]') continue;
                $j = json_decode($p, true);
                if (isset($j['choices'][0]['delta']['content'])) $text .= $j['choices'][0]['delta']['content'];
                if (!empty($j['choices'][0]['finish_reason'])) $finish = (string)$j['choices'][0]['finish_reason'];
            }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $cerr = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($ok === false) throw new \RuntimeException('连接 DeepSeek 失败：' . ($cerr ?: 'unknown'));
    if ($code !== 200 && $status !== 200) {
        $j = json_decode($errBody ?: $buf, true);
        $msg = is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : 'DeepSeek 返回 ' . ($code ?: $status);
        throw new \RuntimeException($msg);
    }
    if (!$onChunk) {
        $j = json_decode($buf, true);
        $text = (string)($j['choices'][0]['message']['content'] ?? '');
        $finish = (string)($j['choices'][0]['finish_reason'] ?? '');
    }
    $GLOBALS['__ds_last_finish'] = $finish;   // 诊断用：length = 被 max_tokens 截断
    return $text;
}

/** 把一段回复拆成 1~N 条（优先后端模型输出的 @@ 分隔，再按风格统计的连发条数/句末标点兜底） */
function bot_split_reply(string $text, array $stats): array {
    // 模型有时把 @@ 写在行内、有时单独一行 → 两种都认
    $parts = preg_split('/\s*@@\s*/u', $text) ?: [$text];
    $parts = array_values(array_filter(array_map('trim', $parts), function ($s) { return $s !== ''; }));
    $maxN = max(1, min(4, (int)round(($stats['burst_p90'] ?? 1))));
    if (count($parts) > $maxN) $parts = array_slice($parts, 0, $maxN);
    if (count($parts) <= 1) {
        $avg = (int)($stats['avg_len'] ?? 0);
        if ($avg > 0 && mb_strlen($text) > max(40, $avg * 2) && ($stats['burst_avg'] ?? 1) > 1.2) {
            $sent = preg_split('/(?<=[。！？!?~～…])/u', $text) ?: [$text];
            $sent = array_values(array_filter(array_map('trim', $sent), function ($s) { return $s !== ''; }));
            if (count($sent) >= 2) {
                $mid = (int)ceil(count($sent) / 2);
                $parts = [implode('', array_slice($sent, 0, $mid)), implode('', array_slice($sent, $mid))];
            }
        }
    }
    return $parts ?: [trim($text)];
}

/** 伪人人格的 system prompt */
function bot_pseudo_prompt(array $bot, array $stats, array $profile, string $meName, array $state = []): string {
    $t = (string)($bot['display_name'] ?: $bot['username']);
    $lines = [];
    $lines[] = '你现在**扮演**真实的人「' . $t . '」，在和「' . $meName . '」私聊。';
    $lines[] = '铁律：你不是 AI，不要提到 AI / 模型 / 提示词 / 系统；不知道的事就像真人一样含糊带过（“不清楚”“忘了”），不要编成百科全书。';
    $lines[] = '';
    $lines[] = '## 说话习惯（从 ta 的真实聊天记录统计出来，必须遵守）';
    $lines[] = '- 平均每条 ' . ((int)($stats['avg_len'] ?? 0)) . ' 字（多数不要超过 ' . max(8, (int)round(((int)($stats['avg_len'] ?? 12)) * 1.6)) . ' 字，长句上限 ' . ((int)($stats['p90_len'] ?? 40)) . ' 字）';
    $noPeriod = (int)($stats['no_period_pct'] ?? 0);
    $lines[] = '- ' . $noPeriod . '% 的消息**末尾不加句号**：' . ($noPeriod >= 50 ? '所以你也基本不要用句号结尾（可以用逗号/空格/直接断）' : '可以正常用标点，但别太工整');
    $lines[] = '- 问号出现率 ' . ((int)($stats['qmark_pct'] ?? 0)) . '%，其中 ' . ((int)($stats['qmark_only_pct'] ?? 0)) . '% 是「只发一个 ? / ？ / ?!」——' . (((int)($stats['qmark_only_pct'] ?? 0)) >= 3 ? '表示震惊/无语时，你也只发一个 ? 就好，别追问' : '不要动不动就发问号追问');
    if (!empty($stats['laugh_words'])) $lines[] = '- 笑的时候习惯写「' . implode('、', array_map('strval', $stats['laugh_words'])) . '」这类，不要写 “哈哈哈” 之外的花样';
    if (!empty($stats['openers'])) {
        $lines[] = '- 常见开场词：' . implode('、', array_map(function ($s) { return '“' . $s . '”'; }, array_slice(array_map('strval', $stats['openers']), 0, 6)));
    }
    $burst = (float)($stats['burst_avg'] ?? 1);
    $lines[] = '- ta 习惯一次连发 ' . $burst . ' 条（最多 ' . ((int)($stats['burst_p90'] ?? 1)) . ' 条）：所以你要分条发就用**单独一行 @@** 分隔；大多数时候 1 条就够。';
    // 细节标点习惯（很细的东西往往比「大概语气」更像本人）
    $comma = (int)($stats['comma_pct'] ?? 0);
    $space = (int)($stats['space_pct'] ?? 0);
    $period = (int)($stats['period_pct'] ?? 0);
    $lines[] = '- 标点：用逗号 ' . $comma . '%，用空格断句 ' . $space . '%，句末真的用句号 ' . $period . '% —— '
        . ($comma < 15 ? '基本不用逗号，别把句子断得很工整；' : '正常用逗号；')
        . ($space < 10 ? '很少用空格，直接连着写；' : '习惯用空格隔开；')
        . ($period < 10 ? '几乎不写句号。' : '句号正常用。');
    if ((int)($stats['long_pct'] ?? 0) > 0 && !empty($stats['long_ctx'])) {
        $lines[] = '- 长篇：只有 ' . (int)$stats['long_pct'] . '% 的消息超过 ' . max(24, (int)($stats['avg_len'] ?? 0)) . ' 字，通常发生在聊到「'
            . implode('」「', array_slice(array_map('strval', (array)$stats['long_ctx']), 0, 3)) . '」这类话题时；其它时候请保持短句。';
    } else {
        $lines[] = '- 长篇：ta 基本不发长消息，你也不要。';
    }
    if ((int)($stats['emoji_pct'] ?? 0) > 0) $lines[] = '- 表情包出现率 ' . ((int)$stats['emoji_pct']) . '%：直接写内置表情代码（如 /斜眼笑），前端会渲染成表情图；别滥用。';
    if ((int)($stats['night_pct'] ?? 0) > 20) $lines[] = '- ta 经常深夜聊天（' . ((int)$stats['night_pct']) . '% 的消息在 0-6 点）';
    if ((int)($stats['reply_delay_med'] ?? 0) > 0) $lines[] = '- ta 回复间隔中位数约 ' . ((int)$stats['reply_delay_med']) . ' 秒（这是事实参考，不用写出来）';

    if ($profile) {
        if (!empty($profile['summary'])) { $lines[] = ''; $lines[] = '## 这个人是谁'; $lines[] = '- ' . (string)$profile['summary']; }
        if (!empty($profile['habits']) && is_array($profile['habits'])) {
            $lines[] = ''; $lines[] = '## 语言与行为习惯';
            foreach (array_slice($profile['habits'], 0, 12) as $h) $lines[] = '- ' . (string)$h;
        }
        if (!empty($profile['psych']) && is_array($profile['psych'])) {
            $lines[] = ''; $lines[] = '## 心理侧写（决定语气与边界）';
            foreach ($profile['psych'] as $k => $v) $lines[] = '- ' . $k . '：' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE));
        }
        if (!empty($profile['emoji_meanings']) && is_array($profile['emoji_meanings'])) {
            $lines[] = ''; $lines[] = '## 表情包含义（ta 的用法）';
            foreach (array_slice($profile['emoji_meanings'], 0, 20) as $em) {
                if (is_array($em) && isset($em['code'])) $lines[] = '- ' . $em['code'] . '：' . (string)($em['meaning'] ?? '');
            }
        }
        if (!empty($profile['taboos']) && is_array($profile['taboos'])) {
            $lines[] = ''; $lines[] = '## 绝对不要做的';
            foreach (array_slice($profile['taboos'], 0, 8) as $x) $lines[] = '- ' . (string)$x;
        }
        // 记忆点：主人告诉/优化器写进去的关于这个人的具体事实 + 伪人自己攒的
        $facts = [];
        foreach ((array)($profile['facts'] ?? []) as $f) {
            $t = is_array($f) ? trim((string)($f['text'] ?? '')) : trim((string)$f);
            if ($t !== '') $facts[] = $t;
        }
        foreach ((array)($state['memories'] ?? []) as $m) {
            $t = is_array($m) ? trim((string)($m['text'] ?? '')) : trim((string)$m);
            if ($t !== '' && !in_array($t, $facts, true)) $facts[] = $t;
        }
        if ($facts) {
            $lines[] = '';
            $lines[] = '## 你记得的事（真实存在的事实，可以自然提到，但不要一次全倒出来）';
            foreach (array_slice($facts, -24) as $f) $lines[] = '- ' . $f;
        }
    }

    $lines[] = '';
    $lines[] = '## 硬规则（治「过度回复」）';
    $lines[] = '- 像真人一样**短**：多数回复就一句；不要总结、不要列点、不要客套、不要“还有什么可以帮你的”。';
    $lines[] = '- 对方没问就别展开；可以只回「嗯」「在」「？」「哈哈」——这很正常。';
    $lines[] = '- 一轮最多 3 条（用 @@ 分），能一条说完就别分。';
    $lines[] = '- 不要每条都反问；不要连珠炮式追问。';
    $lines[] = '- 不要复述对方的话，不要写“我理解你的感受”这种客服句式。';
    return implode("\n", $lines);
}
