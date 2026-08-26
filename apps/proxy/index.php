<?php
/**
 * ChatApp 通用网页代理（apps/proxy）
 *
 * Client → 本服务器 → Target
 * 学校 DNS/SNI 封锁域名时，透过本页浏览普通网页（新闻/文档/论坛/工具站）。
 *
 * URL 形式（路径式，支持 JS 相对请求）：
 *   index.php?u=<目标>           首页表单提交 → 302 到路径式
 *   index.php/q/<base64url目标>           代理页面 / 资源
 *   index.php/q/<base64url目标>/<相对>    目标页 JS 内部相对请求（靠 <base> 拼接）
 *
 * 说明：
 *  - 页面重写式（类 Glype/PHP-Proxy）。注入 <base> 指向当前代理 URL，
 *    使目标页 JS 的相对请求（fetch('auth.php')、动态加载资源）也走代理。
 *  - 对重度 SPA / WebSocket / Cloudflare JS 挑战不适用，仅作普通浏览代理。
 *
 * 安全：
 *  - 仅允许 http/https 目标（防 SSRF 可设 PROXY_ALLOWED_HOSTS）
 *  - 需 ChatApp 登录；受全局维护门保护
 *  - cookie jar 按 PHP session 隔离
 */

require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
require_once __DIR__ . '/../../maintenance.php';

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

/* ---------- 配置 ---------- */
define('PROXY_COOKIE_DIR', __DIR__ . '/../../data/proxy');   // cookie jar 目录
define('PROXY_TIMEOUT', 25);                                  // 目标抓取超时（秒）
define('PROXY_MAX_SIZE', 15 * 1024 * 1024);                   // 响应体上限 15MB
define('PROXY_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36');

// 可选：SSRF 防护——若要限定目标域名，放开下面数组（留空 = 允许任意 http/https）
define('PROXY_ALLOWED_HOSTS', '');   // 例：'deepseek.com,example.com'（逗号分隔，空=不限制）

/* ---------- 工具 ---------- */
function proxy_bad($msg, $code = 400) {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>网页代理</title>';
    echo '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:#0e1116;color:#d8dee9;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center}.box{max-width:520px;padding:28px 24px;border:1px solid #2b333d;border-radius:10px;background:#161b22}h1{font-size:20px;margin:0 0 10px}p{color:#8b949e;font-size:14px;line-height:1.6}a{color:#58a6ff}.err{color:#f85149}</style>';
    echo '</head><body><div class="box"><h1 class="err">代理错误</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="index.php">← 返回代理首页</a></p></div></body></html>';
    exit;
}

function proxy_norm($u) {
    if ($u === '' || $u === null) return '';
    $u = trim($u);
    if (strlen($u) > 4096) return '';
    if (!preg_match('#^https?://#i', $u)) {
        // 允许省略协议（自动补 https）
        if (preg_match('#^[a-z0-9.-]+(:[0-9]+)?(/.*)?$#i', $u)) $u = 'https://' . $u;
        else return '';
    }
    $p = parse_url($u);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return '';
    if (!in_array(strtolower($p['scheme']), ['http', 'https'])) return '';
    // SSRF 防护（可选白名单）
    if (defined('PROXY_ALLOWED_HOSTS') && PROXY_ALLOWED_HOSTS !== '') {
        $host = strtolower($p['host']);
        $allowed = array_map('trim', explode(',', PROXY_ALLOWED_HOSTS));
        $ok = false;
        foreach ($allowed as $a) {
            if ($host === strtolower($a) || substr($host, -strlen('.' . $a)) === '.' . strtolower($a)) { $ok = true; break; }
        }
        if (!$ok) proxy_bad('目标域名不在允许列表内。', 403);
    }
    return $u;
}

// base64url 编码/解码（无 / + =，可安全放路径）
function proxy_b64($u) {
    return rtrim(strtr(base64_encode($u), '+/', '-_'), '=');
}
function proxy_unb64($s) {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode($s, true);
}

