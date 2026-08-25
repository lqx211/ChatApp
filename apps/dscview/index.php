<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DeepSeek 聊天记录查看器</title>
<link rel="stylesheet" href="theme/base-fonts.css">
<link rel="stylesheet" href="theme/base.css">
<link rel="stylesheet" href="a.css">
<link rel="stylesheet" href="katex.css">
<link rel="stylesheet" href="index.css">
<script src="katex.min.js"></script>
</head>
<body data-ds-dark-theme>

<!-- 上传 -->
<div id="dropzone">
  <div class="uploader-inner">
    <div class="uploader-icon">🐋</div>
    <h1>DeepSeek 聊天记录</h1>
    <p>选择导出的聊天记录 JSON，全部在本地浏览器解析，不会上传到任何服务器。</p>
    <label class="btn-primary" for="fileinput">选择 JSON 文件</label>
    <input type="file" id="fileinput" accept=".json,application/json" hidden>
    <p class="small">也可以直接把文件拖拽到此处</p>
  </div>
</div>

<!-- 解析进度 -->
<div id="progress" class="hidden">
  <div class="progress-inner">
    <h2>正在解析…</h2>
    <div class="bar"><div id="bar-fill"></div></div>
    <p id="progress-text" class="small">0%</p>
  </div>
</div>

<!-- 主界面 -->
<div id="app" class="hidden">
  <aside id="sidebar">
    <div id="logoRow">
      <div id="brand">
        <span id="brandMark">🐋</span>
        <span id="brandName">聊天记录</span>
      </div>
      <button id="reuploadBtn" class="iconButton" type="button" title="重新上传">⟲</button>
    </div>
    <button id="newSession" type="button"><span>＋</span><span id="newSessionLabel">上传记录</span></button>
    <div id="regionArea">
      <div id="searchbox-wrap">
        <input id="searchbox" type="search" placeholder="搜索标题或内容…" autocomplete="off">
      </div>
      <div id="chatlist"></div>
      <div id="loadmore" class="hidden"><button id="loadmore-btn" type="button">加载更多</button></div>
    </div>
    <button id="settingsBtn" type="button" class="ds-button ds-button--outlinedNeutral ds-button--outlined ds-button--capsule ds-button--m ds-button--icon-relative-m ds-button--min-width" style="--dsl-button-height: 38px; --dsl-button-border-radius: 12px;">
      <div class="ds-button__background"></div>
      <div class="ds-button__border"></div>
      <div class="ds-button__icon"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 6.86A1.14 1.14 0 1 0 8 9.14 1.14 1.14 0 0 0 8 6.86ZM8 10.48a2.48 2.48 0 1 1 0-4.96 2.48 2.48 0 0 1 0 4.96Z" fill="currentColor"/><path d="M14.09 5.51a6.6 6.6 0 0 0-.8-1.33l-1.45.74a5.2 5.2 0 0 0-1.95-1.13L9.64 2.4a6.7 6.7 0 0 0-3.28 0l-.25 1.4A5.2 5.2 0 0 0 4.16 4.92l-1.45-.74a6.6 6.6 0 0 0-.8 1.33l1.15.9a5.2 5.2 0 0 0 0 2.28l-1.15.9c.2.47.47.92.8 1.33l1.45-.74c.43.53.98.92 1.6 1.13l.25 1.4c.54.13 1.1.13 1.64 0l.25-1.4c.62-.2 1.17-.6 1.6-1.13l1.45.74c.33-.4.6-.86.8-1.33l-1.15-.9a5.2 5.2 0 0 0 0-2.28l1.15-.9ZM8 11.02A3.02 3.02 0 1 1 8 4.98a3.02 3.02 0 0 1 0 6.04Z" fill="currentColor"/></svg></div>
      <span class="ds-button__content">设置</span>
    </button>
  </aside>

  <main id="main">
    <div id="empty">
      <div id="empty-icon">💬</div>
      <p>从左侧选择一条对话开始查看</p>
    </div>
    <div id="chatview" class="hidden">
      <header id="conv-header">
        <div id="titleRow">
          <div id="crumbs"><span class="crumb crumbCurrent" id="chatTitle"></span></div>
          <span id="headerMeta" class="muted"></span>
        </div>
        <div id="tabs">
          <button class="tab tabActive" type="button" data-panel="chat">对话</button>
          <button class="tab" type="button" data-panel="stats">统计</button>
        </div>
      </header>
      <div id="chatScroll"><div id="messages"></div></div>
      <div id="statsView" class="hidden"><div id="statsContent"></div></div>
    </div>
  </main>
</div>

<!-- 设置弹窗（官方系统设置） -->
<div class="ds-modal-overlay hidden" id="settingsOverlay"></div>
<div id="settingsRoot" class="hidden"><?php include '_settings_modal.html'; ?></div>

<script src="main.js"></script>
</body>
</html>
