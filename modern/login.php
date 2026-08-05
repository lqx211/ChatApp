<?php
/**
 * ChatApp - Login / Register page
 */
require_once __DIR__ . '/../api/config.php';

chatapp_session_start();

// Handle language switching via GET parameter (before login, no auth session yet)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'zh', 'zh_egg', 'wyw', 'raw'], true)) {
    $_SESSION['preferred_language'] = $_GET['lang'];
}
$currentLang = $_SESSION['preferred_language'] ?? 'en';

// Redirect if already logged in
if (isset($_SESSION['username'])) {
    header('Location: chat.php');
    exit;
}
?><!DOCTYPE html>
<html lang="<?php echo $currentLang === 'zh' ? 'zh-Hans' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('title_login'); ?></title><link rel="stylesheet" href="../css/global.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background: #1a1a1a;
            color: #e0e0e0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .auth-container {
            background: #2a2a2a;
            border: 1px solid #3a3a3a;
            padding: 40px 38px;
            width: 380px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }
        .auth-container h1 {
            text-align: center;
            font-size: 1.8em;
            color: #c0c0c0;
            margin-bottom: 6px;
            font-weight: 600;
        }
        .auth-container p.subtitle {
            text-align: center;
            color: #777;
            margin-bottom: 28px;
            font-size: 0.9em;
        }
        .lang-selector {
            text-align: right;
            margin-bottom: 10px;
        }
        .lang-selector select {
            background: #1e1e1e;
            border: 1px solid #444;
            color: #aaa;
            padding: 4px 8px;
            font-size: 0.78em;
            font-family: inherit;
            outline: none;
            cursor: pointer;
        }
        .lang-selector select:hover {
            border-color: #666;
        }
        .lang-selector label {
            color: #666;
            font-size: 0.75em;
            margin-right: 4px;
        }
        .tabs {
            display: flex;
            margin-bottom: 24px;
            border-bottom: 2px solid #3a3a3a;
        }
        .tab-btn {
            flex: 1;
            background: none;
            border: none;
            color: #777;
            padding: 10px 0;
            cursor: pointer;
            font-size: 0.95em;
            font-weight: 500;
            transition: color 0.2s, border-color 0.2s;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            font-family: inherit;
        }
        .tab-btn.active {
            color: #c0c0c0;
            border-bottom-color: #888;
        }
        .tab-btn:hover {
            color: #aaa;
        }
        .form-panel {
            display: none;
        }
        .form-panel.active {
            display: block;
        }
        .form-group {
            margin-bottom: 16px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #aaa;
            font-size: 0.85em;
        }
        .form-group input {
            width: 100%;
            padding: 11px 14px;
            background: #1e1e1e;
            border: 1px solid #444;
            color: #e0e0e0;
            font-size: 0.95em;
            outline: none;
            transition: border-color 0.2s;
            font-family: inherit;
        }
        .form-group input:focus {
            border-color: #888;
        }
        .form-group select {
            width: 100%;
            padding: 11px 14px;
            background: #1e1e1e;
            border: 1px solid #444;
            color: #e0e0e0;
            font-size: 0.95em;
            font-family: inherit;
            outline: none;
        }
        .error-msg {
            background: #3d2020;
            border: 1px solid #5c2a2a;
            color: #e06060;
            padding: 10px 14px;
            margin-bottom: 16px;
            font-size: 0.85em;
            display: none;
        }
        .error-msg.show {
            display: block;
        }
        .btn-primary {
            width: 100%;
            padding: 12px;
            background: #4a4a4a;
            border: 1px solid #555;
            color: #e0e0e0;
            font-size: 0.95em;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
            font-family: inherit;
        }
        .btn-primary:hover {
            background: #5a5a5a;
        }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #777;
            text-decoration: none;
            font-size: 0.85em;
        }
        .back-link:hover {
            color: #aaa;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="lang-selector">
            <label for="langSwitch"><?php echo t('lang_select'); ?>:</label>
            <select id="langSwitch" onchange="switchLang(this.value)">
                <option value="en"<?php echo $currentLang === 'en' ? ' selected' : ''; ?>><?php echo t('lang_en'); ?></option>
                <option value="zh"<?php echo $currentLang === 'zh' ? ' selected' : ''; ?>><?php echo t('lang_zh'); ?></option>
                <option value="zh_egg"<?php echo $currentLang === 'zh_egg' ? ' selected' : ''; ?>><?php echo t('lang_zh_egg'); ?></option>
                <option value="wyw"<?php echo $currentLang === 'wyw' ? ' selected' : ''; ?>><?php echo t('lang_wyw'); ?></option>
                <option value="raw"<?php echo $currentLang === 'raw' ? ' selected' : ''; ?>><?php echo t('lang_raw'); ?></option>
            </select>
        </div>

        <h1><?php echo t('title_login'); ?></h1>

        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('login')"><?php echo t('btn_login'); ?></button>
            <button class="tab-btn" onclick="switchTab('register')"><?php echo t('btn_register'); ?></button>
        </div>

        <div class="error-msg" id="errorMsg"></div>

        <!-- Login Form -->
        <form class="form-panel active" id="loginPanel" onsubmit="handleLogin(event)">
            <div class="form-group">
                <label for="loginUsername"><?php echo t('label_username'); ?></label>
                <input type="text" id="loginUsername" maxlength="20" required autocomplete="username">
            </div>
            <div class="form-group">
                <label for="loginPassword"><?php echo t('label_password'); ?></label>
                <input type="password" id="loginPassword" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn-primary"><?php echo t('btn_login'); ?></button>
        </form>

        <!-- Register Form -->
        <form class="form-panel" id="registerPanel" onsubmit="handleRegister(event)">
            <div class="form-group">
                <label for="regUsername"><?php echo t('label_username'); ?></label>
                <input type="text" id="regUsername" maxlength="20" required autocomplete="username" placeholder="<?php echo t('msg_login_username_hint'); ?>">
            </div>
            <div class="form-group">
                <label for="regPassword"><?php echo t('label_password'); ?></label>
                <input type="password" id="regPassword" required autocomplete="new-password" placeholder="<?php echo t('msg_login_password_hint'); ?>">
            </div>
            <div class="form-group">
                <label for="regPassword2"><?php echo t('label_confirm_password'); ?></label>
                <input type="password" id="regPassword2" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="regLanguage"><?php echo t('title_preferred_language'); ?></label>
                <select id="regLanguage">
                    <option value="en"<?php echo $currentLang === 'en' ? ' selected' : ''; ?>><?php echo t('lang_en'); ?></option>
                    <option value="zh"<?php echo $currentLang === 'zh' ? ' selected' : ''; ?>><?php echo t('lang_zh'); ?></option>
                    <option value="zh_egg"<?php echo $currentLang === 'zh_egg' ? ' selected' : ''; ?>><?php echo t('lang_zh_egg'); ?></option>
                    <option value="wyw"<?php echo $currentLang === 'wyw' ? ' selected' : ''; ?>><?php echo t('lang_wyw'); ?></option>
                    <option value="raw"<?php echo $currentLang === 'raw' ? ' selected' : ''; ?>><?php echo t('lang_raw'); ?></option>
                </select>
            </div>
            <button type="submit" class="btn-primary"><?php echo t('btn_register'); ?></button>
        </form>

        <a href="../index.php" class="back-link"><?php echo t('msg_back_entry'); ?></a>
    </div>

    <script>
        function switchLang(lang) {
            var url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }

        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(function(btn) { btn.classList.remove('active'); });
            document.querySelectorAll('.form-panel').forEach(function(panel) { panel.classList.remove('active'); });
            if (tab === 'login') {
                document.querySelectorAll('.tab-btn')[0].classList.add('active');
                document.getElementById('loginPanel').classList.add('active');
            } else {
                document.querySelectorAll('.tab-btn')[1].classList.add('active');
                document.getElementById('registerPanel').classList.add('active');
            }
            hideError();
        }

        function showError(msg) {
            var el = document.getElementById('errorMsg');
            el.textContent = msg;
            el.classList.add('show');
        }

        function hideError() {
            var el = document.getElementById('errorMsg');
            el.classList.remove('show');
            el.textContent = '';
        }

        async function handleLogin(e) {
            e.preventDefault();
            hideError();

            var username = document.getElementById('loginUsername').value.trim();
            var password = document.getElementById('loginPassword').value;

            var formData = new URLSearchParams();
            formData.append('action', 'login');
            formData.append('username', username);
            formData.append('password', password);

            try {
                var resp = await fetch('../api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });
                var data = await resp.json();
                if (data.success) {
                    window.location.href = 'chat.php';
                } else {
                    showError(data.error || 'Something went wrong.');
                }
            } catch (err) {
                showError('Something went wrong.');
            }
        }

        async function handleRegister(e) {
            e.preventDefault();
            hideError();

            var username = document.getElementById('regUsername').value.trim();
            var password = document.getElementById('regPassword').value;
            var password2 = document.getElementById('regPassword2').value;

            if (password !== password2) {
                showError('<?php echo t('msg_login_password_mismatch'); ?>');
                return;
            }

            var formData = new URLSearchParams();
            formData.append('action', 'register');
            formData.append('username', username);
            formData.append('password', password);
            formData.append('language', document.getElementById('regLanguage').value);

            try {
                var resp = await fetch('../api/auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });
                var data = await resp.json();
                if (data.success) {
                    window.location.href = 'chat.php';
                } else {
                    showError(data.error || 'Something went wrong.');
                }
            } catch (err) {
                showError('Something went wrong.');
            }
        }
    </script>
</body>
</html>