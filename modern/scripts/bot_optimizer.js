/* ============================================================
 * 伪人「人格优化」窗口（P2）
 *
 * 和伪人的聊天完全独立：这里对话的是「优化器 AI」，它负责维护人格模型。
 *  - 它能提问（尤其是看不懂的表情包）→ 聊天流里出卡片：灰色「跳过」/ 绿色「提交」
 *  - 它给出的改动一律先显示 diff，点「应用」才写库（action=optimize_apply）
 *  - 会话记录只存在 localStorage（chatapp_opt_<bot>），不写 messages 表，
 *    所以不会污染伪人的人格，也不会出现在私聊里
 * ============================================================ */

var _optUser = '';        // 当前优化窗口对应的机器人 username
var _optBusy = false;

function optStoreKey(u) { return 'chatapp_opt_' + u; }
function optLoad(u) {
    try { return JSON.parse(localStorage.getItem(optStoreKey(u)) || '[]') || []; } catch (e) { return []; }
}
function optSave(u, list) {
    try { localStorage.setItem(optStoreKey(u), JSON.stringify(list.slice(-60))); } catch (e) {}
}

/* 通用 SSE POST：event: xxx + data: {...} → onEvent(name, json) */
function ssePost(url, body, onEvent) {
    return fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    }).then(function (res) {
        if (!res.ok || !res.body) throw new Error('HTTP ' + res.status);
        var reader = res.body.getReader(), dec = new TextDecoder(), buf = '', cur = '';
        function pump() {
            return reader.read().then(function (r) {
                if (r.done) return;
                buf += dec.decode(r.value, { stream: true });
                var i;
                while ((i = buf.indexOf('\n')) >= 0) {
                    var line = buf.slice(0, i).replace(/\r$/, '');
                    buf = buf.slice(i + 1);
                    if (line === '') { cur = ''; continue; }
                    if (line.indexOf('event:') === 0) { cur = line.slice(6).trim(); continue; }
                    if (line.indexOf('data:') !== 0) continue;
                    var p = line.slice(5).trim();
                    if (p === '' || p === '[DONE]') continue;
                    var j = null;
                    try { j = JSON.parse(p); } catch (e) { continue; }
                    if (onEvent(cur || 'message', j) === false) return;
                }
                return pump();
            });
        }
        return pump();
    });
}

function openBotOptimizer() {
    var u = D;
    if (!u || !_bots[u]) return;
    _optUser = u;
    var modal = document.getElementById('botOptModal');
    if (!modal) return;
    document.getElementById('botOptTitle').textContent = '🧠 人格优化器 · ' + (_bots[u].display_name || u);
    modal.classList.add('active');
    optRender();
    // 拉一次最新人格（顺带把顶部摘要和窗口标题写准）
    optRefreshInfo(u).then(function () {
        if (_optUser !== u) return;
        if (_botInfo[u] && _botInfo[u].profile) optLoadEmojiQuestions(true);
    });
}

function closeBotOptimizer() {
    var modal = document.getElementById('botOptModal');
    if (modal) modal.classList.remove('active');
}

/* 重新拉一次机器人信息（应用 patch 后刷新顶部摘要） */
function optRefreshInfo(u) {
    return botApi({ action: 'get', username: u }).then(function (d) {
        if (d && d.success && d.bot) {
            _botInfo[u] = _botInfo[u] || {};
            _botInfo[u].profile = d.bot.profile;
            _botInfo[u].has_profile = !!d.bot.has_profile;
            _botInfo[u].kind = d.bot.kind;
            _botInfo[u].target_uid = d.bot.target_uid;
            if (u === _optUser) {
                var title = document.getElementById('botOptTitle');
                if (title) title.textContent = '🧠 人格优化器 · ' + (d.bot.display_name || u);
                var sub = document.getElementById('botOptSub');
                var p = d.bot.profile;
                if (sub) sub.textContent = p
                    ? ('人格模型：' + ((p.habits || []).length) + ' 条习惯 · 表情 ' + ((p.emoji_meanings || []).length) + ' 个' + (p.samples ? ' · ' + p.samples + ' 条样本' : ''))
                    : '还没有人格模型 —— 先去私聊窗口里让它分析一次（或点「重新分析」）';
            }
        }
    }).catch(function () {});
}

