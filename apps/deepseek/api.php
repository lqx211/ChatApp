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

$raw = file_get_contents('php://input');
$in = json_decode((string)$raw, true);
if (!is_array($in)) ds_err('Bad request');

$key = trim((string)($in['key'] ?? ''));
if ($key === '') ds_err('缺少 API Key');
if (!function_exists('curl_init')) ds_err('服务器未安装 cURL', 500);

$ALLOWED_MODELS = ['deepseek-chat', 'deepseek-reasoner', 'deepseek-v4-flash', 'deepseek-v4-pro'];
$model = (string)($in['model'] ?? 'deepseek-chat');
if (!in_array($model, $ALLOWED_MODELS, true)) $model = 'deepseek-chat';

$messages = $in['messages'] ?? [];
if (!is_array($messages) || count($messages) === 0) ds_err('缺少消息');
// 消息清洗：只保留 role/content，role 白名单，限制单条长度，防注入怪异负载
$clean = [];
foreach ($messages as $m) {
    if (!is_array($m)) continue;
    $role = (string)($m['role'] ?? '');
    if (!in_array($role, ['system', 'user', 'assistant'], true)) continue;
    $content = (string)($m['content'] ?? '');
    if ($content === '') continue;
    if (mb_strlen($content) > 60000) $content = mb_substr($content, 0, 60000);
    $clean[] = ['role' => $role, 'content' => $content];
}
if (!$clean) ds_err('消息为空');
// 只保留最近 60 条，控制成本
if (count($clean) > 60) $clean = array_slice($clean, -60);

$payload = ['model' => $model, 'messages' => $clean, 'stream' => true];
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
