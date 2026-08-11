<?php
/**
 * ChatApp - Maintenance admin login (retro table UI)
 */
require_once __DIR__ . '/config.php';

$error = '';
$token = '';

// Already has a valid token? Straight through.
$hour_window = floor(time() / 3600);
$has_token = (isset($_COOKIE['MT_TOKEN']) && hash_equals(hash_hmac('sha256', 'mt:' . $hour_window, $MAINT_SECRET), $_COOKIE['MT_TOKEN']));

if ($has_token) {
    header('Location: /');
    exit;
}

// Handle login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['login'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if (hash_equals((string)$MAINT_USER, $u) && hash_equals((string)$MAINT_PASS, $p)) {
        $hour_window = floor(time() / 3600);
        $token = hash_hmac('sha256', 'mt:' . $hour_window, $MAINT_SECRET);
        setcookie('MT_TOKEN', $token, 0, '/', '', false, true); // session cookie, httponly
        header('Location: /');
        exit;
    }
    $error = 'Invalid login or password.';
}
?>
<html xmlns="http://www.w3.org/1999/xhtml"><head>
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	<meta http-equiv="Content-Language" content="zh-CN">
	<meta name="roots" content="">
	<meta name="Keywords" content="">
    <meta name="Description" content="">



	<title>Maintenance Admin Login</title>
	<link rel="stylesheet" href="../css/global.css">
    <style type="text/css">

    @font-face {
        font-family: 'Default';            
        src: url('/errors/default.otf') format('opentype');
		font-weight: normal;
		font-style: normal;
    }

	@font-face {
		font-family: 'Roboto';
		src: url('/css/fonts/Roboto-Regular.ttf') format('truetype');
		font-weight: normal;
		font-style: normal;
	}

	body,table,tr,td,th,div,table,p,b,span,input,select,textarea {
		font-family: 'Default', 'Roboto', Arial, Helvetica, sans-serif;
		font-size: 12px;
		font-weight: normal;
		color: #000000;
		margin: 0;
		padding: 0;
    }

	.inbox {
		border: 1px solid #7F9DB9;
		background: #fff;
		padding: 3px;
		font-size: 12px;
	}
	.err { color: #C00; font-size: 12px; margin-top: 8px; }
	.ok  { color: #060; font-size: 12px; margin-top: 8px; word-break: break-all; }
	</style>
</head>
<body leftmargin="0" topmargin="0">
	<br><br><br><br>
	<div>
	</div>
	<div style="width: 598px;margin: auto;border: 1px solid #D1CBD0;background: #F9F9F9 no-repeat right top;">
		<div style="width: 98%;margin: 5px auto;">
			<table width="586" border="0" cellpadding="0" cellspacing="0">
				<tbody><tr>
					<td width="134" valign="top">
						<br>
						<!--
						<p>
							<img src="/errors/warn.gif">
						</p>
-->
					</td>
					<td width="452" valign="top">
						<br>
						<p>
							<b>Maintenance Admin Login 维护登录</b>
						</p><br><br>
						<p>
							<?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
							<?php if ($token): ?><div class="ok">You obtained a 1-hour access token:<br><?php echo htmlspecialchars($token); ?></div><?php endif; ?>
						</p>
						<form name="form1" method="post" action="/maintenance/index.php">
						<table border="0" cellspacing="0" cellpadding="4">
						<tr>
							<td align="right">用户 User:</td>
							<td><input type="text" name="login" size="20" maxlength="30" value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>" class="inbox" autocomplete="username"></td>
						</tr>
						<tr>
							<td align="right">密码 Password:</td>
							<td><input type="password" name="password" size="20" maxlength="128" class="inbox" autocomplete="current-password"></td>
						</tr>
						<tr>
							<td colspan="2" align="center">
								<img src="" width="1" height="4"><br>
								<input type="submit" value="登录">
							</td>
						</tr>
						<br>
						</table>
						</form>
					</td>
				</tr></tbody>
			</table>
		</div>
	</div>
</body></html>