function optAdd(entry) {
    var u = _optUser;
    var list = optLoad(u);
    entry.ts = Date.now();
    entry.id = 'o' + Math.random().toString(36).slice(2, 9);
    list.push(entry);
    optSave(u, list);
    optRender();
    return entry.id;
}

function optUpdate(id, patch) {
    var list = optLoad(_optUser);
    for (var i = 0; i < list.length; i++) {
        if (list[i].id === id) { for (var k in patch) list[i][k] = patch[k]; break; }
    }
    optSave(_optUser, list);
    optRender();
}

function optRender() {
    var box = document.getElementById('botOptChat');
    if (!box) return;
    var list = optLoad(_optUser);
    if (!list.length) {
        box.innerHTML = '<div class="opt-empty">这里对话的是<strong>优化器</strong>（不是伪人本人）。<br>'
            + '它能看图学习、帮你补全人格模型，改动都会先给你看 diff 再应用。<br><br>'
            + '可以问它：「这个人说话最明显的特征是什么？」<br>'
            + '或者点下面的「表情包待补全」——把 ta 常用的表情含义告诉它。</div>';
        return;
    }
    var html = list.map(function (e) {
        if (e.type === 'user') return '<div class="opt-msg me">' + eh(e.text) + '</div>';
        if (e.type === 'bot') {
            var extra = (e.what ? '<div class="opt-why">' + eh(e.what) + '</div>' : '');
            var parts = String(e.text || '').split(/@@/).map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
            if (!parts.length) return '';
            return parts.map(function (p, i) {
                return '<div class="opt-msg bot">' + eh(p) + (i === parts.length - 1 ? extra : '') + '</div>';
            }).join('');
        }
        if (e.type === 'note') return '<div class="opt-note">' + eh(e.text) + '</div>';
        if (e.type === 'think') {
            return '<div class="opt-note think"><div class="opt-think-head" onclick="this.parentNode.classList.toggle(\'open\')">'
                + '🧠 思考过程（' + ((e.text || '').length) + ' 字）<span class="opt-cnt">点开看</span></div>'
                + '<div class="opt-think-body">'
                + (e.text ? '<div class="opt-think-live">' + eh(e.text) + '</div>' : '')
                + (e.json ? '<pre class="opt-raw">' + eh(e.json) + '</pre>' : '')
                + '</div></div>';
        }
        if (e.type === 'q') return optQCardHtml(e);
        if (e.type === 'diff') return optDiffCardHtml(e);
        if (e.type === 'mem') return optMemCardHtml(e);
        if (e.type === 'done') return '<div class="opt-note ok">' + eh(e.text) + '</div>';
        if (e.type === 'err') return '<div class="opt-note err">' + eh(e.text) + (e.raw ? '<pre class="opt-raw">' + eh(e.raw) + '</pre>' : '') + '</div>';
        return '';
    }).join('');
    box.innerHTML = html;
    box.scrollTop = box.scrollHeight;
}

/* ---- 表情包提问卡：灰色「跳过」+ 绿色「提交」+ 「AI 猜」预设 ---- */
function optStickerHtml(code) {
    if (!code) return '';
    if (/^[a-f0-9]{32}$/.test(code)) {   // 自定义贴图
        return '<img src="../../api/emoji.php?action=img&hash=' + code + '" class="chat-emoji" alt="贴图">';
    }
    return renderEmojiHtml(renderMd(code));
}

