<?php
/**
 * ChatApp · 伪人人格分析（api/bot_analyze.php）
 *
 * 纯函数 + 可注入的 LLM 调用，方便单测（见文件末尾注释里的自测命令）。
 * bots.php 的 action=analyze 只是把这里的逻辑接上 SSE。
 *
 * 为什么单独一个文件：
 *  - 模型经常不老实输出 JSON（带前言、带 ``` 围栏、尾逗号、中文引号、被 max_tokens 截断）
 *  - 这些都要能单独测，不然只能靠肉眼猜「到底哪儿不合法」
 */

/** 分析用的 system 提示词 */
function bot_analyze_system(string $tName): string {
    return "你是人物分析师。分析下面这段真实聊天记录里的「{$tName}」，输出**严格 JSON**（不要 markdown 围栏、不要前言后语、不要注释）。\n"
        . "字段：\n"
        . '{"summary":"一句话概括", "habits":["语言/行为习惯，5-12 条，要具体"], '
        . '"style":{"laugh":"笑声写法","address":"怎么称呼对方","punctuation":"标点习惯"}, '
        . '"psych":{"sensitive":true|false,"self_esteem":"","personal_history":"能从记录里看到的经历","attitude_to_others":"对熟人/陌生人态度","likes":"","dislikes":""}, '
        . '"emoji_meanings":[{"code":"/xxx","meaning":"ta 用这个表情通常想表达"}], '
        . '"taboos":["聊天中不要碰的话题/说法"], "facts":["从记录里能确认的具体事实（关于 ta 的工作/生活/关系/经历，五无则空数组）"], "confidence":0-1}'
        . "\n只根据记录推断，不确定就写“不确定”。字符串里不要出现换行（用空格代替）。";
}

/** 分析用的 user 提示词 */
function bot_analyze_user(array $stats, string $dialog): string {
    return "统计参考（确定性）：" . json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n\n聊天记录：\n" . $dialog;
}

/** 从一段「大概包含 JSON」的文本里抠出对象；失败返回 null */
function bot_extract_json(string $text): ?array {
    $t = trim((string)$text);
    if ($t === '') return null;

    // 1) 整段就是 JSON
    $j = json_decode($t, true);
    if (is_array($j)) return $j;

    // 2) ```json ... ``` 围栏（取最长那段）
    if (preg_match_all('/```[a-zA-Z]*\s*([\s\S]*?)```/', $t, $m) && !empty($m[1])) {
        $blocks = $m[1];
        usort($blocks, function ($a, $b) { return strlen($b) - strlen($a); });
        $cand = bot_extract_json(trim($blocks[0]));
        if ($cand) return $cand;
    }

    // 3) 平衡括号扫描（跳过字符串与转义），找出第一个能解析的对象
    $hit = bot_scan_object($t);
    if ($hit) return $hit;

    // 4) 常见毛病修补后再试：尾逗号、中文引号、全角冒号、裸换行
    $fix = preg_replace('/,\s*([\]}])/u', '$1', $t);
    $fix = str_replace(['“', '”', '‘', '’'], '"', (string)$fix);
    $fix = str_replace('：', ':', (string)$fix);
    $hit = bot_scan_object((string)$fix);
    if ($hit) return $hit;

    return null;
}

/** 扫描文本里所有「从某个 { 开始完整配对」的片段，返回第一个能 json_decode 的数组 */
function bot_scan_object(string $t): ?array {
    $len = strlen($t);
    $pos = 0;
    while (($start = strpos($t, '{', $pos)) !== false) {
        $depth = 0; $inStr = false; $esc = false;
        for ($i = $start; $i < $len; $i++) {
            $c = $t[$i];
            if ($esc) { $esc = false; continue; }
            if ($c === '\\') { $esc = true; continue; }
            if ($c === '"') { $inStr = !$inStr; continue; }
            if ($inStr) continue;
            if ($c === '{') $depth++;
            elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    $cand = substr($t, $start, $i - $start + 1);
                    $j = json_decode($cand, true);
                    if (is_array($j)) return $j;
                    break;                       // 这段不行，从下一个 { 继续
                }
            }
        }
        $pos = $start + 1;
    }
    return null;
}

