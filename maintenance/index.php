<?php
/**
 * ChatApp — Maintenance Admin Login（复刻 modern/wp/login.php 视觉，仅用于维护门户登录）
 *
 * 注意：不加载 api/config.php（否则维护模式闸门会拦截本页）—— 仅用维护凭据
 * (creds.php) + 自包含 PoW (api/pow.php)，DB 挂掉时也能登录。
 *
 * 流程：输入维护账号+密码 → PoW 验证 → 校验维护凭据 → 签发 1 小时 MT_TOKEN →
 * 跳转 /maintenance/portal.php
 */
require_once __DIR__ . '/creds.php';
require_once __DIR__ . '/../api/pow.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$__creds = chatapp_maint_creds();
$__secret = (string)$__creds['secret'];

// 已有有效 token → 直接进门户
$__hour = floor(time() / 3600);
$__tok = $_COOKIE['MT_TOKEN'] ?? '';
if ($__secret !== '' && $__tok !== '' && hash_equals(hash_hmac('sha256', 'mt:' . $__hour, $__secret), $__tok)) {
    header('Location: /maintenance/portal.php');
    exit;
}

// POST：PoW + 维护凭据校验（返回 JSON；成功已设 cookie）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $__u = trim($_POST['login'] ?? '');
    $__p = (string)($_POST['password'] ?? '');
    if (!chatapp_verify_pow((string)($_POST['pow_challenge'] ?? ''), (string)($_POST['pow_nonce'] ?? ''), 'maint_pow')) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired challenge. Please reload and try again.']);
        exit;
    }
    if ($__creds['user'] !== '' && $__creds['pass'] !== ''
        && hash_equals((string)$__creds['user'], $__u)
        && hash_equals((string)$__creds['pass'], $__p)) {
        $__hour = floor(time() / 3600);
        setcookie('MT_TOKEN', hash_hmac('sha256', 'mt:' . $__hour, $__secret), 0, '/', '', false, true);
        echo json_encode(['success' => true, 'portal' => true]);
        exit;
    }
    echo json_encode(['success' => false, 'error' => 'Invalid maintenance username or password.']);
    exit;
}