function optQCardHtml(e) {
    if (e.state === 'skipped') return '<div class="opt-card done"><div class="opt-q">已跳过' + (e.code ? ' ' + eh(e.code) : '') + '</div></div>';
    if (e.state === 'sent') return '<div class="opt-card done ok"><div class="opt-q">已记录 ' + (e.code || '') + '：' + eh(e.answer || '') + '</div></div>';
    var head = e.code
        ? '<div class="opt-q">' + optStickerHtml(e.code) + (e.count ? ' <span class="opt-cnt">用了 ' + e.count + ' 次</span>' : '') + '</div>'
        : '';
    var ex = (e.examples && e.examples.length)
        ? '<div class="opt-ex">' + e.examples.map(function (s) { return '「' + eh(s) + '」'; }).join('<br>') + '</div>' : '';
    return '<div class="opt-card" data-qid="' + e.id + '">'
        + head
        + '<div class="opt-qt">' + eh(e.text || '') + '</div>'
        + ex
        + '<textarea class="opt-in" rows="2" placeholder="' + (e.code ? '比如：无语但不想撕破脸' : '尽量说具体一点') + '">' + eh(e.answer || '') + '</textarea>'
        + '<div class="opt-acts">'
        + '<button class="opt-btn gray" onclick="optSkip(\'' + e.id + '\')">跳过</button>'
        + '<button class="opt-btn guess" onclick="optGuess(\'' + e.id + '\')" title="让 AI 根据聊天上下文先猜一个，你再改">🤖 AI 猜</button>'
        + '<button class="opt-btn green" onclick="optSubmit(\'' + e.id + '\')">提交</button>'
        + '</div></div>';
}

/* AI 猜预设：让优化器根据上下文先猜，填进输入框（你不满意就改或跳过） */
function optGuess(id) {
    var card = document.querySelector('.opt-card[data-qid="' + id + '"]');
    if (!card || card.dataset.busy === '1') return;
    var cfg = {};
    try { cfg = JSON.parse(localStorage.getItem('chatapp_ds_cfg') || '{}') || {}; } catch (e) { cfg = {}; }
    if (!cfg.key) { xalert('AI 猜也要用你的 DeepSeek API Key（在 /apps/deepseek/ 设置里填）。'); return; }
    var list = optLoad(_optUser);
    var e = null;
    for (var i = 0; i < list.length; i++) if (list[i].id === id) e = list[i];
    if (!e) return;
    card.dataset.busy = '1';
    var btn = card.querySelector('.opt-btn.guess');
    if (btn) { btn.disabled = true; btn.textContent = '🤖 猜…'; }
    botApi({ action: 'emoji_guess', username: _optUser, key: cfg.key, model: cfg.model || 'deepseek-v4-flash',
             code: e.code || '', examples: JSON.stringify(e.examples || []) }).then(function (d) {
        card.dataset.busy = '';
        if (btn) { btn.disabled = false; btn.textContent = '🤖 AI 猜'; }
        if (!d || !d.success) { xalert('猜不出来：' + ((d && d.error) || '未知错误')); return; }
        var ta = card.querySelector('.opt-in');
        if (ta) { ta.value = d.guess; ta.focus(); }
        var hint = card.querySelector('.opt-hint');
        if (!hint) { hint = document.createElement('div'); hint.className = 'opt-ex opt-hint'; card.insertBefore(hint, card.querySelector('.opt-acts')); }
        hint.textContent = '🤖 AI 猜的（可信度 ' + (d.confidence != null ? d.confidence : '?') + '）—— 改完再点提交';
    }).catch(function (err) {
        card.dataset.busy = '';
        if (btn) { btn.disabled = false; btn.textContent = '🤖 AI 猜'; }
        xalert('猜不出来：' + ((err && err.message) || '网络错误'));
    });
}

function optSkip(id) {
    optUpdate(id, { state: 'skipped' });
}

