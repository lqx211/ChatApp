<?php
/**
 * ChatApp - Global maintenance gate
 * Reads is_maintenance from status.php.
 * When maintenance is on, a valid 1-hour admin token (cookie MT_TOKEN)
 * bypasses the gate (used for local admin access during maintenance).
 */

require_once __DIR__ . '/maintenance/status_loader.php';

$__status = chatapp_maint_status();
if (is_array($__status) && !empty($__status['is_maintenance'])) {

    // ---- Admin bypass: valid 1-hour token grants access ----
    // 凭据来源：data/maint_config.php（Web 可写，OOBE 写入，优先）→ maintenance/config.php（legacy）
    require_once __DIR__ . '/maintenance/creds.php';
    $__mt_creds = chatapp_maint_creds();
    $__mt_secret = (string)$__mt_creds['secret'];
    $__hour_window = floor(time() / 3600);
    $__bypass = false;
    $__mt_token = $_COOKIE['MT_TOKEN'] ?? '';
    if ($__mt_token !== '' && $__mt_secret !== '') {
        $__expected = hash_hmac('sha256', 'mt:' . $__hour_window, $__mt_secret);
        if (hash_equals($__expected, $__mt_token)) {
            $__bypass = true;
        }
    }
    // Also allow a one-off token passed as ?token= in the query string
    if (!$__bypass && isset($_GET['token']) && $__mt_secret !== '') {
        $__expected = hash_hmac('sha256', 'mt:' . $__hour_window, $__mt_secret);
        if (hash_equals($__expected, (string)$_GET['token'])) {
            // Promote to cookie so subsequent page loads keep working
            setcookie('MT_TOKEN', (string)$_GET['token'], 0, '/', '', false, true);
            $__bypass = true;
        }
    }
    if ($__bypass) {
        // 维护期间门户 token 只放行维护门户页面（/maintenance/*）与门户调用的 API（/api/*）。
        // 其余（含登录页 login.php / 聊天 chat.php / 首页 /）一律仍显示维护页 —— 这样“维护”才真正生效。
        $__reqPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $__isPortal = ($__reqPath === '/maintenance' || strpos($__reqPath, '/maintenance/') === 0);
        $__isPortalApi = (strpos($__reqPath, '/api/') === 0);
        if ($__isPortal || $__isPortalApi) {
            return; // 放行：门户页面 / 门户调用的危险 API
        }
        // 有 token 但访问非门户路径 → 继续走到下面的维护页
    }

    $__code = (int)($__status['mt_return_code'] ?? 503);
    http_response_code($__code);
    header('Content-Type: text/html; charset=UTF-8');
    // maintenance_page may be '/errors/unavailable_upgrade.html' (already has
    // the extension). Strip it first, then append the allow_mt_login '2' suffix
    // and re-add '.html' so we look for the correct unavailable_upgrade2.html.
    $__page_base = (string)($__status['maintenance_page'] ?? '/errors/unavailable_upgrade');
    $__page_base = preg_replace('/\.html$/', '', $__page_base);
    $__maintenance_page = __DIR__ . $__page_base . ($__status['allow_mt_login'] ? '2' : '') . '.html';
    if (file_exists($__maintenance_page)) readfile($__maintenance_page);
    else {
        $__maintenance_page = __DIR__ . $__page_base . '.html';
        if (file_exists($__maintenance_page)) readfile($__maintenance_page);
        else echo '<html xmlns="http://www.w3.org/1999/xhtml"><head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	<meta http-equiv="Content-Language" content="zh-CN">  
	<meta name="roots" content="">  
	<meta name="Keywords" content="">  
	<meta name="Description" content=""> 
	<title>Emergency Repair 紧急修复</title>
	<style type="text/css">
	@font-face {
		font-family: \'CustomOTF\';              /* 自定义字体名称，可随意起 */
		src: url(\'/errors/default.otf\') format(\'opentype\');
		font-weight: normal;
		font-style: normal;
	}
	body,td,th {
		font-family: \'CustomOTF\', Arial, Helvetica, sans-serif;
		font-size: 12px;
		font-weight: normal;
		color: #000000;
		margin: 0;
		padding: 0;
	}
	</style>
</head>
<body leftmargin="0" topmargin="0">
	<br><br><br><br>
	<div>
		
	</div>
	<div style="width: 598px;margin: auto;border: 1px solid #D1CBD0;background: #F9F9F9 no-repeat right top;">
		<div style="width: 98%;margin: 5px auto;">
			<table width="586" height="220" border="0" cellpadding="0" cellspacing="0">
				<tbody><tr>
					<td width="134" height="106">
						<img src="/errors/warn.gif">
					</td>
					<td width="452" valign="top">
						<br>
						<p>
							<b>Emergency repair</b>
						</p>
						<p>
							Our host server has occurred a serious failure, and we are trying our best to fix it.
						</p>
						<p>
							<b>紧急修复</b>
						</p>
						<p>
							我们的服务器发生了很严重的情况，我们将会尽力修复服务器。
						</p>
						<br>
						<p><a href="/maintenance/index.php">Admin Login</a></p>
					</td>
				</tr></tbody>
			</table>
		</div>
	</div>

</body></html>';
    }
    exit;
}