<?php
/**
 * apps/filemgr/index.php — 文件管理器（root 专用，深色主题）
 * 排版照抄 RojExplorer：左侧文件夹树 + 可拖分隔条 + 右侧（地址栏/工具栏/列表表头/文件列表）
 * 后端全部安全重写（见 api.php）。
 */
require __DIR__ . '/../../api/config.php';
chatapp_session_start();
$u = !empty($_SESSION['username']) ? chatapp_get_user() : null;
$uid = $u['user_id'] ?? null;
$isRoot = $uid && chatapp_get_role((int)$uid) === 'root';
if (!$isRoot) {
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><body style="background:#141414;color:#e06060;font-family:sans-serif;padding:40px;text-align:center"><h2>403 无权访问</h2><p>文件管理器仅限 root 使用</p></body>');
}
$API = 'api.php';
?>
<!doctype html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>文件管理器</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden;background:#1b1b1b;color:#ccc;font:13px/1.4 -apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,"PingFang SC","Microsoft YaHei",sans-serif}
img[src*="data/res/svg"],img[src*="data/res/cil"]{filter:brightness(0) invert(1)}
::-webkit-scrollbar{width:9px;height:9px}
::-webkit-scrollbar-thumb{background:#3a3a3a;border-radius:4px}
::-webkit-scrollbar-track{background:transparent}

#app{display:flex;height:100%}
/* 左侧树 */
.frame-left{width:220px;min-width:120px;background:#161616;border-right:1px solid #2c2c2c;display:flex;flex-direction:column}
.tree-head{padding:8px 12px;font-size:12px;color:#8a8a8a;border-bottom:1px solid #2a2a2a;display:flex;justify-content:space-between;align-items:center}
.tree-head button{background:none;border:none;color:#6a9fd8;cursor:pointer;font-size:12px}
#tree{flex:1;overflow:auto;padding:6px 4px}
.tree-node{white-space:nowrap;padding:2px 4px;cursor:pointer;border-radius:4px;color:#bbb}
.tree-node:hover{background:#262626}
.tree-node.sel{background:#2b3a4a;color:#fff}
.tree-node .tw{display:inline-block;width:14px;text-align:center;color:#888;font-size:10px}
.tree-node .tlabel{padding:1px 2px}
.tree-node .tlabel.cur{color:#6a9fd8;font-weight:600}
.tree-children{padding-left:16px}
.tree-node img{width:14px;height:14px;vertical-align:-2px;margin-right:3px}

/* 分隔条 */
.frame-resize{width:4px;cursor:col-resize;background:#1f1f1f;flex-shrink:0}
.frame-resize:hover{background:#3a5a7a}

/* 右侧 */
.frame-right{flex:1;display:flex;flex-direction:column;min-width:0}
.frame-header{background:#1f1f1f;border-bottom:1px solid #2c2c2c;padding:6px 10px;display:flex;align-items:center;gap:8px}
.btn{background:#2a2a2a;border:1px solid #3c3c3c;color:#ccc;padding:5px 10px;border-radius:4px;cursor:pointer;font-size:13px;line-height:1}
.btn:hover{background:#343434}
.btn:disabled{opacity:.4;cursor:default}
.btn img{width:15px;height:15px;vertical-align:-3px}
.hgroup{display:flex;gap:0}
.hgroup .btn{border-radius:0}
.hgroup .btn:first-child{border-radius:4px 0 0 4px}
.hgroup .btn:last-child{border-radius:0 4px 4px 0}
.header-left,.header-right{flex-shrink:0}
.header-middle{flex:1;display:flex;align-items:center;gap:6px;min-width:0}
.breadcrumb{flex:1;display:flex;align-items:center;gap:2px;background:#141414;border:1px solid #333;border-radius:4px;padding:4px 8px;overflow-x:auto;white-space:nowrap}
.bc-seg{color:#6a9fd8;cursor:pointer;padding:1px 4px;border-radius:3px}
.bc-seg:hover{background:#2a3a4a}
.bc-sep{color:#555}
.bc-seg.cur{color:#ccc;cursor:default}
#searchBox{width:150px;background:#141414;border:1px solid #333;color:#ccc;padding:5px 8px;border-radius:4px;outline:none}
.srch-wrap{display:flex;align-items:center;gap:4px}
.srch-mode{padding:4px 8px;font-size:12px;background:#2a2a2a;border:1px solid #3c3c3c;color:#aaa;border-radius:4px;cursor:pointer}
.srch-mode.on{background:#2a4a7a;border-color:#3a6a9a;color:#fff}

/* 工具栏 */
.tools{background:#1c1c1c;border-bottom:1px solid #2c2c2c;padding:5px 10px;display:flex;align-items:center;gap:8px}
.tools-left{display:flex;align-items:center;gap:8px;flex:1}
.tools-right{display:flex;align-items:center;gap:6px}
.tools .msg{color:#888;font-size:12px}
.btn.big{display:inline-flex;align-items:center;gap:5px}
/* 窄屏：工具栏只显示图标，隐藏文字与状态，避免拥挤 */
@media (max-width: 1100px){
  .tools .btn.big{padding:5px 8px;gap:0}
  .tools .btn.big .lbl{display:none}
  .tools .msg{display:none}
}
@media (max-width: 720px){
  .tools-left{gap:4px}
  .tools .btn.big{padding:5px 7px}
  .tools-right{gap:4px}
}

/* 列表表头 */
.list-header{background:#1f1f1f;border-bottom:1px solid #2c2c2c;display:flex;font-size:12px;color:#999;user-select:none}
:root{--col-type:90px;--col-size:90px;--col-time:150px}
.lh{flex:0 0 auto;padding:6px 10px;cursor:pointer;position:relative}
.lh:hover{color:#ccc}
.lh.up::after{content:" ▲";color:#6a9fd8}
.lh.down::after{content:" ▼";color:#6a9fd8}
.lh-name{flex:1}
.lh-type{width:var(--col-type)}
.lh-size{width:var(--col-size);text-align:right}
.lh-time{width:var(--col-time)}
.rh{position:absolute;right:-4px;top:0;width:8px;height:100%;cursor:col-resize;z-index:2}
.rh:hover{background:rgba(106,159,216,.35)}

/* 主体 */
.bodymain{flex:1;overflow:auto;background:#181818;position:relative}
#fileList{}
.row{display:flex;align-items:center;padding:5px 10px;border-bottom:1px solid #202020;cursor:default}
.row:hover{background:#222}
.row.sel{background:#2b3a4a}
.row .ico{width:22px;text-align:center;flex-shrink:0;margin-right:6px}
.row .ico img{width:18px;height:18px;vertical-align:middle}
.row .nm{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer}
.row .ty{width:var(--col-type);color:#888;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.row .sz{width:var(--col-size);text-align:right;color:#999;font-size:12px}
.row .tm{width:var(--col-time);color:#888;font-size:12px}
.row .ops{display:flex;align-items:center;justify-content:flex-end;gap:2px;opacity:0}
.row:hover .ops{opacity:1}
.op{background:none;border:none;cursor:pointer;padding:3px;border-radius:3px;display:inline-flex;align-items:center;justify-content:center}
.op img{width:15px;height:15px;display:block}
.op:hover{background:#2a3a4a}
.op.danger:hover{background:#4a2a2a}
.up-row{color:#888;font-style:italic}
.empty{padding:40px;text-align:center;color:#666}

/* 图标视图 */
#fileList.icon-view{display:flex;flex-wrap:wrap;align-content:flex-start;padding:10px;gap:6px}
.icon-item{width:88px;padding:8px 4px;border-radius:6px;text-align:center;cursor:pointer;border:1px solid transparent}
.icon-item:hover{background:#222;border-color:#333}
.icon-item.sel{background:#2b3a4a;border-color:#6a9fd8}
.icon-item .ico{width:44px;height:44px;margin:0 auto 6px;display:flex;align-items:center;justify-content:center}
.icon-item .ico img{width:44px;height:44px}
.icon-item .nm{font-size:11px;color:#ccc;word-break:break-all;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}

.statusbar{height:24px;background:#1c1c1c;border-top:1px solid #2c2c2c;color:#888;font-size:12px;display:flex;align-items:center;padding:0 10px;gap:14px}
#stClip{color:#d8b060;cursor:pointer;max-width:40vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#stClip:hover{color:#e8c87a}
#stInfo{color:#8a8a8a}
.up-wrap{position:relative;flex:1;align-self:stretch;margin-left:8px}
#upBar{position:absolute;bottom:0;left:0;height:2px;width:0;background:#6a9fd8;transition:width .15s}

/* 面包屑编辑 */
.bc-wrap{flex:1;display:flex;align-items:center;min-width:0;position:relative}
.bc-wrap .breadcrumb{flex:1;min-width:0}
.breadcrumb.editing{display:none}
.path-edit{display:none;flex:1;min-width:0;background:#141414;border:1px solid #6a9fd8;color:#e0e0e0;border-radius:4px;padding:4px 8px;font:13px/1.3 inherit;outline:none}
.bc-edit{flex-shrink:0;padding:4px 7px}
.bc-edit img{width:14px;height:14px}

/* 右键菜单（RojExplorer 结构 + 深色） */
.context-menu-list{position:fixed;z-index:1200;min-width:178px;max-height:80vh;overflow-y:auto;background:#242424;border:1px solid #3a3a3a;border-radius:6px;padding:4px;box-shadow:0 8px 28px rgba(0,0,0,.6);display:none}
.context-menu-list.open{display:block}
.context-menu-item{position:relative;display:flex;align-items:center;gap:8px;padding:5px 14px 5px 8px;border:1px solid transparent;border-radius:4px;color:#d5d5d5;cursor:pointer;white-space:nowrap;font-size:13px;user-select:none}
.context-menu-item:hover{background:#2b3a4a;color:#fff;border-color:rgba(106,159,216,.7)}
.context-menu-item.disabled{opacity:.35;pointer-events:none}
.context-menu-item .ctx-ico{width:16px;height:16px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center}
.context-menu-item .ctx-ico img{width:16px;height:16px;vertical-align:middle}
.context-menu-item .ctx-label{flex:1;overflow:hidden;text-overflow:ellipsis}
.context-menu-item .ctx-arrow{color:#888;font-size:11px;margin-left:8px}
.context-menu-sep{height:1px;margin:4px 6px;background:#333}
.context-menu-sub{position:absolute;left:100%;top:-4px;display:none;min-width:150px;background:#242424;border:1px solid #3a3a3a;border-radius:6px;padding:4px;box-shadow:0 8px 28px rgba(0,0,0,.6)}
.context-menu-item:hover > .context-menu-sub{display:block}

/* 行内悬停更多按钮 */
.row .more-btn{background:none;border:none;color:#9a9a9a;cursor:pointer;padding:2px 4px;border-radius:3px;display:none}
.row:hover .more-btn{display:inline-block}
.row .more-btn:hover{color:#fff;background:#2a3a4a}
.row .more-btn img{width:14px;height:14px;vertical-align:-2px}

/* 选中复选框指示 */
.row .ck,.icon-item .ck{width:16px;height:16px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;margin-right:6px;opacity:0;transition:opacity .1s}
.row:hover .ck,.row.sel .ck,.icon-item:hover .ck,.icon-item.sel .ck{opacity:1}
.row .ck i,.icon-item .ck i{width:11px;height:11px;border:1px solid #6a9fd8;border-radius:3px;background:transparent}
.row.sel .ck i,.icon-item.sel .ck i{background:#6a9fd8;position:relative}
.row.sel .ck i::after,.icon-item.sel .ck i::after{content:"";position:absolute;left:3px;top:1px;width:3px;height:6px;border:solid #0d1420;border-width:0 2px 2px 0;transform:rotate(45deg)}
.icon-item{position:relative}
.icon-item .ck{position:absolute;top:5px;right:5px;margin:0}

/* 内部拖拽（RojExplorer 风格） */
#dragGhost{position:fixed;z-index:2000;pointer-events:none;background:#1f2a36;border:1px solid #6a9fd8;border-radius:6px;padding:6px 10px;color:#e0e0e0;font-size:13px;display:flex;align-items:center;gap:6px;box-shadow:0 6px 20px rgba(0,0,0,.5)}
#dragGhost img{width:16px;height:16px}
.row.drop-target,.icon-item.drop-target{outline:2px dashed #6a9fd8;outline-offset:-2px}
.tree-node.drop-target{outline:2px dashed #6a9fd8;outline-offset:-2px;background:#22303e}
.bc-seg.drop-target{background:#2a3a4a}

/* 自定义模态框 */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.55);display:none;align-items:center;justify-content:center;z-index:1000}
.modal-backdrop.show{display:flex}
.modal{background:#222;border:1px solid #3c3c3c;border-radius:8px;padding:16px;min-width:340px;max-width:92vw;box-shadow:0 10px 40px rgba(0,0,0,.55)}
.modal h3{margin:0 0 12px;font-size:14px;color:#e0e0e0}
.modal p{margin:0 0 4px;color:#ccc;font-size:13px;line-height:1.6;word-break:break-all}
.modal input{width:100%;box-sizing:border-box;background:#141414;border:1px solid #444;color:#e0e0e0;padding:8px 10px;border-radius:4px;font-size:13px;outline:none;margin-top:4px}
.modal input:focus{border-color:#6a9fd8}
.modal .mbtns{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
.modal .mbtns .btn{min-width:64px}
.modal .mbtns .btn.primary{background:#2a4a7a;border-color:#3a6a9a;color:#fff}
.modal .mbtns .btn.danger{background:#5a2a2a;border-color:#8a3a3a;color:#fff}
.modal .mbtns .btn:hover{filter:brightness(1.2)}
.modal-backdrop.wide .modal{min-width:min(86vw,1120px)}
.txt-preview{max-height:55vh;overflow:auto;background:#101010;border:1px solid #333;border-radius:4px;padding:10px;font:12px/1.5 Menlo,Consolas,monospace;white-space:pre-wrap;word-break:break-all;color:#d5d5d5;margin-top:4px}
.modal .mbtns a.btn{display:inline-flex;align-items:center;text-decoration:none}
</style>
</head>
<body>
<div id="app">
  <div class="frame-left" id="frameLeft">
    <div class="tree-head"><span>文件树</span><button id="treeRoot"><img src="../../data/res/cil/cil-home.svg" style="width:12px;height:12px;vertical-align:-2px;margin-right:3px">根目录</button></div>
    <div id="tree"></div>
  </div>
  <div class="frame-resize" id="resizer"></div>
  <div class="frame-right">
    <div class="frame-header">
      <div class="header-left">
        <div class="hgroup">
          <button class="btn" id="btnBack" title="后退">◀</button>
          <button class="btn" id="btnNext" title="前进">▶</button>
        </div>
      </div>
      <div class="header-middle">
        <button class="btn" id="btnHome" title="根目录">⌂</button>
        <div class="bc-wrap">
          <div class="breadcrumb" id="breadcrumb"></div>
          <input class="path-edit" id="pathEdit" spellcheck="false" placeholder="输入路径，回车跳转">
          <button class="btn bc-edit" id="btnPathEdit" title="编辑路径"><img src="../../data/res/svg/edit_16.svg"></button>
        </div>
        <button class="btn" id="btnRefresh" title="刷新">⟳</button>
        <button class="btn" id="btnUp" title="上一级">↑</button>
      </div>
      <div class="header-right">
        <div class="srch-wrap">
          <input id="searchBox" placeholder="搜索当前目录…">
          <button class="srch-mode" id="btnSearchMode" title="切换搜索范围：目录 / 全局递归">目录</button>
        </div>
      </div>
    </div>

    <div class="tools">
      <div class="tools-left">
        <button class="btn big" id="btnNewDir"><img src="../../data/res/svg/folder_24.svg"><span class="lbl">新建文件夹</span></button>
        <button class="btn big" id="btnNewFile"><img src="../../data/res/svg/document_to_computer_24.svg"><span class="lbl">新建文件</span></button>
        <button class="btn big" id="btnUpload"><img src="../../data/res/svg/upload_24.svg"><span class="lbl">上传</span></button>
        <button class="btn big" id="btnDownload"><img src="../../data/res/svg/download_24.svg"><span class="lbl">下载</span></button>
        <button class="btn big" id="btnDelete"><img src="../../data/res/svg/delete_24.svg"><span class="lbl">删除</span></button>
        <button class="btn big" id="btnZip"><img src="../../data/res/svg/filelook_zip_16.svg"><span class="lbl">压缩</span></button>
        <button class="btn big" id="btnPaste"><img src="../../data/res/svg/paste_16.svg"><span class="lbl">粘贴</span></button>
        <button class="btn big" id="btnSelectAll"><img src="../../data/res/svg/box_select_16.svg"><span class="lbl">全选</span></button>
        <span class="msg" id="msg">就绪</span>
      </div>
      <div class="tools-right">
        <div class="hgroup">
          <button class="btn" id="viewList" title="列表视图"><img src="../../data/res/cil/cil-list.svg"></button>
          <button class="btn" id="viewIcon" title="图标视图"><img src="../../data/res/cil/cil-grid.svg"></button>
        </div>
        <button class="btn" id="btnSizeMinus" title="缩小">−</button>
        <button class="btn" id="btnSizePlus" title="放大">＋</button>
      </div>
    </div>

    <div class="list-header" id="listHeader">
      <div class="lh lh-name" data-f="name">名称</div>
      <div class="lh lh-type" data-f="ext">类型<span class="rh" data-col="type"></span></div>
      <div class="lh lh-size" data-f="size">大小<span class="rh" data-col="size"></span></div>
      <div class="lh lh-time" data-f="mtime">修改时间<span class="rh" data-col="time"></span></div>
    </div>

    <div class="bodymain" id="fileList"><div class="empty">加载中…</div></div>

    <div class="statusbar">
      <span id="stCount"></span>
      <span id="stInfo"></span>
      <span id="stPath"></span>
      <span id="stClip" style="display:none"></span>
      <span class="up-wrap"><span id="upBar"></span></span>
    </div>
  </div>
</div>
<div class="context-menu-list" id="ctxMenu"></div>
<div class="modal-backdrop" id="modalBackdrop"><div class="modal" id="modalBox"></div></div>
<input type="file" id="uploadInput" multiple style="display:none">
<script>
(function () {
    var API = 'api.php';
    var state = {
        path: '/',
        items: [],
        sel: new Set(),
        view: 'list',
        sortKey: 'name',
        sortDir: 1,
        size: 1,       // 图标大小倍数
        history: [],
        hIdx: -1,
        search: '',
        clip: null    // {type:'copy'|'cut', paths:[绝对路径...]}
    };
    var $ = function (id) { return document.getElementById(id); };
    var msg = function (t) { $('msg').textContent = t || '就绪'; };

    /* ---------- 工具 ---------- */
    function enc(p) { return encodeURIComponent(p); }
    function apiUrl(a, p) { return API + '?action=' + a + (p !== undefined ? '&path=' + enc(p) : ''); }
    function apiGet(a, p) {
        return fetch(apiUrl(a, p)).then(function (r) { return r.json(); });
    }
    function apiCall(action, params) {
        var parts = ['action=' + encodeURIComponent(action)];
        for (var k in params) {
            var v = params[k];
            if (Array.isArray(v)) { v.forEach(function (x) { parts.push(encodeURIComponent(k) + '[]=' + encodeURIComponent(x)); }); }
            else parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
        }
        return fetch(API + '?' + parts.join('&')).then(function (r) { return r.json(); });
    }
    function apiPostForm(url, data) {
        return fetch(url, { method: 'POST', body: data }).then(function (r) { return r.json(); });
    }
    function fmtSize(n) {
        if (n == null) return '';
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
        return (n / 1073741824).toFixed(1) + ' GB';
    }
    function fmtTime(ts) {
        var d = new Date(ts * 1000);
        var p = function (n) { return n < 10 ? '0' + n : '' + n; };
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }
    function extIcon(ext) {
        var m = {
            doc:'filelook_doc_16', docx:'filelook_doc_16', txt:'filelook_txt_16', md:'filelook_txt_16',
            pdf:'filelook_pdf_16', ppt:'filelook_ppt_16', pptx:'filelook_ppt_16', xls:'filelook_xls_16', xlsx:'filelook_xls_16',
            png:'filelook_image_16', jpg:'filelook_image_16', jpeg:'filelook_image_16', gif:'filelook_image_16', webp:'filelook_image_16', svg:'filelook_image_16',
            zip:'filelook_zip_16', rar:'filelook_zip_16', '7z':'filelook_zip_16', gz:'filelook_zip_16', tar:'filelook_zip_16',
            mp3:'filelook_audio_16', wav:'filelook_audio_16', m4a:'filelook_audio_16', ogg:'filelook_audio_16',
            mp4:'filelook_video_16', webm:'filelook_video_16', mov:'filelook_video_16',
            html:'filelook_html_16', htm:'filelook_html_16', js:'filelook_unknown_16', py:'filelook_unknown_16', php:'filelook_unknown_16',
            exe:'filelook_exe_16', apk:'filelook_apk_16', ipa:'filelook_ipa_16', link:'filelook_link_16'
        };
        return (m[(ext || '').toLowerCase()] || 'filelook_unknown_16') + '.svg';
    }
    function joinPath(base, name) {
        return base === '/' ? name : base.replace(/\/+$/, '') + '/' + name;
    }
    function parentPath(p) {
        var t = p.replace(/\/+$/, '');
        if (!t) return '/';
        var i = t.lastIndexOf('/');
        return i <= 0 ? '/' : t.slice(0, i);
    }

    /* ---------- 导航 ---------- */
    function navigate(path, pushHist) {
        if (pushHist !== false) {
            // 截断前进栈
            state.history = state.history.slice(0, state.hIdx + 1);
            state.history.push(path);
            state.hIdx = state.history.length - 1;
        }
        state.path = path;
        state.sel = new Set();
        state.search = '';
        state.searchResults = null;
        $('searchBox').value = '';
        renderBreadcrumb();
        loadList();
        // 树：自动展开到当前路径（不整树重建，保持展开状态）
        expandTreeTo(state.path);
    }
    function up() { navigate(parentPath(state.path)); }
    function home() { navigate('/'); }

    /* ---------- 列表 ---------- */
    function loadList() {
        apiGet('list', state.path).then(function (d) {
            if (!d.success) { $('fileList').innerHTML = '<div class="empty">' + (d.error || '加载失败') + '</div>'; msg(d.error); return; }
            state.items = d.items;
            applyFilterAndRender();
            $('stPath').textContent = '路径: ' + state.path;
            var fc = 0, ff = 0;
            d.items.forEach(function (it) { if (it.dir) fc++; else ff++; });
            $('stInfo').textContent = fc + ' 文件夹 / ' + ff + ' 文件';
            $('stCount').textContent = d.items.length + ' 项';
            msg('已加载');
        }).catch(function () { $('fileList').innerHTML = '<div class="empty">加载失败</div>'; });
    }
    function applyFilterAndRender() {
        var kw = state.search.toLowerCase();
        var items = state.items;
        if (kw) items = items.filter(function (it) { return it.name.toLowerCase().indexOf(kw) >= 0; });
        // 排序
        var k = state.sortKey, dir = state.sortDir;
        items = items.slice().sort(function (a, b) {
            if (a.dir !== b.dir) return a.dir ? -1 : 1;
            var va = a[k], vb = b[k];
            if (k === 'size') { va = va || 0; vb = vb || 0; return (va - vb) * dir; }
            if (k === 'mtime') { va = va || 0; vb = vb || 0; return (va - vb) * dir; }
            va = String(va || '').toLowerCase(); vb = String(vb || '').toLowerCase();
            return va < vb ? -dir : va > vb ? dir : 0;
        });
        state.order = items.map(function (i) { return i.name; });
        render(items);
    }
    function render(items) {
        var el = $('fileList');
        if (state.view === 'icon') {
            el.classList.add('icon-view');
            var h = '';
            items.forEach(function (it) {
                var ico = it.dir ? 'folder_24.svg' : extIcon(it.ext);
                h += '<div class="icon-item' + (state.sel.has(it.name) ? ' sel' : '') + '" data-name="' + esc(it.name) + '" data-dir="' + it.dir + '">'
                   + '<span class="ck"><i></i></span>'
                   + '<div class="ico"><img src="../../data/res/svg/' + ico + '" style="width:' + (40 * state.size) + 'px;height:' + (40 * state.size) + 'px"></div>'
                   + '<div class="nm">' + esc(it.name) + '</div></div>';
            });
            if (!items.length) h = '<div class="empty">（空目录）</div>';
            el.innerHTML = h;
        } else {
            el.classList.remove('icon-view');
            var h2 = '';
            if (state.path !== '/') {
                h2 += '<div class="row" data-name=".." data-dir="true" title="返回上一级"><span class="ck"></span><div class="ico"><img src="../../data/res/svg/folder_24.svg"></div><div class="nm">..</div><div class="ty">文件夹</div><div class="sz"></div><div class="tm"></div><div class="ops"></div></div>';
            }
            h2 += '<div class="row" data-name="." data-dir="true" title="当前目录"><span class="ck"></span><div class="ico"><img src="../../data/res/svg/folder_24.svg"></div><div class="nm">.</div><div class="ty">文件夹</div><div class="sz"></div><div class="tm"></div><div class="ops"></div></div>';
            items.forEach(function (it) {
                var ico = it.dir ? 'folder_24.svg' : extIcon(it.ext);
                var type = it.dir ? '文件夹' : (it.ext ? it.ext.toUpperCase() + ' 文件' : '文件');
                var openIco = it.dir ? 'cil-folder-open.svg' : 'cil-file.svg';
                h2 += '<div class="row' + (state.sel.has(it.name) ? ' sel' : '') + '" data-name="' + esc(it.name) + '" data-dir="' + it.dir + '">'
                   + '<span class="ck"><i></i></span>'
                   + '<div class="ico"><img src="../../data/res/svg/' + ico + '"></div>'
                   + '<div class="nm" title="双击打开">' + esc(it.name) + '</div>'
                   + '<div class="ty">' + esc(type) + '</div>'
                   + '<div class="sz">' + (it.dir ? '' : fmtSize(it.size)) + '</div>'
                   + '<div class="tm">' + fmtTime(it.mtime) + '</div>'
                   + '<div class="ops">'
                   + (it.dir ? '<button class="op" data-op="enter" title="打开"><img src="../../data/res/cil/' + openIco + '"></button>' : '<button class="op" data-op="open" title="打开"><img src="../../data/res/cil/' + openIco + '"></button>')
                   + '<button class="op" data-op="rn" title="重命名"><img src="../../data/res/cil/cil-pen.svg"></button>'
                   + (pathExt(it.name) === 'zip' ? '<button class="op" data-op="unzip" title="解压"><img src="../../data/res/svg/filelook_zip_16.svg"></button>' : '')
                   + '<button class="op danger" data-op="del" title="删除"><img src="../../data/res/cil/cil-trash.svg"></button>'
                   + '<button class="more-btn" data-op="more" title="更多操作"><img src="../../data/res/svg/more_upright_new_16.svg"></button>'
                   + '</div></div>';
            });
            if (!items.length) h2 = '<div class="empty">（空目录）</div>';
            el.innerHTML = h2;
        }
        bindRows();
    }
    /* ---------- 内部拖拽 ---------- */
    var _drag = null;
    var _dropEl = null;
    function startDragGhost(d) {
        var g = document.createElement('div');
        g.id = 'dragGhost';
        g.innerHTML = '<img src="../../data/res/cil/cil-folder-open.svg" alt=""> ' + (d.names.length > 1 ? d.names.length + ' 项' : esc(d.names[0])) + (d.copy ? '（复制）' : '');
        document.body.appendChild(g);
    }
    function clearDropHighlight() {
        if (_dropEl) { _dropEl.classList.remove('drop-target'); _dropEl = null; }
        document.querySelectorAll('.tree-node.drop-target').forEach(function (n) { n.classList.remove('drop-target'); });
        document.querySelectorAll('.bc-seg.drop-target').forEach(function (n) { n.classList.remove('drop-target'); });
    }
    function removeDragGhost() {
        var g = document.getElementById('dragGhost'); if (g) g.remove();
        clearDropHighlight();
    }
    function moveDragGhost(e) {
        var g = document.getElementById('dragGhost');
        if (g) { g.style.left = (e.clientX + 12) + 'px'; g.style.top = (e.clientY + 12) + 'px'; }
        _drag.copy = !!(e.ctrlKey || e.metaKey); // 拖动中按住 Ctrl = 复制
        if (g) g.innerHTML = '<img src="../../data/res/cil/cil-folder-open.svg" alt=""> ' + (_drag.names.length > 1 ? _drag.names.length + ' 项' : esc(_drag.names[0])) + (_drag.copy ? '（复制）' : '');
        // 找 drop 目标（文件夹行 / 树节点 / 面包屑）
        var el = document.elementFromPoint(e.clientX, e.clientY);
        var target = null;
        while (el && el !== document.body) {
            if (el.classList && el.classList.contains('row') && el.getAttribute('data-dir') === 'true') {
                var dn = el.getAttribute('data-name');
                if (dn === '.') { target = null; break; }
                target = { el: el, path: dn === '..' ? parentPath(state.path) : absPath(dn) };
                break;
            }
            if (el.classList && el.classList.contains('tree-node')) { target = { el: el, path: el.getAttribute('data-path') }; break; }
            if (el.classList && el.classList.contains('bc-seg') && !el.classList.contains('cur')) { target = { el: el, path: el.getAttribute('data-p') }; break; }
            el = el.parentNode;
        }
        clearDropHighlight();
        if (target && target.path && target.path !== state.path) { target.el.classList.add('drop-target'); _dropEl = target.el; _drag._target = target.path; }
        else _drag._target = null;
    }
    function endDragDrop() {
        var dest = _drag._target;
        if (!dest) return;
        var names = _drag.names.filter(function (n) { return n !== '.' && n !== '..'; });
        if (!names.length) return;
        var act = _drag.copy ? 'copy' : 'move';
        apiCall(act, { path: state.path, names: names, dest: dest }).then(function (d) {
            if (!d.success) { errModal(d.error || (act === 'copy' ? '复制失败' : '移动失败')); return; }
            msg('已' + (act === 'copy' ? '复制' : '移动') + ' ' + d.count + ' 项');
            loadList(); loadTree(false);
        });
    }

    function bindRows() {
        var el = $('fileList');

        function opClick(e, name) {
            if (!e.target.closest('.op') && !e.target.closest('.more-btn')) return false;
            var op = (e.target.closest('.op') || e.target.closest('.more-btn')).getAttribute('data-op');
            if (op === 'enter') navigate(absPath(name));
            else if (op === 'open') openRow(name);
            else if (op === 'dl') download(absPath(name));
            else if (op === 'rn') renameItem(name);
            else if (op === 'del') delItem(name);
            else if (op === 'unzip') unzipItem(name);
            else if (op === 'more') { var s = state.sel.size > 1 && state.sel.has(name) ? selNames() : [name]; ctxShow(s.length > 1 ? menuForMulti(s) : menuForSingle(s[0]), e.clientX, e.clientY); }
            e.stopPropagation();
            return true;
        }
        function rowMousedown(e, name) {
            // RojExplorer：mousedown 即选中（Ctrl/Meta/Shift 加选）
            if (e.shiftKey) toggleSel(name);
            else if (e.ctrlKey || e.metaKey) toggleSel(name);
            else if (!state.sel.has(name)) { state.sel = new Set([name]); applyFilterAndRender(); }
            // 记录拖拽起点
            _drag = { startX: e.clientX, startY: e.clientY, active: false, names: Array.from(state.sel), copy: false, _target: null };
        }

        el.querySelectorAll('.row').forEach(function (row) {
            var name = row.getAttribute('data-name'), dir = row.getAttribute('data-dir') === 'true';
            if (name === '.' || name === '..') {
                row.addEventListener('click', function () { if (name === '..') up(); else loadList(); });
                row.addEventListener('dblclick', function () { if (name === '..') up(); else loadList(); });
                return;
            }
            row.addEventListener('mousedown', function (e) {
                if (e.button !== 0) return;
                if (e.target.closest('.op') || e.target.closest('.more-btn') || e.target.closest('.ck')) return;
                rowMousedown(e, name);
            });
            row.addEventListener('click', function (e) {
                if (opClick(e, name)) return;
                if (e.target.closest('.ck')) toggleSel(name);
            });
            row.addEventListener('dblclick', function (e) {
                if (opClick(e, name)) return;
                if (e.altKey) { showInfo(name); return; }   // Alt+双击 = 属性（RojExplorer）
                if (dir) navigate(absPath(name));           // 双击 = 打开（文件夹/文件）
                else openRow(name);
            });
            row.addEventListener('contextmenu', function (e) {
                e.preventDefault(); e.stopPropagation();
                if (!state.sel.has(name)) state.sel = new Set([name]);
                applyFilterAndRender();
                ctxShow(state.sel.size > 1 ? menuForMulti(selNames()) : menuForSingle(name), e.clientX, e.clientY);
            });
        });
        el.querySelectorAll('.icon-item').forEach(function (it) {
            var name = it.getAttribute('data-name'), dir = it.getAttribute('data-dir') === 'true';
            it.addEventListener('mousedown', function (e) {
                if (e.button !== 0) return;
                if (e.target.closest('.ck')) return;
                rowMousedown(e, name);
            });
            it.addEventListener('click', function (e) { if (e.target.closest('.ck')) toggleSel(name); });
            it.addEventListener('dblclick', function (e) {
                if (e.altKey) { showInfo(name); return; }
                if (dir) navigate(absPath(name));
                else openRow(name);
            });
            it.addEventListener('contextmenu', function (e) {
                e.preventDefault(); e.stopPropagation();
                if (!state.sel.has(name)) state.sel = new Set([name]);
                applyFilterAndRender();
                ctxShow(state.sel.size > 1 ? menuForMulti(selNames()) : menuForSingle(name), e.clientX, e.clientY);
            });
        });
    }
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    /* ---------- 自定义模态框 ---------- */
    function showModal(html) {
        $('modalBox').innerHTML = html;
        $('modalBackdrop').classList.add('show');
        var inp = $('modalBox').querySelector('input');
        if (inp) setTimeout(function () { inp.focus(); inp.select(); }, 30);
    }
    function hideModal() { $('modalBackdrop').classList.remove('show', 'wide'); }
    function customPrompt(title, def) {
        return new Promise(function (resolve) {
            showModal('<h3>' + esc(title) + '</h3><input type="text" id="mInput" value="' + esc(def || '') + '"><div class="mbtns"><button class="btn" id="mCancel">取消</button><button class="btn primary" id="mOk">确定</button></div>');
            var ok = function () { var v = $('mInput').value; hideModal(); resolve(v); };
            $('mOk').onclick = ok;
            $('mCancel').onclick = function () { hideModal(); resolve(null); };
            $('mInput').addEventListener('keydown', function (e) {
                if (e.key === 'Enter') ok();
                if (e.key === 'Escape') { hideModal(); resolve(null); }
            });
            $('modalBackdrop').onclick = function (e) { if (e.target === $('modalBackdrop')) { hideModal(); resolve(null); } };
        });
    }
    function customConfirm(msg, okLabel) {
        return new Promise(function (resolve) {
            showModal('<h3>确认操作</h3><p>' + esc(msg) + '</p><div class="mbtns"><button class="btn" id="mCancel">取消</button><button class="btn danger" id="mOk">' + esc(okLabel || '确定') + '</button></div>');
            $('mOk').onclick = function () { hideModal(); resolve(true); };
            $('mCancel').onclick = function () { hideModal(); resolve(false); };
            $('modalBackdrop').onclick = function (e) { if (e.target === $('modalBackdrop')) { hideModal(); resolve(false); } };
        });
    }
    function errModal(msg) {
        return new Promise(function (resolve) {
            showModal('<h3>错误</h3><p style="color:#e08080">' + esc(msg) + '</p><div class="mbtns"><button class="btn primary" id="mOk">确定</button></div>');
            $('mOk').onclick = function () { hideModal(); resolve(); };
            $('modalBackdrop').onclick = function (e) { if (e.target === $('modalBackdrop')) { hideModal(); resolve(); } };
        });
    }
    function toggleSel(name) {
        if (state.sel.has(name)) state.sel.delete(name); else state.sel.add(name);
        applyFilterAndRender();
    }

    /* ---------- 操作 ---------- */
    function download(path) { window.open(API + '?action=download&path=' + enc(path), '_blank'); }
    function newDir() {
        customPrompt('新建文件夹名称').then(function (v) {
            if (!v || !v.trim()) return;
            apiCall('mkdir', { path: state.path, name: v.trim() }).then(function (d) {
                if (!d.success) return errModal(d.error || '创建失败');
                msg('已创建'); loadList(); loadTree(false);
            });
        });
    }
    function newFile() {
        customPrompt('新建文件名').then(function (v) {
            if (!v || !v.trim()) return;
            apiCall('mkfile', { path: state.path, name: v.trim() }).then(function (d) {
                if (!d.success) return errModal(d.error || '创建失败');
                msg('已创建'); loadList();
            });
        });
    }
    function renameItem(name) {
        customPrompt('重命名「' + name + '」为', name).then(function (v) {
            if (!v || !v.trim() || v.trim() === name) return;
            apiCall('rename', { path: state.path, old: name, new: v.trim() }).then(function (d) {
                if (!d.success) return errModal(d.error || '重命名失败');
                msg('已重命名'); loadList(); loadTree(false);
            });
        });
    }
    function delItem(name) {
        var item = null;
        for (var i = 0; i < state.items.length; i++) if (state.items[i].name === name) { item = state.items[i]; break; }
        customConfirm('确认删除「' + name + '」' + (item && item.dir ? ' 及其全部内容？' : '？'), '删除').then(function (ok) {
            if (!ok) return;
            apiCall('delete', { path: state.path, name: name }).then(function (d) {
                if (!d.success) return errModal(d.error || '删除失败');
                state.sel.delete(name); msg('已删除'); loadList(); loadTree(false);
            });
        });
    }

    /* ---------- 工具（选中项/路径） ---------- */
    function absPath(name) { return joinPath(state.path, name); }
    function pathExt(n) { var i = n.lastIndexOf('.'); return i > 0 ? n.slice(i + 1).toLowerCase() : ''; }
    function findItem(name) {
        for (var i = 0; i < state.items.length; i++) if (state.items[i].name === name) return state.items[i];
        return null;
    }
    function selNames() { return Array.from(state.sel); }

    /* ---------- 剪贴板（复制/剪切/粘贴） ---------- */
    function clipSet(type, paths) {
        state.clip = { type: type, paths: paths.slice() };
        clipUpdateUI();
        msg((type === 'copy' ? '已复制 ' : '已剪切 ') + paths.length + ' 项到剪贴板');
    }
    function clipClear() { state.clip = null; clipUpdateUI(); }
    function clipUpdateUI() {
        var el = $('stClip');
        if (state.clip) {
            var srcs = {};
            state.clip.paths.forEach(function (p) { srcs[parentPath(p)] = 1; });
            el.textContent = '剪贴板: ' + state.clip.paths.length + ' 项(' + (state.clip.type === 'copy' ? '复制' : '剪切') + ') · 来源: ' + Object.keys(srcs).join(', ');
            el.style.display = '';
        } else { el.style.display = 'none'; }
        if ($('btnPaste')) $('btnPaste').disabled = !state.clip;
    }
    function clipPaste() {
        if (!state.clip) return;
        var clip = state.clip;
        var groups = {};
        clip.paths.forEach(function (p) {
            var pd = parentPath(p);
            (groups[pd] = groups[pd] || []).push(p.split('/').pop());
        });
        if (clip.type === 'cut' && groups[state.path]) return errModal('不能剪切回原目录');
        var proms = [];
        Object.keys(groups).forEach(function (srcDir) {
            if (clip.type === 'cut' && srcDir === state.path) return;
            proms.push(apiCall(clip.type === 'copy' ? 'copy' : 'move', { path: srcDir, names: groups[srcDir], dest: state.path }).then(function (d) {
                if (!d.success) throw new Error(d.error || '操作失败');
            }));
        });
        Promise.all(proms).then(function () {
            msg('已' + (clip.type === 'copy' ? '复制' : '移动') + '到当前目录');
            if (clip.type === 'cut') clipClear();
            loadList(); loadTree(false);
        }).catch(function (e) { errModal(e.message); loadList(); });
    }

    /* ---------- 压缩 / 解压 ---------- */
    function zipSelected(names) {
        customPrompt('压缩为（.zip）', 'archive.zip').then(function (z) {
            if (!z || !z.trim()) return;
            var zn = z.trim();
            if (!/\.zip$/i.test(zn)) zn += '.zip';
            apiCall('zip', { path: state.path, names: names, zname: zn }).then(function (d) {
                if (!d.success) return errModal(d.error || '压缩失败');
                msg('已压缩为 ' + d.name); loadList();
            });
        });
    }
    function unzipItem(name) {
        apiCall('unzip', { path: state.path, name: name }).then(function (d) {
            if (!d.success) return errModal(d.error || '解压失败');
            msg('已解压 ' + name); loadList();
        });
    }

    /* ---------- 属性 ---------- */
    function showInfo(name) {
        var p = name === null ? state.path : absPath(name);
        apiGet('info', p).then(function (d) {
            if (!d.success) return errModal(d.error || '获取信息失败');
            var it = d.info, rows = '';
            function row(k, v) { rows += '<p><b>' + esc(k) + ':</b> ' + esc(String(v == null ? '' : v)) + '</p>'; }
            row('名称', it.name);
            row('类型', it.dir ? '文件夹' : (it.link ? '链接' : (pathExt(it.name) || '文件')));
            if (it.dir) row('包含', it.items + ' 项');
            else row('大小', fmtSize(it.size) + '（' + it.size + ' 字节）');
            row('路径', it.path === '/' ? '/' : it.path);
            row('修改时间', fmtTime(it.mtime));
            row('创建时间', fmtTime(it.ctime));
            row('权限', (it.readable ? '可读' : '不可读') + ' / ' + (it.writable ? '可写' : '只读'));
            showModal('<h3>属性' + (it.dir ? '（文件夹）' : '') + '</h3>' + rows + '<div class="mbtns"><button class="btn primary" id="mOk">确定</button></div>');
            $('mOk').onclick = hideModal;
        });
    }

    /* ---------- 新建（带目标目录 + 模板） ---------- */
    function newDirAt(path) {
        customPrompt('新建文件夹名称').then(function (v) {
            if (!v || !v.trim()) return;
            apiCall('mkdir', { path: path, name: v.trim() }).then(function (d) {
                if (!d.success) return errModal(d.error || '创建失败');
                msg('已创建'); if (path === state.path) loadList(); loadTree(false);
            });
        });
    }
    function newFileAt(path) {
        customPrompt('新建文件名').then(function (v) {
            if (!v || !v.trim()) return;
            apiCall('mkfile', { path: path, name: v.trim() }).then(function (d) {
                if (!d.success) return errModal(d.error || '创建失败');
                msg('已创建'); if (path === state.path) loadList(); loadTree(false);
            });
        });
    }
    function newFileTpl(ext) {
        customPrompt('新建 .' + ext + ' 文件', '新建文件.' + ext).then(function (v) {
            if (!v || !v.trim()) return;
            var nm = v.trim();
            if (nm.indexOf('.') === -1) nm += '.' + ext;
            apiCall('mkfile', { path: state.path, name: nm }).then(function (d) {
                if (!d.success) return errModal(d.error || '创建失败');
                msg('已创建'); loadList();
            });
        });
    }
    function selectAll() {
        if (state.searchResults) return; // 搜索模式下行是跨目录结果，不能全选
        var kw = state.search.toLowerCase();
        state.sel = new Set();
        state.items.forEach(function (it) { if (!kw || it.name.toLowerCase().indexOf(kw) >= 0) state.sel.add(it.name); });
        applyFilterAndRender();
    }

    /* ---------- 上传（共享 input 与拖拽） ---------- */
    function uploadFiles(files) {
        if (!files || !files.length) return;
        var total = files.length;
        msg('上传中 0/' + total + '…');
        var fd = new FormData();
        for (var i = 0; i < files.length; i++) fd.append('file', files[i]);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', API + '?action=upload&path=' + enc(state.path));
        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) $('upBar').style.width = Math.round(e.loaded / e.total * 100) + '%';
        };
        xhr.onload = function () {
            $('upBar').style.width = '0%';
            var d = null;
            try { d = JSON.parse(xhr.responseText); } catch (err) { d = { success: false, error: '服务器响应异常' }; }
            if (!d.success) { errModal(d.error || '上传失败'); msg(d.error); return; }
            msg('已上传 ' + d.name + (total > 1 ? '（共 ' + total + ' 个）' : ''));
            loadList();
        };
        xhr.onerror = function () { $('upBar').style.width = '0%'; msg('上传失败'); };
        xhr.send(fd);
    }

    /* ---------- 右键菜单（RojExplorer 风格） ---------- */
    var _ctxOpen = false;
    function ctxItem(label, icon, act, sub, disabled) { return { label: label, icon: icon, act: act, sub: sub, disabled: !!disabled }; }
    function ctxRender(container, menu) {
        container.innerHTML = '';
        menu.forEach(function (it) {
            if (it.sep) { var s = document.createElement('div'); s.className = 'context-menu-sep'; container.appendChild(s); return; }
            var el = document.createElement('div');
            el.className = 'context-menu-item' + (it.disabled ? ' disabled' : '');
            el.innerHTML = (it.icon ? '<span class="ctx-ico"><img src="../../data/res/svg/' + it.icon + '"></span>' : '<span class="ctx-ico"></span>')
                + '<span class="ctx-label">' + esc(it.label) + '</span>'
                + (it.sub && it.sub.length ? '<span class="ctx-arrow">›</span>' : '');
            if (it.sub && it.sub.length) {
                var sub = document.createElement('div');
                sub.className = 'context-menu-sub';
                ctxRender(sub, it.sub);
                el.appendChild(sub);
                el.addEventListener('mouseenter', function () {
                    var s = el.querySelector('.context-menu-sub');
                    if (!s) return;
                    s.style.right = ''; s.style.left = '100%';
                    var r = s.getBoundingClientRect();
                    if (r.right > window.innerWidth - 4) { s.style.left = 'auto'; s.style.right = '100%'; }
                    var rr = s.getBoundingClientRect();
                    if (rr.bottom > window.innerHeight - 4) s.style.top = (window.innerHeight - 4 - rr.bottom) + 'px';
                });
            }
            if (!it.disabled) {
                el.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (it.act) it.act();
                    ctxClose();
                });
            }
            container.appendChild(el);
        });
    }
    function ctxShow(menu, x, y) {
        ctxClose();
        var m = $('ctxMenu');
        ctxRender(m, menu);
        m.classList.add('open');
        m.style.left = '0px'; m.style.top = '0px';
        var r = m.getBoundingClientRect();
        m.style.left = Math.max(4, Math.min(x, window.innerWidth - r.width - 4)) + 'px';
        m.style.top = Math.max(4, Math.min(y, window.innerHeight - r.height - 4)) + 'px';
        _ctxOpen = true;
    }
    function ctxClose() {
        var m = $('ctxMenu');
        m.classList.remove('open');
        m.innerHTML = '';
        _ctxOpen = false;
    }

    /* 菜单内容 */
    function menuForSingle(name) {
        var it = findItem(name);
        var isDir = it && it.dir;
        var isZip = !isDir && pathExt(name) === 'zip';
        var m = [];
        if (isDir) {
            m.push(ctxItem('打开', 'open_file_16.svg', function () { navigate(absPath(name)); }));
            m.push(ctxItem('在新标签页打开', 'channel_arrow_right_16.svg', function () { openNewTab(absPath(name)); }));
        } else {
            m.push(ctxItem('打开', 'open_file_16.svg', function () { openRow(name); }));
            m.push(ctxItem('下载', 'download_24.svg', function () { download(absPath(name)); }));
        }
        if (isZip) m.push(ctxItem('解压', 'filelook_zip_16.svg', function () { unzipItem(name); }));
        m.push({ sep: true });
        m.push(ctxItem('重命名', 'edit_16.svg', function () { renameItem(name); }));
        m.push(ctxItem('复制', 'copy_16.svg', function () { clipSet('copy', [absPath(name)]); }));
        m.push(ctxItem('剪切', 'transmission_file_16.svg', function () { clipSet('cut', [absPath(name)]); }));
        m.push(ctxItem('压缩', 'filelook_zip_16.svg', function () { zipSelected([name]); }));
        m.push(ctxItem('删除', 'delete_24.svg', function () { delItem(name); }));
        m.push({ sep: true });
        m.push(ctxItem('属性', 'info_circle_16.svg', function () { showInfo(name); }));
        return m;
    }
    function menuForMulti(names) {
        return [
            ctxItem('下载', 'download_24.svg', function () { names.forEach(function (n) { download(absPath(n)); }); }),
            { sep: true },
            ctxItem('复制', 'copy_16.svg', function () { clipSet('copy', names.map(absPath)); }),
            ctxItem('剪切', 'transmission_file_16.svg', function () { clipSet('cut', names.map(absPath)); }),
            ctxItem('压缩', 'filelook_zip_16.svg', function () { zipSelected(names); }),
            ctxItem('删除', 'delete_24.svg', function () { names.forEach(delItem); })
        ];
    }
    function newFileTemplates() {
        var tpls = [['txt', '文本文件'], ['md', 'Markdown'], ['html', 'HTML'], ['php', 'PHP'], ['js', 'JavaScript'], ['json', 'JSON']];
        var arr = tpls.map(function (t) {
            return ctxItem(t[1] + ' (' + t[0] + ')', (t[0] === 'txt' || t[0] === 'md' ? 'filelook_txt_16' : 'filelook_unknown_16') + '.svg', function () { newFileTpl(t[0]); });
        });
        arr.push({ sep: true });
        arr.push(ctxItem('无后缀文件', 'filelook_unknown_16.svg', function () { newFileAt(state.path); }));
        return arr;
    }
    function menuForBg() {
        var m = [
            ctxItem('新建文件夹', 'folder_24.svg', function () { newDirAt(state.path); }),
            ctxItem('新建文件', 'document_to_computer_24.svg', null, newFileTemplates()),
            { sep: true },
            ctxItem('上传文件', 'upload_24.svg', startUpload),
            { sep: true }
        ];
        if (state.clip) m.push(ctxItem('粘贴', 'paste_16.svg', clipPaste));
        m.push(ctxItem('刷新', 'refresh_16.svg', function () { loadList(); loadTree(false); }));
        m.push(ctxItem('全选', 'box_select_16.svg', selectAll));
        m.push({ sep: true });
        m.push(ctxItem('目录属性', 'info_circle_16.svg', function () { showInfo(null); }));
        return m;
    }
    function menuForTree(path) {
        return [
            ctxItem('打开', 'open_file_16.svg', function () { navigate(path); }),
            ctxItem('刷新', 'refresh_16.svg', function () { loadTree(false); if (path === state.path) loadList(); }),
            { sep: true },
            ctxItem('在此新建文件夹', 'folder_24.svg', function () { newDirAt(path); }),
            ctxItem('在此新建文件', 'document_to_computer_24.svg', function () { newFileAt(path); })
        ];
    }

    /* ---------- 文件预览（RojExplorer 查看器风格） ---------- */
    var PREVIEW_TEXT = ['txt','md','php','phtml','js','mjs','cjs','ts','jsx','tsx','py','java','c','h','cpp','hpp','cs','go','rb','rs','sh','bash','zsh','json','xml','html','htm','css','scss','less','yml','yaml','ini','conf','cfg','log','sql','pve','pvm','pvs','csv','env','gitignore','htaccess','vue','svelte'];
    var PREVIEW_IMG = ['png','jpg','jpeg','gif','webp','bmp','svg','ico','avif'];
    var PREVIEW_AUDIO = ['mp3','wav','m4a','ogg','aac','flac','opus'];
    var PREVIEW_VIDEO = ['mp4','webm','mov','mkv','ogv','m4v'];

    function makeModalWide(on) { $('modalBackdrop').classList.toggle('wide', !!on); }
    function viewUrl(path) { return API + '?action=view&path=' + enc(path); }
    function dlUrl(path) { return API + '?action=download&path=' + enc(path); }

    function openItem(it) {
        if (!it) return;
        var ext = (it.ext || pathExt(it.name)).toLowerCase();
        if (it.dir) return navigate(it.path);
        if (PREVIEW_IMG.indexOf(ext) >= 0) return previewImage(it.path, it.name);
        if (PREVIEW_AUDIO.indexOf(ext) >= 0) return previewMedia(it.path, it.name, 'audio');
        if (PREVIEW_VIDEO.indexOf(ext) >= 0) return previewMedia(it.path, it.name, 'video');
        if (PREVIEW_TEXT.indexOf(ext) >= 0 && (it.size == null || it.size <= 1048576)) return previewText(it.path, it.name);
        download(it.path);
    }
    function openRow(name) {
        var it = findItem(name);
        if (!it) return;
        openItem({ name: it.name, path: absPath(name), dir: it.dir, size: it.size, ext: it.ext });
    }
    function openFull(path) {
        var it = null;
        for (var i = 0; i < (state.searchResults || []).length; i++) if (state.searchResults[i].path === path) { it = state.searchResults[i]; break; }
        openItem(it || { name: path.split('/').pop(), path: path, dir: false, size: null, ext: pathExt(path) });
    }
    function previewImage(path, name) {
        showModal('<h3>' + esc(name) + '</h3><div style="text-align:center;margin-top:6px"><img src="' + viewUrl(path) + '" style="max-width:80vw;max-height:58vh;border-radius:4px" onerror="this.style.display=\'none\';document.getElementById(\'imgErr\').style.display=\'block\'"><p id="imgErr" style="display:none;color:#e08080;margin-top:10px">图片无法预览</p></div><div class="mbtns"><a class="btn" href="' + dlUrl(path) + '" target="_blank">下载原图</a><button class="btn primary" id="mOk">关闭</button></div>');
        makeModalWide(true);
        $('mOk').onclick = hideModal;
    }
    function previewMedia(path, name, kind) {
        var url = viewUrl(path);
        var tag = kind === 'audio'
            ? '<audio controls autoplay style="width:100%;margin-top:6px" src="' + url + '"></audio>'
            : '<video controls autoplay style="max-width:82vw;max-height:58vh;margin-top:6px;border-radius:4px" src="' + url + '"></video>';
        showModal('<h3>' + esc(name) + '</h3>' + tag + '<div class="mbtns"><a class="btn" href="' + dlUrl(path) + '" target="_blank">下载</a><button class="btn primary" id="mOk">关闭</button></div>');
        makeModalWide(true);
        $('mOk').onclick = hideModal;
    }
    function previewText(path, name) {
        showModal('<h3>' + esc(name) + '</h3><div class="txt-preview" id="txtPrev">加载中…</div><div class="mbtns"><a class="btn" href="' + dlUrl(path) + '" target="_blank">下载</a><button class="btn primary" id="mOk">关闭</button></div>');
        makeModalWide(true);
        $('mOk').onclick = hideModal;
        fetch(viewUrl(path)).then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
            .then(function (t) { $('txtPrev').textContent = t.length > 200000 ? t.slice(0, 200000) + '\n\n…（内容过长，已截断显示）' : t; })
            .catch(function () { $('txtPrev').textContent = '（无法读取，可能是二进制文件）'; });
    }
    function openNewTab(path) { window.open(location.pathname + '#' + encodeURIComponent(path), '_blank'); }

    /* ---------- 键盘导航 ---------- */
    function navMove(key) {
        var order = state.order || [];
        if (!order.length) return;
        var idx = -1;
        if (state.sel.size === 1) idx = order.indexOf(Array.from(state.sel)[0]);
        var n = order.length;
        if (key === 'ArrowDown') idx = idx < 0 ? 0 : Math.min(n - 1, idx + 1);
        else if (key === 'ArrowUp') idx = idx < 0 ? 0 : Math.max(0, idx - 1);
        else if (key === 'Home') idx = 0;
        else if (key === 'End') idx = n - 1;
        else if (key === 'PageDown') idx = Math.min(n - 1, idx + 12);
        else if (key === 'PageUp') idx = Math.max(0, idx - 12);
        if (idx < 0) return;
        var nm = order[idx];
        if (nm) { state.sel = new Set([nm]); applyFilterAndRender(); scrollToRow(nm); }
    }
    function scrollToRow(name) {
        var rows = document.querySelectorAll('#fileList .row');
        for (var i = 0; i < rows.length; i++) if (rows[i].getAttribute('data-name') === name) { rows[i].scrollIntoView({ block: 'nearest' }); return; }
    }

    /* ---------- 设置持久化 ---------- */
    var SETTINGS_KEY = 'fm_settings_v1';
    function applyCols(cols) {
        state.cols = { type: cols.type || 90, size: cols.size || 90, time: cols.time || 150 };
        var d = document.documentElement.style;
        d.setProperty('--col-type', state.cols.type + 'px');
        d.setProperty('--col-size', state.cols.size + 'px');
        d.setProperty('--col-time', state.cols.time + 'px');
    }
    function saveSettings() {
        try {
            localStorage.setItem(SETTINGS_KEY, JSON.stringify({
                view: state.view, size: state.size,
                sortKey: state.sortKey, sortDir: state.sortDir,
                treeWidth: $('frameLeft').style.width || '',
                cols: state.cols || null
            }));
        } catch (e) {}
    }
    function loadSettings() {
        try {
            var s = JSON.parse(localStorage.getItem(SETTINGS_KEY) || 'null');
            if (!s) return;
            if (s.view === 'icon' || s.view === 'list') state.view = s.view;
            if (s.size) state.size = s.size;
            if (s.sortKey) { state.sortKey = s.sortKey; state.sortDir = s.sortDir || 1; }
            if (s.treeWidth) $('frameLeft').style.width = s.treeWidth;
            if (s.cols) applyCols(s.cols);
        } catch (e) {}
    }

    /* ---------- 递归搜索 ---------- */
    function onSearchInput() {
        var kw = $('searchBox').value.trim();
        if (state.searchRecursive) {
            state.search = '';
            state.searchResults = null;
            if (kw) {
                msg('搜索中…');
                apiCall('search', { path: state.path, q: kw }).then(function (d) {
                    if (!d.success) { msg(d.error); return; }
                    state.searchResults = d.items || [];
                    renderSearch(state.searchResults);
                    msg('找到 ' + state.searchResults.length + ' 项');
                });
            } else { render([]); msg('就绪'); }
        } else {
            state.search = kw; state.searchResults = null;
            applyFilterAndRender();
        }
    }
    function toggleSearchMode() {
        state.searchRecursive = !state.searchRecursive;
        $('btnSearchMode').classList.toggle('on', state.searchRecursive);
        $('btnSearchMode').textContent = state.searchRecursive ? '全局' : '目录';
        onSearchInput();
    }
    function renderSearch(items) {
        var el = $('fileList');
        el.classList.remove('icon-view');
        var h = '';
        items.forEach(function (it) {
            var ico = it.dir ? 'folder_24.svg' : extIcon(it.ext);
            h += '<div class="row' + (state.sel.has(it.path) ? ' sel' : '') + '" data-name="' + esc(it.name) + '" data-full="' + esc(it.path) + '" data-dir="' + it.dir + '">'
               + '<span class="ck"><i></i></span>'
               + '<div class="ico"><img src="../../data/res/svg/' + ico + '"></div>'
               + '<div class="nm">' + esc(it.name) + '</div>'
               + '<div class="ty" style="flex:1;color:#6a9fd8;font-size:11px">' + esc(parentPath(it.path)) + '</div>'
               + '<div class="sz">' + (it.dir ? '' : fmtSize(it.size)) + '</div>'
               + '<div class="tm">' + fmtTime(it.mtime) + '</div>'
               + '<div class="ops"><button class="op" data-op="open">打开</button><button class="op" data-op="locate">定位</button></div></div>';
        });
        if (!items.length) h = '<div class="empty">（无匹配结果）</div>';
        el.innerHTML = h;
        bindSearchRows();
    }
    function bindSearchRows() {
        var el = $('fileList');
        el.querySelectorAll('.row').forEach(function (row) {
            var full = row.getAttribute('data-full'), dir = row.getAttribute('data-dir') === 'true';
            row.addEventListener('click', function (e) {
                if (e.target.closest('.op')) {
                    var op = e.target.closest('.op').getAttribute('data-op');
                    if (op === 'open') { if (dir) navigate(full); else openFull(full); }
                    else if (op === 'locate') navigate(parentPath(full));
                    e.stopPropagation(); return;
                }
                state.sel = new Set([full]);
                applyFilterAndRender();
            });
            row.addEventListener('dblclick', function () { if (dir) navigate(full); else openFull(full); });
        });
    }

    /* ---------- 面包屑编辑 ---------- */
    function breadcrumbEdit(show) {
        var bc = $('breadcrumb'), pe = $('pathEdit');
        if (show) {
            pe.value = state.path;
            bc.classList.add('editing');
            pe.style.display = 'block';
            pe.focus(); pe.select();
        } else {
            bc.classList.remove('editing');
            pe.style.display = 'none';
        }
    }
    function goPathInput() {
        var v = $('pathEdit').value.replace(/\\/g, '/').trim();
        breadcrumbEdit(false);
        if (!v) return;
        if (v.charAt(0) !== '/') v = '/' + v;
        v = v.replace(/\/{2,}/g, '/').replace(/\/+$/, '') || '/';
        navigate(v);
    }

    /* ---------- 上传 ---------- */
    function startUpload() { $('uploadInput').click(); }
    $('uploadInput').addEventListener('change', function () {
        uploadFiles(this.files);
        this.value = '';
    });

    /* ---------- 面包屑 ---------- */
    function renderBreadcrumb() {
        var el = $('breadcrumb');
        var segs = state.path.split('/').filter(Boolean);
        var h = '<span class="bc-seg" data-p="/">⌂</span>';
        var acc = '';
        segs.forEach(function (s, i) {
            acc += '/' + s;
            h += '<span class="bc-sep">/</span><span class="bc-seg' + (i === segs.length - 1 ? ' cur' : '') + '" data-p="' + acc + '">' + esc(s) + '</span>';
        });
        el.innerHTML = h;
        el.querySelectorAll('.bc-seg').forEach(function (s) {
            if (s.classList.contains('cur')) return;
            s.onclick = function (e) { e.stopPropagation(); navigate(s.getAttribute('data-p')); };
        });
        // 点击面包屑空白区域进入编辑模式
        el.onclick = function (e) { if (e.target === el || e.target.classList.contains('bc-sep')) breadcrumbEdit(true); };
    }

    /* ---------- 树 ---------- */
    function loadTree(reset) {
        apiGet('tree', '/').then(function (d) {
            if (!d.success) return;
            $('tree').innerHTML = '<div class="tree-node" data-path="/" data-exp="1"><span class="tw">▾</span><img src="../../data/res/svg/folder_24.svg"><span class="tlabel">ChatApp (/)</span></div><div class="tree-children" id="tc-root">'
                + d.dirs.map(function (x) { return treeNode(x.path, x.name); }).join('')
                + '</div>';
            bindTree();
            // 构建完成后自动展开到当前路径并高亮
            expandTreeTo(state.path);
        });
    }
    function treeNode(path, name) {
        if (path.charAt(0) !== '/') path = '/' + path;   // 统一绝对路径，与 state.path 一致
        return '<div class="tree-node" data-path="' + esc(path) + '"><span class="tw">▸</span><img src="../../data/res/svg/folder_24.svg"><span class="tlabel">' + esc(name) + '</span></div>';
    }
    function bindTree() {
        $('tree').querySelectorAll('.tree-node').forEach(function (node) {
            node.onclick = function (e) {
                e.stopPropagation();
                var path = node.getAttribute('data-path');
                navigate(path);
            };
            node.oncontextmenu = function (e) {
                e.preventDefault(); e.stopPropagation();
                ctxShow(menuForTree(node.getAttribute('data-path')), e.clientX, e.clientY);
            };
            var tw = node.querySelector('.tw');
            tw.onclick = function (e) {
                e.stopPropagation();
                var path = node.getAttribute('data-path');
                var exp = node.getAttribute('data-exp') === '1';
                var ch = node.nextElementSibling;
                if (exp) {
                    node.setAttribute('data-exp', '0'); tw.textContent = '▸';
                    if (ch && ch.classList.contains('tree-children')) ch.style.display = 'none';
                } else {
                    node.setAttribute('data-exp', '1'); tw.textContent = '▾';
                    if (ch && ch.classList.contains('tree-children')) { ch.style.display = ''; return; }
                    // 懒加载子目录
                    apiGet('tree', path).then(function (d) {
                        if (!d.success) return;
                        var div = document.createElement('div');
                        div.className = 'tree-children';
                        div.innerHTML = d.dirs.map(function (x) { return treeNode(x.path, x.name); }).join('');
                        node.after(div);
                        bindTree();
                    });
                }
            };
        });
    }
    function highlightTree() {
        $('tree').querySelectorAll('.tree-node').forEach(function (n) {
            n.classList.remove('sel');
            if (n.getAttribute('data-path') === state.path) n.classList.add('sel');
        });
    }

    /* 树：懒加载并自动展开到当前路径的各级祖先，最后高亮 */
    function expandTreeTo(path) {
        var segs = path.split('/').filter(Boolean);
        var rootNode = $('tree').querySelector('.tree-node[data-path="/"]');
        if (!rootNode) { highlightTree(); return; }
        function recurse(parentNode, idx) {
            if (idx >= segs.length) { highlightTree(); return; }
            var acc = '/' + segs.slice(0, idx + 1).join('/');
            var chDiv = parentNode.nextElementSibling;
            var node = null;
            if (chDiv && chDiv.classList.contains('tree-children')) {
                var list = chDiv.querySelectorAll('.tree-node');
                for (var k = 0; k < list.length; k++) if (list[k].getAttribute('data-path') === acc) { node = list[k]; break; }
            }
            if (!node) {
                // 该级还没加载 → 懒加载父节点子目录（闭包引用本层参数，安全）
                var pn = parentNode, accp = acc, myIdx = idx;
                apiGet('tree', pn.getAttribute('data-path')).then(function (d) {
                    if (!d.success) { highlightTree(); return; }
                    var div = document.createElement('div');
                    div.className = 'tree-children';
                    div.innerHTML = d.dirs.map(function (x) { return treeNode(x.path, x.name); }).join('');
                    var ns = pn.nextElementSibling;
                    if (ns && ns.classList.contains('tree-children')) ns.remove();
                    pn.after(div);
                    pn.setAttribute('data-exp', '1');
                    var tw = pn.querySelector('.tw'); if (tw) tw.textContent = '▾';
                    bindTree();
                    var list2 = div.querySelectorAll('.tree-node');
                    var found = null;
                    for (var k = 0; k < list2.length; k++) if (list2[k].getAttribute('data-path') === accp) { found = list2[k]; break; }
                    if (found) recurse(found, myIdx + 1); else highlightTree();
                });
                return;
            }
            // 节点已存在 → 展开它（若折叠）
            if (node.getAttribute('data-exp') !== '1') {
                node.setAttribute('data-exp', '1');
                var tw2 = node.querySelector('.tw'); if (tw2) tw2.textContent = '▾';
            }
            var nch = node.nextElementSibling;
            if (nch && nch.classList.contains('tree-children')) nch.style.display = '';
            recurse(node, idx + 1);
        }
        recurse(rootNode, 0);
    }

    /* ---------- 历史/视图/排序 ---------- */
    function updateHistoryBtns() {
        $('btnBack').disabled = state.hIdx <= 0;
        $('btnNext').disabled = state.hIdx >= state.history.length - 1;
    }
    function setView(v) { state.view = v; applyFilterAndRender(); saveSettings(); }

    /* ---------- 初始化 ---------- */
    function init() {
        loadSettings();
        var h = location.hash.replace(/^#/, '');
        var initP = '/';
        if (h) { try { initP = decodeURIComponent(h); if (initP.charAt(0) !== '/') initP = '/' + initP; } catch (e) {} }
        loadTree(true);   // 建树（后续导航用 expandTreeTo 保持展开）
        navigate(initP, true);
        // 事件绑定
        $('btnBack').onclick = function () { if (state.hIdx > 0) { state.hIdx--; navigate(state.history[state.hIdx], false); } };
        $('btnNext').onclick = function () { if (state.hIdx < state.history.length - 1) { state.hIdx++; navigate(state.history[state.hIdx], false); } };
        $('btnHome').onclick = home;
        $('btnUp').onclick = up;
        $('btnRefresh').onclick = function () { loadList(); loadTree(false); };
        $('btnNewDir').onclick = newDir;
        $('btnNewFile').onclick = newFile;
        $('btnUpload').onclick = startUpload;
        $('btnZip').onclick = function () { var s = selNames(); if (!s.length) return errModal('请先选择文件'); zipSelected(s); };
        $('btnPaste').onclick = clipPaste;
        $('btnSelectAll').onclick = selectAll;
        $('btnDownload').onclick = function () { var s = selNames(); if (!s.length) return errModal('请先选择文件'); s.forEach(function (n) { download(absPath(n)); }); };
        $('btnDelete').onclick = function () { var s = selNames(); if (!s.length) return errModal('请先选择文件'); s.forEach(delItem); };
        $('viewList').onclick = function () { setView('list'); };
        $('viewIcon').onclick = function () { setView('icon'); };
        $('btnSizePlus').onclick = function () { state.size = Math.min(2, state.size + 0.25); applyFilterAndRender(); saveSettings(); };
        $('btnSizeMinus').onclick = function () { state.size = Math.max(0.5, state.size - 0.25); applyFilterAndRender(); saveSettings(); };
        $('searchBox').addEventListener('input', onSearchInput);
        $('btnSearchMode').onclick = toggleSearchMode;
        // 面包屑编辑
        $('btnPathEdit').onclick = function () { breadcrumbEdit(true); };
        $('pathEdit').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') goPathInput();
            else if (e.key === 'Escape') { breadcrumbEdit(false); }
        });
        $('pathEdit').addEventListener('blur', function () { breadcrumbEdit(false); });
        $('listHeader').querySelectorAll('.lh').forEach(function (lh) {
            lh.onclick = function () {
                var f = lh.getAttribute('data-f');
                if (state.sortKey === f) state.sortDir = -state.sortDir; else { state.sortKey = f; state.sortDir = 1; }
                $('listHeader').querySelectorAll('.lh').forEach(function (x) { x.classList.remove('up', 'down'); });
                lh.classList.add(state.sortDir === 1 ? 'up' : 'down');
                applyFilterAndRender();
                saveSettings();
            };
        });
        // 列宽拖拽
        $('listHeader').querySelectorAll('.rh').forEach(function (rh) {
            rh.addEventListener('mousedown', function (e) {
                e.preventDefault(); e.stopPropagation();
                var col = rh.getAttribute('data-col');
                var startX = e.clientX;
                var startW = (state.cols || {})[col] || 90;
                var moved = false;
                function mm(ev) {
                    var c = state.cols ? JSON.parse(JSON.stringify(state.cols)) : {};
                    c[col] = Math.max(50, startW + (ev.clientX - startX));
                    applyCols(c); moved = true;
                }
                function mu() { document.removeEventListener('mousemove', mm); document.removeEventListener('mouseup', mu); if (moved) saveSettings(); }
                document.addEventListener('mousemove', mm);
                document.addEventListener('mouseup', mu);
            });
        });
        // 分隔条拖拽
        var dragging = false;
        $('resizer').addEventListener('mousedown', function (e) { dragging = true; e.preventDefault(); });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var left = Math.min(Math.max(120, e.clientX), window.innerWidth - 320);
            $('frameLeft').style.width = left + 'px';
        });
        document.addEventListener('mouseup', function () { if (dragging) saveSettings(); dragging = false; });
        // 内部拖拽（RojExplorer 风格）：文件拖到文件夹/树/面包屑 → 移动（Ctrl=复制）
        document.addEventListener('mousemove', function (e) {
            if (!_drag) return;
            if (!_drag.active && (Math.abs(e.clientX - _drag.startX) > 5 || Math.abs(e.clientY - _drag.startY) > 5)) {
                _drag.active = true;
                startDragGhost(_drag);
            }
            if (_drag.active) { e.preventDefault(); moveDragGhost(e); }
        });
        document.addEventListener('mouseup', function () {
            if (_drag && _drag.active) endDragDrop();
            removeDragGhost();
            _drag = null;
        });
        // 右键菜单全局关闭
        document.addEventListener('click', function (e) { if (_ctxOpen && !e.target.closest('#ctxMenu')) ctxClose(); });
        document.addEventListener('scroll', ctxClose, true);
        // 拖拽上传
        var bm = $('fileList'), dragDepth = 0;
        bm.addEventListener('dragenter', function () { dragDepth++; bm.style.outline = '2px dashed #6a9fd8'; });
        bm.addEventListener('dragover', function (e) { e.preventDefault(); });
        bm.addEventListener('dragleave', function () { if (--dragDepth <= 0) { dragDepth = 0; bm.style.outline = ''; } });
        bm.addEventListener('drop', function (e) {
            e.preventDefault(); dragDepth = 0; bm.style.outline = '';
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
        });
        // 空白区右键 → 背景菜单
        bm.addEventListener('contextmenu', function (e) {
            if (e.target === bm || (e.target.classList && e.target.classList.contains('empty'))) {
                e.preventDefault();
                ctxShow(menuForBg(), e.clientX, e.clientY);
            }
        });
        // 键盘
        document.addEventListener('keydown', function (e) {
            var tag = document.activeElement ? document.activeElement.tagName : '';
            var inInput = /INPUT|TEXTAREA/.test(tag);
            if (e.key === 'Escape') { ctxClose(); breadcrumbEdit(false); return; }
            if (inInput) return;
            var mod = e.ctrlKey || e.metaKey;
            if (mod && (e.key === 'a' || e.key === 'A')) { e.preventDefault(); selectAll(); }
            else if (mod && (e.key === 'c' || e.key === 'C')) { var s = selNames(); if (s.length) { e.preventDefault(); clipSet('copy', s.map(absPath)); } }
            else if (mod && (e.key === 'x' || e.key === 'X')) { var s2 = selNames(); if (s2.length) { e.preventDefault(); clipSet('cut', s2.map(absPath)); } }
            else if (mod && (e.key === 'v' || e.key === 'V')) { e.preventDefault(); clipPaste(); }
            if (state.searchResults) return; // 搜索模式：禁 F2/Enter/Delete/方向键等目录操作
            else if (e.key === 'F2') { var s3 = selNames(); if (s3.length) { e.preventDefault(); renameItem(s3[0]); } }
            else if (e.key === 'Enter') { var s4 = selNames(); if (s4.length === 1) { var it = findItem(s4[0]); if (it) { if (it.dir) navigate(absPath(s4[0])); else openRow(s4[0]); } } }
            else if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Home' || e.key === 'End' || e.key === 'PageUp' || e.key === 'PageDown') { e.preventDefault(); navMove(e.key); }
            else if (e.key === 'Delete' || e.key === 'Backspace') {
                var s5 = selNames();
                if (s5.length) { e.preventDefault(); s5.forEach(delItem); }
            }
        });
        // 选中统计 + 剪贴板 + 历史按钮
        setInterval(function () {
            var total = state.searchResults ? state.searchResults.length : state.items.length;
            $('stCount').textContent = state.sel.size ? (state.sel.size + ' 选中 / ') : '';
            $('stCount').textContent += total + (state.searchResults ? ' 项匹配' : ' 项');
            updateHistoryBtns();
            if (state.path === '/') $('btnUp').disabled = true; else $('btnUp').disabled = false;
            clipUpdateUI();
        }, 300);
        window.addEventListener('hashchange', function () {});
    }
    init();
})();
</script>
</body>
</html>