// GET：渲染登录页，服务端签发 PoW challenge（并给出 target，前端才能求解）
$__pow = chatapp_pow_issue('maint_pow');
$__pow['target'] = chatapp_pow_target((int)$__pow['target_bits']);
$__wallpaper = rand(1, 10);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Maintenance Portal Login</title><link rel="stylesheet" href="../css/global.css">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        color: #e0e0e0;
        display: flex; justify-content: center; align-items: center;
        min-height: 100vh;
        background-color: #1a1a1a;
        background-image:
            radial-gradient(rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.5) 100%),
            radial-gradient(rgba(0, 0, 0, 0) 33%, rgba(0, 0, 0, 0.3) 166%),
            url('../modern/bg/background<?php echo $__wallpaper; ?>.jpg');
        background-size: cover; background-position: center; background-repeat: no-repeat; background-attachment: fixed;
    }
    .auth-container {
        background: rgba(42, 42, 42, 0.88);
        -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
        border: 1px solid rgba(90, 90, 90, 0.5);
        padding: 40px 38px; width: 380px; max-width: 92vw;
        box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    }
    .auth-container h1 { text-align: center; font-size: 1.8em; color: #c0c0c0; margin-bottom: 6px; font-weight: 600; }
    .auth-container p.subtitle { text-align: center; color: #777; margin-bottom: 28px; font-size: 0.9em; }
    .maint-badge {
        display: inline-block; margin: 0 auto 16px; padding: 4px 14px;
        background: #3d2a1e; border: 1px solid #6a4a2a; color: #e0a040;
        font-size: 0.72em; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;
        text-align: center;
    }
    .form-group { position: relative; margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 6px; color: #aaa; font-size: 0.85em; }
    .form-group input {
        width: 100%; padding: 11px 2px; background: transparent; border: none;
        border-bottom: 1px solid #555; border-radius: 0; color: #e0e0e0;
        font-size: 0.95em; outline: none; font-family: inherit;
    }
    .form-group::after {
        content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 2px;
        background: #4a9dd8; transform: scaleX(0); transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1); transform-origin: left;
    }
    .form-group:focus-within::after { transform: scaleX(1); }
    .form-group input:-webkit-autofill,
    .form-group input:-webkit-autofill:hover,
    .form-group input:-webkit-autofill:focus {
        -webkit-box-shadow: 0 0 0 1000px #1e1e1e inset; box-shadow: 0 0 0 1000px #1e1e1e inset;
        -webkit-text-fill-color: #e0e0e0; caret-color: #e0e0e0;
        transition: background-color 9999s ease-out 0s;
    }
    .error-msg {
        background: #3d2020; border: 1px solid #5c2a2a; color: #e06060;
        padding: 10px 14px; margin-bottom: 16px; font-size: 0.85em; display: none;
    }
    .error-msg.show { display: block; }
    .btn-primary {
        width: 100%; padding: 12px; background: #4a4a4a; border: 1px solid #555;
        color: #e0e0e0; font-size: 0.95em; font-weight: 600; cursor: pointer;
        transition: background 0.2s; font-family: inherit;
    }
    .btn-primary:hover { background: #5a5a5a; }
    .btn-primary.pow-working { opacity: 0.55; pointer-events: none; cursor: default; }
    .back-link { display: block; text-align: center; margin-top: 20px; color: #777; text-decoration: none; font-size: 0.85em; }
    .back-link:hover { color: #aaa; }
    .foot-note { text-align: center; color: #555; font-size: 0.72em; margin-top: 14px; line-height: 1.6; }
</style>
</head>
<body>
<div class="auth-container">
    <div style="text-align:center"><span class="maint-badge">Maintenance Mode</span></div>
    <h1>Maintenance Portal</h1>
    <p class="subtitle">Admin Login for Maintenance Mode</p>

    <div class="error-msg" id="errorMsg"></div>

    <form id="loginPanel" onsubmit="handleLogin(event)">
        <div class="form-group">
            <label for="loginUsername">Maintenance Username</label>
            <input type="text" id="loginUsername" maxlength="100" required autocomplete="username">
        </div>
        <div class="form-group">
            <label for="loginPassword">Maintenance Password</label>
            <input type="password" id="loginPassword" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn-primary" id="loginBtn">Log In</button>
    </form>

    <a href="../../index.php" class="back-link">&#8592; Back to ChatApp</a>
    <p class="foot-note">This login is used when the site is in maintenance mode.<br>Once logged in, you can control maintenance from the portal.</p>
</div>

<script src="../modern/scripts/pow.js"></script>
<script>
var POW = { challenge: <?php echo json_encode($__pow['challenge']); ?>, target: <?php echo json_encode($__pow['target']); ?> };

function showError(t){
    var el = document.getElementById('errorMsg');
    el.textContent = t;
    el.classList.add('show');
}
function hideError(){
    document.getElementById('errorMsg').classList.remove('show');
}
function handleLogin(e){
    e.preventDefault();
    hideError();
    var btn = document.getElementById('loginBtn');
    btn.classList.add('pow-working');
    btn.textContent = 'Logging in...';
    ChatAppPow.solve(POW.challenge, POW.target, function(kHps){
        btn.textContent = 'Logging in... (' + Math.round(kHps) + ' kH/s)';
    }).then(function(solved){
        if (!solved){
            btn.classList.remove('pow-working'); btn.textContent = 'Log In';
            showError('Challenge failed. Please reload the page.');
            return;
        }
        var fd = new URLSearchParams();
        fd.append('login', document.getElementById('loginUsername').value.trim());
        fd.append('password', document.getElementById('loginPassword').value);
        fd.append('pow_challenge', POW.challenge);
        fd.append('pow_nonce', solved.nonce);
        fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: fd.toString(),
            credentials: 'same-origin'
        }).then(function(r){ return r.json(); }).then(function(d){
            btn.classList.remove('pow-working'); btn.textContent = 'Log In';
            if (d.success && d.portal){
                window.location.href = '/maintenance/portal.php';
            } else {
                showError(d.error || 'Login failed.');
            }
        }).catch(function(){
            btn.classList.remove('pow-working'); btn.textContent = 'Log In';
            showError('Network error. Please try again.');
        });
    });
}
</script>
</body>
</html>

