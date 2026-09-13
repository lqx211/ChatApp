<?php
/**
 * ChatApp · DeepSeek AI 聊天（apps/deepseek）
 *
 * 伪装成正常聊天的 AI 客户端：复用 modern/style/chat.css 的气泡/输入栏，
 * 视觉与私聊一致。API Key 由用户自己填、只存浏览器本地（localStorage），
 * 每次请求经 api.php 转发给 DeepSeek，服务端不保存。
 * Deepseek 写的
 */
require_once __DIR__ . '/../../maintenance.php';
require_once __DIR__ . '/../../api/config.php';
chatapp_session_start();

// 当前登录用户（只用于把名字/UID 注入系统提示词上下文；未登录时为空）
$__dsUser = isset($_SESSION['username']) ? (string)$_SESSION['username'] : '';
$__dsUid = 0;
$__dsEmojiPanel = 'dynamic';
$__dsEmojiChat = 'dynamic';
if ($__dsUser !== '') {
    try {
        $__st = db()->prepare('SELECT user_id, emoji_panel_mode, emoji_chat_mode FROM users WHERE username = ?');
        $__st->execute([$__dsUser]);
        $__row = $__st->fetch() ?: [];
        $__dsUid = (int)($__row['user_id'] ?? 0);
        // 表情显示模式跟随用户在聊天页的设置：dynamic(APNG) / hover(摸到才动) / static(PNG)
        $__dsEmojiPanel = in_array(($__row['emoji_panel_mode'] ?? ''), ['dynamic', 'hover', 'static'], true) ? $__row['emoji_panel_mode'] : 'dynamic';
        $__dsEmojiChat = in_array(($__row['emoji_chat_mode'] ?? ''), ['dynamic', 'static'], true) ? $__row['emoji_chat_mode'] : 'dynamic';
    } catch (\Throwable $__e) {}
}
// 内置表情表：直接从 data/res/emoji/default_config.json 读，服务端注入
// （不走 api/emoji.php —— 那个接口要求登录，而且异步返回会让历史消息先以纯文本显示一下）
$__dsEmojiList = [];
$__emojiCfgPath = __DIR__ . '/../../data/res/emoji/default_config.json';
if (is_file($__emojiCfgPath)) {
    $__emojiRaw = json_decode((string)file_get_contents($__emojiCfgPath), true);
    $__emojiDir = __DIR__ . '/../../data/res/emoji/';
    foreach ((array)($__emojiRaw['normalPanelResult']['SysEmojiGroupList'] ?? []) as $__g) {
        $__gname = (string)($__g['groupName'] ?? 'Emoji');
        foreach ((array)($__g['SysEmojiList'] ?? []) as $__e) {
            if (!empty($__e['isHide'])) continue;
            $__etype = (int)($__e['emojiType'] ?? 0);
            $__eid   = (string)($__e['emojiId'] ?? '');
            $__ecode = (string)($__e['describe'] ?? '');
            if ($__eid === '' && $__ecode === '') continue;
            $__entry = ['id' => $__eid, 'code' => $__ecode, 'type' => $__etype, 'group' => $__gname, 'img' => null];
            if ($__etype === 4) {
                $__entry['unicode'] = $__eid;
            } elseif (is_file($__emojiDir . $__eid . '.png')) {
                $__entry['img'] = 'data/res/emoji/' . $__eid . '.png';
                if (is_file($__emojiDir . 's' . $__eid . '.png')) $__entry['img_dyn'] = 'data/res/emoji/s' . $__eid . '.png';
            } else {
                continue;   // 没有 PNG 的跳过
            }
            $__dsEmojiList[] = $__entry;
        }
    }
}
$v = time();
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DeepSeek</title>
<!-- 复用聊天样式：气泡/输入栏与正常私聊完全一致 -->
<link rel="stylesheet" href="../../modern/style/chat.css?v=<?php echo $v;?>">
<style>
  html,body{margin:0;height:100%;background:transparent;color:#e0e0e0;
    font-family:Roboto,-apple-system,"system-ui","Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",Arial,sans-serif}
  /* chat.css 里 body{display:flex} 是给主界面(侧栏+主区)用的，这边整页就是这个 App，必须复位成 block，
     否则 .ds-wrap 作为 flex item 会被压成内容宽度，顶部标题栏/底部输入栏都只有一小条 */
  body{display:block}
  .ds-wrap{position:relative;z-index:1;display:flex;flex-direction:column;height:100%;width:100%}
  .ds-wrap .ch{gap:8px}
  .ds-wrap .ch h2 .ds-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#4caf50;margin-right:8px;vertical-align:1px}
  .ds-key-warn{background:#3a2a1e;border-bottom:1px solid #5a3a1e;color:#e0a040;font-size:.78em;padding:8px 20px}
  .ds-key-warn a{color:#6a9fd8;cursor:pointer;text-decoration:underline}
  .ds-reason{max-width:min(560px,80%);background:#242424;border:1px dashed #4a4a4a;color:#9a9a9a;font-size:.78em;
    padding:6px 10px;margin-bottom:4px;white-space:pre-wrap;word-break:break-word;border-radius:6px;font-style:italic}
  .ds-err{color:#e06060;font-size:.82em;padding:6px 2px}
  #dsSettingsModal .modal-box{text-align:left}
  #dsSettingsModal .ds-field{margin-bottom:12px}
  #dsSettingsModal label{display:block;font-size:.78em;color:#999;margin-bottom:4px}
  #dsSettingsModal input,#dsSettingsModal select,#dsSettingsModal textarea{width:100%;box-sizing:border-box;
    background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.85em;padding:8px 10px;outline:none;font-family:inherit}
  #dsSettingsModal textarea{min-height:70px;resize:vertical}
  .ds-row2{display:flex;gap:10px}
  .ds-row2>div{flex:1}
  /* ---- Agent 工具 UI ---- */
  .ds-check{display:flex;align-items:flex-start;gap:8px;padding:7px 0;border-top:1px solid #2c2c2c}
  .ds-check label{display:flex;align-items:flex-start;gap:8px;font-size:.8em;color:#c0c0c0;margin:0;cursor:pointer;line-height:1.5}
  .ds-check input[type=checkbox]{width:auto;flex:0 0 auto;margin-top:3px;accent-color:#4caf50}
  .ds-check .ds-hint{display:block;color:#7c7c7c;font-size:.92em;margin-top:2px}
  .ds-badge{font-size:.7em;color:#7f8c99;border:1px solid #3a444e;border-radius:999px;padding:2px 9px;align-self:center;white-space:nowrap}
  .ds-badge.off{color:#8a7f6a;border-color:#4a4234}
  .ds-status{color:#8a939c;font-size:.75em;font-style:italic;padding:2px 0 4px}
  /* ---- 工具逐项选择 ---- */
  .ds-tool-list{max-height:216px;overflow:auto;border:1px solid #333;background:#181818;padding:4px 9px}
  .ds-tool-group{color:#7f8c99;font-size:.72em;font-weight:600;padding:7px 0 3px;border-bottom:1px solid #2a2a2a;letter-spacing:.04em}
  .ds-tool-row{display:flex;gap:8px;align-items:flex-start;padding:5px 0;border-bottom:1px solid #202020;cursor:pointer}
  .ds-tool-row:last-child{border-bottom:none}
  .ds-tool-row.dis{opacity:.45;cursor:not-allowed}
  .ds-tool-row input[type=checkbox]{width:auto;flex:0 0 auto;margin-top:3px;accent-color:#4caf50}
  .ds-tool-row b{display:inline-block;color:#9fd0ff;font-weight:600;font-size:.8em;margin-right:6px;font-family:ui-monospace,Menlo,Consolas,monospace}
  .ds-tool-row i{font-style:normal;color:#8a8a8a;font-size:.76em;line-height:1.5}
  /* ---- AI 权限档位 ---- */
  .ds-level{border:1px solid #333;background:#181818;padding:2px 10px}
  .ds-level-row{display:flex;align-items:flex-start;gap:8px;padding:7px 0;border-top:1px solid #262626;cursor:pointer;font-size:.8em;color:#c0c0c0;margin:0}
  .ds-level-row:first-child{border-top:none}
  .ds-level-row input[type=radio]{width:auto;flex:0 0 auto;margin-top:3px;accent-color:#4caf50}
  .ds-level-row b{color:#9fd0ff;font-weight:600;white-space:nowrap;min-width:62px}
  .ds-level-row .ds-hint{margin-top:0}
  /* ---- 工具调用确认卡的样式在 chat.css（.ca-ask*，两个页面共用）---- */
  /* ---- 输入框上方的运行状态栏（轮/步/耗时/token/缓存/上下文）---- */
  .ds-stats{display:flex;flex-wrap:wrap;align-items:center;gap:4px 7px;padding:3px 20px 0;
    font-size:.68em;color:#7b8794;font-variant-numeric:tabular-nums;line-height:1.6}
  .ds-stats .seg{white-space:nowrap}
  .ds-stats .sep{color:#46505a}
  .ds-stats .hot{color:#9ec48a}
  .ds-stats .warn{color:#d0a45a}
  .ds-stats:empty{display:none}
  /* ---- 聊天外壳：附件条 / 图片 / 9 点菜单 / 表情面板 / 涂鸦 ---- */
  .ds-att{display:flex;align-items:center;gap:10px;padding:8px 20px;background:rgba(42,42,42,.92);border-top:1px solid #3a3a3a}
  .ds-att img{width:54px;height:54px;object-fit:cover;border-radius:6px;border:1px solid #555}
  .ds-att span{font-size:.74em;color:#9aa7b3}
  .ds-att .ds-att-x{margin-left:auto;cursor:pointer;color:#e08080;font-size:1.15em;padding:0 6px}
  .ds-img{max-width:min(300px,62vw);max-height:320px;border-radius:8px;display:block;margin:2px 0;cursor:zoom-in;border:1px solid #444}
  /* 表情选择器 / 9 点菜单的样式全部来自 chat.css（#emojiPopup 与 #dmNineMenu），这里只补手机端停靠 */
  .ds-wrap.emoji-open .ma{padding-bottom:44vh}
  .ds-doodle{position:fixed;inset:0;z-index:3000;background:rgba(0,0,0,.35)}
  .ds-doodle canvas{position:absolute;inset:0;width:100%;height:100%;touch-action:none;cursor:crosshair}
  .ds-doodle-bar{position:fixed;left:0;right:0;bottom:0;display:flex;align-items:center;gap:10px;padding:10px 16px;background:#1e1e1e;border-top:1px solid #444;flex-wrap:wrap;z-index:3001}
  .ds-doodle-title{color:#fff;font-size:13px;font-weight:700}
  .ds-doodle-colors{display:flex;gap:6px}
  .ds-doodle-colors button{width:20px;height:20px;border-radius:50%;border:2px solid #444;cursor:pointer;padding:0}
  .ds-doodle-colors button.active{border-color:#fff}
  .ds-doodle-size{color:#ccc;font-size:12px;display:flex;align-items:center;gap:6px}
</style>
</head>
<body>
<script>
  // 服务端注入：登录状态（工具要用它判断能不能读 ChatApp 数据）+ 表情显示设置 + 内置表情表
  // 必须在 ui.js 之前：ui.js 加载时就要用到 EMOJI_BUILTIN
  var DS_USER = { username: <?php echo json_encode($__dsUser, JSON_UNESCAPED_UNICODE); ?>, uid: <?php echo (int)$__dsUid; ?> };
  var EMOJI_PANEL = <?php echo json_encode($__dsEmojiPanel); ?>;   // dynamic / hover / static
  var EMOJI_CHAT  = <?php echo json_encode($__dsEmojiChat); ?>;    // dynamic / static
  // 内置表情表（服务端直出，不依赖登录、无异步闪烁）
  var EMOJI_BUILTIN = <?php echo json_encode($__dsEmojiList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="../../modern/scripts/markdown.js?v=<?php echo $v;?>"></script>
<script src="agent.js?v=<?php echo $v;?>"></script>
<script src="ui.js?v=<?php echo $v;?>"></script>
<div id="app-bg"></div>
<div class="bg-overlay" id="app-bg-overlay"></div>

<div class="ds-wrap">
  <div class="ch">
    <h2><span class="ds-dot"></span>Deepseek</h2>
    <span class="ds-badge" id="dsToolBadge"></span>
    <button class="bsm" id="dsSettingsBtn" type="button">设置</button>
    <button class="bsm" id="dsClearBtn" type="button">清空</button>
  </div>
  <div class="ds-key-warn" id="dsKeyWarn" style="display:none">
    还没填 DeepSeek API Key —— 点「<a id="dsKeyWarnLink">这里</a>」填写后即可开聊（Key 只存在你浏览器本地）。
  </div>
  <div class="ma" id="aiMessages"><div class="es"><p>我是内置的 AI 助手，会自己调用工具把事办完：<br>算数、换算单位、看时间 · 查你的等级/资料/好友/群/会话/工单 · 帮你记点小事。<br>比如问我「现在几点」「帮我算 (3+5)*2」「我几级了」。想让我听你的？点右上角「设置」。</p></div></div>
  <div class="typing-indicator" id="aiTyping">DeepSeek 正在输入…</div>
  <div class="ds-att" id="dsAttachChip" style="display:none"></div>
  <div class="ds-stats" id="dsStats"></div>
  <div class="cia">
    <textarea id="aiInput" rows="1" placeholder="输入消息…（Enter 发送，Shift+Enter 换行）" style="resize:none;overflow-y:auto;line-height:1.4;max-height:20em"></textarea>
    <input type="file" id="dmMediaFile" multiple accept="image/*" style="display:none">
    <button class="bsm" id="dmEmojiBtn" onclick="toggleEmojiPicker(event,'aiInput')" title="Emoji"><img src="../../data/res/svg/expression_24.svg" width="16" style="vertical-align:-2px"></button>
    <button class="bsm" id="dmNineBtn" onclick="toggleDmNineMenu(event,this)" title="更多"><svg width="16" height="16" viewBox="0 0 24 24" fill="#ccc"><circle cx="5" cy="5" r="1.8"/><circle cx="12" cy="5" r="1.8"/><circle cx="19" cy="5" r="1.8"/><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/><circle cx="5" cy="19" r="1.8"/><circle cx="12" cy="19" r="1.8"/><circle cx="19" cy="19" r="1.8"/></svg></button>
    <button class="bs" id="aiSendBtn" type="button">发送</button>
  </div>
</div>

<!-- 9 点菜单（结构与 chat.php 的 #dmNineMenu 完全一致） -->
<div class="nine-menu" id="dmNineMenu" style="display:none">
  <div class="nine-cell" onclick="nineEmoji()"><img src="../../data/res/svg/expression_24.svg" alt=""><span>表情</span></div>
  <div class="nine-cell" onclick="nineUpload()"><img src="../../data/res/svg/folder_16.svg" alt=""><span>图片</span></div>
  <div class="nine-cell" onclick="ninePen()"><img src="../../data/res/svg/brush_24.svg" alt=""><span>涂鸦</span></div>
  <div class="nine-cell" onclick="nineCompress()"><img src="../../data/res/svg/ai_summary_24.svg" alt=""><span>压缩聊天</span></div>
</div>

<!-- 表情选择器（markup 与 chat.php 的 #emojiPopup 一致，CSS 直接用 chat.css） -->
<input type="file" id="customEmojiFile" accept="image/*" multiple style="display:none">
<div class="emoji-popup" id="emojiPopup" style="display:none">
  <div class="emoji-sidebar">
    <button class="active" id="emojiTabBuiltin" onclick="switchEmojiTab('builtin')">内置表情</button>
    <button id="emojiTabCustom" onclick="switchEmojiTab('custom')">自定义表情</button>
  </div>
  <div class="emoji-grid" id="emojiGrid"></div>
</div>

<div id="dsDoodle" class="ds-doodle" style="display:none">
  <canvas id="dsDoodleCanvas"></canvas>
  <div class="ds-doodle-bar">
    <span class="ds-doodle-title">涂鸦</span>
    <span class="ds-doodle-colors" id="dsDoodleColors">
      <button class="active" data-color="#ff4d4d" style="background:#ff4d4d"></button>
      <button data-color="#ffb84d" style="background:#ffb84d"></button>
      <button data-color="#4dd06a" style="background:#4dd06a"></button>
      <button data-color="#4ea1ff" style="background:#4ea1ff"></button>
      <button data-color="#c86bff" style="background:#c86bff"></button>
      <button data-color="#ffffff" style="background:#fff"></button>
    </span>
    <label class="ds-doodle-size">粗细 <input type="range" id="dsDoodleSize" min="1" max="40" value="6"></label>
    <button class="bsm" id="dsDoodleUndo" type="button">撤销</button>
    <button class="bsm" id="dsDoodleClear" type="button">清空</button>
    <button class="bsm" id="dsDoodleCancel" type="button">取消</button>
    <button class="bs" id="dsDoodleSend" type="button" style="background:#2a4a2a;border-color:#3a6a3a">画好了，发给 AI</button>
  </div>
</div>

<div class="modal-overlay" id="dsSettingsModal">
  <div class="modal-box">
    <h3>DeepSeek 设置</h3>
    <div class="ds-field">
      <label>API Key（只存本机 localStorage，不会上传服务器保存）</label>
      <input type="password" id="dsKey" placeholder="sk-..." autocomplete="off">
      <div style="display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap">
        <span id="dsKeyState" style="font-size:.72em;color:#7c9c7c"></span>
        <button class="bsm" id="dsKeyClear" type="button" style="font-size:.72em;padding:3px 8px">清除已保存的 Key</button>
      </div>
    </div>
    <div class="ds-field">
      <label>模型（当前仅支持此模型）</label>
      <select id="dsModel">
        <option value="deepseek-v4-flash">deepseek-v4-flash</option>
      </select>
    </div>
    <div class="ds-field">
      <label>系统提示词（角色设定；留空 = 使用内置默认提示词）</label>
      <textarea id="dsSystem" placeholder="留空即可 —— 内置默认提示词已包含工具协议、行为准则与安全边界"></textarea>
      <div style="display:flex;gap:8px;align-items:center;margin-top:6px">
        <button class="bsm" id="dsLoadDefault" type="button">载入默认提示词</button>
        <span style="font-size:.72em;color:#7c7c7c">工具清单与当前上下文会自动附加</span>
      </div>
    </div>
    <div class="ds-check">
      <label><input type="checkbox" id="dsTools"> 允许使用工具（总开关）
        <span class="ds-hint">关掉后模型完全看不到工具；下面的逐项勾选只决定「哪些工具可用」，权限的最终判定始终在服务端</span>
      </label>
    </div>
    <div class="ds-field">
      <label>可用工具（逐项选择）</label>
      <div class="ds-tool-list" id="dsToolList"></div>
      <div style="display:flex;gap:8px;margin-top:6px">
        <button class="bsm" id="dsToolsDefault" type="button">恢复默认</button>
        <button class="bsm" id="dsToolsAll" type="button">全选可用</button>
        <button class="bsm" id="dsToolsNone" type="button">全不选</button>
      </div>
    </div>
    <div class="ds-field" style="margin-bottom:4px"><label>AI 权限（账号级，存在服务器；默认「关闭」）</label></div>
    <div class="ds-level" id="dsLevelGroup">
      <label class="ds-level-row"><input type="radio" name="dsAiLevel" value="0" checked>
        <b>关闭</b><span class="ds-hint">AI 只能算数/看时间/用表情，读不到你的 ChatApp 数据</span></label>
      <label class="ds-level-row"><input type="radio" name="dsAiLevel" value="1">
        <b>仅读</b><span class="ds-hint">可读我的资料/等级/好友/群/会话/聊天记录（只读，不会发消息）</span></label>
      <label class="ds-level-row"><input type="radio" name="dsAiLevel" value="2">
        <b>读写</b><span class="ds-hint">仅读 + 可代我发私聊（仅好友、5 分钟 3 条、全量审计、发送前会先问你）</span></label>
      <label class="ds-level-row"><input type="radio" name="dsAiLevel" value="3">
        <b>允许所有</b><span class="ds-hint">读写 + 管理员统计，以及以后新增的工具</span></label>
    </div>
    <div class="ds-hint" style="margin:2px 0 10px;color:#8a7f6a">
      ⚠ 「仅读」及以上意味着你允许 AI 读取的相应内容会发送给 DeepSeek 用于生成回复（服务端不存日志，调用记录见「AI 访问记录」）。随时可改回「关闭」，立即生效。
    </div>
    <div class="ds-row2">
      <div class="ds-field"><label>温度 (0~2)</label><input type="number" id="dsTemp" min="0" max="2" step="0.1" value="1"></div>
      <div class="ds-field"><label>最大回复长度 (max_tokens)</label><input type="number" id="dsMaxTokens" min="1" max="8192" step="1" value="2048"></div>
    </div>
    <div class="ds-row2">
      <div class="ds-field"><label>上下文窗口 (Context Window)</label><input type="number" id="dsCtxWin" min="8000" max="2000000" step="1000" value="1000000"><div class="ds-hint" style="margin-top:4px">deepseek-v4-flash 默认 1M tokens。只用于本地估算与自动压缩（按字符粗估，非官方分词器）；用量超过它的 65% 会自动压缩对话。</div></div>
      <div class="ds-field"><label>AI 读图的许可</label>
        <select id="dsImgPerm">
          <option value="">每次读取都问我</option>
          <option value="session">本会话内都允许（刷新失效）</option>
          <option value="always">全部允许（永久，记住选择）</option>
        </select>
        <div class="ds-hint" style="margin-top:4px">只影响 AI 读取别人主页的图片（每次仍然是经过隐私过滤的图）。随时可改回「每次读取都问我」。</div>
      </div>
    </div>
    <div class="modal-actions">
      <button class="bsm" id="dsSettingsCancel" type="button">取消</button>
      <button class="bsm" id="dsSettingsSave" type="button" style="background:#2a4a2a;border-color:#3a6a3a">保存</button>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var LS_CFG = 'chatapp_ds_cfg', LS_CONV = 'chatapp_ds_conv';
  var DS = window.DSAgent;
  var USER = window.DS_USER || { username: '', uid: 0 };
  // tools=总开关；toolPrefs=逐项开关({工具名:bool}，缺省用工具的 defaultOn)
  // 账号级权限（读会话摘要 / 允许代发私聊）由服务端持有 → serverPrefs
  var cfg = { key: '', model: 'deepseek-v4-flash', system: '', temp: 0.7, maxTokens: 2048, tools: true, toolPrefs: null };
  var serverPrefs = { ai_level: 0 };
  var conv = [];            // [{role:'user'|'assistant', content, hidden?, kind?}]  hidden = 工具结果，不上屏但发给模型
  var streaming = false;
  var aborter = null;       // 当前流的中断控制器（「停止」按钮）
  var CONV_KEEP = 80;       // 本地最多保留多少条（含隐藏的工具结果）
  var WELCOME = '我是内置的 AI 助手，会自己调用工具把事办完：<br>算数、换算单位、看时间 · 查你的等级/资料/好友/群/会话/工单 · 帮你记点小事。<br>比如问我「现在几点」「帮我算 (3+5)*2」「我几级了」。想让我听你的？点右上角「设置」。';

  var $ = function (id) { return document.getElementById(id); };
  var msgArea = $('aiMessages'), input = $('aiInput'), sendBtn = $('aiSendBtn'),
      typing = $('aiTyping'), keyWarn = $('dsKeyWarn');

  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function load() {
    try { var c = JSON.parse(localStorage.getItem(LS_CFG) || '{}'); if (c && typeof c === 'object') cfg = Object.assign(cfg, c); } catch (e) {}
    cfg.model = 'deepseek-v4-flash'; // 只允许 V4 Flash：旧存档里的其他模型一律迁移过来
    if (cfg.tools == null) cfg.tools = true;
    if (!(cfg.temp >= 0)) cfg.temp = 0.7;
    delete cfg.readChats;   // 旧字段：已搬到服务端账号开关（tools.php）
    if (!cfg.toolPrefs || typeof cfg.toolPrefs !== 'object') cfg.toolPrefs = DS.defaultEnabledMap();
    DS.setEnabled(cfg.toolPrefs);      // 前端只能收窄；服务端会独立再判一次
    DS.setContext({ loggedIn: !!(USER && USER.username) });
    try { var v = JSON.parse(localStorage.getItem(LS_CONV) || '[]'); if (Array.isArray(v)) conv = v; } catch (e) {}
  }
  function saveCfg() { try { localStorage.setItem(LS_CFG, JSON.stringify(cfg)); } catch (e) {} }
  function saveConv() {
    if (conv.length > CONV_KEEP) conv = conv.slice(-CONV_KEEP);
    // 老消息里的图片太大（base64），只保留最近 6 条，避免 localStorage 爆掉
    for (var i = 0; i < conv.length - 6; i++) { if (conv[i].images) delete conv[i].images; }
    try { localStorage.setItem(LS_CONV, JSON.stringify(conv)); } catch (e) {}
  }
  function refreshBadge() {
    var b = $('dsToolBadge');
    if (!b) return;
    if (cfg.tools === false) { b.className = 'ds-badge off'; b.textContent = '工具已关闭'; return; }
    b.className = 'ds-badge';
    b.textContent = '🛠 ' + DS.activeTools().length + '/' + DS.TOOLS.length + ' 个工具';
  }

  /* ---------- 账号级权限（服务端持有，页面只负责读/写） ---------- */
  function loadServerPrefs() {
    return DS.prefs().then(function (j) {
      if (j && j.ok && j.data) {
        serverPrefs = { ai_level: Math.max(0, Math.min(3, Number(j.data.ai_level) || 0)) };
        if (j.data.username) USER = { username: j.data.username, uid: j.data.uid, isAdmin: !!j.data.is_admin };
        DS.setContext({ loggedIn: true, isAdmin: !!j.data.is_admin, aiLevel: serverPrefs.ai_level });
      }
      refreshBadge();
    }).catch(function () {
      DS.setContext({ loggedIn: !!(USER && USER.username) });
      refreshBadge();
    });
  }

  /* ---------- 工具逐项选择 ---------- */
  var GROUP_TITLE = { local: '本地工具（不出浏览器，不需要登录）', mine: '我的 ChatApp 数据（服务端只读）', write: '会改动数据的操作（默认关闭）', admin: '管理员工具' };
  function renderToolList() {
    var box = $('dsToolList');
    if (!box) return;
    box.innerHTML = '';
    var groups = {};
    DS.TOOLS.forEach(function (t) { (groups[t.group] = groups[t.group] || []).push(t); });
    Object.keys(GROUP_TITLE).forEach(function (g) {
      if (!groups[g]) return;
      var h = document.createElement('div');
      h.className = 'ds-tool-group';
      h.textContent = GROUP_TITLE[g];
      box.appendChild(h);
      groups[g].forEach(function (t) {
        var reason = DS.unavailableReason(t);
        var row = document.createElement('label');
        row.className = 'ds-tool-row' + (reason ? ' dis' : '');
        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.setAttribute('data-tool', t.name);
        cb.checked = !reason && DS.isOn(t);
        cb.disabled = !!reason;
        var sp = document.createElement('span');
        var bEl = document.createElement('b'); bEl.textContent = t.name;
        var iEl = document.createElement('i'); iEl.textContent = reason ? ('不可用 · ' + reason) : t.desc;
        sp.appendChild(bEl); sp.appendChild(iEl);
        row.appendChild(cb); row.appendChild(sp);
        box.appendChild(row);
      });
    });
  }
  function collectToolPrefs() {
    var m = {};
    Array.prototype.forEach.call($('dsToolList').querySelectorAll('input[data-tool]'), function (cb) {
      m[cb.getAttribute('data-tool')] = cb.checked;
    });
    return m;
  }

  function hasKey() { return !!(cfg.key && cfg.key.trim()); }
  function refreshKeyWarn() { keyWarn.style.display = hasKey() ? 'none' : ''; }

  function clearEmpty() { var e = msgArea.querySelector('.es'); if (e) e.remove(); }
  function scrollBottom() { msgArea.scrollTop = msgArea.scrollHeight; }

  // 用户气泡（靠右，与私聊 .mr.own 一致）；text 可空（只发图片）
  function addUserBubble(text, images) {
    clearEmpty();
    var r = document.createElement('div');
    r.className = 'mr own';
    r.innerHTML = '<div class="mc"><div class="mb"><div class="mt"></div><div class="mti"></div></div></div>';
    var mt = r.querySelector('.mt');
    (images || []).forEach(function (u) {
      var img = document.createElement('img');
      img.className = 'ds-img';
      img.src = u;
      img.addEventListener('click', function () { try { window.open(u, '_blank'); } catch (e) {} });
      mt.appendChild(img);
    });
    if (text) {
      var t = document.createElement('div');
      t.innerHTML = DSUI.renderEmoji(esc(DSUI.normalizeEmojiHtml(text)));
      mt.appendChild(t);
    }
    r.querySelector('.mti').textContent = nowTime();
    msgArea.appendChild(r); scrollBottom();
    return r;
  }
  // AI 气泡（靠左，.mu 显示 DeepSeek）
  function addAiBubble() {
    clearEmpty();
    var r = document.createElement('div');
    r.className = 'mr';
    r.innerHTML = '<div class="mc"><div class="mb"><div class="mu">DeepSeek</div>'
      + '<div class="ds-reason" style="display:none"></div>'
      + '<div class="mt"></div><div class="mti"></div></div></div>';
    msgArea.appendChild(r); scrollBottom();
    return r;
  }
  function nowTime() {
    var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '/' + p(d.getMonth() + 1) + '/' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  /* ---------- 气泡内排版：正文块 + 工具卡按出现顺序排列 ---------- */
  function appendPart(b, el) {
    var mb = b.querySelector('.mb');
    mb.insertBefore(el, mb.querySelector('.mti'));
  }
  function newTextBlock(b) {
    var el = document.createElement('div'); el.className = 'mt'; appendPart(b, el); return el;
  }
  function addStatus(b, text) {
    var el = document.createElement('div'); el.className = 'ds-status'; el.textContent = text;
    appendPart(b, el); return el;
  }
  // 隐藏的 tool_result 消息 → 还原每个工具的返回，用于历史重绘时把卡片结果填回去
  function resultsFromHidden(content) {
    var out = [], re = /<tool_result name="([^"]*)">([\s\S]*?)<\/tool_result>/g, m;
    while ((m = re.exec(String(content || '')))) {
      var r; try { r = JSON.parse(m[2]); } catch (e) { r = { ok: false, error: '历史结果解析失败' }; }
      out.push(r);
    }
    return out;
  }
  // 把一条 assistant 消息（可能含 <tool> 块）画进气泡
  function renderAssistant(b, content, results, toolCalls) {
    var parts = DS.splitToolBlocks(content), ti = 0;
    parts.forEach(function (p) {
      if (p.type === 'text') {
        if (!String(p.text).trim()) return;
        var el = newTextBlock(b);
        el.innerHTML = DSUI.renderEmojiHtml(renderMd(DSUI.normalizeEmojiHtml(String(p.text))));
      } else {
        var card = DS.toolCard(p);
        appendPart(b, card);
        var r = results && results[ti++];
        DS.fillToolCard(card, r || { ok: true, data: '（历史记录：该结果未保存）' });
      }
    });
    // 原生 function calling 的调用不在正文里，历史重绘时要从 tool_calls 补出卡片
    (toolCalls || []).forEach(function (tc) {
      var fn = tc.function || {}, args = {};
      try { args = JSON.parse(fn.arguments || '{}'); } catch (e) {}
      var card2 = DS.toolCard({ name: fn.name, args: args });
      appendPart(b, card2);
      var r2 = results && results[ti++];
      DS.fillToolCard(card2, r2 || { ok: true, data: '（历史记录：该结果未保存）' });
    });
  }

  function renderAll() {
    msgArea.innerHTML = '';
    if (!conv.filter(function (m) { return !m.hidden; }).length) {
      msgArea.innerHTML = '<div class="es"><p>' + WELCOME + '</p></div>';
      return;
    }
    for (var i = 0; i < conv.length; i++) {
      var m = conv[i];
      if (m.hidden) continue;                       // 工具结果不上屏
      if (m.kind === 'summary') {                   // 压缩后的交接摘要：单独一张卡（不是用户发言）
        var sb = addAiBubble();
        var sc = document.createElement('div');
        sc.className = 'ca-ask';
        sc.style.maxWidth = 'min(560px,86vw)';
        sc.innerHTML = '<div class="flash-title">对话压缩摘要</div>' +
          '<div class="ca-ask-purpose">这份摘要替代了之前的对话记录，后续回答都基于它继续。</div>';
        var body = document.createElement('div');
        body.style.cssText = 'font-size:.8em;line-height:1.55;white-space:pre-wrap;word-break:break-word;color:#cfd8e0;max-height:340px;overflow:auto';
        body.textContent = String(m.content).replace(/^【[^】]*】\s*/, '');
        sc.appendChild(body);
        appendPart(sb, sc);
        sb.querySelector('.mti').textContent = m.time || nowTime();
        continue;
      }
      if (m.role === 'user') { addUserBubble(m.content, m.images); continue; }
      var b = addAiBubble();
      var nxt = conv[i + 1];
      var hist;
      if (nxt && nxt.hidden && nxt.kind === 'tool_result') hist = resultsFromHidden(nxt.content);
      else if (nxt && nxt.role === 'tool') {           // 原生 function calling 的历史结果
        hist = [];
        for (var k = i + 1; k < conv.length && conv[k].role === 'tool'; k++) {
          try { hist.push(JSON.parse(conv[k].content)); } catch (e) { hist.push({ ok: false, error: '历史结果解析失败' }); }
        }
      }
      renderAssistant(b, m.content, hist, m.tool_calls);
      b.querySelector('.mti').textContent = m.time || nowTime();
    }
    scrollBottom();
  }

  /* ---------- 设置 ---------- */
  function openSettings() {
    $('dsKey').value = cfg.key || '';
    $('dsKeyState').textContent = hasKey()
      ? '✅ 已保存在本机（localStorage 的 chatapp_ds_cfg），下次打开自动使用'
      : '⚠ 还没填写 —— 填好后点保存即写入本机';
    $('dsModel').value = 'deepseek-v4-flash';
    $('dsSystem').value = cfg.system || '';
    $('dsTemp').value = (cfg.temp != null ? cfg.temp : 0.7);
    $('dsMaxTokens').value = (cfg.maxTokens || 2048);
    $('dsCtxWin').value = ctxWindowSize();
    $('dsImgPerm').value = DS.imagePermMode() || '';
    $('dsTools').checked = cfg.tools !== false;
    var radios = document.getElementsByName('dsAiLevel');
    for (var ri = 0; ri < radios.length; ri++) radios[ri].checked = (Number(radios[ri].value) === serverPrefs.ai_level);
    refreshLevelHint();
    renderToolList();
    $('dsSettingsModal').classList.add('active');
    setTimeout(function () { try { $('dsKey').focus(); } catch (e) {} }, 50);
  }
  function closeSettings() { $('dsSettingsModal').classList.remove('active'); }
  function saveSettings() {
    cfg.key = $('dsKey').value.trim();
    cfg.model = 'deepseek-v4-flash';
    cfg.system = $('dsSystem').value;
    var t = parseFloat($('dsTemp').value); cfg.temp = isNaN(t) ? 0.7 : Math.max(0, Math.min(2, t));
    var mt = parseInt($('dsMaxTokens').value, 10); cfg.maxTokens = isNaN(mt) ? 2048 : Math.max(1, Math.min(8192, mt));
    var cw = parseInt($('dsCtxWin').value, 10); cfg.contextWindow = isNaN(cw) ? 1000000 : Math.max(8000, Math.min(2000000, cw));
    DS.setImagePerm($('dsImgPerm').value || '');
    cfg.tools = $('dsTools').checked;
    cfg.toolPrefs = collectToolPrefs();
    DS.setEnabled(cfg.toolPrefs);
    saveCfg(); refreshKeyWarn(); refreshBadge(); renderStats(); closeSettings();

    // 账号级权限：一个档位（默认 0 关闭）；选「读写」及以上需要二次确认
    var radios = document.getElementsByName('dsAiLevel'), wantLv = serverPrefs.ai_level;
    for (var i = 0; i < radios.length; i++) if (radios[i].checked) wantLv = Number(radios[i].value) || 0;
    if (wantLv >= 2 && serverPrefs.ai_level < 2) {
      if (!confirm('选择「读写」后，AI 可以在你明确要求时以你的身份给好友发消息。\n服务端限制：只能发给好友、5 分钟最多 3 条、每次调用都记审计日志。\n确定吗？')) {
        wantLv = 1;
      }
    }
    if (wantLv !== serverPrefs.ai_level) {
      DS.setPrefs({ ai_level: wantLv }).then(function (j) {
        if (j && j.ok) {
          serverPrefs = { ai_level: Number((j.data && j.data.ai_level) || wantLv) };
          DS.setContext({ aiLevel: serverPrefs.ai_level });
          // 逐项勾选跟着档位重置，省得让人手点 20 个开关
          cfg.toolPrefs = DS.prefsForLevel(serverPrefs.ai_level);
          DS.setEnabled(cfg.toolPrefs);
          saveCfg();
          refreshBadge();
          alert('AI 权限已设为「' + DS.levelName(serverPrefs.ai_level) + '」。');
        } else {
          alert('AI 权限保存失败：' + ((j && j.error) || '未知错误'));
        }
      }).catch(function (e) {
        alert('AI 权限保存失败：' + ((e && e.message) || '网络错误'));
      });
    }
  }
  function refreshLevelHint() {
    // 档位存在服务器，未登录改不了（服务端也会拒）→ 直接禁用，避免白填
    var logged = !!(USER && USER.username);
    var radios = document.getElementsByName('dsAiLevel');
    for (var i = 0; i < radios.length; i++) {
      radios[i].disabled = !logged;
      radios[i].closest('label').style.opacity = logged ? '' : '.5';
    }
  }

  /* ================= 运行状态栏（输入框上方那行） =================
     轮 / 步 · LLM 与工具耗时 · 首 token · tok/s · 缓存命中 · 输入输出 · 上下文占用
     数据来源：轮次与步数自己数；时长用 Date.now() 量；token 与缓存命中取上游
     最后一个 SSE 块里的 usage（api.php 已加 stream_options.include_usage）。 */
  var LS_STATS = 'chatapp_ds_stats';
  var stats = { rounds: 0, steps: 0, llmMs: 0, toolMs: 0, ttftSum: 0, ttftN: 0, genMs: 0, inTok: 0, outTok: 0, hit: 0, miss: 0, comps: 0 };
  (function loadStats() {
    try {
      var v = JSON.parse(localStorage.getItem(LS_STATS) || '{}');
      if (v && typeof v === 'object') Object.keys(stats).forEach(function (k) { if (typeof v[k] === 'number' && isFinite(v[k])) stats[k] = v[k]; });
    } catch (e) {}
  })();
  function saveStats() { try { localStorage.setItem(LS_STATS, JSON.stringify(stats)); } catch (e) {} }
  function resetStats() { Object.keys(stats).forEach(function (k) { stats[k] = 0; }); saveStats(); renderStats(); }
  function fmtTok(n) {
    n = Number(n) || 0;
    if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
    return String(Math.round(n));
  }
  function fmtDur(ms) {
    var s = Math.max(0, Number(ms) || 0) / 1000;
    if (s < 60) return (s < 10 ? s.toFixed(1) : String(Math.round(s))) + '秒';
    var m = Math.floor(s / 60), ss = Math.round(s % 60);
    if (m < 60) return m + '分' + ss + '秒';
    return Math.floor(m / 60) + '小时' + (m % 60) + '分';
  }
  function fmtDurLong(ms) {
    var s = Math.max(0, Number(ms) || 0) / 1000;
    var m = Math.floor(s / 60);
    return m + '分' + Math.round(s % 60) + '秒';
  }
  /* token 估算：中日韩字符 ≈ 1 token，其它 ≈ 3.5 字符/token（不是官方分词器，只用于本地显示与自动压缩判断） */
  function estTokens(text) {
    var s = String(text == null ? '' : text), cjk = 0, other = 0;
    for (var i = 0; i < s.length; i++) { if (s.charCodeAt(i) >= 0x2E80) cjk++; else other++; }
    return Math.ceil(cjk + other / 3.5);
  }
  function ctxTokens() {
    var n = 0;
    for (var i = 0; i < conv.length; i++) {
      var m = conv[i];
      if (Array.isArray(m.content)) {
        m.content.forEach(function (p) {
          n += (p && p.type === 'image_url') ? 900 : estTokens(p && p.text);
        });
      } else n += estTokens(m.content);
      if (m.tool_calls) n += estTokens(JSON.stringify(m.tool_calls));
    }
    return n + 1400;   // 系统提示词（人设 + 工具清单 + 表情表）的量级
  }
  function ctxWindowSize() { return Math.max(8000, Number(cfg.contextWindow) || 1000000); }
  function renderStats() {
    var el = $('dsStats');
    if (!el) return;
    var win = ctxWindowSize(), used = ctxTokens(), pct = used / win * 100;
    var segs = [
      stats.rounds + ' 轮 · ' + stats.steps + ' 步',
      'LLM ' + fmtDurLong(stats.llmMs) + ' · 工具调用 ' + fmtDurLong(stats.toolMs),
      '首 token 平均 ' + (stats.ttftN ? (stats.ttftSum / stats.ttftN / 1000).toFixed(1) + '秒' : '—') +
        ' · ' + (stats.genMs > 0 ? Math.max(1, Math.round(stats.outTok / (stats.genMs / 1000))) + ' tok/s' : '— tok/s'),
      '缓存命中 ' + ((stats.hit + stats.miss) > 0 ? Math.round(stats.hit / (stats.hit + stats.miss) * 100) + '%' : '—'),
      '输入 ' + fmtTok(stats.inTok) + ' tok · 输出 ' + fmtTok(stats.outTok) + ' tok',
      '<span class="' + (pct >= 65 ? 'warn' : (pct >= 40 ? 'hot' : '')) + '">上下文 ' + fmtTok(used) + '/' + fmtTok(win) +
        ' · ' + (pct < 10 ? pct.toFixed(1) : Math.round(pct)) + '%' + (stats.comps ? ' · 已压缩 ' + stats.comps + ' 次' : '') + '</span>'
    ];
    el.innerHTML = segs.map(function (s) {
      return '<span class="seg">' + s + '</span>';
    }).join('<span class="sep">|</span>');
    fitStats();
  }
  /* 状态栏排不下就整条隱藏（窄窗口 / 手机上不占地方、不折行） */
  function fitStats() {
    var el = $('dsStats');
    if (!el) return;
    el.style.display = '';
    if (!el.textContent.trim()) { el.style.display = 'none'; return; }
    // 折行 => 一行放不下 => 直接藏起来（需求：自动检测空间，不够就别显示）
    var oneLine = parseFloat(getComputedStyle(el).lineHeight) || 16;
    if (el.scrollHeight > oneLine * 1.6 || el.scrollWidth > el.clientWidth + 1) el.style.display = 'none';
  }
  function sysNote(text) {
    var el = document.createElement('div');
    el.className = 'ds-status';
    el.style.padding = '2px 20px';
    el.textContent = text;
    msgArea.appendChild(el);
    scrollBottom();
    return el;
  }

  /* 「HTTP 502」这种错误本身没信息量，尽量把真正的原因翻出来
     （响应体可能是：代理的 SSE 错误帧 / 登录页 HTML / 网关错误页） */
  function describeHttpError(status, body) {
    var s = String(body || '');
    var m = /"error"\s*:\s*"((?:[^"\\]|\\.){1,200})"/.exec(s);
    if (m) return m[1].replace(/\\n/g, ' ');              // 代理自己的 SSE/JSON 错误帧
    if (/login\.php|name="password"|请登录|重新登录/i.test(s)) return '登录状态已失效（服务端回的是登录页）——刷新页面重新登录就行';
    if (status === 502 || status === 504) return '服务端网关错误（HTTP ' + status + '）：可能是 API Key 失效 / 余额不足 / 上游限流 / 服务刚重启；直接重发上一条消息一般就好';
    if (status === 401 || status === 403) return '没权限或登录已失效（HTTP ' + status + '）——刷新页面重新登录';
    if (status === 404) return '接口 404：通常是登录失效后被跳转到了不存在的 login.php ——刷新页面重新登录';
    if (status >= 500) return '服务端错误（HTTP ' + status + '）——稍后重发试试';
    var t = s.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    return 'HTTP ' + status + (t ? '：' + t.slice(0, 120) : '');
  }

  /* ================= 上下文压缩 =================
     思路（照需求）：给模型发一条「你是系统」的指令，让它输出一份详尽的交接摘要
     ——用户目前在干嘛、要 AI 干什么、已确认的结论、待办、重要参数——然后用这份
     摘要替换掉之前的历史。手动入口在 9 点菜单「压缩聊天」；上下文超过窗口 65%
     时也会自动压缩一次。 */
  var COMPRESS_ASK = [
    '【系统消息 · 这不是用户说的话，请勿回答上面的内容、也不要调用任何工具】',
    '现在执行一次「上下文压缩」：把这段对话（直到本条消息为止）整理成一份**详尽**的交接摘要，',
    '供压缩后的你（同一个助手）无缝继续为用户工作。宁可啰嗦也不要漏掉信息。必须包含：',
    '1) 用户是谁、目前在做什么、处境与目标；',
    '2) 用户要你（AI）干什么：当前任务、已经做到哪一步、下一步该做什么；',
    '3) 已经确认的事实、结论、决定、偏好、禁忌，以及用户明确纠正过你的地方；',
    '4) 未解决的问题、待确认的事项、悬而未决的分歧；',
    '5) 所有重要参数 / ID / 用户名 / 路径 / 代码片段 / 链接 / 数字，原样保留不要改写；',
    '6) 最近几轮的关键原文要点，尤其是用户最后一句话的意图。',
    '',
    '直接输出摘要正文，用小标题分节；不要客套话（别写「好的」「以下是摘要」），不要调用工具。'
  ].join('\n');

  // 只取正文的一次性调用（不画到气泡上，给压缩用）
  async function callModelOnce(msgs, maxTokens) {
    var res = await fetch('api.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: cfg.key, model: cfg.model, messages: msgs, temperature: 0.3, max_tokens: maxTokens || 4096 })
    });
    var ct = res.headers.get('content-type') || '';
    if (!res.ok && ct.indexOf('event-stream') < 0) {
      var peek = '';
      try { peek = (await res.text()).slice(0, 200); } catch (e3) {}
      throw new Error(describeHttpError(res.status, peek));
    }
    var reader = res.body.getReader(), dec = new TextDecoder(), buf = '', curEvent = '', text = '';
    for (;;) {
      var r = await reader.read();
      if (r.done) break;
      buf += dec.decode(r.value, { stream: true });
      var idx;
      while ((idx = buf.indexOf('\n')) >= 0) {
        var line = buf.slice(0, idx); buf = buf.slice(idx + 1);
        line = line.replace(/\r$/, '');
        if (line === '') { curEvent = ''; continue; }
        if (line.indexOf('event:') === 0) { curEvent = line.slice(6).trim(); continue; }
        if (line.indexOf('data:') !== 0) continue;
        var payload = line.slice(5).trim();
        if (payload === '[DONE]') continue;
        var j;
        try { j = JSON.parse(payload); } catch (e) { continue; }
        if (curEvent === 'error' || (j && j.error)) throw new Error((j && j.error) || '模型出错');
        var d = (j.choices && j.choices[0] && j.choices[0].delta) || {};
        if (d.content) text += d.content;
      }
    }
    return text;
  }

  async function compressConversation(auto) {
    if (streaming) return false;
    if (!hasKey()) { openSettings(); return false; }
    var visible = conv.filter(function (m) { return !m.hidden; }).length;
    if (visible < 4) {
      if (!auto) alert('对话还很短（' + visible + ' 条），压缩没意义 —— 至少聊几句再压。');
      return false;
    }
    var beforeTok = ctxTokens(), oldCount = conv.length;
    setStreaming(true);
    typing.textContent = '正在压缩对话…（生成交接摘要，可能要十几秒）';
    try {
      var msgs = await buildMessages();
      msgs.push({ role: 'user', content: COMPRESS_ASK });
      var summary = String(await callModelOnce(msgs) || '').trim();
      if (!summary) throw new Error('模型没有返回摘要');
      var kept = conv.slice(-2);          // 最近一轮留着，衔接更自然
      conv = [{
        role: 'user', kind: 'summary', time: nowTime(),
        content: '【对话压缩摘要 · ' + (auto ? '自动' : '手动') + '压缩，替代了之前 ' + oldCount + ' 条消息】\n\n' + summary
      }].concat(kept);
      stats.comps++;
      saveConv(); saveStats(); renderAll(); renderStats();
      sysNote('已压缩：' + oldCount + ' 条 → ' + conv.length + ' 条（摘要 ' + summary.length + ' 字；上下文约 ' +
        fmtTok(beforeTok) + ' → ' + fmtTok(ctxTokens()) + ' tok）');
      return true;
    } catch (e) {
      showError(msgArea, '压缩失败：' + ((e && e.message) || '未知错误'));
      return false;
    } finally {
      setStreaming(false);
    }
  }

  /* 9 点菜单入口（菜单是 ui.js 画的，这里给个全局函数） */
  function nineCompress() {
    if (typeof window.closeDmNineMenu === 'function') window.closeDmNineMenu();
    compressConversation(false);
  }
  window.nineCompress = nineCompress;

  /* ---------- AI 读图许可（比工具确认多两个选项） ----------
     全部允许（永久记忆）/ 本会话允许 / 允许 / 跳过。只在 AI 要读别人主页图片时出现。 */
  function askImageReadCard(info) {
    var wrap = curBubble || addAiBubble();
    var intro = newTextBlock(wrap);
    intro.innerHTML = DSUI.renderEmojiHtml(renderMd('我想看看这 ' + (info.count || 0) + ' 张图片' + (info.from ? '（来自 ' + esc(info.from) + ' 的主页）' : '') + '，你同意吗？'));

    var items = (info.items || []).slice(0, 6);
    var h = '<div class="flash-title">读取图片</div>'
      + '<div class="flash-file">' + (info.count || items.length) + ' 张<span class="ca-ask-scope">· ' + esc(info.tool || '') + '</span></div>'
      + '<div class="ca-ask-purpose">图片已经过对方隐私设置过滤；你同意后我才会读取，且只用于这次回答。</div>'
      + '<div class="ca-ask-args"><b>要读的图片</b>'
      + items.map(function (it, i) {
          var name = String(it.url || '').split('/').pop();
          return '<div class="row"><span class="k">#' + (i + 1) + '</span><span class="v">' + esc((it.time ? it.time + ' · ' : '') + name) + '</span></div>';
        }).join('')
      + '</div>'
      + '<div class="ca-ask-btns four">'
        + '<button class="ca-ask-ok" type="button" data-v="always">全部允许</button>'
        + '<button class="ca-ask-ok" type="button" data-v="session">本会话允许</button>'
        + '<button class="ca-ask-ok" type="button" data-v="once">允许</button>'
        + '<button class="ca-ask-no" type="button" data-v="skip">跳过</button>'
      + '</div>';

    var card = document.createElement('div');
    card.className = 'ca-ask';
    card.innerHTML = h;
    appendPart(wrap, card);
    wrap.querySelector('.mti').textContent = nowTime();
    scrollBottom();

    return new Promise(function (resolve) {
      var done = false;
      var labels = { always: '✓ 已全部允许（永久，可在设置里改）', session: '✓ 本会话内允许', once: '✓ 本次允许', skip: '✗ 已跳过，这次不读图' };
      Array.prototype.forEach.call(card.querySelectorAll('.ca-ask-btns button'), function (btn) {
        btn.addEventListener('click', function () {
          if (done) return;
          done = true;
          var v = btn.getAttribute('data-v');
          card.classList.add(v === 'skip' ? 'done-no' : 'done-ok');
          card.querySelector('.ca-ask-btns').innerHTML = '<span class="ca-ask-state ' + (v === 'skip' ? 'no' : 'ok') + '">' + labels[v] + '</span>';
          scrollBottom();
          resolve(v);
        });
      });
    });
  }

  /* ---------- 发送 / 流式接收 / 工具循环 ---------- */
  function setStreaming(on) {
    streaming = on;
    typing.style.display = on ? 'block' : 'none';
    typing.textContent = 'DeepSeek 正在思考…';
    sendBtn.textContent = on ? '停止' : '发送';
    if (on) scrollBottom();
  }
  function showError(el, msg) {
    var e = document.createElement('div'); e.className = 'ds-err'; e.textContent = '⚠ ' + msg;
    (el || msgArea).appendChild(e); scrollBottom();
  }

  /* ---------- 工具确认卡（AI 想要调用 ChatApp 工具时先问一句） ----------
     外观照抄闪传卡片（.flash-card 的皮肤），内容：工具名 / 用途 / 参数 / 通过·拒绝。
     返回 Promise<boolean>，由 DS.setConfirmer 接入引擎；用户拒绝 → 引擎会把
     「用户拒绝了，不要重试」回灌给模型。 */
  var curBubble = null;   // 当前正在流式输出的气泡：确认卡插进同一个气泡，读起来才连贯
  var pendingConfirms = 0;  // 正在等用户点「通过/拒绝」的工具数（>0 时不让发新消息）
  function argValHtml(k, v, desc) {
    var s = (v === null || v === undefined) ? '' : (typeof v === 'object' ? JSON.stringify(v) : String(v));
    var full = s.length > 200 ? s : '';
    var show = s.length > 80 ? (s.slice(0, 80) + '…') : s;
    return '<div class="row"><span class="k">' + esc(k) + '</span><span class="v"' +
      (full || desc ? ' title="' + esc(full || desc) + '"' : '') + '>' + esc(show || '（空）') + '</span></div>';
  }
  function askToolPermission(info) {
    var wrap = curBubble || addAiBubble();
    var intro = newTextBlock(wrap);
    intro.innerHTML = DSUI.renderEmojiHtml(renderMd('我要调用一个 ChatApp 工具，先请你确认：'));

    var scopeTxt = info.scope === 'write' ? '会改动数据' : (info.scope === 'admin' ? '管理员专用' : '只读');
    var keys = Object.keys(info.args || {});
    var h = '<div class="flash-title">工具调用申请</div>'
      + '<div class="flash-file">' + esc(info.name) + '<span class="ca-ask-scope">· ' + esc(scopeTxt) + '</span></div>'
      + '<div class="ca-ask-purpose">' + esc(String(info.purpose || '').replace(/[*`]/g, '')) + '</div>'
      + '<div class="ca-ask-args"><b>参数</b>'
      + (keys.length ? keys.map(function (k) {
          return argValHtml(k, (info.args || {})[k], (info.params || {})[k] || '');
        }).join('') : '<span style="color:#7c7c7c">（无）</span>')
      + '</div>'
      + '<div class="ca-ask-btns"><button class="ca-ask-ok" type="button">✓ 通过</button>'
      + '<button class="ca-ask-no" type="button">✗ 拒绝</button></div>';

    var card = document.createElement('div');
    card.className = 'ca-ask pending';
    card.setAttribute('data-ask-tool', info.name);
    card.innerHTML = h;
    appendPart(wrap, card);
    wrap.querySelector('.mti').textContent = nowTime();
    scrollBottom();
    pendingConfirms++;

    return new Promise(function (resolve) {
      var done = false;
      function finish(ok) {
        if (done) return;
        done = true;
        pendingConfirms = Math.max(0, pendingConfirms - 1);
        card.classList.remove('pending');
        card.classList.add(ok ? 'done-ok' : 'done-no');
        var btns = card.querySelector('.ca-ask-btns');
        btns.innerHTML = '<span class="ca-ask-state ' + (ok ? 'ok' : 'no') + '">'
          + (ok ? '✓ 已通过，执行中…' : '✗ 已拒绝，这次不会执行') + '</span>';
        scrollBottom();
        resolve(ok);
      }
      card.querySelector('.ca-ask-ok').addEventListener('click', function () { finish(true); });
      card.querySelector('.ca-ask-no').addEventListener('click', function () { finish(false); });
    });
  }

  /* 工具真的跑完之后，把确认卡上的「执行中…」换成最终结果，并收成一行（不然看着像卡死了） */
  function settleAskCards(bubble, calls, rows) {
    if (!bubble) return;
    var cards = [].slice.call(bubble.querySelectorAll('.ca-ask[data-ask-tool]'));
    var idx = 0;
    calls.forEach(function (c, i) {
      var t = DS.tool(c.call && c.call.name);
      if (!t || !DS.needsConfirm(t)) return;      // 本地工具没有确认卡，跳过
      var card = cards[idx++];
      if (!card) return;
      var st = card.querySelector('.ca-ask-state');
      var r = rows && rows[i] && rows[i].result;
      if (st) {
        if (r && r.denied) { st.textContent = '✗ 已拒绝，未执行'; st.className = 'ca-ask-state no'; }
        else if (r && r.ok) {
          var ms = (r.data && r.data._ms != null) ? r.data._ms + 'ms' : '完成';
          st.textContent = '✓ 已执行（' + ms + '）';
          st.className = 'ca-ask-state ok';
        } else {
          st.textContent = '✗ 失败：' + ((r && r.error) || '未知错误');
          st.className = 'ca-ask-state no';
          card.classList.add('done-no');
        }
      }
      card.classList.add('settled');
      card.title = '点一下可以展开/收起参数';
      if (!card._askToggle) {
        card._askToggle = true;
        card.addEventListener('click', function () { card.classList.toggle('settled'); });
      }
    });
  }

  // 系统提示词 = 人设（默认或用户自定义）+ 工具协议/清单 + 实时上下文
  // 带图片的消息 → content 变成 [{type:'text'},{type:'image_url'}]（视觉输入）
  async function buildMessages() {
    var memCount = 0;
    try { memCount = Object.keys(DS.memory.all() || {}).length; } catch (e) {}
    var msgs = [{
      role: 'system',
      content: DS.buildSystemPrompt(cfg, {
        username: USER.username || '', uid: USER.uid || 0, isAdmin: !!USER.isAdmin,
        lang: document.documentElement.lang || navigator.language || '',
        memCount: memCount
      })
    }];
    for (var i = 0; i < conv.length; i++) {
      var m = conv[i];
      var imgs = [];
      if (m.role === 'user') {
        if (m.images && m.images.length) imgs = imgs.concat(m.images);
        if (String(m.content || '').indexOf('[emoji:') >= 0) {
          try { imgs = imgs.concat(await DSUI.emojiImages(m.content)); } catch (e) {}
        }
      }
      if (imgs.length) {
        var parts = [];
        if (m.content) parts.push({ type: 'text', text: m.content });
        imgs.slice(0, 4).forEach(function (u) { parts.push({ type: 'image_url', image_url: { url: u } }); });
        msgs.push({ role: m.role, content: parts });
      } else if (m.tool_calls) {                     // 原生 function calling 的历史调用
        msgs.push({ role: 'assistant', content: m.content || '', tool_calls: m.tool_calls });
      } else if (m.role === 'tool') {                // 原生调用的结果
        msgs.push({ role: 'tool', tool_call_id: m.tool_call_id, content: String(m.content || '') });
      } else {
        msgs.push({ role: m.role, content: m.content });
      }
    }
    return msgs;
  }

  // 跑一轮 assistant 输出：正文流式上屏，<tool> 块被扣下来变成工具卡
  async function streamTurn() {
    var b = addAiBubble();
    curBubble = b;
    var reasonEl = b.querySelector('.ds-reason');
    var parser = DS.createStreamParser();
    var acc = DS.createToolCallAccumulator();
    var raw = '', reasoning = '', curText = null, curAcc = '', calls = [];
    var ctrl = new AbortController();
    aborter = ctrl;
    var reqT0 = Date.now(), firstTokAt = 0, useUsage = null;   // 状态栏用

    function handle(evs) {
      evs.forEach(function (ev) {
        if (ev.type === 'text') {
          if (!curText) curText = newTextBlock(b);
          curAcc += ev.text;
          DSUI.paintHtml(curText, DSUI.renderEmojiHtml(renderMd(DSUI.normalizeEmojiHtml(curAcc))));
          raw += ev.text;
        } else {
          curText = null; curAcc = '';
          var card = DS.toolCard(ev);
          appendPart(b, card);
          calls.push({ call: ev, card: card });
          raw += '<tool>' + ev.raw + '</tool>';
        }
      });
      scrollBottom();
    }

    try {
      var schemas = (cfg.tools === false) ? [] : DS.toolSchemas();
      var reqBody = { key: cfg.key, model: cfg.model, messages: await buildMessages(), temperature: cfg.temp, max_tokens: cfg.maxTokens };
      if (schemas.length) reqBody.tools = schemas;      // 原生 function calling（模型就不会自己乱写标签了）
      var res = await fetch('api.php', {
        method: 'POST', credentials: 'same-origin', signal: ctrl.signal,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(reqBody)
      });
      if (!res.ok && res.headers.get('content-type') && res.headers.get('content-type').indexOf('event-stream') === -1) {
        // 非 SSE 的错误响应（网关 502 / 登录失效的登录页 / 反代错误页）→ 尽量把原因说清楚
        var peek = '';
        try { peek = (await res.text()).slice(0, 200); } catch (e2) {}
        throw new Error(describeHttpError(res.status, peek));
      }
      var reader = res.body.getReader(), dec = new TextDecoder(), buf = '', curEvent = '';
      while (true) {
        var r = await reader.read();
        if (r.done) break;
        buf += dec.decode(r.value, { stream: true });
        var idx;
        while ((idx = buf.indexOf('\n')) >= 0) {
          var line = buf.slice(0, idx); buf = buf.slice(idx + 1);
          line = line.replace(/\r$/, '');
          if (line === '') { curEvent = ''; continue; }
          if (line.indexOf('event:') === 0) { curEvent = line.slice(6).trim(); continue; }
          if (line.indexOf('data:') !== 0) continue;
          var payload = line.slice(5).trim();
          if (payload === '[DONE]') continue;
          var j;
          try { j = JSON.parse(payload); } catch (e) { continue; }
          if (curEvent === 'error' || j.error) throw new Error(j.error || 'DeepSeek 出错');
          if (j.usage) useUsage = j.usage;                  // 上游最后一个块带的用量（含缓存命中）
          var delta = (j.choices && j.choices[0] && j.choices[0].delta) || {};
          if (delta.reasoning_content) {
            reasoning += delta.reasoning_content;
            reasonEl.style.display = '';
            // 思维链里可能夹着模型自创的调用标记（DSML / use_tool）→ 清掉再显示，不要当乱码吐给用户
            reasonEl.textContent = DS.stripProtocol(reasoning);
          }
          if (delta.tool_calls) acc.feed(delta.tool_calls);   // 原生 function calling
          if (delta.content) {
            if (!firstTokAt) firstTokAt = Date.now();         // 首个正文 token 的延迟
            handle(parser.feed(delta.content));
          }
        }
      }
      handle(parser.end());
      // 原生调用排在最后：卡片按顺序追加到气泡里
      acc.end().forEach(function (call) {
        var c2 = DS.toolCard(call);
        appendPart(b, c2);
        calls.push({ call: call, card: c2 });
      });
    } catch (e) {
      // 「停止」触发的中断：保留已生成的部分，不当作错误
      if (!(e && (e.name === 'AbortError' || ctrl.signal.aborted))) throw e;
    } finally {
      try { handle(parser.end()); } catch (e2) {}
      Array.prototype.forEach.call(b.querySelectorAll('.mt'), function (el) {
        if (!el.textContent.trim() && !el.querySelector('img')) el.remove();
      });
      b.querySelector('.mti').textContent = nowTime();
      if (aborter === ctrl) aborter = null;
      // ---- 状态栏统计 ----
      var now = Date.now();
      stats.llmMs += now - reqT0;
      if (firstTokAt) {
        stats.ttftSum += firstTokAt - reqT0;
        stats.ttftN++;
        stats.genMs += now - firstTokAt;
      }
      if (useUsage) {
        stats.inTok += Number(useUsage.prompt_tokens || 0);
        stats.outTok += Number(useUsage.completion_tokens || 0);
        stats.hit += Number(useUsage.prompt_cache_hit_tokens || useUsage.prompt_cache_hit || 0);
        stats.miss += Number(useUsage.prompt_cache_miss_tokens || useUsage.prompt_cache_miss || 0);
      }
      saveStats(); renderStats();
    }
    return { raw: raw, calls: calls, aborted: ctrl.signal.aborted, bubble: b };
  }

  // agent 循环：模型调用工具 → 执行 → 回灌结果 → 模型继续（最多 5 轮）
  async function agentLoop() {
    var MAX_ROUNDS = 5, used = 0;
    for (;;) {
      setStreaming(true);
      var out;
      try {
        out = await streamTurn();
      } catch (e) {
        setStreaming(false);
        showError(msgArea, (e && e.message) ? e.message : '请求失败');
        return;
      }
      setStreaming(false);

      if (out.raw.trim() || out.calls.length) {
        stats.rounds++;
        var ent = { role: 'assistant', content: out.raw, time: nowTime() };
        var nats = out.calls.filter(function (c) { return c.call.native && c.call.id; });
        if (nats.length) {                               // 原生调用要入历史，下一轮才能对上 tool_call_id
          ent.tool_calls = nats.map(function (c) {
            return { id: c.call.id, type: 'function', function: { name: c.call.name, arguments: JSON.stringify(c.call.args || {}) } };
          });
        }
        conv.push(ent);
        saveConv();
        out.nativeIds = nats.map(function (c) { return c.call.id; });
      }      if (out.aborted || !out.calls.length) return;
      if (used >= MAX_ROUNDS - 1) {
        addStatus(out.bubble, '已达到单轮工具调用上限（' + MAX_ROUNDS + ' 轮），先停在这里');
        return;
      }
      used++;

      var st = addStatus(out.bubble, '正在执行 ' + out.calls.length + ' 个工具…');
      var rows;
      if (cfg.tools === false) {
        rows = out.calls.map(function (c) {
          return { name: c.call.name, args: c.call.args, result: { ok: false, error: '用户已关闭工具，请直接用已有信息回答' } };
        });
      } else {
        rows = await DS.executeAll(out.calls.map(function (c) { return c.call; }));
      }
      var okCount = 0;
      rows.forEach(function (row, i) {
        DS.fillToolCard(out.calls[i].card, row.result);
        if (row.result.ok) okCount++;
      });
      settleAskCards(out.bubble, out.calls, rows);   // 确认卡上的「执行中…」→ 最终结果
      stats.steps += rows.length;
      rows.forEach(function (row) { stats.toolMs += Number((row.result && row.result._ms) || 0); });
      // 工具读回来的图片（ca_space）：交给模型看，但绝不能把 base64 写进历史 JSON
      var visionRows = [];
      rows.forEach(function (row) {
        var d = row.result && row.result.data;
        if (d && Array.isArray(d._vision) && d._vision.length) {
          visionRows.push({ images: d._vision.slice(0, 4), from: (d.user && d.user.name) || '' });
          delete d._vision;
        }
      });
      saveStats(); renderStats();
      st.textContent = '工具返回 ' + okCount + '/' + rows.length + ' 成功';
      scrollBottom();

      // 结果回灌：原生调用 → role:tool 消息（带 tool_call_id）；文本协议的调用 → 隐藏 user 消息
      var textRows = [], nativeRows = [];
      out.calls.forEach(function (c, i) {
        if (c.call.native && c.call.id) nativeRows.push({ call: c.call, row: rows[i] });
        else textRows.push(rows[i]);
      });
      nativeRows.forEach(function (x) {
        conv.push({ role: 'tool', tool_call_id: x.call.id, content: JSON.stringify(x.row.result), hidden: true, time: nowTime() });
      });
      if (textRows.length) {
        conv.push({ role: 'user', content: DS.resultMessage(textRows), hidden: true, kind: 'tool_result' });
      }
      // 图片以「用户消息 + image_url」的形式喂给模型（工具消息不能带图）
      visionRows.forEach(function (v) {
        var parts = [{ type: 'text', text: '（这是你刚读取的图片' + (v.from ? '，来自 ' + v.from + ' 的主页' : '') + '，已经过用户同意）' }];
        v.images.forEach(function (u) { parts.push({ type: 'image_url', image_url: { url: u } }); });
        conv.push({ role: 'user', content: parts, hidden: true, kind: 'vision', time: nowTime() });
      });
      saveConv();
    }
  }

  async function send() {
    if (streaming) { if (aborter) aborter.abort(); return; }   // 生成中再点 = 停止
    // 还有工具在等用户确认时不让发新消息：否则会开两个循环，卡成一团
    if (pendingConfirms > 0) {
      sysNote('还有 ' + pendingConfirms + ' 个工具调用在等你确认 —— 点卡片上的「通过」或「拒绝」再继续。');
      return;
    }
    var att = DSUI.getAttachment();
    var text = input.value.trim();
    if (!text && !att) return;
    if (!hasKey()) { openSettings(); return; }

    // 上下文超过窗口的 65% → 先自动压缩（避免越聊越糊 / 超限）
    var win = ctxWindowSize(), usedTok = ctxTokens();
    if (usedTok > win * 0.65) {
      sysNote('上下文已到 ' + Math.round(usedTok / win * 100) + '%（阈值 65%），先自动压缩…');
      await compressConversation(true);
    }

    input.value = ''; autoResize();
    var entry = { role: 'user', content: text, time: nowTime() };
    if (att) entry.images = [att.url];
    conv.push(entry);
    addUserBubble(entry.content, entry.images);
    DSUI.setAttachment(null);
    saveConv();
    await agentLoop();
  }

  /* ---------- 输入框 ---------- */
  function autoResize() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 200) + 'px';
  }

  /* ---------- 事件绑定 ---------- */
  $('dsSettingsBtn').addEventListener('click', openSettings);
  $('dsKeyWarnLink').addEventListener('click', openSettings);
  $('dsSettingsCancel').addEventListener('click', closeSettings);
  $('dsSettingsSave').addEventListener('click', saveSettings);
  $('dsLoadDefault').addEventListener('click', function () {
    $('dsSystem').value = DS.persona();
  });
  $('dsKeyClear').addEventListener('click', function () {
    if (!confirm('清除本机保存的 API Key？清除后需要重新填写才能使用。')) return;
    cfg.key = '';
    $('dsKey').value = '';
    saveCfg();
    refreshKeyWarn();
    $('dsKeyState').textContent = '⚠ 已清除，请重新填写';
  });
  $('dsToolsDefault').addEventListener('click', function () {
    cfg.toolPrefs = DS.defaultEnabledMap();
    DS.setEnabled(cfg.toolPrefs);
    renderToolList();
  });
  $('dsToolsAll').addEventListener('click', function () {
    var m = {};
    DS.TOOLS.forEach(function (t) { m[t.name] = !DS.unavailableReason(t); });
    DS.setEnabled(m);
    renderToolList();
  });
  $('dsToolsNone').addEventListener('click', function () {
    var m = {};
    DS.TOOLS.forEach(function (t) { m[t.name] = false; });
    DS.setEnabled(m);
    renderToolList();
  });
  $('dsClearBtn').addEventListener('click', function () {
    if (!confirm('清空当前对话记录与运行统计？（本地记忆不受影响）')) return;
    if (aborter) aborter.abort();
    conv = []; saveConv(); renderAll(); resetStats();
  });
  sendBtn.addEventListener('click', send);
  input.addEventListener('input', autoResize);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });

  load(); refreshKeyWarn(); refreshBadge(); renderAll(); autoResize(); renderStats();
  window.addEventListener('resize', fitStats);   // 窗口变窄时状态栏自动隐藏
  DS.setConfirmer(askToolPermission);      // 所有 ca_* 工具执行前先弹确认卡
  DS.setImageConsent(askImageReadCard);    // 读图片时弹「全部允许 / 本会话 / 允许 / 跳过」
  // 表情库异步到达：到了就把历史重渲染一遍，让 /斜眼笑 这类代码变成表情图
  if (window.DSUI && DSUI.onEmojiListReady) DSUI.onEmojiListReady(function () { if (!streaming) renderAll(); });
  loadServerPrefs();   // 登录态 / 管理员身份 / 账号级权限（服务端为准）
  try { DSUI.init(); } catch (e) { console.error('[ds] ui init failed', e); }
})();
</script>
</body>
</html>
