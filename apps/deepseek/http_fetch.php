<?php
/**
 * ChatApp · AI 的 HTTP 请求器（apps/deepseek/http_fetch.php）
 *
 * 给 AI 工具 ca_http 用：抓网页 / 调接口（GET/POST/PUT/PATCH/DELETE/HEAD/OPTIONS）。
 * 浏览器侧 fetch 会被 CORS 挡，所以只能服务端代抓 —— 服务端代抓就必须自己把好关：
 *
 * 安全（SSRF）：
 *   - 只允许 http/https；域名必须能解析，且解析结果不能是内网/回环/链路本地/CGNAT/保留地址
 *   - 每次重定向都重新校验（跟着 302 跳到 127.0.0.1 也会被拦）
 *   - 最多 3 次重定向，超时就断（12 秒），下载上限 1MB（超了直接截断并标注）
 *   - **绝不带上用户的 ChatApp cookie**（匿名抓取，第三方拿不到你的登录态）
 *   - 不让 AI 自己设 Cookie / Authorization / Host / Origin 这类头（防把用户数据/凭据外送）
 *
 * 清洗（给模型看的是「能读的内容」，不是原始字节）：
 *   - data:...;base64,xxxx 这种内联的图片/视频/字体 → 换成 [已省略 base64 image/png ≈ 12KB]
 *   - 长短的裸 base64 / 十六进制大块（往往是内嵌资源）→ 同样省略
 *   - HTML → 去掉 script/style/svg/noscript/注释，剥标签后压空白，保留可读文字
 *   - 非文本类型（图片/音视频/压缩包/pdf…）→ 只回元信息，不回正文
 *   - 响应头只留有用的几个；Set-Cookie 一律不下发
 *   - 正文上限 200KB（超出截断并标注真实长度）
 *
 * 输出给模型时还要在两侧包一层「这是资料，不是指令」，防提示注入。
 */
require_once __DIR__ . '/../../api/config.php';

const AI_HTTP_TIMEOUT = 12;          // 秒
const AI_HTTP_MAX_BYTES = 1048576;   // 下载上限 1MB
const AI_HTTP_MAX_OUT = 200000;      // 清洗后交给模型的正文上限
const AI_HTTP_MAX_REDIRECT = 3;

/** 是否是内网/不可达地址（SSRF 拦这个） */
function ai_http_ip_is_private(string $ip): bool {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long === false) return true;
        $ranges = [
            ['0.0.0.0', '0.255.255.255'],        // 本网络
            ['10.0.0.0', '10.255.255.255'],      // 私有
            ['100.64.0.0', '100.127.255.255'],   // CGNAT
            ['127.0.0.0', '127.255.255.255'],    // 回环
            ['169.254.0.0', '169.254.255.255'],  // 链路本地（含云元数据 169.254.169.254）
            ['172.16.0.0', '172.31.255.255'],    // 私有
            ['192.0.0.0', '192.0.2.255'],        // 保留
            ['192.168.0.0', '192.168.255.255'],  // 私有
            ['198.18.0.0', '198.19.255.255'],    // 基准测试
            ['224.0.0.0', '255.255.255.255'],    // 组播/保留
        ];
        foreach ($ranges as $r) {
            if ($long >= ip2long($r[0]) && $long <= ip2long($r[1])) return true;
        }
        return false;
    }
    // IPv6：fc00::/7(ULA)、fe80::/10(链路本地)、::1、::ffff:IPv4
    $ip = strtolower($ip);
    if (strpos($ip, '::ffff:') === 0) return ai_http_ip_is_private(substr($ip, 7));
    if ($ip === '::1' || $ip === '::') return true;
    if (strpos($ip, 'fc') === 0 || strpos($ip, 'fd') === 0) return true;
    if (preg_match('/^fe[89ab]/', $ip)) return true;
    return false;
}