// 代理脚本绝对路径（如 http://host/apps/proxy/index.php）
function proxy_base() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $self   = $_SERVER['SCRIPT_NAME'] ?? '/apps/proxy/index.php';
    return $scheme . '://' . $host . $self;
}

// 生成代理链接（路径式）：index.php/q/<b64>
function proxy_link($u) {
    return proxy_base() . '/q/' . proxy_b64($u);
}

// 把相对 URL 解析为绝对 URL（基于 base）
function proxy_abs($base, $ref) {
    $ref = trim($ref);
    if ($ref === '') return $base;
    if (preg_match('#^https?://#i', $ref)) return $ref;
    if ($ref[0] === '#') return $base . $ref; // 锚点保留
    $b = parse_url($base);
    if (!$b) return $ref;
    $scheme = $b['scheme'] ?? 'http';
    $host   = $b['host'] ?? '';
    if ($ref[0] === '//') return $scheme . ':' . $ref;                 // 协议相对
    $port   = isset($b['port']) ? ':' . $b['port'] : '';
    $origin = $scheme . '://' . $host . $port;
    $path   = $b['path'] ?? '/';
    $dir    = preg_replace('#/[^/]*$#', '/', $path);                    // 目录部分
    if ($ref[0] === '/') return $origin . $ref;                         // 根相对
    if ($ref[0] === '?') return $origin . $path . $ref;                 // 查询
    // 相对路径：处理 ./ 与 ../
    $parts = explode('/', $dir . $ref);
    $out = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') { if ($out) array_pop($out); }
        else $out[] = $part;
    }
    return $origin . '/' . implode('/', $out);
}

/* ---------- 会话 & cookie jar ---------- */
chatapp_session_start();
if (!is_dir(PROXY_COOKIE_DIR)) @mkdir(PROXY_COOKIE_DIR, 0775, true);
$jar = PROXY_COOKIE_DIR . '/' . (session_id() ?: 'anon') . '.jar';

/* ---------- 解析目标 URL ---------- */
// 路径式：REQUEST_URI 里 /index.php/q/<b64>[/相对]
$target = '';
$relSuffix = '';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$uri = preg_replace('/\?.*$/', '', $uri);   // 去掉 query
if (preg_match('#/index\.php/q/([A-Za-z0-9_-]+)(.*)$#', $uri, $m)) {
    $decoded = proxy_unb64($m[1]);
    if ($decoded !== false && proxy_norm($decoded) !== '') {
        $target = $decoded;
        $relSuffix = $m[2];   // 目标页 JS 相对请求拼出的后缀，如 /auth.php
    }
}
// 兜底：query 参数 u（首页表单）
if ($target === '' && isset($_GET['u'])) {
    $target = proxy_norm($_GET['u']);
    if ($target !== '') {
        // 统一 302 到路径式，保证 <base> 可拼接相对请求
        header('Location: ' . proxy_link($target));
        exit;
    }
}

// 目标页 JS 相对请求：b64 解码失败（如 ../ 抵消后）→ 用 session 记忆的最后目标兜底
if ($target === '' && isset($_SESSION['proxy_last'])) {
    $cand = proxy_abs($_SESSION['proxy_last'], $relSuffix ?: '/');
    if (proxy_norm($cand) !== '') $target = $cand;
}
// 相对后缀（b64 解码成功但带后缀）→ 解析到目标
if ($target !== '' && $relSuffix !== '') {
    $resolved = proxy_abs($target, $relSuffix);
    if (proxy_norm($resolved) !== '') $target = $resolved;
}

