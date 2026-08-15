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
    <!--[if lte IE 8]>
    <script type="text/javascript">
        window.location.replace('/errors/unsupported_browser.html');
    </script>
    <![endif]-->
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
            display: block;
            opacity: 0;
            transform: translateY(8px);
            transition: opacity 0.28s ease 0.05s, transform 0.28s ease 0.05s;
        }
        /* 高度过渡：grid-template-rows 0fr ↔ 1fr，窗口平滑延伸/收缩 */
        .panel-wrap {
            display: grid;
            grid-template-rows: 0fr;
            transition: grid-template-rows 0.38s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .panel-wrap.open {
            grid-template-rows: 1fr;
        }
        .panel-wrap > .form-panel {
            overflow: hidden;
            min-height: 0;
        }
        .panel-wrap.open > .form-panel {
            opacity: 1;
            transform: translateY(0);
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

        /* Restricted notice screen */
        .restricted-panel {
            display: none;
            position: relative;
        }
        .restricted-panel.active {
            display: block;
        }
        .rstr-badge {
            position: absolute;
            top: -34px;
            left: 0;
            background: #4a3a1e;
            border: 1px solid #6a552a;
            color: #e0a040;
            font-size: 0.7em;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 4px 12px;
            text-transform: uppercase;
        }
        .rstr-greeting {
            margin-top: 28px;
            font-size: 1.1em;
            color: #e0e0e0;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .rstr-body {
            color: #aaa;
            font-size: 0.9em;
            line-height: 1.6;
            margin-bottom: 18px;
        }
        .rstr-reason {
            color: #e0a040;
            font-size: 0.9em;
            margin-bottom: 28px;
            word-break: break-word;
        }
        .rstr-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .rstr-actions .btn-primary.continue {
            background: #4a2a2a;
            border-color: #6a3a3a;
            color: #e06060;
        }
        .rstr-actions .btn-primary.continue:hover {
            background: #5a3a3a;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="lang-selector" id="langSelector">
            <label for="langSwitch"><?php echo t('lang_select'); ?>:</label>
            <select id="langSwitch" onchange="switchLang(this.value)">
                <option value="en"<?php echo $currentLang === 'en' ? ' selected' : ''; ?>><?php echo t('lang_en'); ?></option>
                <option value="zh"<?php echo $currentLang === 'zh' ? ' selected' : ''; ?>><?php echo t('lang_zh'); ?></option>
                <option value="zh_egg"<?php echo $currentLang === 'zh_egg' ? ' selected' : ''; ?>><?php echo t('lang_zh_egg'); ?></option>
                <option value="wyw"<?php echo $currentLang === 'wyw' ? ' selected' : ''; ?>><?php echo t('lang_wyw'); ?></option>
                <option value="raw"<?php echo $currentLang === 'raw' ? ' selected' : ''; ?>><?php echo t('lang_raw'); ?></option>
            </select>
        </div>

        <div id="normalAuth">
            <h1><?php echo t('title_login'); ?></h1>

            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('login')"><?php echo t('btn_login'); ?></button>
                <button class="tab-btn" onclick="switchTab('register')"><?php echo t('btn_register'); ?></button>
            </div>

            <div class="error-msg" id="errorMsg"></div>

            <!-- Login Form -->
            <div class="panel-wrap open" id="loginWrap">
            <form class="form-panel" id="loginPanel" onsubmit="handleLogin(event)">
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
            </div>

            <!-- Register Form -->
            <div class="panel-wrap" id="registerWrap">
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
            </div>
        </div>

        <!-- Restricted notice screen -->
        <div class="restricted-panel" id="restrictedPanel">
            <div class="rstr-badge" id="rstrBadge"><?php echo t('msg_restricted_login_title'); ?></div>
            <div class="rstr-greeting" id="rstrGreeting"></div>
            <div class="rstr-body"><?php echo t('msg_restricted_login_body'); ?></div>
            <div class="rstr-reason" id="rstrReason"></div>
            <div class="rstr-actions">
                <button class="btn-primary continue" onclick="doContinueLogin()"><?php echo t('btn_continue_login'); ?></button>
                <button class="btn-primary" onclick="doLogout()"><?php echo t('btn_log_out'); ?></button>
            </div>
        </div>

        <a href="../index.php" class="back-link" id="backLink"><?php echo t('msg_back_entry'); ?></a>
    </div>

    <script>
        var _currentLang = '<?php echo $currentLang; ?>';
        var LANG = <?php
            $langArr = lang_load();
            echo json_encode([
                'msg_restricted_reason' => $langArr['msg_restricted_reason'] ?? 'Reason: %s',
            ], JSON_UNESCAPED_UNICODE);
        ?>;
        var _restrictedUser = null;
        var _restrictedPass = null;

        function t(key, fallback) {
            return typeof LANG !== 'undefined' && LANG && LANG[key] ? LANG[key] : (fallback !== undefined ? fallback : key);
        }

        function switchLang(lang) {
            var url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }

        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(function(btn) { btn.classList.remove('active'); });
            var loginOpen = (tab === 'login');
            document.querySelectorAll('.tab-btn')[loginOpen ? 0 : 1].classList.add('active');
            document.getElementById('loginWrap').classList.toggle('open', loginOpen);
            document.getElementById('registerWrap').classList.toggle('open', !loginOpen);
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

        function renderRestricted(displayName, reason) {
            document.getElementById('langSelector').style.display = 'none';
            document.getElementById('normalAuth').style.display = 'none';
            document.getElementById('backLink').style.display = 'none';
            document.getElementById('restrictedPanel').classList.add('active');
            document.getElementById('rstrGreeting').textContent = displayName + ',';
            document.getElementById('rstrReason').textContent = t('msg_restricted_reason', 'Reason: %s').replace('%s', reason || '-');
        }

        function showRestricted(data) {
            _restrictedUser = document.getElementById('loginUsername').value.trim();
            _restrictedPass = document.getElementById('loginPassword').value;
            // If the user's preferred language differs from the current page
            // language, save credentials and reload with their language so the
            // restricted screen renders in their own i18n.
            if (data.preferred_language && data.preferred_language !== _currentLang) {
                try {
                    sessionStorage.setItem('rstr_user', _restrictedUser);
                    sessionStorage.setItem('rstr_pass', _restrictedPass);
                    sessionStorage.setItem('rstr_name', data.display_name || _restrictedUser);
                    sessionStorage.setItem('rstr_reason', data.reason || '');
                } catch (e) {}
                var url = new URL(window.location.href);
                url.searchParams.set('lang', data.preferred_language);
                url.searchParams.set('restricted', '1');
                window.location.href = url.toString();
                return;
            }
            renderRestricted(data.display_name || _restrictedUser, data.reason || '');
        }

        // Auto-restore the restricted screen after language redirect
        (function() {
            var params = new URLSearchParams(window.location.search);
            if (params.get('restricted') === '1') {
                try {
                    var u = sessionStorage.getItem('rstr_user');
                    var p = sessionStorage.getItem('rstr_pass');
                    if (u && p) {
                        _restrictedUser = u;
                        _restrictedPass = p;
                        var name = sessionStorage.getItem('rstr_name') || u;
                        var reason = sessionStorage.getItem('rstr_reason') || '';
                        renderRestricted(name, reason);
                        sessionStorage.removeItem('rstr_user');
                        sessionStorage.removeItem('rstr_pass');
                        sessionStorage.removeItem('rstr_name');
                        sessionStorage.removeItem('rstr_reason');
                    }
                } catch (e) {}
            }
        })();

        async function doContinueLogin() {
            if (!_restrictedUser || !_restrictedPass) {
                window.location.reload();
                return;
            }
            var formData = new URLSearchParams();
            formData.append('action', 'login');
            formData.append('username', _restrictedUser);
            formData.append('password', _restrictedPass);
            formData.append('confirm', '1');
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
                    window.location.reload();
                }
            } catch (err) {
                window.location.reload();
            }
        }

        function doLogout() {
            fetch('../api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=logout'
            }).catch(function() {}).finally(function() {
                window.location.href = '../index.php';
            });
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
                } else if (data.restricted) {
                    showRestricted(data);
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