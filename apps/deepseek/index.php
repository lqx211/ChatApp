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
  html,body{margin:0;height:100%;background:#1a1a1a;color:#e0e0e0;
    font-family:Roboto,-apple-system,"system-ui","Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",Arial,sans-serif}
  .ds-wrap{display:flex;flex-direction:column;height:100%}
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
</style>
</head>
<body>
<script src="../../modern/scripts/markdown.js?v=<?php echo $v;?>"></script>

<div class="ds-wrap">
  <div class="ch">
    <h2><span class="ds-dot"></span>Deepseek</h2>
    <button class="bsm" id="dsSettingsBtn" type="button">设置</button>
    <button class="bsm" id="dsClearBtn" type="button">清空</button>
  </div>
  <div class="ds-key-warn" id="dsKeyWarn" style="display:none">
    还没填 DeepSeek API Key —— 点「<a id="dsKeyWarnLink">这里</a>」填写后即可开聊（Key 只存在你浏览器本地）。
  </div>
  <div class="ma" id="aiMessages"><div class="es"><p>和 DeepSeek 聊聊吧～</p></div></div>
  <div class="typing-indicator" id="aiTyping">DeepSeek 正在输入…</div>
  <div class="cia">
    <textarea id="aiInput" rows="1" placeholder="输入消息…（Enter 发送，Shift+Enter 换行）" style="resize:none;overflow-y:auto;line-height:1.4;max-height:12em"></textarea>
    <button class="bs" id="aiSendBtn" type="button">发送</button>
  </div>
</div>