function optSubmit(id) {
    var card = document.querySelector('.opt-card[data-qid="' + id + '"]');
    var val = card ? (card.querySelector('.opt-in').value || '').trim() : '';
    if (!val) { xalert('先写点内容再提交吧（或者点「跳过」）'); return; }
    var list = optLoad(_optUser);
    var e = null;
    for (var i = 0; i < list.length; i++) if (list[i].id === id) e = list[i];
    if (!e) return;
    var patch = e.code ? { emoji_meanings: [{ code: e.code, meaning: val }] } : { habits: [val] };
    botApi({ action: 'optimize_apply', username: _optUser, patch: JSON.stringify(patch) }).then(function (d) {
        if (!d || !d.success) { xalert('保存失败：' + ((d && d.error) || '未知错误')); return; }
        optUpdate(id, { state: 'sent', answer: val });
        optAdd({ type: 'done', text: e.code ? ('已写入表情含义：' + e.code + ' = ' + val) : ('已加入习惯：' + val) });
        optRefreshInfo(_optUser);
    }).catch(function (err) { xalert('保存失败：' + ((err && err.message) || '网络错误')); });
}

/* ---- 改动确认卡：diff + 灰色「忽略」/ 绿色「应用」 ---- */
function optDiffLines(patch, profile) {
    var out = [];
    var prof = profile || (_botInfo[_optUser] && _botInfo[_optUser].profile) || {};
    for (var k in patch) {
        if (k === 'samples' || k === 'updated_at') continue;
        var v = patch[k];
        if (Array.isArray(v) && k === 'emoji_meanings') {
            var old = {}, i;
            var cur = Array.isArray(prof.emoji_meanings) ? prof.emoji_meanings : [];
            for (i = 0; i < cur.length; i++) if (cur[i] && cur[i].code) old[cur[i].code] = cur[i].meaning || '';
            for (i = 0; i < v.length; i++) {
                var code = (v[i] && v[i].code) || '', mean = (v[i] && v[i].meaning) || '';
                if (old[code] !== undefined) out.push({ op: old[code] === mean ? '=' : '~', text: code + '：' + (old[code] ? old[code] + ' → ' : '') + mean });
                else out.push({ op: '+', text: code + '：' + mean });
            }
        } else if (Array.isArray(v)) {
            var have = Array.isArray(prof[k]) ? prof[k].map(function (x) { return typeof x === 'string' ? x : (x && x.text) || ''; }) : [];
            for (var j = 0; j < v.length; j++) {
                var s = typeof v[j] === 'string' ? v[j] : ((v[j] && v[j].text) || JSON.stringify(v[j]));
                out.push({ op: have.indexOf(s) >= 0 ? '=' : '+', text: k + ' · ' + s });
            }
        } else if (v && typeof v === 'object') {
            for (var kk in v) {
                var oldV = (prof[k] || {})[kk];
                out.push({ op: (oldV === undefined ? '+' : (String(oldV) === String(v[kk]) ? '=' : '~')), text: k + '.' + kk + '：' + (oldV !== undefined && String(oldV) !== String(v[kk]) ? String(oldV) + ' → ' : '') + String(v[kk]) });
            }
        } else {
            out.push({ op: prof[k] === undefined ? '+' : '~', text: k + '：' + (prof[k] !== undefined && String(prof[k]) !== String(v) ? String(prof[k]) + ' → ' : '') + String(v) });
        }
    }
    return out;
}

function optDiffCardHtml(e) {
    if (e.state === 'applied') return '<div class="opt-card done ok"><div class="opt-q">已应用改动</div>' + optDiffBodyHtml(e.lines) + '</div>';
    if (e.state === 'ignored') return '<div class="opt-card done"><div class="opt-q">已忽略这组改动</div></div>';
    return '<div class="opt-card" data-qid="' + e.id + '">'
        + '<div class="opt-q">建议改动' + (e.why ? '<span class="opt-cnt">' + eh(e.why) + '</span>' : '') + '</div>'
        + optDiffBodyHtml(e.lines)
        + '<div class="opt-acts">'
        + '<button class="opt-btn gray" onclick="optIgnore(\'' + e.id + '\')">忽略</button>'
        + '<button class="opt-btn green" onclick="optApply(\'' + e.id + '\')">应用</button>'
        + '</div></div>';
}

