<?php
/**
 * ChatApp · DeepSeek AI 聊天代理（apps/deepseek/api.php）
 *
 * 纯转发：API Key 由前端每次请求体带入，本代理**不落库、不写日志**。
 * 用 SSE 边收边回，实现流式逐字输出。仅允许 api.deepseek.com（防开放代理）。
 *
 * 请求：POST JSON { key, model, messages:[{role,content}], temperature?, max_tokens? }
 * 响应：text/event-stream（原样转发 DeepSeek 的 data: 行）
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
require_once __DIR__ . '/../../maintenance.php';

// 关掉一切缓冲，保证边收边发
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_clean(); }

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

function ds_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo "event: error\n";
    echo 'data: ' . json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
    exit;
}

/* JSON Schema 修正：PHP 把 json_decode(..., true) 的 {} 变成 []，再 encode 就成了 []，
   DeepSeek 会报 “[] is not of type object”。按字段名把该是对象的位置改回对象。 */
function ds_schema_fix($node, string $key = '') {
    if (!is_array($node)) return $node;
    $isList = ($node === [] || array_keys($node) === range(0, count($node) - 1));
    if ($isList) {
        if ($node === []) {
            return in_array($key, ['properties', 'patternProperties', 'definitions', '$defs'], true) ? new stdClass() : [];
        }
        $out = [];
        foreach ($node as $v) $out[] = ds_schema_fix($v, '');
        return $out;
    }
    $out = [];
    foreach ($node as $k => $v) $out[(string)$k] = ds_schema_fix($v, (string)$k);
    return $out;
}

/* 工具定义清洗：只放行标准 function 形态，并把 schema 里的空对象修正回去 */
function ds_sanitize_tools(array $list): array {
    $tools = [];
    foreach ($list as $t) {
        if (!is_array($t)) continue;
        $fn = $t['function'] ?? null;
        if (!is_array($fn)) continue;
        $nm = (string)($fn['name'] ?? '');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $nm)) continue;
        $desc = (string)($fn['description'] ?? '');
        if (mb_strlen($desc) > 1024) $desc = mb_substr($desc, 0, 1024);
        $params = $fn['parameters'] ?? null;
        if (!is_array($params) || !$params) $params = ['type' => 'object'];
        $params = ds_schema_fix($params);
        if (!isset($params['type']) || !is_string($params['type'])) $params['type'] = 'object';
        // type=object 时 properties 必须是对象，不能是 []
        if ($params['type'] === 'object' && (!isset($params['properties']) || !is_object($params['properties']))) {
            $props = $params['properties'] ?? null;
            $params['properties'] = (is_array($props) && $props) ? (object)$props : new stdClass();
        }
        if (strlen(json_encode($params)) > 16384) continue;
        $tools[] = ['type' => 'function', 'function' => ['name' => $nm, 'description' => $desc, 'parameters' => $params]];
        if (count($tools) >= 32) break;
    }
    return $tools;
}

$raw = file_get_contents('php://input');
$in = json_decode((string)$raw, true);
if (!is_array($in)) ds_err('Bad request');

$key = trim((string)($in['key'] ?? ''));
if ($key === '') ds_err('缺少 API Key');
if (!function_exists('curl_init')) ds_err('服务器未安装 cURL', 500);

// 当前只允许 V4 Flash（其余模型一律回退到它）
$ALLOWED_MODELS = ['deepseek-v4-flash'];
$model = (string)($in['model'] ?? 'deepseek-v4-flash');
if (!in_array($model, $ALLOWED_MODELS, true)) $model = 'deepseek-v4-flash';