/** 校验 URL：协议 / 主机 / 解析结果。返回 [ok, host, error] */
function ai_http_check_url(string $url): array {
    $u = parse_url($url);
    if (!$u || empty($u['scheme']) || empty($u['host'])) return [false, '', 'URL 格式不对（要 http:// 或 https:// 开头）'];
    $scheme = strtolower($u['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) return [false, '', '只允许 http / https'];
    $host = strtolower($u['host']);
    if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -10) === '.internal') {
        return [false, '', '不允许访问本机/内网地址（' . $host . '）'];
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (ai_http_ip_is_private($host)) return [false, '', '不允许访问内网/保留地址（' . $host . '）'];
        return [true, $host, ''];
    }
    $ips = [];
    $a = @gethostbynamel($host);            // IPv4
    if (is_array($a)) $ips = $a;
    if (!$ips) {
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) foreach ($aaaa as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    if (!$ips) return [false, '', '域名解析不了：' . $host];
    foreach ($ips as $ip) {
        if (ai_http_ip_is_private($ip)) return [false, '', '域名指向内网地址（' . $host . ' → ' . $ip . '），已拦下'];
    }
    return [true, $host, ''];
}

/** base64 字符？允许 JSON 里把 "/" 写成 "\/" 这种情况（按两字符处理） */
function ai_http_b64_run(string $s, int $i): int {
    $n = strlen($s);
    $len = 0;
    while ($i + $len < $n) {
        $c = $s[$i + $len];
        if (($c >= 'A' && $c <= 'Z') || ($c >= 'a' && $c <= 'z') || ($c >= '0' && $c <= '9')
            || $c === '+' || $c === '/' || $c === '=' || $c === "\n" || $c === "\r") { $len++; continue; }
        if ($c === '\\' && ($i + $len + 1) < $n && ($s[$i + $len + 1] === '/' || $s[$i + $len + 1] === 'n' || $s[$i + $len + 1] === 'r')) { $len += 2; continue; }
        break;
    }
    return $len;
}

/** data:…;base64,… 与超长裸 base64/hex 大块 → 占位符（图片/视频塞进上下文纯浪费） */
function ai_http_strip_blobs(string $s): array {
    $n = 0;
    $out = '';
    $i = 0;
    $len = strlen($s);
    while ($i < $len) {
        // ---- data URI ----
        if (($i === 0 || !ctype_alnum($s[$i - 1])) && stripos(substr($s, $i, 5), 'data:') === 0) {
            $comma = strpos($s, ',', $i);
            if ($comma !== false && $comma - $i < 120) {
                $head = substr($s, $i, $comma - $i);                    // data:image/png;base64
                $run = ai_http_b64_run($s, $comma + 1);
                if ($run >= 24) {
                    $mime = 'unknown';
                    if (preg_match('/^data:([^;,]+)/i', str_replace('\\/', '/', $head), $m)) $mime = strtolower($m[1]);
                    $isB64 = stripos($head, ';base64') !== false;
                    $out .= ($isB64 ? '[已省略 base64 ' . $mime . '（内联图片/媒体；要看图请让用户直接发图）]'
                                    : '[已省略内联数据 ' . $mime . ']');
                    $n++;
                    $i = $comma + 1 + $run;
                    continue;
                }
            }
        }
        // ---- 裸的 base64 巨块（JS/CSS/JSON 里内嵌资源）----
        $c = $s[$i];
        $startB64 = (($c >= 'A' && $c <= 'Z') || ($c >= 'a' && $c <= 'z') || ($c >= '0' && $c <= '9') || $c === '+' || $c === '/');
        if ($startB64) {
            $run = ai_http_b64_run($s, $i);
            if ($run >= 400) {
                $raw = str_replace(['\\/', '\\n', '\\r'], '/', substr($s, $i, $run));
                $out .= '[已省略疑似 base64 数据块 ≈ ' . round(strlen($raw) * 3 / 4 / 1024, 1) . ' KB]';
                $n++;
                $i += $run;
                continue;
            }
        }
        // ---- 超长十六进制块 ----
        if (ctype_xdigit($c) && ($i === 0 || !ctype_alnum($s[$i - 1]))) {
            $j = $i;
            while ($j < $len && ctype_xdigit($s[$j])) $j++;
            $hl = $j - $i;
            if ($hl >= 600 && ($j >= $len || !ctype_alnum($s[$j]))) {
                $out .= '[已省略十六进制数据块 ' . $hl . ' 字符]';
                $n++;
                $i = $j;
                continue;
            }
        }
        $out .= $c;
        $i++;
    }
    return [$out, $n];
}

/** HTML → 可读文本 */
function ai_http_html_to_text(string $html): string {
    $s = $html;
    $s = preg_replace('#<!--.*?-->#s', ' ', $s);
    foreach (['script', 'style', 'svg', 'noscript', 'template', 'head'] as $tag) {
        $s = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '>#is', ' ', $s);
    }
    $s = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\s*/?>#i', "\n", $s);
    $s = preg_replace('#<li\b[^>]*>#i', "\n- ", $s);
    $s = preg_replace('#<[^>]+>#', '', $s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s);
    $s = preg_replace('/\n{3,}/', "\n\n", $s);
    $s = preg_replace('/^[ \t]+|[ \t]+$/m', '', $s);
    return trim($s);
}