function optDiffBodyHtml(lines) {
    if (!lines || !lines.length) return '<div class="opt-ex">（没有实际变化）</div>';
    var map = { '+': ['add', '＋'], '~': ['chg', '～'], '=': ['same', '＝'] };
    return '<div class="opt-diff">' + lines.map(function (l) {
        var m = map[l.op] || map['+'];
        return '<div class="opt-dline ' + m[0] + '"><span class="opt-dop">' + m[1] + '</span>' + eh(l.text) + '</div>';
    }).join('') + '</div>';
}

function optIgnore(id) { optUpdate(id, { state: 'ignored' }); }

/* ---- 🧷 记忆点：关于 ta 的具体事实（可手写、可删；聊天时会用到） ---- */
function optShowMemories() {
    if (!_optUser) return;
    botApi({ action: 'memory', username: _optUser }).then(function (d) {
        if (!d || !d.success) { xalert('读取失败：' + ((d && d.error) || '未知错误')); return; }
        var list = optLoad(_optUser);
        var facts = d.facts || [];
        var runtime = ((d.state && d.state.memories) || []).map(function (m) { return (m && m.text) || m; });
        var id = optAdd({
            type: 'mem', facts: facts, runtime: runtime,
            mood: (d.state && d.state.mood) || '',
            text: '🧷 记忆点'
        });
    }).catch(function (e) { xalert('读取失败：' + ((e && e.message) || '网络错误')); });
}

function optMemCardHtml(e) {
    var facts = e.facts || [];
    var runtime = (e.runtime || []).filter(function (t) { return !!t; });
    var h = '<div class="opt-q">🧷 ta 的记忆点' + (e.mood ? '<span class="opt-cnt">当前心情：' + eh(e.mood) + '</span>' : '') + '</div>';
    h += '<div class="opt-ex">写在这儿的事实会写进人格（bot_profile.facts），伪人聊天时会自然用到。</div>';
    if (facts.length) {
        h += '<div class="opt-mem">' + facts.map(function (f, i) {
            return '<div class="opt-mem-row"><span class="opt-mem-x" onclick="optMemDel(' + i + ')" title="删除">✕</span>' + eh(f.text) + (f.at ? '<span class="opt-cnt">' + eh(f.at) + '</span>' : '') + '</div>';
        }).join('') + '</div>';
    } else {
        h += '<div class="opt-ex">（还没有，先写一条吧）</div>';
    }
    if (runtime.length) {
        h += '<div class="opt-ex" style="margin-top:6px">伪人自己攒的（只读，存 bot_state）：<br>'
           + runtime.slice(-8).map(function (t) { return '「' + eh(t) + '」'; }).join('<br>') + '</div>';
    }
    h += '<textarea class="opt-in opt-mem-add" rows="1" placeholder="比如：养了只猫叫团子 / 生日 3 月 8 号 / 讨厌被叫全名"></textarea>'
       + '<div class="opt-acts"><button class="opt-btn green" onclick="optMemAdd()">＋ 记住这条</button></div>';
    return '<div class="opt-card" data-mem="1">' + h + '</div>';
}

function optMemAdd() {
    var box = document.querySelector('#botOptChat .opt-card[data-mem] textarea.opt-mem-add');
    var val = box ? (box.value || '').trim() : '';
    if (!val) { xalert('写点什么再记吧'); return; }
    botApi({ action: 'memory', username: _optUser, op: 'add', text: val }).then(function (d) {
        if (!d || !d.success) { xalert('保存失败：' + ((d && d.error) || '未知错误')); return; }
        // 把那张卡刷新成最新列表
        var list = optLoad(_optUser);
        for (var i = list.length - 1; i >= 0; i--) if (list[i].type === 'mem') { list.splice(i, 1); break; }
        optSave(_optUser, list);
        optAdd({ type: 'done', text: '已记住：' + val });
        optShowMemories();
        optRefreshInfo(_optUser);
    }).catch(function (e) { xalert('保存失败：' + ((e && e.message) || '网络错误')); });
}

