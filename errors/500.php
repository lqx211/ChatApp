<?php
/**
 * ChatApp - 500 友好错误页（自包含，零依赖）
 *
 * 由 api/config.php 的全局 shutdown handler 在 PHP 致命/语法错误时接管输出。
 * 故意不 require config.php：错误发生时 config 状态不可靠，避免二次失败。
 * 资源用绝对路径（可能被 include 进任意层级的出错页面 URL）。
 */
http_response_code(500);

// 语言（尽力读取 session，失败按 Accept-Language 兜底）
$lang = 'en';
try {
    if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    $lang = (string)($_SESSION['preferred_language'] ?? '');
} catch (\Throwable $e) {}
if ($lang === '') {
    $acc = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
    $lang = (stripos($acc, 'zh') !== false) ? 'zh' : 'en';
}

$L = [
    'en' => [
        'title' => '500 - Server Error',
        'head'  => 'Something went wrong on our server.',
        'desc'  => 'An internal error occurred while processing your request.',
        'try'   => 'Please try again in a moment.',
        'retry' => 'Retry',
        'back'  => 'Go Back',
        'debug' => 'Debug Info',
        'report'=> 'If this keeps happening, please report it via the support ticket system.',
        'close' => 'Shut Down My Device',
    ],
    'zh' => [
        'title' => '500 - 服务器错误',
        'head'  => '服务器出了点问题。',
        'desc'  => '处理您的请求时发生了内部错误。',
        'try'   => '请稍后重试。',
        'retry' => '重试',
        'back'  => '返回',
        'debug' => '调试信息',
        'report'=> '如果反复出现，请通过工单系统反馈。',
        'close' => '直接关闭我的设备',
    ],
    'zh_egg' => [
        'title' => '500 - 服务器错误',
        'head'  => '服务器飞起来了 (°▽°)',
        'desc'  => '处理请求时出了点小差错。',
        'try'   => '你等多久都不会理你',
        'retry' => '再来一次',
        'back'  => '滚回',
        'debug' => '调试信息',
        'report'=> '有问题请自己改源码，建议踢服主几脚。',
        'close' => '我想关机',
    ],
    'wyw' => [
        'title' => '500 - 伺服器錯誤',
        'head'  => '伺服器偶有恙。',
        'desc'  => '處置請求之際，內務有差池。',
        'try'   => '請稍候再試。',
        'retry' => '再試',
        'back'  => '返回',
        'debug' => '調試信息',
        'report'=> '若屢現此患，請以工單報之。',
        'close' => '直關吾之設備',
    ],
];
if (!isset($L[$lang])) $lang = 'en';
$w = $L[$lang];
$bgWallpaper = rand(1, 10);
?>
<!DOCTYPE html>
<html lang="zh-Hans">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php echo htmlspecialchars($w['title']); ?> - ChatApp</title>
<style>
@font-face{font-family:'Roboto';src:url('/css/fonts/Roboto-Regular.ttf') format('truetype');font-weight:400;font-style:normal}
@font-face{font-family:'Chinese';src:url('/css/fonts/chinese.otf') format('opentype');font-weight:400;font-style:normal}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Roboto','Chinese',-apple-system,BlinkMacSystemFont,'Segoe UI','Helvetica Neue',sans-serif !important}
body{font-family:'Roboto','Chinese',-apple-system,BlinkMacSystemFont,'Segoe UI','Helvetica Neue',sans-serif;color:#e0e0e0;display:flex;justify-content:center;align-items:center;min-height:100vh;background-color:#1a1a1a;background-image:radial-gradient(rgba(0,0,0,0) 0%,rgba(0,0,0,0.5) 100%),radial-gradient(rgba(0,0,0,0) 33%,rgba(0,0,0,0.3) 166%),url('/modern/bg/background<?php echo $bgWallpaper; ?>.jpg');background-size:cover;background-position:center;background-repeat:no-repeat;background-attachment:fixed}
a{color:#6a9fd8}
.error-wrapper{background:#2a2a2a;border:1px solid #3a3a3a;padding:40px 38px;width:500px;box-shadow:0 8px 32px rgba(0,0,0,0.4);text-align:center}
.error-icon{margin-bottom:20px;font-size:48px}
h1{font-size:1.6em;font-weight:600;color:#e06060;margin-bottom:12px;word-wrap:break-word}
h2{font-size:1.1em;color:#999;font-weight:400;margin-bottom:24px}
.error-code{font-size:.8em;color:#555;text-transform:uppercase;margin-bottom:20px}
.error-detail{text-align:left;background:#1e1e1e;border:1px solid #333;padding:16px 20px;margin:16px 0;font-size:.82em;color:#888;line-height:1.6}
.error-detail summary{cursor:pointer;color:#aaa;font-weight:600;margin-bottom:8px;font-size:.9em}
.error-detail summary:hover{color:#ccc}
.error-detail p{margin:6px 0}
.error-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:16px}
.error-actions button,.error-actions a{padding:10px 22px;background:#3a3a3a;border:1px solid #555;color:#ccc;cursor:pointer;font-size:.85em;font-family:inherit;text-decoration:none;display:inline-block}
.error-actions button:hover,.error-actions a:hover{background:#4a4a4a}
.error-actions .btn-retry{background:#2a4a2a;border-color:#3a6a3a;color:#8e8}
.error-actions .btn-retry:hover{background:#3a5a3a}
.back-link{display:block;text-align:center;margin-top:24px;color:#666;text-decoration:none;font-size:.8em}
.back-link:hover{color:#888}
</style>
</head>
<body>
<div class="error-wrapper">
    <div class="error-icon">😵</div>
    <h1><?php echo htmlspecialchars($w['head']); ?></h1>
    <h2><?php echo htmlspecialchars($w['desc']); ?></h2>
    <div class="error-code">HTTP 500 Internal Server Error</div>

    <details class="error-detail">
        <summary><?php echo htmlspecialchars($w['report']); ?></summary>
        <p><?php echo htmlspecialchars($w['try']); ?></p>
    </details>

    <details class="error-detail">
        <summary><?php echo htmlspecialchars($w['debug']); ?></summary>
        <div id="debugInfo"></div>
    </details>

    <div class="error-actions">
        <a href="javascript:void(0)" onclick="location.reload();" class="btn-retry"><?php echo htmlspecialchars($w['retry']); ?></a>
        <a href="javascript:void(0)" onclick="history.go(-1);"><?php echo htmlspecialchars($w['back']); ?></a>
    </div>
    <a href="/data/byebye_linux_windows.bat" download class="back-link"><?php echo htmlspecialchars($w['close']); ?></a>
</div>
<script>
(function(){
    var ua = navigator.userAgent;
    var browser = 'Unknown';
    if (ua.indexOf('Firefox') > -1) browser = 'Mozilla Firefox ' + (ua.match(/Firefox\/(\d+)/)||[,'?'])[1];
    else if (ua.indexOf('Edg/') > -1) browser = 'Microsoft Edge ' + (ua.match(/Edg\/(\d+)/)||[,'?'])[1];
    else if (ua.indexOf('Chrome') > -1) browser = 'Google Chrome ' + (ua.match(/Chrome\/(\d+)/)||[,'?'])[1];
    else if (ua.indexOf('Safari') > -1) browser = 'Apple Safari ' + (ua.match(/Version\/(\d+)/)||[,'?'])[1];
    var d = document.getElementById('debugInfo');
    d.innerHTML =
        '<p><strong>Browser:</strong> ' + browser + '</p>' +
        '<p><strong>Platform:</strong> ' + (navigator.platform || 'Unknown') + '</p>' +
        '<p><strong>Language:</strong> ' + (navigator.language || 'Unknown') + '</p>' +
        '<p><strong>Screen:</strong> ' + screen.width + 'x' + screen.height + '</p>' +
        '<p><strong>URL:</strong> ' + location.href + '</p>';
})();
</script>
<!-- 共享底部版权栏（同 404.php：modern/partials/footer.php） -->
<?php @include __DIR__ . '/../modern/partials/footer.php'; ?>
</body>
</html>