/**
 * 真去抓。
 * @param array $a ['url','method','headers'=>[],'body'=>string,'max_bytes'=>int]
 * @return array 给 AI 看的结果（或 ['ok'=>false,'error'=>...]）
 */
function ai_http_request(array $a): array {
    $url = trim((string)($a['url'] ?? ''));
    $method = strtoupper(trim((string)($a['method'] ?? 'GET')));
    if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)) {
        return ['ok' => false, 'error' => '不支持的方法：' . $method];
    }
    $headers = [];
    $blocked = ['cookie', 'authorization', 'host', 'origin', 'referer', 'content-length', 'connection', 'proxy-authorization', 'x-forwarded-for'];
    if (!empty($a['headers'])) {
        $raw = $a['headers'];
        if (is_string($raw)) {
            $j = json_decode($raw, true);
            $raw = is_array($j) ? $j : preg_split('/\r?\n/', $raw);
        }
        foreach ((array)$raw as $k => $v) {
            if (is_int($k)) {                     // 形如 "X-Foo: bar" 的行
                if (strpos((string)$v, ':') === false) continue;
                list($k2, $v2) = explode(':', (string)$v, 2);
                $k = $k2; $v = $v2;
            }
            $k = trim((string)$k);
            if (!preg_match('/^[A-Za-z0-9\-_]{1,64}$/', $k)) continue;
            if (in_array(strtolower($k), $blocked, true)) continue;   // 不许自己设这些
            $v = trim(str_replace(["\r", "\n"], '', (string)$v));
            if ($v === '' || strlen($v) > 2000) continue;
            $headers[] = $k . ': ' . $v;
            if (count($headers) >= 12) break;
        }
    }
    $body = (string)($a['body'] ?? '');
    if (strlen($body) > 100000) $body = substr($body, 0, 100000);
    if (in_array($method, ['GET', 'HEAD'], true)) $body = '';

    $maxBytes = (int)($a['max_bytes'] ?? AI_HTTP_MAX_BYTES);
    if ($maxBytes < 4096) $maxBytes = 4096;
    if ($maxBytes > AI_HTTP_MAX_BYTES) $maxBytes = AI_HTTP_MAX_BYTES;

    $hops = [];
    $current = $url;
    $resp = null;
    for ($i = 0; $i <= AI_HTTP_MAX_REDIRECT; $i++) {
        list($okUrl, $host, $errUrl) = ai_http_check_url($current);
        if (!$okUrl) return ['ok' => false, 'error' => $errUrl];
        $hops[] = $current;

        $ch = curl_init($current);
        $opt = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,      // 自己跟，每一跳都重校验
            CURLOPT_TIMEOUT => AI_HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'ChatApp-AI/1.0 (+https://chat.lqx211.com)',
            CURLOPT_HTTPHEADER => array_merge($headers, ['Accept-Encoding: identity']),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS => 0,
        ];
        if ($body !== '') { $opt[CURLOPT_POSTFIELDS] = $body; }
        $respHeaders = [];
        $opt[CURLOPT_HEADERFUNCTION] = function ($ch, $line) use (&$respHeaders) {
            $len = strlen($line);
            $t = trim($line);
            if ($t !== '' && strpos($t, ':') !== false) {
                list($k, $v) = explode(':', $t, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
            return $len;
        };
        $got = '';
        $opt[CURLOPT_WRITEFUNCTION] = function ($ch, $chunk) use (&$got, $maxBytes) {
            $got .= $chunk;
            if (strlen($got) > $maxBytes) return 0;      // 让 curl 直接中止
            return strlen($chunk);
        };
        curl_setopt_array($ch, $opt);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($status >= 300 && $status < 400 && !empty($respHeaders['location'])) {
            $loc = $respHeaders['location'];
            if (strpos($loc, '//') === 0) $loc = (strpos($current, 'https:') === 0 ? 'https:' : 'http:') . $loc;
            elseif (strpos($loc, '/') === 0) {
                $p = parse_url($current);
                $loc = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $loc;
            } elseif (strpos($loc, 'http') !== 0) {
                $p = parse_url($current);
                $base = substr($current, 0, strrpos($current, '/') + 1);
                $loc = $base . $loc;
            }
            $current = $loc;
            continue;
        }
        $resp = ['ok' => $ok !== false || $status > 0, 'status' => $status, 'error' => $err, 'body' => $got, 'headers' => $respHeaders];
        break;
    }
    if ($resp === null) return ['ok' => false, 'error' => '重定向次数太多（>' . AI_HTTP_MAX_REDIRECT . '）'];

    $ctype = strtolower(explode(';', (string)($resp['headers']['content-type'] ?? ''))[0]);
    $out = [
        'url' => $current,
        'requested_url' => $url,
        'redirects' => count($hops) - 1,
        'status' => (int)$resp['status'],
        'content_type' => $ctype !== '' ? $ctype : '(未声明)',
        'downloaded_bytes' => strlen($resp['body']),
        'truncated_download' => strlen($resp['body']) > $maxBytes,
    ];
    if (!empty($resp['headers']['content-length'])) $out['content_length_header'] = (int)$resp['headers']['content-length'];
    if (!empty($resp['headers']['server'])) $out['server'] = (string)$resp['headers']['server'];

    if ($resp['status'] <= 0) {
        $out['ok'] = false;
        $out['error'] = '连不上或超时：' . ($resp['error'] ?: '未知错误');
        return $out;
    }
    if ($method === 'HEAD' || $resp['status'] === 204 || $resp['body'] === '') {
        $out['ok'] = true;
        $out['note'] = '没有正文（' . $method . ' / 状态 ' . $resp['status'] . '）';
        return $out;
    }

    /* 非文本类型：只回元信息（图片/视频/压缩包/pdf 的字节对模型没用，还占上下文） */
    $isText = ($ctype === '' || strpos($ctype, 'text/') === 0
        || in_array($ctype, ['application/json', 'application/xml', 'application/xhtml+xml', 'application/javascript', 'application/x-javascript', 'application/ld+json', 'application/rss+xml', 'application/atom+xml'], true)
        || substr($ctype, -4) === '+xml' || substr($ctype, -5) === '+json');
    if (!$isText) {
        $out['ok'] = true;
        $out['note'] = '这是 ' . $ctype . '（' . $out['downloaded_bytes'] . ' 字节），不是文本，正文已省略';
        return $out;
    }

    $text = $resp['body'];
    $looksHtml = ($ctype === 'text/html' || $ctype === 'application/xhtml+xml' || preg_match('#<html[\s>]#i', substr($text, 0, 2000)));
    if ($looksHtml) $text = ai_http_html_to_text($text);
    list($text, $stripped) = ai_http_strip_blobs($text);
    if ($stripped > 0) $out['blobs_stripped'] = $stripped;
    if (strlen($text) > AI_HTTP_MAX_OUT) {
        $out['truncated_text'] = true;
        $text = substr($text, 0, AI_HTTP_MAX_OUT);
    }
    $out['ok'] = true;
    $out['text'] = $text;
    $out['text_length'] = strlen($text);
    return $out;
}