function optMemDel(i) {
    botApi({ action: 'memory', username: _optUser, op: 'del', index: i }).then(function (d) {
        if (!d || !d.success) { xalert('删除失败：' + ((d && d.error) || '未知错误')); return; }
        var list = optLoad(_optUser);
        for (var k = list.length - 1; k >= 0; k--) if (list[k].type === 'mem') { list.splice(k, 1); break; }
        optSave(_optUser, list);
        optShowMemories();
    }).catch(function (e) { xalert('删除失败：' + ((e && e.message) || '网络错误')); });
}

function optApply(id) {
    var list = optLoad(_optUser);
    var e = null;
    for (var i = 0; i < list.length; i++) if (list[i].id === id) e = list[i];
    if (!e || !e.patch) return;
    botApi({ action: 'optimize_apply', username: _optUser, patch: JSON.stringify(e.patch) }).then(function (d) {
        if (!d || !d.success) { xalert('应用失败：' + ((d && d.error) || '未知错误')); return; }
        optUpdate(id, { state: 'applied' });
        optAdd({ type: 'done', text: '人格已更新（' + e.lines.filter(function (l) { return l.op === '+'; }).length + ' 项新增，' + e.lines.filter(function (l) { return l.op === '~'; }).length + ' 项修改）' });
        optRefreshInfo(_optUser);
    }).catch(function (err) { xalert('应用失败：' + ((err && err.message) || '网络错误')); });
}

/* ---- 表情包待补全：确定性生成卡片（不花 token） ---- */
function optLoadEmojiQuestions(silent) {
    if (!_optUser) return;
    botApi({ action: 'emoji_questions', username: _optUser }).then(function (d) {
        if (!d || !d.success) { if (!silent) xalert('读取失败：' + ((d && d.error) || '')); return; }
        var qs = d.questions || [];
        var list = optLoad(_optUser);
        var known = {};
        list.forEach(function (e) { if (e.type === 'q' && e.code) known[e.code] = true; });
        var added = 0;
        qs.forEach(function (q) {
            if (known[q.code]) return;
            optAdd({
                type: 'q', code: q.code, count: q.count, examples: q.examples || [],
                text: 'ta 发 ' + q.code + ' 的时候，一般是想表达什么？'
            });
            added++;
        });
        if (!added && !silent) optAdd({ type: 'note', text: 'ta 常用的表情都已经有人格含义了 ✅' });
        else if (!added) optAdd({ type: 'note', text: 'ta 常用的表情含义都补完了 ✅' });
    }).catch(function (err) { if (!silent) xalert('读取失败：' + ((err && err.message) || '网络错误')); });
}

