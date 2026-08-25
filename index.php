<?php
/**
 * ChatApp - Network Chatting Application
 * Entry page with links to both Modern (PHP 8.3) and IE8 versions
 */
require_once __DIR__ . '/maintenance.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ChatApp</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1a1a;
            color: #e0e0e0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            text-align: center;
            background: #2a2a2a;
            padding: 60px 50px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            border: 1px solid #3a3a3a;
        }
        h1 {
            font-size: 2.4em;
            margin-bottom: 10px;
            color: #c0c0c0;
            font-weight: 600;
        }
        p.subtitle {
            color: #888;
            margin-bottom: 40px;
            font-size: 1.05em;
        }
        p.even_smaller_subtitle {
            color: #888;
            margin-bottom: 40px;
            font-size: 0.8em;
        }
        .entries {
            display: flex;
            gap: 30px;
            justify-content: center;
        }
        .entry-card {
            background: #333;
            border: 1px solid #444;
            padding: 30px 25px;
            width: 220px;
            text-decoration: none;
            color: #ccc;
            transition: background 0.2s, border-color 0.2s;
        }
        .entry-card-dimmed {
            background: #2a2a2a;
            border-color: #555;
            color: #999;
            cursor: not-allowed;
        }
        .entry-card:hover {
            background: #3a3a3a;
            border-color: #666;
        }
        .entry-card h2 {
            font-size: 1.2em;
            margin-bottom: 8px;
            color: #ddd;
        }

        .entry-card p {
            font-size: 0.85em;
            color: #777;
        }
        .entry-card .label {
            font-size: 1.1em;
            color: #aaa;
            margin-bottom: 12px;
            font-weight: bold;
        }
        .label-gray {
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>ChatApp</h1>
        <p class="subtitle">其实就是一个自己暑假期间写的聊天网站</p>
        
        <div class="entries">
            <a href="/modern/wp/index.php" class="entry-card">
                <div class="label">最新版本</div>
                <h2>目前正在开发的地方</h2>
                <p>选这个准没错</p>
            </a>
            <a href="/apps/music/index.html" class="entry-card">
                <div class="label">听音乐</div>
                <h2>在聊天界面不起眼的地方的一个音乐组件</h2>
                <p>只想听音乐不想注册(热知识: 兼容IE9)</p>
            </a>
        </div>
        <div>
        <br><br> <!-- 加点没用的美观br -->
        <a href="tablet/index.html" class="label label-gray">点击此不起眼文字可以去看已经废弃的「尝试兼容IE9」版本</a><br><br>
        <p class="even_smaller_subtitle">(14岁加Deepseek V4 Flash写的)</p>
        </div>
    </div>
</body>
</html>