/* ---------- 首页 ---------- */
if ($target === '') {
    $placeholder = '输入要访问的网址，例如 https://example.com';
    ?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>网页代理</title>
<style>
  :root { --bg:#0e1116; --panel:#161b22; --border:#2b333d; --text:#d8dee9; --muted:#8b949e; --accent:#58a6ff; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
         background:var(--bg); color:var(--text); min-height:100vh; display:flex; align-items:center; justify-content:center; }
  .wrap { width:min(680px,92vw); }
  h1 { font-size:24px; margin:0 0 6px; }
  .sub { color:var(--muted); font-size:13px; margin:0 0 22px; line-height:1.6; }
  form { display:flex; gap:10px; }
  input[type=text] { flex:1; padding:13px 16px; border:1px solid var(--border); border-radius:10px;
         background:#0d1117; color:var(--text); font-size:15px; outline:none; }
  input[type=text]:focus { border-color:var(--accent); }
  button { padding:13px 26px; border:none; border-radius:10px; background:var(--accent); color:#fff;
           font-size:15px; font-weight:600; cursor:pointer; }
  button:hover { filter:brightness(1.1); }
  .tips { margin-top:20px; color:var(--muted); font-size:12px; line-height:1.7; }
  .tips code { background:#1c2128; padding:1px 6px; border-radius:5px; }
</style>
</head>
<body>
<div class="wrap">
  <h1><img src="../../data/res/cil/cil-globe-alt.svg" style="width:22px;height:22px;vertical-align:-4px;margin-right:6px;filter:brightness(0) invert(1)" alt=""> 网页代理</h1>
  <p class="sub">透过本服务器访问目标网页（绕过域名封锁）。登录态、表单、重定向均已处理。</p>
  <form method="get" action="index.php">
    <input type="text" name="u" placeholder="<?php echo htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'); ?>" autofocus autocomplete="off">
    <button type="submit">访问</button>
  </form>
  <p class="tips">
    支持：普通网页浏览、CSS/图片、表单提交、Cookie 会话、页面内 JS 相对请求。<br>
    不支持：JS 重度应用（SPA/聊天流式）、WebSocket、Cloudflare 验证。<br>
    提示：直接访问 <code>index.php?u=目标地址</code> 也可。
  </p>
</div>
</body>
</html><?php
    exit;
}

// 记忆当前目标（供 JS 相对请求兜底解析）
$_SESSION['proxy_last'] = $target;

/* ---------- 发起代理请求 ---------- */
$method = $_SERVER['REQUEST_METHOD'];   // GET / POST
$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => PROXY_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT      => PROXY_UA,
    CURLOPT_COOKIEFILE     => $jar,
    CURLOPT_COOKIEJAR      => $jar,
    CURLOPT_SSL_VERIFYPEER => false,   // 目标证书常自签/不可信，代理场景放开
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_ENCODING       => '',      // 自动解压 gzip/br
    CURLOPT_HEADER         => true,
    CURLOPT_NOBODY         => false,
    CURLOPT_MAXFILESIZE    => PROXY_MAX_SIZE,
    CURLOPT_HTTPHEADER     => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        'Referer: ' . (($_SERVER['HTTP_REFERER'] ?? '') !== '' ? $_SERVER['HTTP_REFERER'] : proxy_link($target)),
    ],
]);
if ($method === 'POST') {
    curl_setopt($ch, CURLOPT_POST, true);
    // 透传表单数据（去掉代理自身的 u 参数）
    $body = $_POST;
    unset($body['u']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
}
$raw = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

if ($errno) {
    proxy_bad('无法连接目标站点：' . $error . '（超时或站点不可达）', 502);
}
if ($status === 0 || $status >= 400) {
    if ($status === 404) proxy_bad('目标页面不存在（404）。', 404);
    if ($status === 403) proxy_bad('目标站点拒绝了请求（403，可能被 Cloudflare/防火墙拦截）。', 502);
    if ($status >= 500) proxy_bad('目标站点服务器错误（HTTP ' . $status . '）。', 502);
}

$body = (string)substr($raw, $headerSize);
$headerBlock = (string)substr($raw, 0, $headerSize);

// 解析响应头（用于 Location 重定向处理）
$location = '';
if (preg_match('/^Location:\s*(.+)$/mi', $headerBlock, $m)) $location = trim($m[1]);

// 重定向：若目标跳转，重写为代理链接并 302
if ($location !== '') {
    $abs = proxy_abs($finalUrl ?: $target, $location);
    header('Location: ' . proxy_link($abs));
    exit;
}

/* ---------- 按 Content-Type 分流 ---------- */
$ct = strtolower($contentType);
$isHtml = (strpos($ct, 'text/html') !== false);
$isCss  = (strpos($ct, 'text/css') !== false);

if ($isHtml) {
    header('Content-Type: text/html; charset=utf-8');

    // 1) 取 <base>（若有）作为相对路径基准
    if (preg_match('/<base[^>]+href=["\']([^"\']+)["\']/i', $body, $m)) {
        $base = proxy_abs($finalUrl ?: $target, $m[1]);
    } else {
        $base = $finalUrl ?: $target;
    }

    // 2) 重写 href/src/action 属性（srcset 一起）
    $rewriteAttr = function ($matches) use ($base) {
        $full = $matches[0];
        $attr = $matches[1];      // 形如 href=
        $quote = $matches[2];     // 引号
        $val  = $matches[3];      // 属性值
        // 保留锚点等纯页内目标
        if ($val !== '' && $val[0] === '#') return $full;
        // 保留 data:/javascript:/mailto:/tel: 等非页面地址
        if (preg_match('#^(data|javascript|mailto|tel|blob|about):#i', $val)) return $full;
        $u = proxy_abs($base, $val);
        return $attr . $quote . proxy_link($u) . $quote;
    };
    $body = preg_replace_callback('/(\b(?:href|src|action)\s*=\s*)(["\'])(.*?)\2/i', $rewriteAttr, $body);

    // srcset：逗号分隔 "url 描述"
    $rewriteSrcset = function ($matches) use ($base) {
        $attr = $matches[1];     // 形如 srcset=
        $quote = $matches[2];
        $items = explode(',', $matches[3]);
        $out = [];
        foreach ($items as $it) {
            $it = trim($it);
            if ($it === '') continue;
            if (preg_match('/^(\S+)(\s+.*)?$/', $it, $mm)) {
                $out[] = proxy_link(proxy_abs($base, $mm[1])) . ($mm[2] ?? '');
            } else {
                $out[] = $it;
            }
        }
        return $attr . $quote . implode(', ', $out) . $quote;
    };
    $body = preg_replace_callback('/(\bsrcset\s*=\s*)(["\'])(.*?)\2/i', $rewriteSrcset, $body);

    // 3) 注入 <base href="当前代理URL"> —— 让目标页 JS 的相对请求（fetch('auth.php')、
    //    动态加载脚本/图片）也解析到代理路径下，再由本页转发。
    $baseTag = '<base href="' . proxy_link($finalUrl ?: $target) . '/">';
    if (preg_match('/<head[^>]*>/i', $body, $hm, PREG_OFFSET_CAPTURE)) {
        $pos = $hm[0][1] + strlen($hm[0][0]);
        $body = substr($body, 0, $pos) . $baseTag . substr($body, $pos);
    } else {
        $body = $baseTag . $body;
    }

    echo $body;
} elseif ($isCss) {
    header('Content-Type: text/css; charset=utf-8');
    // 重写 url(...)（CSS 无 <base>，基准即目标 URL 自身）
    $rewriteUrl = function ($matches) use ($target) {
        $inner = trim($matches[1], "'\" \t");
        if ($inner === '') return $matches[0];
        if (preg_match('/^data:/i', $inner)) return $matches[0];
        if (preg_match('/^#/', $inner)) return $matches[0];
        return 'url(' . proxy_link(proxy_abs($target, $inner)) . ')';
    };
    $body = preg_replace_callback('/url\(\s*(["\']?)(.*?)\1\s*\)/i', $rewriteUrl, $body);
    echo $body;
} else {
    // 其它：原样透传（图片/字体/JSON/JS 等），保留 Content-Type
    if ($contentType !== '') header('Content-Type: ' . $contentType);
    echo $body;
}