/* ---- 发消息给优化器 ---- */
function optSend() {
    if (_optBusy || !_optUser) return;
    var ta = document.getElementById('botOptInput');
    var msg = (ta.value || '').trim();
    if (!msg) return;
    var cfg = {};
    try { cfg = JSON.parse(localStorage.getItem('chatapp_ds_cfg') || '{}') || {}; } catch (e) { cfg = {}; }
    if (!cfg.key) { xalert('优化器和伪人一样，用的是你自己的 DeepSeek API Key —— 先去 /apps/deepseek/ 设置里填一下。'); return; }

    ta.value = '';
    optAdd({ type: 'user', text: msg });
    var noteId = optAdd({ type: 'note', text: '优化器正在读人格模型与聊天样本…' });
    _optBusy = true;

    // 实时区：直接改这个 note 节点的 DOM（不走 localStorage，不然每个字都写一次）
    var live = { think: '', json: '', el: null };
    (function () {
        var all = document.querySelectorAll('#botOptChat .opt-note');
        live.el = all.length ? all[all.length - 1] : null;
    })();
    function paintLive(title) {
        if (!live.el) return;
        var h = '<div class="opt-live-title">' + eh(title) + '</div>';
        if (live.think) h += '<div class="opt-think-live">' + eh(live.think) + '</div>';
        if (live.json) h += '<pre class="opt-raw">' + eh(live.json) + '</pre>';
        live.el.innerHTML = h;
        var box = document.getElementById('botOptChat');
        if (box) box.scrollTop = box.scrollHeight;
    }

    var hist = optLoad(_optUser).filter(function (e) { return e.type === 'user' || e.type === 'bot'; })
        .slice(-8).map(function (e) { return { role: e.type === 'user' ? 'user' : 'bot', text: e.text }; });

    ssePost('../../api/bots.php', {
        action: 'optimize', username: _optUser, key: cfg.key, model: cfg.model || 'deepseek-v4-flash',
        stream: 1, message: msg, history: JSON.stringify(hist)
    }, function (name, j) {
        if (name === 'note') { paintLive(j.text || '优化器正在读人格模型与聊天样本…'); return; }
        if (name === 'stage') { paintLive(j.note || '重问一次…'); return; }
        if (name === 'think') { live.think += (j.delta || ''); paintLive('🧠 优化器在想… ' + live.think.length + ' 字'); return; }
        if (name === 'json') { live.json += (j.delta || ''); paintLive('🧠 优化器在想… ' + live.think.length + ' 字'); return; }
        if (name === 'error' || (j && j.error)) {
            _optBusy = false;
            optUpdate(noteId, { type: 'err', text: '优化器出错：' + (j.error || '未知错误'),
                raw: (j.raw || live.json || '') + (live.think ? "\n\n[思考过程]\n" + live.think : '') });
            return false;
        }
        if (name === 'done') {
            _optBusy = false;
            var list = optLoad(_optUser);
            for (var i = list.length - 1; i >= 0; i--) if (list[i].id === noteId) { list.splice(i, 1); break; }
            optSave(_optUser, list);
            if (live.think || live.json) optAdd({ type: 'think', text: live.think, json: live.json });
            if (j.reply) optAdd({ type: 'bot', text: j.reply, what: j.why || (j.repaired ? '（重问过一轮才拿到 JSON）' : '') });
            (j.questions || []).forEach(function (q) {
                optAdd({ type: 'q', code: q.code || '', field: q.field || 'habit', text: q.text || '' });
            });
            if (j.patch && Object.keys(j.patch).length) {
                var lines = optDiffLines(j.patch, _botInfo[_optUser] && _botInfo[_optUser].profile);
                var changed = lines.filter(function (l) { return l.op !== '='; });
                if (changed.length) optAdd({ type: 'diff', patch: j.patch, lines: changed, why: j.why || '' });
                else optAdd({ type: 'note', text: '优化器建议的内容和现有人格一致，不需要改。' });
            }
            return false;
        }
    }).catch(function (err) {
        _optBusy = false;
        optUpdate(noteId, { type: 'err', text: '优化器出错：' + ((err && err.message) || '网络错误'), raw: live.json || '' });
    });
}

function optInputKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); optSend(); }
}

/* 「让优化器体检」：把人格模型发过去，让它找问题/给建议 */
function optAskCheck() {
    var info = _botInfo[_optUser] || {};
    if (!info.profile) { xalert('还没有人格模型 —— 先在私聊窗口里让它分析一次（或点「重新分析」）。'); return; }
    var p = info.profile;
    var empty = [];
    if (!p.summary) empty.push('summary');
    if (!(p.habits || []).length) empty.push('habits');
    if (!(p.psych || {}).attitude_to_others) empty.push('psych.attitude_to_others');
    if (!(p.emoji_meanings || []).length) empty.push('emoji_meanings');
    if (!(p.taboos || []).length) empty.push('taboos');
    var inp = document.getElementById('botOptInput');
    inp.value = '帮我体检一下这套人格模型：哪里太笼统、哪里可能不准？'
        + (empty.length ? '（这几个字段现在还是空的或很粗糙：' + empty.join('、') + '，你直接提个修改建议）' : '')
        + ' 需要改的话直接给 patch。';
    optSend();
}