$messages = $in['messages'] ?? [];
if (!is_array($messages) || count($messages) === 0) ds_err('缺少消息');
// 消息清洗：只保留 role/content，role 白名单，限制单条长度，防注入怪异负载
// content 允许两种形态：
//   1) 字符串（普通文本）
//   2) 数组（视觉输入）：[{type:'text',text},{type:'image_url',image_url:{url:'data:image/png;base64,…'}}]
//      —— 图片只接受 data:image/*（拒绝任何外链 URL，防 SSRF / 探测），并限制张数与体积
$clean = [];
$totalImgBytes = 0;
foreach ($messages as $m) {
    if (!is_array($m)) continue;
    $role = (string)($m['role'] ?? '');
    if (!in_array($role, ['system', 'user', 'assistant', 'tool'], true)) continue;
    $raw = $m['content'] ?? '';

    // 原生 function calling：assistant 的 tool_calls / tool 结果的 tool_call_id 原样透传（做白名单清洗）
    $toolCalls = null;
    if ($role === 'assistant' && isset($m['tool_calls']) && is_array($m['tool_calls'])) {
        $toolCalls = [];
        foreach ($m['tool_calls'] as $tc) {
            if (!is_array($tc)) continue;
            $fn = $tc['function'] ?? [];
            $nm = (string)($fn['name'] ?? '');
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $nm)) continue;
            $argStr = $fn['arguments'] ?? '';
            if (is_array($argStr)) $argStr = json_encode($argStr, JSON_UNESCAPED_UNICODE);
            $argStr = (string)$argStr;
            if (strlen($argStr) > 8000) $argStr = substr($argStr, 0, 8000);
            $toolCalls[] = [
                'id'   => substr((string)($tc['id'] ?? ('call_' . count($toolCalls))), 0, 64),
                'type' => 'function',
                'function' => ['name' => $nm, 'arguments' => ($argStr === '' ? '{}' : $argStr)],
            ];
            if (count($toolCalls) >= 8) break;
        }
    }
    $toolCallId = ($role === 'tool') ? substr((string)($m['tool_call_id'] ?? ''), 0, 64) : '';
    if ($role === 'tool' && $toolCallId === '') continue;

    if (is_array($raw)) {
        $parts = [];
        $imgs = 0;
        foreach ($raw as $part) {
            if (!is_array($part)) continue;
            $ptype = (string)($part['type'] ?? '');
            if ($ptype === 'text') {
                $t = (string)($part['text'] ?? '');
                if (mb_strlen($t) > 20000) $t = mb_substr($t, 0, 20000);
                if ($t !== '') $parts[] = ['type' => 'text', 'text' => $t];
                continue;
            }
            if ($ptype !== 'image_url') continue;
            $url = (string)($part['image_url']['url'] ?? ($part['url'] ?? ''));
            if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $url)) continue;
            $len = strlen($url);
            if ($len > 3 * 1024 * 1024) continue;                             // 单张 ≤ ~2.2MB 二进制
            if ($imgs >= 4 || $totalImgBytes + $len > 8 * 1024 * 1024) break; // 每条消息 ≤ 4 张、累计 ≤ 8MB
            $imgs++;
            $totalImgBytes += $len;
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }
        if (!$parts) continue;
        $out = ['role' => $role, 'content' => $parts];
        if ($toolCalls) $out['tool_calls'] = $toolCalls;
        if ($toolCallId !== '') $out['tool_call_id'] = $toolCallId;
        $clean[] = $out;
        continue;
    }

    $content = (string)$raw;
    if ($content === '') {
        // assistant 只带 tool_calls、没有正文是合法的
        if ($toolCalls) {
            $clean[] = ['role' => $role, 'content' => '', 'tool_calls' => $toolCalls];
        }
        continue;
    }
    if (mb_strlen($content) > 60000) $content = mb_substr($content, 0, 60000);
    $out2 = ['role' => $role, 'content' => $content];
    if ($toolCalls) $out2['tool_calls'] = $toolCalls;
    if ($toolCallId !== '') $out2['tool_call_id'] = $toolCallId;
    $clean[] = $out2;
}
if (!$clean) ds_err('消息为空');
// 只保留最近 60 条，控制成本
if (count($clean) > 60) $clean = array_slice($clean, -60);

$payload = ['model' => $model, 'messages' => $clean, 'stream' => true];

// 原生工具定义透传（只放行标准 function 形态，防奇怪负载）
if (isset($in['tools']) && is_array($in['tools'])) {
    $tools = ds_sanitize_tools($in['tools']);
    if ($tools) {
        $payload['tools'] = $tools;
        $payload['tool_choice'] = 'auto';
    }
}
if (isset($in['temperature']) && is_numeric($in['temperature'])) {
    $t = (float)$in['temperature'];
    if ($t < 0) $t = 0; if ($t > 2) $t = 2;
    $payload['temperature'] = $t;
}
if (!empty($in['max_tokens']) && is_numeric($in['max_tokens'])) {
    $mt = (int)$in['max_tokens'];
    if ($mt < 1) $mt = 1; if ($mt > 8192) $mt = 8192;
    $payload['max_tokens'] = $mt;
}

$httpStatus = 0;
$errBody = '';

$ch = curl_init('https://api.deepseek.com/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: text/event-stream',
        'Authorization: Bearer ' . $key,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$httpStatus) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $httpStatus = (int)$m[1];
        return strlen($line);
    },
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$httpStatus, &$errBody) {
        if ($httpStatus === 200) {
            echo $chunk;
            @flush();
        } else {
            // 非 200：先攒着，结束后统一报错（通常是 JSON 错误，不是 SSE）
            $errBody .= $chunk;
            if (strlen($errBody) > 8192) $errBody = substr($errBody, 0, 8192);
        }
        return strlen($chunk);
    },
]);

$ok = curl_exec($ch);
$curlErr = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($ok === false) {
    ds_err('连接 DeepSeek 失败：' . ($curlErr ?: 'unknown'), 502);
}
if ($code !== 200 && $httpStatus !== 200) {
    $status = $code ?: $httpStatus;
    $msg = 'DeepSeek 返回 ' . $status;
    $j = json_decode($errBody, true);
    if (is_array($j) && isset($j['error']['message'])) $msg = (string)$j['error']['message'];
    elseif (is_array($j) && isset($j['error']) && is_string($j['error'])) $msg = $j['error'];
    if ($status === 401) $msg = 'API Key 无效或已过期（' . $msg . '）';
    elseif ($status === 402) $msg = 'DeepSeek 余额不足（' . $msg . '）';
    elseif ($status === 429) $msg = '请求过于频繁，请稍后重试（' . $msg . '）';
    ds_err($msg, 502);
}

echo "event: done\n";
echo "data: [DONE]\n\n";
@flush();
