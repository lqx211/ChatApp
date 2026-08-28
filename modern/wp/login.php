<?php
/**
 * ChatApp - Login / Register page
 */
require_once __DIR__ . '/../../api/config.php';

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

// 来自 index.php 的入口：fromindex=1 且 t 距当前 unix 时间戳 <3 秒 → 显示 ChatApp 主页入口 UI，否则显示登录表单
$fromIndex = (($_GET['fromindex'] ?? '') === '1');
$__t = (int)($_GET['t'] ?? 0);
$showHome = $fromIndex && $__t > 0 && abs(time() - $__t) < 3;

// 壁纸同步：同一会话内所有页面（首页入口 / 登录表单）共用同一张壁纸（首次访问随机，之后沿用）
if (empty($_SESSION['wallpaper']) || (int)$_SESSION['wallpaper'] < 1 || (int)$_SESSION['wallpaper'] > 10) {
    $_SESSION['wallpaper'] = rand(1, 10);
}
$bgWallpaper = (int)$_SESSION['wallpaper'];
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
    <title><?php echo $showHome ? 'ChatApp' : t('title_login'); ?></title><link rel="stylesheet" href="../../css/global.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            color: #e0e0e0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            /* 主页壁纸（随机 1-10）+ 与主页一致的暗色渐变遮罩，保证文字可读 */
            background-color: #1a1a1a;
            background-image:
                radial-gradient(rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.5) 100%),
                radial-gradient(rgba(0, 0, 0, 0) 33%, rgba(0, 0, 0, 0.3) 166%),
                url('../bg/background<?php echo $bgWallpaper; ?>.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }
        .auth-container {
            background: rgba(42, 42, 42, 0.88);
            -webkit-backdrop-filter: blur(10px);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(90, 90, 90, 0.5);
            padding: 40px 38px;
            width: 380px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.5);
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
        /* 浏览器自动填充会盖一层黄色：用同色内阴影遮住 + 保持文字/光标颜色（毛玻璃质感不破） */
        .form-group input:-webkit-autofill,
        .form-group input:-webkit-autofill:hover,
        .form-group input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 1000px #1e1e1e inset;
            box-shadow: 0 0 0 1000px #1e1e1e inset;
            -webkit-text-fill-color: #e0e0e0;
            caret-color: #e0e0e0;
            transition: background-color 9999s ease-out 0s;
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
        /* 自定义下拉组件：不依赖系统原生 select 面板，面板完全由 CSS 控制 */
        .cselect {
            position: relative;
            display: inline-block;
            text-align: left;
            vertical-align: middle;
        }
        .cselect.full {
            display: block;
        }
        .cselect-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            width: 100%;
            box-sizing: border-box;
            background: #1e1e1e;
            border: 1px solid #444;
            color: #e0e0e0;
            padding: 4px 8px;
            font-size: 0.78em;
            font-family: inherit;
            cursor: pointer;
            outline: none;
            transition: border-color 0.2s;
        }
        .cselect.full .cselect-trigger {
            padding: 11px 14px;
            font-size: 0.95em;
        }
        .cselect-trigger:hover {
            border-color: #666;
        }
        .cselect-arrow {
            font-size: 0.7em;
            color: #888;
            transition: transform 0.2s;
        }
        .cselect.open .cselect-arrow {
            transform: rotate(180deg);
        }
        .cselect-menu {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            min-width: 100%;
            box-sizing: border-box;
            background: #1a1a1a;
            border: 1px solid #444;
            border-radius: 4px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
            z-index: 50;
            padding: 4px;
            opacity: 0;
            transform: translateY(-4px);
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease;
        }
        .cselect.open .cselect-menu {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        .cselect-item {
            padding: 8px 12px;
            color: #ccc;
            font-size: 0.82em;
            cursor: pointer;
            border-radius: 2px;
            transition: background 0.12s, color 0.12s;
        }
        .cselect.full .cselect-item {
            font-size: 0.95em;
        }
        .cselect-item:hover {
            background: #2a2a2a;
            color: #fff;
        }
        .cselect-item.selected {
            color: #fff;
            background: #333;
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
        /* PoW solving state: dim + disable the submit button */
        .btn-primary.pow-working {
            opacity: 0.55;
            pointer-events: none;
            cursor: default;
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
            background: #e0b030;
            border-color: #b8902a;
            color: #1a1a1a;
        }
        .rstr-actions .btn-primary.continue:hover {
            background: #f0c040;
            color: #1a1a1a;
        }

        /* ===== ChatApp 主页入口（index.php → login.php?fromindex=1 短时效显示） ===== */
        .auth-container.home {
            width: 680px;
            max-width: 92vw;
            text-align: center;
        }
        .home-logo { font-size: 46px; line-height: 1; margin-bottom: 6px; }
        .home-logo img { width: 52px; height: 52px; filter: brightness(0) invert(1); }
        .auth-container.home h1 {
            font-size: 2.5em;
            background: linear-gradient(135deg, #a8c9ff 0%, #d9b6ff 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .home-subtitle { color: rgba(255, 255, 255, 0.72); font-size: 1em; margin: 6px 0 28px; }
        .home-entries { display: flex; gap: 18px; justify-content: center; flex-wrap: wrap; }
        .home-card {
            display: flex; flex-direction: column; align-items: center;
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 26px 20px 20px; width: 250px; border-radius: 14px;
            text-decoration: none; color: #d5d5d5;
            transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
        }
        .home-card:hover {
            transform: translateY(-4px);
            background: rgba(255, 255, 255, 0.09);
            border-color: rgba(255, 255, 255, 0.24);
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.45);
        }
        .home-icon { font-size: 32px; margin-bottom: 10px; }
        .home-icon img { width: 38px; height: 38px; filter: brightness(0) invert(1); }
        .home-label { font-size: 0.72em; letter-spacing: 2.5px; text-transform: uppercase; color: #8fc0ff; margin-bottom: 8px; font-weight: 600; }
        .home-card h2 { font-size: 1.08em; color: #fff; margin-bottom: 8px; font-weight: 600; }
        .home-card p { font-size: 0.8em; color: rgba(255, 255, 255, 0.55); line-height: 1.5; }
        .home-go { margin-top: 14px; font-size: 0.8em; color: #7fb0ff; opacity: 0; transform: translateY(4px); transition: opacity 0.18s ease, transform 0.18s ease; }
        .home-card:hover .home-go { opacity: 1; transform: translateY(0); }
        .home-extra { margin-top: 26px; }
        .home-tablet-link {
            display: inline-block; color: rgba(255, 255, 255, 0.5); font-size: 0.8em;
            text-decoration: none; border: 1px solid rgba(255, 255, 255, 0.14);
            padding: 7px 16px; border-radius: 999px;
            transition: color 0.18s ease, border-color 0.18s ease;
        }
        .home-tablet-link:hover { color: #fff; border-color: rgba(255, 255, 255, 0.32); }
        .home-credit { margin-top: 14px; color: rgba(255, 255, 255, 0.4); font-size: 0.76em; }
        .home-login-link { display: inline-block; margin-top: 16px; color: rgba(255, 255, 255, 0.5); font-size: 0.8em; text-decoration: none; }
        .home-login-link:hover { color: #fff; text-decoration: underline; }

        /* 进入/切换动画：首页入口 与 登录表单 共用，导航切换时平滑淡入上浮 */
        @keyframes authFadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: none; }
        }
        .auth-container { animation: authFadeIn 0.45s ease; }
    </style>
</head>
<body>
    <?php if ($showHome): ?>
    <div class="auth-container home">
        <div class="home-logo"><img src="../../data/res/cil/cil-comment-bubble.svg" alt=""></div>
        <h1>ChatApp</h1>
        <p class="home-subtitle">其实就是一个自己暑假期间写的聊天网站</p>
        <div class="home-entries">
            <a href="chat.php" class="home-card">
                <div class="home-icon"><img src="../../data/res/cil/cil-comment-bubble.svg" alt=""></div>
                <div class="home-label">最新版本</div>
                <h2>目前正在开发的地方</h2>
                <p>选这个准没错</p>
                <div class="home-go">进入 →</div>
            </a>
            <a href="../../apps/music/index.html" class="home-card">
                <div class="home-icon"><img src="../../data/res/cil/cil-music-note.svg" alt=""></div>
                <div class="home-label">听音乐</div>
                <h2>聊天界面里不起眼的音乐组件</h2>
                <p>只想听音乐不想注册（热知识：兼容 IE9）</p>
                <div class="home-go">进入 →</div>
            </a>
        </div>
        <div class="home-extra">
            <a href="../../tablet/index.html" class="home-tablet-link">点这里看看已废弃的「尝试兼容 IE9」版本</a>
            <br><br>
            <a href="login.php" class="home-login-link">不想看主页？直接登录 →</a>
            <p class="home-credit">(14 岁 + Deepseek V4 Flash 写的)</p>
        </div>
    </div>
    <?php else: ?>
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
                    <input type="text" id="loginUsername" maxlength="100" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="loginPassword"><?php echo t('label_password'); ?></label>
                    <input type="password" id="loginPassword" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn-primary" id="loginBtn"><?php echo t('btn_login'); ?></button>
            </form>
            </div>

            <!-- Register Form -->
            <div class="panel-wrap" id="registerWrap">
            <form class="form-panel" id="registerPanel" onsubmit="handleRegister(event)">
                <div class="form-group">
                    <label for="regUsername"><?php echo t('label_username'); ?></label>
                    <input type="text" id="regUsername" maxlength="100" required autocomplete="username" placeholder="<?php echo t('msg_login_username_hint'); ?>">
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
                <button type="submit" class="btn-primary" id="registerBtn"><?php echo t('btn_register'); ?></button>
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
                <button class="btn-primary continue" onclick="doContinueLogin()">Login</button>
                <button class="btn-primary" onclick="doLogout()"><?php echo t('btn_log_out'); ?></button>
            </div>
        </div>

        <a href="../../index.php" class="back-link" id="backLink"><?php echo t('msg_back_entry'); ?></a>
    </div>
    <?php endif; ?>

    <!-- 共享底部版权栏（modern/partials/footer.php，一处更新全站生效） -->
    <?php include __DIR__ . '/../partials/footer.php'; ?>

    <script src="../scripts/pow.js"></script>
    <script>
        var _currentLang = '<?php echo $currentLang; ?>';
        var LANG = <?php
            $langArr = lang_load();
            echo json_encode([
                'msg_restricted_reason' => $langArr['msg_restricted_reason'] ?? 'Reason: %s',
                'msg_pow_working' => $langArr['msg_pow_working'] ?? 'Logging in...',
                'msg_pow_registering' => $langArr['msg_pow_registering'] ?? 'Registering...',
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
                var resp = await fetch('../../api/auth.php', {
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
            fetch('../../api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=logout'
            }).catch(function() {}).finally(function() {
                window.location.href = '../../index.php';
            });
        }

        var _powLabel = t('msg_pow_working', 'Logging in...');
        var _powRegisterLabel = t('msg_pow_registering', 'Registering...');

        function setButtonWorking(btn, label) {
            btn.classList.add('pow-working');
            btn.textContent = label;
        }

        function resetButton(btn, label) {
            btn.classList.remove('pow-working');
            btn.textContent = label;
        }

        async function fetchPowChallenge() {
            try {
                var resp = await fetch('../../api/auth.php?action=challenge');
                var data = await resp.json();
                if (data.success && data.challenge && data.target) return data;
            } catch (e) {}
            return null;
        }

        // Fetch challenge → solve (button shows "Logging in... (n kH/s)") → POST.
        // Auto-retries once when the server reports an expired/failed challenge.
        async function submitWithPow(btn, action, appendFields, label) {
            if (!label) label = _powLabel;
            var retried = false;
            while (true) {
                var pow = await fetchPowChallenge();
                if (!pow) return { success: false, error: 'Something went wrong.' };
                var solved = await ChatAppPow.solve(pow.challenge, pow.target, function (kHps) {
                    btn.textContent = label + ' (' + Math.round(kHps) + ' kH/s)';
                });
                if (!solved) return { success: false, error: 'Something went wrong.' };
                var fd = new URLSearchParams();
                fd.append('action', action);
                fd.append('pow_challenge', pow.challenge);
                fd.append('pow_nonce', solved.nonce);
                appendFields(fd);
                var data;
                try {
                    var resp = await fetch('../../api/auth.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: fd.toString()
                    });
                    data = await resp.json();
                } catch (err) {
                    return { success: false, error: 'Something went wrong.' };
                }
                if (data.error === 'pow_challenge_failed' && !retried) {
                    retried = true;
                    continue; // refetch + re-solve once
                }
                return data;
            }
        }

        function powErrorText(data) {
            if (data.error === 'pow_challenge_failed') return 'Please try again.';
            return data.error || 'Something went wrong.';
        }

        async function handleLogin(e) {
            e.preventDefault();
            hideError();

            var username = document.getElementById('loginUsername').value.trim();
            var password = document.getElementById('loginPassword').value;
            var btn = document.getElementById('loginBtn');
            var origLabel = btn.textContent;

            setButtonWorking(btn, _powLabel);
            try {
                var data = await submitWithPow(btn, 'login', function (fd) {
                    fd.append('username', username);
                    fd.append('password', password);
                });
                if (data.success) {
                    window.location.href = 'chat.php';
                } else if (data.restricted) {
                    resetButton(btn, origLabel);
                    showRestricted(data);
                } else {
                    resetButton(btn, origLabel);
                    showError(powErrorText(data));
                }
            } catch (err) {
                resetButton(btn, origLabel);
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

            var btn = document.getElementById('registerBtn');
            var origLabel = btn.textContent;

            setButtonWorking(btn, _powRegisterLabel);
            try {
                var data = await submitWithPow(btn, 'register', function (fd) {
                    fd.append('username', username);
                    fd.append('password', password);
                    fd.append('language', document.getElementById('regLanguage').value);
                }, _powRegisterLabel);
                if (data.success) {
                    window.location.href = 'chat.php';
                } else {
                    resetButton(btn, origLabel);
                    showError(powErrorText(data));
                }
            } catch (err) {
                resetButton(btn, origLabel);
                showError('Something went wrong.');
            }
        }
        /* ===== 自定义下拉组件：用 div 面板替换系统原生 select 弹出列表 ===== */
        function initCustomSelect(selectId, full) {
            var select = document.getElementById(selectId);
            if (!select || select.__cselect) return;
            select.__cselect = true;

            var opts = select.querySelectorAll('option');
            var current = select.selectedIndex < 0 ? 0 : select.selectedIndex;

            var wrap = document.createElement('div');
            wrap.className = 'cselect' + (full ? ' full' : '');

            var trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'cselect-trigger';
            trigger.innerHTML = '<span class="cselect-label"></span><span class="cselect-arrow">&#9660;</span>';

            var menu = document.createElement('div');
            menu.className = 'cselect-menu';
            var items = [];

            for (var i = 0; i < opts.length; i++) {
                (function(idx) {
                    var it = document.createElement('div');
                    it.className = 'cselect-item' + (idx === current ? ' selected' : '');
                    it.textContent = opts[idx].textContent;
                    it.addEventListener('click', function() {
                        select.selectedIndex = idx;
                        sync();
                        close();
                        select.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                    menu.appendChild(it);
                    items.push(it);
                })(i);
            }

            function sync() {
                var idx = select.selectedIndex < 0 ? 0 : select.selectedIndex;
                trigger.querySelector('.cselect-label').textContent = opts[idx].textContent;
                for (var j = 0; j < items.length; j++) {
                    items[j].classList.toggle('selected', j === idx);
                }
            }

            function close() {
                wrap.classList.remove('open');
                document.removeEventListener('click', outsideHandler);
            }

            function outsideHandler(e) {
                if (!wrap.contains(e.target)) close();
            }

            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                if (wrap.classList.contains('open')) {
                    close();
                } else {
                    wrap.classList.add('open');
                    document.addEventListener('click', outsideHandler);
                }
            });

            sync();
            wrap.appendChild(trigger);
            wrap.appendChild(menu);
            select.style.display = 'none'; // 保留原生 select（值同步 + 表单提交），仅视觉隐藏
            select.insertAdjacentElement('afterend', wrap);
        }

        // 页面加载完初始化：语言选择器（紧凑）+ 注册偏好语言（全宽）
        initCustomSelect('langSwitch', false);
        initCustomSelect('regLanguage', true);    </script>
</body>
</html>