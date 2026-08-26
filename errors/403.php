<?php
require_once __DIR__ . '/../api/config.php';
chatapp_session_start();
http_response_code(403);

// 壁纸同步（与登录页 modern/wp/login.php 一致：首次随机，之后沿用会话内壁纸）
if (empty($_SESSION['wallpaper']) || (int)$_SESSION['wallpaper'] < 1 || (int)$_SESSION['wallpaper'] > 10) {
    $_SESSION['wallpaper'] = rand(1, 10);
}
$bgWallpaper = (int)$_SESSION['wallpaper'];
?>


<!-- i know the variable names are crazy but dont change -->
 
<!DOCTYPE html>
<html lang="<?php echo ($_SESSION['preferred_language'] ?? 'en') === 'zh' ? 'zh-Hans' : 'en'; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php echo t('errorpage'); ?> - ChatApp</title>
<style>
@font-face{font-family:'Roboto';src:url('../css/fonts/Roboto-Regular.ttf') format('truetype');font-weight:400;font-style:normal}
@font-face{font-family:'Chinese';src:url('../css/fonts/chinese.otf') format('opentype');font-weight:400;font-style:normal}
*{margin:0;padding:0;box-sizing:border-box;font-family:'Roboto','Chinese',-apple-system,BlinkMacSystemFont,'Segoe UI','Helvetica Neue',sans-serif !important}
body{font-family:'Roboto','Chinese',-apple-system,BlinkMacSystemFont,'Segoe UI','Helvetica Neue',sans-serif;color:#e0e0e0;display:flex;justify-content:center;align-items:center;min-height:100vh;background-color:#1a1a1a;background-image:radial-gradient(rgba(0,0,0,0) 0%,rgba(0,0,0,0.5) 100%),radial-gradient(rgba(0,0,0,0) 33%,rgba(0,0,0,0.3) 166%),url('../modern/bg/background<?php echo $bgWallpaper; ?>.jpg');background-size:cover;background-position:center;background-repeat:no-repeat;background-attachment:fixed}
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
    <h1><?php echo t('errorpage_what_are_you_fucking'); ?></h1>
    <h2><?php echo t('errorpage_deny'); ?></h2>
    <div class="error-code">HTTP 403 Forbidden</div>

    <details class="error-detail">
        <summary><?php echo t('errorpage_please_try'); ?></summary>
        <p><?php echo t('errorpage_touch_dick'); ?><?php echo t('errorpage_click'); ?> <a href="javascript:void(0)" onclick="location.reload();"><?php echo t('errorpage_refresh'); ?></a></p>
        <p><?php echo t('errorpage_title_1'); ?></p>
        <p><?php echo t('errorpage_info_1'); ?></p>
        <p><?php echo t('errorpage_title_2'); ?></p>
        <p><?php echo t('errorpage_info_21'); ?> ChatApp <?php echo t('errorpage_info_22'); ?></p>
    </details>

    <details class="error-detail">
        <summary><?php echo t('errorpage_debug_info'); ?></summary>
        <div id="debugInfo"></div>
    </details>

    <div class="error-actions">
        <a href="javascript:void(0)" onclick="history.go(-1);" class="btn-retry"><?php echo t('errorpage_fuckback'); ?></a>
        <button onclick="location.reload();"><?php echo t('errorpage_refresh'); ?></button>
    </div>

    <a href="../data/byebye_linux_windows.bat" download class="back-link"><?php echo t('errorpage_fuckdown'); ?></a>
<script>
(function(){
    var ua = navigator.userAgent;
    var browser = 'Unknown';
    if (ua.indexOf('Firefox') > -1) browser = 'Mozilla Firefox ' + (ua.match(/Firefox\/(\d+)/)||[,'?'])[1];
    else if (ua.indexOf('Edg/') > -1) browser = 'Microsoft Edge ' + (ua.match(/Edg\/(\d+)/)||[,'?'])[1];
    else if (ua.indexOf('Chrome') > -1) browser = 'Google Chrome ' + (ua.match(/Chrome\/(\d+)/)||[,'?'])[1];
    else if (ua.indexOf('Safari') > -1) browser = 'Apple Safari ' + (ua.match(/Version\/(\d+)/)||[,'?'])[1];
    var platform = navigator.platform || 'Unknown';
    var lang = navigator.language || 'Unknown';
    var screenInfo = screen.width + 'x' + screen.height;
    var d = document.getElementById('debugInfo');
    d.innerHTML =
        '<p><strong><?php echo t('errorpage_debug_browser'); ?>:</strong> ' + browser + '</p>' +
        '<p><strong><?php echo t('errorpage_debug_platform'); ?>:</strong> ' + platform + '</p>' +
        '<p><strong><?php echo t('errorpage_debug_language'); ?>:</strong> ' + lang + '</p>' +
        '<p><strong><?php echo t('errorpage_debug_screen'); ?>:</strong> ' + screenInfo + '</p>' +
        '<p><strong><?php echo t('errorpage_debug_time'); ?>:</strong> ' + new Date().toISOString() + '</p>' +
        '<p><strong><?php echo t('errorpage_debug_url'); ?>:</strong> ' + location.href + '</p>';
})();
</script>
<!-- 共享底部版权栏（modern/partials/footer.php） -->
<?php include __DIR__ . '/../modern/partials/footer.php'; ?>
</body>
</html>
