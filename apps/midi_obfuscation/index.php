<?php
/**
 * MIDI Obfuscator - MIDI 混淆器（播放不变，拆 10 轨防扒谱）
 * ChatApp apps/midi_obfuscation 应用入口
 * Deepseek 写的
 */
require_once __DIR__ . '/../../maintenance.php';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="renderer" content="webkit">
    <title>MIDI 混淆器</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="wrap">
        <header class="hd">
            <h1>MIDI 混淆器</h1>
            <p class="sub">播放效果 <a>暂不保证不变</a> · 单条旋律打散成 256 条伪装轨道 · 防扒谱 / 防抄袭</p>
        </header>

        <!-- 上传区 -->
        <section class="card upload-card" id="uploadCard">
            <div class="drop" id="dropZone">
                <input type="file" id="fileInput" accept=".mid,.midi,audio/midi,audio/x-midi" hidden>
                <div class="drop-icon">♪</div>
                <p class="drop-txt">点击选择 或 拖拽 MIDI 文件到此处</p>
                <p class="drop-sub">支持 .mid/*.midi</p>
            </div>
            <div class="file-info" id="fileInfo" hidden>
                <div class="fname" id="fileName"></div>
                <div class="fmeta" id="fileMeta"></div>
            </div>
        </section>

        <!-- 参数区 -->
        <section class="card" id="optCard" hidden>
            <h2 class="sec-title">混淆选项</h2>
            <div class="opts">
                <label class="opt-row">
                    <input type="checkbox" id="optSplitOff" checked>
                    <span>把轨道复杂化，拆分256倍</span>
                </label>
                <label class="opt-row">
                    <input type="checkbox" id="optNoise" checked>
                    <span>用迷惑式轨道名</span>
                </label>
            </div>
            <button class="bsm go" id="btnGo">开始混淆</button>
        </section>

        <!-- 结果区 -->
        <section class="card" id="resultCard" hidden>
            <h2 class="sec-title">混淆完成</h2>
            <div class="stats" id="statsBox"></div>
            <div class="verify" id="verifyBox"></div>
            <div class="actions">
                <button class="bsm go" id="btnPlay">试听</button>
                <button class="bsm" id="btnStop" hidden>停止</button>
                <button class="bs m" id="btnDownload">⬇ 下载混淆后的 MIDI</button>
            </div>
            <p class="hint">试听为浏览器内置合成器，效果非常差劲，强烈不建议听</p>
        </section>

    <footer class="ft">
            <p>MIDI Obfuscator · 非ICP备11004514号-1 · 非公网安备191981003000212号 · Copyleft © lqx211.com</p>
        </footer>
    </div>

    <script src="js/midi_parser.js"></script>
    <script src="js/midi_writer.js"></script>
    <script src="js/obfuscator.js"></script>
    <script src="js/app.js"></script>
</body>
</html>