<div class="modal-overlay" id="dsSettingsModal">
  <div class="modal-box">
    <h3>DeepSeek 设置</h3>
    <div class="ds-field">
      <label>API Key（仅存本机浏览器，不上传服务器保存）</label>
      <input type="password" id="dsKey" placeholder="sk-..." autocomplete="off">
    </div>
    <div class="ds-field">
      <label>模型</label>
      <select id="dsModel">
        <option value="deepseek-chat">deepseek-chat（通用对话）</option>
        <option value="deepseek-reasoner">deepseek-reasoner（推理/思考）</option>
        <option value="deepseek-v4-flash">deepseek-v4-flash</option>
        <option value="deepseek-v4-pro">deepseek-v4-pro</option>
      </select>
    </div>
    <div class="ds-field">
      <label>系统提示词（角色设定，可留空）</label>
      <textarea id="dsSystem" placeholder="You are a helpful assistant."></textarea>
    </div>
    <div class="ds-row2">
      <div class="ds-field"><label>温度 (0~2)</label><input type="number" id="dsTemp" min="0" max="2" step="0.1" value="1"></div>
      <div class="ds-field"><label>最大回复长度</label><input type="number" id="dsMaxTokens" min="1" max="8192" step="1" value="2048"></div>
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
  var cfg = { key: '', model: 'deepseek-chat', system: '', temp: 1, maxTokens: 2048 };
  var conv = [];            // [{role:'user'|'assistant', content}]
  var streaming = false;

  var $ = function (id) { return document.getElementById(id); };
  var msgArea = $('aiMessages'), input = $('aiInput'), sendBtn = $('aiSendBtn'),
      typing = $('aiTyping'), keyWarn = $('dsKeyWarn');

  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
  function load() {
    try { var c = JSON.parse(localStorage.getItem(LS_CFG) || '{}'); if (c && typeof c === 'object') cfg = Object.assign(cfg, c); } catch (e) {}
    try { var v = JSON.parse(localStorage.getItem(LS_CONV) || '[]'); if (Array.isArray(v)) conv = v; } catch (e) {}
  }
  function saveCfg() { try { localStorage.setItem(LS_CFG, JSON.stringify(cfg)); } catch (e) {} }
  function saveConv() { try { localStorage.setItem(LS_CONV, JSON.stringify(conv)); } catch (e) {} }

  function hasKey() { return !!(cfg.key && cfg.key.trim()); }
  function refreshKeyWarn() { keyWarn.style.display = hasKey() ? 'none' : ''; }

  function clearEmpty() { var e = msgArea.querySelector('.es'); if (e) e.remove(); }
  function scrollBottom() { msgArea.scrollTop = msgArea.scrollHeight; }

  // 用户气泡（靠右，与私聊 .mr.own 一致）
  function addUserBubble(text) {
    clearEmpty();
    var r = document.createElement('div');
    r.className = 'mr own';
    r.innerHTML = '<div class="mc"><div class="mb"><div class="mt"></div><div class="mti"></div></div></div>';
    r.querySelector('.mt').textContent = text;
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

  function renderAll() {
    msgArea.innerHTML = '';
    if (!conv.length) {
      msgArea.innerHTML = '<div class="es"><p>和 DeepSeek 聊聊吧～</p></div>';
      return;
    }
    conv.forEach(function (m) {
      if (m.role === 'user') addUserBubble(m.content);
      else { var b = addAiBubble(); b.querySelector('.mt').innerHTML = renderMd(m.content); b.querySelector('.mti').textContent = nowTime(); }
    });
    scrollBottom();
  }

  /* ---------- 设置 ---------- */
  function openSettings() {
    $('dsKey').value = cfg.key || '';
    $('dsModel').value = cfg.model || 'deepseek-chat';
    $('dsSystem').value = cfg.system || '';
    $('dsTemp').value = (cfg.temp != null ? cfg.temp : 1);
    $('dsMaxTokens').value = (cfg.maxTokens || 2048);
    $('dsSettingsModal').classList.add('active');
    setTimeout(function () { try { $('dsKey').focus(); } catch (e) {} }, 50);
  }
  function closeSettings() { $('dsSettingsModal').classList.remove('active'); }
  function saveSettings() {
    cfg.key = $('dsKey').value.trim();
    cfg.model = $('dsModel').value;
    cfg.system = $('dsSystem').value;
    var t = parseFloat($('dsTemp').value); cfg.temp = isNaN(t) ? 1 : Math.max(0, Math.min(2, t));
    var mt = parseInt($('dsMaxTokens').value, 10); cfg.maxTokens = isNaN(mt) ? 2048 : Math.max(1, Math.min(8192, mt));
    saveCfg(); refreshKeyWarn(); closeSettings();
  }

  /* ---------- 发送 / 流式接收 ---------- */
  function setStreaming(on) {
    streaming = on; sendBtn.disabled = on; typing.style.display = on ? 'block' : 'none';
    if (on) scrollBottom();
  }
  function showError(el, msg) {
    var e = document.createElement('div'); e.className = 'ds-err'; e.textContent = '⚠ ' + msg;
    (el || msgArea).appendChild(e); scrollBottom();
  }

  async function send() {
    if (streaming) return;
    var text = input.value.trim();
    if (!text) return;
    if (!hasKey()) { openSettings(); return; }

    input.value = ''; autoResize();
    conv.push({ role: 'user', content: text });
    addUserBubble(text); saveConv();

    var msgs = [];
    if (cfg.system && cfg.system.trim()) msgs.push({ role: 'system', content: cfg.system });
    conv.forEach(function (m) { msgs.push({ role: m.role, content: m.content }); });

    var b = addAiBubble();
    var mtEl = b.querySelector('.mt'), reasonEl = b.querySelector('.ds-reason');
    var acc = '', reasoning = '';
    setStreaming(true);

    try {
      var res = await fetch('api.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: cfg.key, model: cfg.model, messages: msgs, temperature: cfg.temp, max_tokens: cfg.maxTokens })
      });
      if (!res.ok && res.headers.get('content-type') && res.headers.get('content-type').indexOf('event-stream') === -1) {
        throw new Error('HTTP ' + res.status);
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
          var delta = (j.choices && j.choices[0] && j.choices[0].delta) || {};
          if (delta.reasoning_content) {
            reasoning += delta.reasoning_content;
            reasonEl.style.display = '';
            reasonEl.textContent = reasoning;
          }
          if (delta.content) {
            acc += delta.content;
            mtEl.innerHTML = renderMd(acc);
            scrollBottom();
          }
        }
      }
    } catch (e) {
      var b2 = b.querySelector('.mb');
      if (acc) { mtEl.innerHTML = renderMd(acc); }
      else { b.remove(); }
      showError(msgArea, (e && e.message) ? e.message : '请求失败');
    } finally {
      setStreaming(false);
      b.querySelector('.mti').textContent = nowTime();
      if (acc) { conv.push({ role: 'assistant', content: acc }); saveConv(); }
    }
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
  $('dsClearBtn').addEventListener('click', function () {
    if (!confirm('清空当前对话记录？')) return;
    conv = []; saveConv(); renderAll();
  });
  sendBtn.addEventListener('click', send);
  input.addEventListener('input', autoResize);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
  });

  load(); refreshKeyWarn(); renderAll(); autoResize();
})();
</script>
</body>
</html>
