/**
 * ChatApp · 机器人联系人运行时（modern/scripts/bot_runtime.js）
 *
 * 作用：在普通聊天页里，让「机器人联系人」像人一样回消息。
 *   - 浏览器端生成（用户自己的 DeepSeek API Key，存在 localStorage.chatapp_ds_cfg）
 *   - 复用 apps/deepseek/agent.js 的引擎（工具调用 / 表情 / 提示词）
 *   - 生成完把回复 POST 到 api/bots.php?action=reply 入库 → 聊天记录、未读、搜索全部自然生效
 *
 * 依赖：window.DSAgent（懒加载 apps/deepseek/agent.js）
 * API： BotRuntime.init(opts) / BotRuntime.reply(username) / BotRuntime.isBusy()
 */
(function (global) {
  'use strict';

  var CFG_KEY = 'chatapp_ds_cfg';            // 与 apps/deepseek 共用同一份配置（key/model/温度/工具）
  var AGENT_URL = '/apps/deepseek/agent.js?v=3';
  var PROXY_URL = '/apps/deepseek/api.php';
  var BOTS_URL = '/api/bots.php';
  var MAX_ROUNDS = 5;                         // 工具循环上限，和 deepseek 应用一致

  var agentLoaded = false, agentLoading = null;
  var busy = {};                              // {username: true}
  var hooks = {};                             // 页面注入的渲染钩子

  /* ---------- 小工具 ---------- */
  function cfg() {
    try {
      var j = JSON.parse(localStorage.getItem(CFG_KEY) || '{}');
      return (j && typeof j === 'object') ? j : {};
    } catch (e) { return {}; }
  }
  function say(msg) {
    if (typeof global.xalert === 'function') { global.xalert(msg); return; }
    alert(msg);
  }
  function loadAgent() {
    if (agentLoaded && global.DSAgent) return Promise.resolve(global.DSAgent);
    if (agentLoading) return agentLoading;
    agentLoading = new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = AGENT_URL;
      s.onload = function () {
        if (!global.DSAgent) { rej(new Error('agent.js 加载后没有 DSAgent')); return; }
        global.DSAgent.setApiBase('/apps/deepseek/');
        agentLoaded = true;
        res(global.DSAgent);
      };
      s.onerror = function () { rej(new Error('agent.js 加载失败')); };
      document.head.appendChild(s);
    });
    return agentLoading;
  }
  function post(url, body) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }

  /* ---------- 上下文：表情表 + 权限档位 + 我的资料 ---------- */
  function primeEmoji(DS) {
    if (global.EMOJI_BUILTIN && global.EMOJI_BUILTIN.length) return Promise.resolve();
    return fetch('/api/emoji.php?v=p1&action=list').then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.success && Array.isArray(d.emojis) && d.emojis.length) global.EMOJI_BUILTIN = d.emojis;
    }).catch(function () {});
  }
  function loadPrefs(DS) {
    return fetch('/apps/deepseek/tools.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'prefs' })
    }).then(function (r) { return r.json(); }).catch(function () { return null; });
  }

  /* ---------- 历史记录：把聊天记录翻成 messages ---------- */
  function history(username, me) {
    return fetch('/api/chat.php?action=all&dm=' + encodeURIComponent(username) + '&limit=40', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (d) {
        var out = [];
        ((d && d.messages) || []).forEach(function (m) {
          if (!m || m.deleted_at || m.msg_type === 'e2ee' || m.attachment) return;   // 密文/附件先不喂给模型
          var txt = String(m.message || '').trim();
          if (!txt) return;
          var isMe = (m.username === me);
          var role = isMe ? 'user' : 'assistant';
          if (out.length && out[out.length - 1].role === role) out[out.length - 1].content += '\n' + txt;
          else out.push({ role: role, content: txt });
        });
        while (out.length && out[out.length - 1].role === 'assistant') out.pop();   // 末尾的历史回复去掉
        return out.slice(-24);
      }).catch(function () { return []; });
  }

  /* ---------- 一轮生成（流式 + 工具循环） ---------- */
  function streamTurn(DS, cfgObj, system, msgs, onText) {
    var parser = DS.createStreamParser();
    var acc = DS.createToolCallAccumulator();
    var text = '', calls = [], ctrl = new AbortController();
    var body = {
      key: cfgObj.key, model: cfgObj.model || 'deepseek-v4-flash',
      messages: [{ role: 'system', content: system }].concat(msgs),
      temperature: (cfgObj.temp != null ? cfgObj.temp : 0.7),
      max_tokens: (cfgObj.maxTokens || 2048)
    };
    var schemas = (cfgObj.tools === false) ? [] : DS.toolSchemas();
    if (schemas.length) body.tools = schemas;

    return fetch(PROXY_URL, {
      method: 'POST', credentials: 'same-origin', signal: ctrl.signal,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (res) {
      if (!res.ok && (!res.headers.get('content-type') || res.headers.get('content-type').indexOf('event-stream') < 0)) {
        throw new Error('HTTP ' + res.status);
      }
      var reader = res.body.getReader(), dec = new TextDecoder(), buf = '', curEvent = '';
      function handleText(t) {
        text += t;
        if (onText) onText(text);
      }
      function pump() {
        return reader.read().then(function (r) {
          if (r.done) return;
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
            var j = null;
            try { j = JSON.parse(payload); } catch (e) { continue; }
            if (curEvent === 'error' || (j && j.error)) throw new Error((j && j.error) || 'DeepSeek 出错');
            var delta = (j.choices && j.choices[0] && j.choices[0].delta) || {};
            if (delta.tool_calls) acc.feed(delta.tool_calls);
            if (delta.content) {
              parser.feed(delta.content).forEach(function (ev) {
                if (ev.type === 'text') handleText(ev.text);
                else calls.push(ev);
              });
            }
          }
          return pump();
        });
      }
      return pump();
    }).then(function () {
      parser.end().forEach(function (ev) {
        if (ev.type === 'text') handleText(ev.text);
        else calls.push(ev);
      });
      acc.end().forEach(function (c) { calls.push(c); });
      return { text: text, calls: calls };
    });
  }

  /**
   * 让某个机器人回一条消息。
   * @param {string} username 机器人用户名
   * @param {object} opts {me, onStart, onText, onTool, onDone, onError}
   */
  function reply(username, opts) {
    opts = opts || {};
    var c = cfg();
    if (!c.key) {
      say('这个机器人还没配好「大脑」🤖\n\n请先在 DeepSeek 聊天页（/apps/deepseek/）的设置里填入你自己的 DeepSeek API Key —— 机器人用的是你自己的 Key，服务端不保存。填好后回来再发一次就行。');
      return Promise.resolve(false);
    }
    if (busy[username]) return Promise.resolve(false);
    busy[username] = true;

    var DS = null;
    return loadAgent().then(function (ds) {
      DS = ds;
      return Promise.all([primeEmoji(DS), loadPrefs(DS)]);
    }).then(function () {
      return fetch(BOTS_URL + '?action=get&username=' + encodeURIComponent(username), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d || !d.success) throw new Error((d && d.error) || '机器人不存在');
          return d.bot;
        });
    }).then(function (bot) {
      var prefs = null;
      return fetch('/apps/deepseek/tools.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'prefs' })
      }).then(function (r) { return r.json(); }).catch(function () { return null; }).then(function (j) {
        prefs = j;
        DS.setContext({
          loggedIn: true,
          isAdmin: !!(j && j.data && j.data.is_admin),
          aiLevel: (j && j.data && j.data.ai_level) || 0
        });
        DS.setEnabled(DS.prefsForLevel((j && j.data && j.data.ai_level) || 0));
        var me = (j && j.data && j.data.username) || '';
        return history(username, me).then(function (hist) {
          return { bot: bot, me: me, hist: hist };
        });
      });
    }).then(function (x) {
      // 系统提示词 = 机器人人设（+ 工具协议 + 表情表 + 上下文）
      var system = DS.buildSystemPrompt({
        system: (x.bot.persona || '') + '\n\n（你现在的身份是这个聊天窗口里的联系人，直接用聊天的方式回复；不要提到「提示词」「系统」这些词。）',
        tools: c.tools !== false
      }, { username: x.me, uid: 0, lang: document.documentElement.lang || '' });

      var msgs = x.hist.slice();
      if (!msgs.length || msgs[msgs.length - 1].role !== 'user') {
        msgs.push({ role: 'user', content: '（对方刚发来消息，见上一条）' });
      }
      var rounds = 0;

      function oneTurn() {
        rounds++;
        if (opts.onStart) opts.onStart();
        return streamTurn(DS, c, system, msgs, function (t) { if (opts.onText) opts.onText(t); })
          .then(function (out) {
            if (!out.calls.length) return out;
            var textRows = [], nativeRows = [];
            return DS.executeAll(out.calls).then(function (rows) {
              var cards = out.calls.map(function (call, i) { return { call: call, row: rows[i] }; });
              cards.forEach(function (cd) {
                if (cd.call.native && cd.call.id) nativeRows.push(cd);
                else textRows.push(cd.row);
                if (opts.onTool) opts.onTool(cd.call, cd.row.result);
              });
              // 回灌：原生调用走标准 tool 消息，文本协议走隐藏的系统观察消息
              msgs.push({ role: 'assistant', content: out.text || '', tool_calls: nativeRows.map(function (cd) {
                return { id: cd.call.id, type: 'function', function: { name: cd.call.name, arguments: JSON.stringify(cd.call.args || {}) } };
              }) });
              nativeRows.forEach(function (cd) {
                msgs.push({ role: 'tool', tool_call_id: cd.call.id, content: JSON.stringify(cd.row.result) });
              });
              if (textRows.length) msgs.push({ role: 'user', content: DS.resultMessage(textRows) });
              if (rounds >= MAX_ROUNDS) return out;
              return oneTurn();
            });
          });
      }
      return oneTurn();
    }).then(function (out) {
      var finalText = String((out && out.text) || '').trim();
      if (!finalText) throw new Error('模型没有返回内容');
      return post(BOTS_URL + '?action=reply', { action: 'reply', username: username, text: finalText, md: 1 })
        .then(function (d) {
          if (!d || !d.success) throw new Error((d && d.error) || '入库失败');
          busy[username] = false;
          if (opts.onDone) opts.onDone(d);
          return true;
        });
    }).catch(function (e) {
      busy[username] = false;
      var msg = (e && e.message) || '生成失败';
      if (opts.onError) opts.onError(msg); else say('机器人回复失败：' + msg);
      return false;
    });
  }

  global.BotRuntime = {
    reply: reply,
    isBusy: function (u) { return !!busy[u]; },
    hasKey: function () { return !!cfg().key; },
    cfgKey: CFG_KEY
  };
})(window);