/** 流式场景：给前端发一条「阶段提示」（只在已经开始输出 SSE 时才发） */
function sse_note(string $msg): void {
    if (!headers_sent()) return;
    echo "event: stage\n";
    echo 'data: ' . json_encode(['note' => $msg], JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

/** 通用：一次调用 → 抠 JSON → 失败就重问 / 再来一轮「JSON 修复器」。
 *
 *  为什么要分情况（踩过的坑）：
 *   - 模型「一个字都没输出」或被 max_tokens 截断时，把空/残缺内容喂给「JSON 修复器」，
 *     它会对着**指令本身**做 JSON 化，返回 {"role":"JSON 修复器","task":"…"} 这种废数据
 *     —— 看起来像修复过，其实全是幻觉。这种情况应该**重新问一次原问题**。
 *   - 只有「内容是对的但格式不合法」（带前言/围栏/尾逗号）才值得走修复轮。
 *
 *  @param callable $call function(string $system, string $user, bool $repair): string
 *  @return array{data:?array, raw:string, repaired:bool, reason:?string}
 */
function bot_json_call(callable $call, string $system, string $user, bool $stream = false): array {
    $raw = trim((string)$call($system, $user, false));
    $d = bot_extract_json($raw);
    if ($d) return ['data' => $d, 'raw' => $raw, 'repaired' => false, 'reason' => null];

    // ① 真空 / 像 JSON 但括号没闭合（被截断）→ 重问原问题（别走修复器）
    $empty = (trim($raw) === '');
    $cut   = (!$empty && strpos($raw, '{') !== false && substr_count($raw, '{') > substr_count($raw, '}'));
    if ($empty || $cut) {
        $fin = (string)($GLOBALS['__ds_last_finish'] ?? '');
        if ($stream) sse_note(($empty ? '模型这次没吐出内容' : '输出像被截断了') . ($fin ? "（finish_reason={$fin}）" : '') . '，重问一次…');
        try {
            $raw2 = trim((string)$call(
                $system . "\n\n【重要】只输出 JSON 对象本身：不要长篇分析，不要省略结尾，确保括号闭合。",
                $user,
                false
            ));
            $raw .= "\n\n==== 重问输出 ====\n" . $raw2;
            $d = bot_extract_json($raw2);
            if ($d) return ['data' => $d, 'raw' => $raw, 'repaired' => true, 'reason' => $empty ? '第一次输出为空' : '第一次输出被截断'];
            $reason = $empty ? '模型两次都没输出内容' : '两次输出都不完整';
            if ($fin) $reason .= "（finish_reason={$fin}）";
            return ['data' => null, 'raw' => $raw, 'repaired' => true, 'reason' => $reason];
        } catch (\Throwable $e) {
            return ['data' => null, 'raw' => $raw, 'repaired' => true, 'reason' => '重问失败：' . $e->getMessage()];
        }
    }

    // ② 有内容但格式不合法 → 让模型自己整理成 JSON
    if ($stream) sse_note('修复轮：第一次输出不是合法 JSON，让模型重排一次…');
    try {
        $raw2 = (string)$call(
            '你是 JSON 修复器。把用户给你的内容整理成严格 JSON（不要围栏、不要解释），字段按原意保留。',
            mb_substr($raw, 0, 6000),
            true
        );
        $raw .= "\n\n==== 修复轮输出 ====\n" . $raw2;
        $d = bot_extract_json($raw2);
        if ($d) return ['data' => $d, 'raw' => $raw, 'repaired' => true, 'reason' => '第一次输出不是合法 JSON'];
        return ['data' => null, 'raw' => $raw, 'repaired' => true, 'reason' => '修复轮输出仍不是合法 JSON'];
    } catch (\Throwable $e) {
        return ['data' => null, 'raw' => $raw, 'repaired' => true, 'reason' => '修复轮失败：' . $e->getMessage()];
    }
}

/** 解析优化器给的 questions。
 *  模型经常自己改 key（把 code 写成 emoji / emoji_code / hash…，field 写成 emoji_code），
 *  以前只认 $q['code']，结果表情卡少了贴图、甚至被当成 habit 问题 —— 这里全部兜住。
 *  @return array<int, array{field:string,code:string,text:string}>
 */
function bot_parse_questions(array $d, int $limit = 5): array {
    $raw = $d['questions'] ?? null;
    if (!is_array($raw)) return [];
    static $valid = null;
    if ($valid === null) {
        $valid = [];
        if (function_exists('chatapp_builtin_emojis')) {
            foreach (chatapp_builtin_emojis() as $e) { if (!empty($e['code'])) $valid[(string)$e['code']] = true; }
        }
    }
    $out = [];
    foreach (array_slice($raw, 0, $limit) as $q) {
        if (is_string($q)) $q = ['text' => $q];
        if (!is_array($q)) continue;
        $txt = trim((string)($q['text'] ?? $q['question'] ?? $q['ask'] ?? $q['prompt'] ?? ''));
        if ($txt === '') continue;
        $code = '';
        foreach (['code', 'emoji', 'emoji_code', 'hash', 'sticker', 'image', 'img'] as $k) {
            if (!empty($q[$k]) && is_string($q[$k])) { $code = trim($q[$k]); break; }
        }
        if ($code === '') {
            if (preg_match('/[a-f0-9]{32}/', $txt, $m)) $code = $m[0];
            elseif (preg_match_all('#/[\p{Han}A-Za-z0-9_+\-]{1,12}#u', $txt, $m2) && $valid) {
                foreach ($m2[0] as $cand) { if (isset($valid[$cand])) { $code = $cand; break; } }
            }
        }
        $isEmoji = ($code !== '') || (bool)preg_match('/emoji|表情|贴图|sticker/i', (string)($q['field'] ?? ''));
        $out[] = ['field' => $isEmoji ? 'emoji' : 'habit', 'code' => $isEmoji ? $code : '', 'text' => $txt];
    }
    return $out;
}

/** 优化器的 system 提示词（另一个 AI 专门维护人格模型） */
function bot_optimize_system(string $tName, array $profile, array $stats, string $meName): string {
    $p = $profile ? json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '（还没有人格模型，需要先跑分析）';
    return "你是「人格优化器」。有一个模仿真人「{$tName}」说话的聊天机器人（主人是「{$meName}」），它的人格模型如下。\n"
        . "你的职责：帮主人把这套人格调得更像那个人。\n"
        . "1) 回答主人关于这人格的问题；\n"
        . "2) 需要的时候提问补全缺失信息 —— 尤其是**看不懂的表情包**（比如记录里 /x 出现很多次但没人知道 ta 想表达什么）；\n"
        . "3) 真的需要改人格时，给出具体的、可直接合并到 JSON 里的修改建议（patch）。\n"
        . "## 风格\n"
        . "- 主人只是问意见/问情况时，**正常回答就好**：不用提问、不用给 patch，也不要把话题引回改人格。\n"
        . "- questions / patch 是**可选**的：只有真的缺信息才提问，只有真的需要改才给 patch；两者都没有就写 [] 和 null。\n"
        . "- 像聊天一样，可以分成几条发：用 **@@** 分隔（最多 3 条），每条不要长；别把所有话挤成一大段。\n"
        . "- 主人贴一大段聊天记录进来时，先挑几件**能确定**的写进 patch（语气词/标点/连发习惯/常用表情和固定搭配），再把真不确定的拿去问。\n"
        . "- 主人告诉你关于 ta 的**具体事实**（真实姓名、工作、宠物、生日、习惯、两个人之间的事、约定…）时，写进 **patch.facts**（每条一句话），这是这个人的长期记忆，以后聊天会用上。\n"
        . "- 说话口语化，不要写报告、不要列标题。\n"
        . "改东西要保守：不确定就别写进 patch，改用 questions 问主人。不要动 samples / updated_at。\n\n"
        . "只输出严格 JSON（不要围栏、不要前言后语）。**字段名必须用下面这套，不要改名**：\n"
        . '{"reply":"给主人看的话（口语化，1-3 句；要分条就写 @@ ）",'
        . ' "questions":[{"field":"emoji","code":"/xx 或 贴图hash","text":"想问主人的话"},{"field":"habit","code":"","text":"想问的说话习惯问题"}],'
        . ' "patch":{"emoji_meanings":[{"code":"/xx","meaning":"…"}],"habits":["…"],"taboos":["…"],"facts":["关于 ta 的具体事实，如：养了只猫叫团子"]},'
        . ' "why":"为什么要这么改（一句话）"}' . "\n"
        . "questions 最多 5 条：表情含义写在 `code`（不要用 emoji/emoji_code 之类别的 key）；field 只能是 emoji 或 habit；没有就写 []。patch 只写真的要改的字段；没有就写 null。\n\n"
        . "## 当前人格模型\n" . $p . "\n\n"
        . "## 确定性统计\n" . json_encode($stats, JSON_UNESCAPED_UNICODE);
}

/** 把 patch 合并进人格模型（纯函数，可单测）
 *  - habits/taboos 这类字符串数组：去重追加
 *  - emoji_meanings：[{code,meaning}] 按 code 覆盖，没有就追加；支持 "/xx=意思" 简写
 *  - psych/style 这类对象：逐键合并（新值覆盖旧值）
 *  - samples/updated_at：模型不许改
 */
function bot_profile_merge(array $prof, array $patch, int $depth = 0): array {
    if ($depth > 4) return $prof;
    foreach ($patch as $k => $v) {
        if (!is_string($k) || $k === '') continue;
        if (in_array($k, ['samples', 'updated_at', 'repaired'], true)) continue;
        $cur = $prof[$k] ?? null;
        if (is_array($v) && array_is_list($v)) {
            // 记忆点 / 事实列表：按 text 去重（模型可能写成字符串或 {text:…}）
            if ($k === 'facts' || $k === 'memories') {
                $items = is_array($cur) && array_is_list($cur) ? $cur : [];
                $seen = [];
                foreach ($items as $it) {
                    $t = is_array($it) ? trim((string)($it['text'] ?? '')) : trim((string)$it);
                    if ($t !== '') $seen[$t] = 1;
                }
                foreach ($v as $it) {
                    $t = is_array($it) ? trim((string)($it['text'] ?? '')) : trim((string)$it);
                    if ($t === '' || isset($seen[$t])) continue;
                    $seen[$t] = 1;
                    $items[] = ['text' => mb_substr($t, 0, 200), 'at' => date('Y-m-d H:i')];
                }
                $prof[$k] = array_values($items);
                continue;
            }
            if ($k === 'emoji_meanings') {
                $byCode = [];
                foreach ((array)$cur as $i => $em) {
                    if (is_array($em) && isset($em['code'])) $byCode[(string)$em['code']] = $i;
                }
                $items = is_array($cur) && array_is_list($cur) ? $cur : [];
                foreach ($v as $em) {
                    if (is_string($em) && preg_match('#^\s*(\S+?)\s*[=＝:：]\s*(.+)$#u', $em, $m)) $em = ['code' => $m[1], 'meaning' => $m[2]];
                    if (!is_array($em) || !isset($em['code']) || trim((string)$em['code']) === '') continue;
                    $code = trim((string)$em['code']);
                    if (isset($byCode[$code])) $items[$byCode[$code]] = array_merge((array)$items[$byCode[$code]], $em);
                    else { $items[] = $em; $byCode[$code] = count($items) - 1; }
                }
                $prof[$k] = array_values($items);
                continue;
            }
            $base = is_array($cur) && array_is_list($cur) ? $cur : [];
            foreach ($v as $item) {
                if (is_scalar($item)) {
                    $s = trim((string)$item);
                    if ($s === '') continue;
                    $exists = false;
                    foreach ($base as $b) { if (is_scalar($b) && (string)$b === $s) { $exists = true; break; } }
                    if (!$exists) $base[] = $s;
                } elseif (is_array($item)) {
                    $base[] = $item;
                }
            }
            $prof[$k] = $base;
            continue;
        }
        if (is_array($v)) { $prof[$k] = bot_profile_merge(is_array($cur) ? $cur : [], $v, $depth + 1); continue; }
        if (is_scalar($v)) $prof[$k] = $v;
    }
    return $prof;
}

/**
 * 跑一次分析：调用 → 解析 → 失败就再来一轮「JSON 修复」。
 * @param callable $call function(string $system, string $user, bool $repair): string
 *        $repair=true 表示这是修复轮：流式场景下别再往同一个 SSE 流里推原文
 * @return array{profile:?array, raw:string, repaired:bool, reason:?string}
 */
function bot_analyze_run(callable $call, string $tName, array $stats, string $dialog, bool $stream = false): array {
    $r = bot_json_call($call, bot_analyze_system($tName), bot_analyze_user($stats, $dialog), $stream);
    return ['profile' => $r['data'], 'raw' => $r['raw'], 'repaired' => $r['repaired'], 'reason' => $r['reason']];
}

/* 自测（不需要网络）：
   php -r 'require "api/bot_analyze.php"; $cases=["{\"a\":1}", "前言\n```json\n{\"a\":2}\n```", "{\"a\":3,}",
   "{\"a\":{\"b\":\"含 } 和 \\\"引号\\\"\"}}", "好的：{\"a\":4} 以上", "{\"a\":\"中文引号“x”\"}", "不是 JSON"];
   foreach ($cases as $c) { var_dump(bot_extract_json($c)); }'
*/
