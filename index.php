<?php
/**
 * ChatApp - Network Chatting Application
 * Entry page with links to both Modern (PHP 8.3) and IE8 versions
 */
require_once __DIR__ . '/maintenance.php';

// 直接重定向到登录页，并携带短时效的 fromindex 标记：
// login.php 在 fromindex=1 且 t 距当前 unix 时间戳 <3 秒时显示 ChatApp 主页入口 UI，否则显示登录表单。
header('Location: modern/wp/login.php?fromindex=1&t=' . time());
exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ChatApp</title>
    <style>
        @font-face {
            font-family: 'Roboto';
            src: url('css/fonts/Roboto-Regular.ttf') format('truetype');
            font-weight: 400;
            font-style: normal;
        }
        @font-face {
            font-family: 'Chinese';
            src: url('css/fonts/chinese.otf') format('opentype');
            font-weight: 400;
            font-style: normal;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Roboto', 'Chinese', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', sans-serif;
            color: #e8e8e8;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 16px 96px;
            /* 主页壁纸（随机 1-10）+ 暗色渐变遮罩，与登录页一致 */
            background-color: #1a1a1a;
            background-image:
                radial-gradient(rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.5) 100%),
                radial-gradient(rgba(0, 0, 0, 0) 33%, rgba(0, 0, 0, 0.3) 166%),
                url('modern/bg/background<?php echo rand(1, 10); ?>.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }
        .container {
            text-align: center;
            width: 100%;
            max-width: 680px;
            padding: 52px 44px 42px;
            background: rgba(20, 20, 22, 0.62);
            -webkit-backdrop-filter: blur(16px);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 22px;
            box-shadow: 0 18px 60px rgba(0, 0, 0, 0.55);
        }
        h1 {
            font-size: 2.7em;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #a8c9ff 0%, #d9b6ff 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        p.subtitle {
            color: rgba(255, 255, 255, 0.72);
            font-size: 1.02em;
            margin-bottom: 40px;
        }
        .entries {
            display: flex;
            gap: 22px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .entry-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 30px 24px 24px;
            width: 250px;
            text-decoration: none;
            color: #d5d5d5;
            border-radius: 16px;
            transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
        }
        .entry-card:hover {
            transform: translateY(-4px);
            background: rgba(255, 255, 255, 0.09);
            border-color: rgba(255, 255, 255, 0.24);
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.45);
        }
        .entry-card .icon {
            font-size: 34px;
            margin-bottom: 12px;
        }
        .entry-card .label {
            font-size: 0.74em;
            letter-spacing: 2.5px;
            text-transform: uppercase;
            color: #8fc0ff;
            margin-bottom: 10px;
            font-weight: 600;
        }
        .entry-card h2 {
            font-size: 1.12em;
            color: #fff;
            margin-bottom: 8px;
            font-weight: 600;
        }
        .entry-card p {
            font-size: 0.82em;
            color: rgba(255, 255, 255, 0.55);
            line-height: 1.5;
        }
        .entry-card .go {
            margin-top: 16px;
            font-size: 0.8em;
            color: #7fb0ff;
            opacity: 0;
            transform: translateY(4px);
            transition: opacity 0.18s ease, transform 0.18s ease;
        }
        .entry-card:hover .go {
            opacity: 1;
            transform: translateY(0);
        }
        .extra {
            margin-top: 34px;
        }
        .tablet-link {
            display: inline-block;
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.8em;
            text-decoration: none;
            border: 1px solid rgba(255, 255, 255, 0.14);
            padding: 7px 16px;
            border-radius: 999px;
            transition: color 0.18s ease, border-color 0.18s ease;
        }
        .tablet-link:hover {
            color: #fff;
            border-color: rgba(255, 255, 255, 0.32);
        }
        p.credit {
            margin-top: 16px;
            color: rgba(255, 255, 255, 0.4);
            font-size: 0.78em;
        }

        @media (max-width: 480px) {
            #footer .hidden {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>ChatApp</h1>
        <p class="subtitle">其实就是一个自己暑假期间写的聊天网站</p>

        <div class="entries">
            <a href="modern/wp/index.php" class="entry-card">
                <div class="icon">💬</div>
                <div class="label">最新版本</div>
                <h2>目前正在开发的地方</h2>
                <p>选这个准没错</p>
                <div class="go">进入 →</div>
            </a>
            <a href="apps/music/index.html" class="entry-card">
                <div class="icon">🎵</div>
                <div class="label">听音乐</div>
                <h2>聊天界面里不起眼的音乐组件</h2>
                <p>只想听音乐不想注册（热知识：兼容 IE9）</p>
                <div class="go">进入 →</div>
            </a>
        </div>

        <div class="extra">
            <a href="tablet/index.html" class="tablet-link">点这里看看已废弃的「尝试兼容 IE9」版本</a>
            <p class="credit">(14 岁 + Deepseek V4 Flash 写的)</p>
        </div>
    </div>

    <!-- 共享底部版权栏（modern/partials/footer.php） -->
    <?php include __DIR__ . '/modern/partials/footer.php'; ?>
</body>
</html>