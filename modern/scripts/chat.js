var U, TZ, DND, RSTR, DS, L = 0,
    P = null,
    S = false,
    D = null,
    typingTimer = null;
var _dmE2eeOn = false; // 当前 DM 会话的 E2EE 共享开关（发送时据此加密）
var seenMsgIds = {};
var pendingMedia = null,
    pendingDmMedia = null;
var unreadCounts = {};
var attFile = null,
    attSendFn = null,
    attBtnId = null;
var repTarget = null;
var _replyTarget = null,
    _replyData = null;
var admSelUser = null;
var _sidebarProfileSaved = null,
    _sidebarNavSaved = null;
var _contactNotes = {};
var _pinned = {};        // username -> 1/0（联系人置顶）
var _pinnedGroup = {};   // group_id -> 1/0（群置顶）
var _pinnedSelf = (typeof window.MYSELF_PIN !== 'undefined') ? (window.MYSELF_PIN ? 1 : 0) : 1; // 自己聊天置顶（默认置顶）
var _loaded = false;
var _msgSearchPage = 1, _msgSearchQ = '', _dmLoading = false, _dmOldest = 0, _annLoading = false, _grpLoading = false, _grpOldest = 0;

/**
 * ChatApp — 统一 API 通信层（POST → WSS，失败自动降级 HTTP）
 *
 * 路由表：已迁移到 WSS 的 action 走 wssRequest，其余全部走原始 HTTP。
 * 规则：
 *   - 附件请求（attachment/temp_upload_id）永远强制 HTTP，不进 WSS
 *   - WSS 未连接 / 请求超时(3s) / 断线 / 服务端报错(FORCE_HTTP 等) → 自动降级原始 HTTP
 *   - 降级后 HTTP 失败仍由调用方原有 catch 处理，保证功能永可用
 *
 * @param {string} action  action 名（send/revoke/mark_read/unread_counts 等）
 * @param {Object} params  参数对象（与 HTTP POST body 同字段）
 * @param {Object} [opts]  {timeoutMs, forceHttp}
 * @returns {Promise<Object>} 服务器 JSON 响应
 */
function apiRequest(action, params, opts) {
    opts = opts || {};
    var paramsObj = params || {};
    // 附件/闪传：强制 HTTP（经 Cloudflare HTTPS 有保障，且避免阻塞 WSS 单线程）
    var hasAttachment = !!(paramsObj.attachment || paramsObj.temp_upload_id);
    var route = { send: 'ws', revoke: 'ws', mark_read: 'ws', unread_counts: 'ws' }[action] || 'http';
    // 幂等：给 send 生成一次性 client_msg_id，WSS 与 HTTP 重试共用同一个键，
    // 服务端据此去重 —— 防止「WSS 已插入但响应超时 → 降级 HTTP 再插一次」导致对方收到两条相同消息
    if (action === 'send' && !paramsObj.client_msg_id) {
        paramsObj.client_msg_id = 'c' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
    }
    var useWs = !opts.forceHttp && !hasAttachment && route === 'ws' &&
        typeof window.wssRequest === 'function' && window.wssRequestAvailable();

    function httpFallback() {
        // 构造与原始调用完全一致的 POST body（application/x-www-form-urlencoded）
        var f = new URLSearchParams();
        f.append('action', action);
        for (var k in paramsObj) {
            if (Object.prototype.hasOwnProperty.call(paramsObj, k) && paramsObj[k] !== undefined && paramsObj[k] !== null) {
                f.append(k, paramsObj[k]);
            }
        }
        return fetch('../../api/chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: f.toString()
        }).then(function(r) { return r.json(); });
    }

    if (!useWs) return httpFallback();

    return window.wssRequest(action, paramsObj, opts.timeoutMs || 3000).then(function(d) {
        // WSS 服务端返回 FORCE_HTTP（附件/闪传被拒）→ 降级 HTTP
        if (d && d.success === false && d.error === 'FORCE_HTTP') return httpFallback();
        // WSS 返回原始错误码，统一翻译（HTTP 路径已在 api/chat.php 翻译）
        if (d && d.success === false && d.error === 'not_friends') {
            d.error = T('msg_not_friends', 'You can only send messages to your friends.');
        }
        if (d && d.success === false && d.error === 'blocked') {
            d.error = T('msg_blocked', 'You cannot send messages to this user.');
        }
        if (d && d.success === false && d.error === 'not_accepting') {
            d.error = T('msg_not_accepting', 'This user is not accepting friend requests.');
        }
        return d;
    }).catch(function() {
        // WSS 不可用/超时/断线 → 降级 HTTP
        return httpFallback();
    });
}

function T(key, fallback) {
    if (typeof LANG !== 'undefined' && LANG && LANG[key]) return LANG[key];
    return fallback !== undefined ? fallback : key;
}


function fmtSize(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1048576).toFixed(2) + ' MB';
}

function safeMdUrl(url) {
    var u = String(url || '').trim();
    if (/^https?:\/\//i.test(u)) return u;
    if (/^data:image\/(png|jpe?g|gif|webp);/i.test(u)) return u;
    return '#';
}

function renderMd(text) {
    // HTML-escape BEFORE markdown processing so every captured fragment
    // (alt text, link text, URL) is safe to emit inside tags/attributes.
    // The img/a replacements below additionally whitelist URL schemes.
    var h = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    var lines = h.split('\n');
    var out = [];

    // ---- fenced code blocks (with generic syntax highlighting) ----
    var inCode = false, codeLang = '', codeBuf = [];
    function flushCode() {
        var lang = codeLang || 'text';
        out.push('<div class="codebox"><pre><code class="language-' + lang + '">' + hlCode(codeBuf.join('\n')) + '</code></pre></div>');
        inCode = false; codeLang = ''; codeBuf = [];
    }

    // ---- nested lists (ul/ol via indentation) ----
    var listStack = []; // {type:'ul'|'ol', indent, liOpen}
    var listHtml = '', inList = false;
    function popList() {
        var top = listStack.pop();
        if (top.liOpen) listHtml += '</li>';
        listHtml += '</' + top.type + '>';
        if (listStack.length && listStack[listStack.length - 1].liOpen) {
            listHtml += '</li>';
            listStack[listStack.length - 1].liOpen = false;
        }
    }
    function listItem(type, indent, content) {
        if (!inList) { inList = true; listHtml = ''; }
        while (listStack.length && listStack[listStack.length - 1].indent > indent) popList();
        if (listStack.length && listStack[listStack.length - 1].indent === indent && listStack[listStack.length - 1].type !== type) popList();
        if (!listStack.length || listStack[listStack.length - 1].indent < indent || listStack[listStack.length - 1].type !== type) {
            listStack.push({ type: type, indent: indent, liOpen: false });
            listHtml += '<' + type + '>';
        }
        if (listStack[listStack.length - 1].liOpen) { listHtml += '</li>'; listStack[listStack.length - 1].liOpen = false; }
        listHtml += '<li>' + mdInline(content);
        listStack[listStack.length - 1].liOpen = true;
    }
    function flushList() {
        while (listStack.length) popList();
        if (inList) { out.push(listHtml); inList = false; listHtml = ''; }
    }

    // ---- nested blockquotes ----
    var qDepth = 0, quoteHtml = '', inQuote = false;
    function openQ(n) { while (qDepth < n) { quoteHtml += '<blockquote>'; qDepth++; } }
    function closeQ(n) { while (qDepth > n) { quoteHtml += '</blockquote>'; qDepth--; } }
    function flushQuote() {
        if (inQuote) { closeQ(0); out.push(quoteHtml); inQuote = false; quoteHtml = ''; }
    }
    function quoteDepthOf(line) {
        // The message was HTML-escaped before parsing, so a literal ">" marker
        // is now the entity &gt; (a user-typed "&gt;" becomes &amp;gt;, so no
        // false positives). Count leading "&gt;" to derive the quote depth and
        // the byte offset where the quoted content begins.
        var d = 0, j = 0;
        while (j < line.length) {
            if (line.substr(j, 4) === '&gt;') { d++; j += 4; }
            else if (line.charAt(j) === ' ' && d > 0 && line.substr(j + 1, 4) === '&gt;') { j++; }
            else break;
        }
        return { d: d, j: j };
    }

    // ---- tables ----
    function isTableSep(l) { return /^\s*\|?[\s:\-|]+\|?\s*$/.test(l) && l.indexOf('-') !== -1; }
    function mdRow(line, tag) {
        var s = line.trim().replace(/^\|/, '').replace(/\|$/, '');
        var cells = s.split('|');
        var html = '';
        for (var k = 0; k < cells.length; k++) html += '<' + tag + '>' + mdInline(cells[k].trim()) + '</' + tag + '>';
        return html;
    }

    for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        var m;

        if (/^```/.test(line)) {
            flushList(); flushQuote();
            if (inCode) { flushCode(); }
            else { inCode = true; codeLang = line.replace(/^```\s*/, '').trim().split(/\s+/)[0] || ''; codeBuf = []; }
            continue;
        }
        if (inCode) { codeBuf.push(line); continue; }

        // headings
        if ((m = line.match(/^### (.+)/))) { flushList(); flushQuote(); out.push('<h4>' + mdInline(m[1]) + '</h4>'); continue; }
        if ((m = line.match(/^## (.+)/))) { flushList(); flushQuote(); out.push('<h3>' + mdInline(m[1]) + '</h3>'); continue; }
        if ((m = line.match(/^# (.+)/))) { flushList(); flushQuote(); out.push('<h2>' + mdInline(m[1]) + '</h2>'); continue; }
        if (/^---+\s*$/.test(line)) { flushList(); flushQuote(); out.push('<hr>'); continue; }

        // table: header line followed by a separator line
        if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && isTableSep(lines[i + 1])) {
            flushList(); flushQuote();
            var th = mdRow(line, 'th');
            i++; // skip the separator row
            var body = '';
            while (i + 1 < lines.length && /^\s*\|.*\|\s*$/.test(lines[i + 1]) && !isTableSep(lines[i + 1])) {
                i++;
                body += '<tr>' + mdRow(lines[i], 'td') + '</tr>';
            }
            out.push('<table><thead><tr>' + th + '</tr></thead><tbody>' + body + '</tbody></table>');
            continue;
        }

        // blockquote (consecutive lines grouped, `>` depth = nesting)
        var qq = quoteDepthOf(line);
        var qd = qq.d;
        if (qd > 0) {
            flushList();
            if (!inQuote) { inQuote = true; quoteHtml = ''; }
            var qc = line.slice(qq.j);
            if (qc.charAt(0) === ' ') qc = qc.slice(1);
            openQ(qd);
            quoteHtml += mdInline(qc) + '<br>';
            continue;
        }
        flushQuote();

        // list item (leading-space indentation → nesting)
        var liM = line.match(/^(\s*)([\-\*]|\d+\.)\s+(.+)/);
        if (liM) {
            flushQuote();
            var indent = liM[1].replace(/\t/g, '  ').length;
            var type = /^\d/.test(liM[2]) ? 'ol' : 'ul';
            listItem(type, indent, liM[3]);
            continue;
        }
        flushList();

        if (line === '') continue; // blank line: separator only

        out.push(mdInline(line));
    }
    if (inCode) flushCode();
    flushList();
    flushQuote();
    return brNewlines(out.join('\n'));
}

// ---- markdown helpers ----

/** Inline tokens (applied to plain lines, headings, list items, blockquotes,
 *  table cells). Code spans are protected first so nothing formats inside them. */
function mdInline(text) {
    var codes = [];
    text = text.replace(/`([^`]+)`/g, function(m0, c) {
        codes.push('<code>' + c + '</code>');
        return '\u0001' + (codes.length - 1) + '\u0001';
    });
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/\*(.+?)\*/g, '<em>$1</em>');
    // images → links → bare http(s) autolink, in one ordered pass (alternation
    // consumes [..](..) before a bare URL inside it can match).
    text = text.replace(/!\[([^\]]*)\]\(([^)]+)\)|\[([^\]]+)\]\(([^)]+)\)|(https?:\/\/[^\s<"']+)/g, function(m0, imgAlt, imgUrl, linkText, linkUrl, bareUrl) {
        if (imgAlt !== undefined) {
            return '<img src="' + safeMdUrl(imgUrl) + '" alt="' + imgAlt + '" style="max-width:100%;max-height:200px">';
        }
        if (linkUrl !== undefined) {
            return '<a href="' + safeMdUrl(linkUrl) + '" target="_blank" rel="noopener noreferrer">' + linkText + '</a>';
        }
        var u = bareUrl.replace(/[.,;:!?)]+$/, '');
        return '<a href="' + safeMdUrl(u) + '" target="_blank" rel="noopener noreferrer">' + u + '</a>';
    });
    return text.replace(/\u0001(\d+)\u0001/g, function(m0, idx) { return codes[+idx] || ''; });
}

/** Generic lightweight syntax highlighter for fenced code blocks: comments,
 *  strings, numbers, keywords, PHP vars, and function calls. Works over the
 *  already-escaped code text, so the wrapped spans stay safe. */
function hlCode(code) {
    var RE = /(\/\/[^\n]*|\/\*[\s\S]*?\*\/|#[^\n]*)|("(?:[^"\\\n]|\\.)*"|'(?:[^'\\\n]|\\.)*'|`(?:[^`\\\n]|\\.)*`)|\b(0x[0-9a-fA-F]+|\d+(?:\.\d+)?)\b|\b(const|let|var|function|return|if|else|for|while|do|switch|case|break|continue|new|delete|typeof|instanceof|class|extends|super|import|export|from|default|async|await|yield|try|catch|finally|throw|def|lambda|True|False|None|and|or|not|in|is|pass|raise|with|as|print|echo|require|require_once|include|include_once|foreach|endforeach|endif|elseif|array|public|private|protected|static|void|int|float|double|bool|boolean|string|char|short|long|unsigned|signed|struct|enum|union|typedef|namespace|using|NULL|true|false|null|this|self|global|local|fn|match|select|insert|update|delete|from|where|join|inner|left|right|outer|group|order|by|limit|offset|create|table|into|values|set|on|between|like|distinct)\b|(\$[a-zA-Z_][a-zA-Z0-9_]*)|([a-zA-Z_][a-zA-Z0-9_]*)(?=\s*\()/g;
    return code.replace(RE, function(m0, comment, str, num, kw, phpVar, fn) {
        if (comment) return '<span class="tok-c">' + comment + '</span>';
        if (str) return '<span class="tok-s">' + str + '</span>';
        if (num) return '<span class="tok-n">' + num + '</span>';
        if (kw) return '<span class="tok-k">' + kw + '</span>';
        if (phpVar) return '<span class="tok-k">' + phpVar + '</span>';
        if (fn) return '<span class="tok-f">' + fn + '</span>';
        return m0;
    });
}

/** Convert inter-item newlines to <br> but keep real newlines inside <pre>
 *  (rendered by pre-wrap) so code blocks stay copy/paste friendly. */
function brNewlines(html) {
    return html.replace(/<pre>[\s\S]*?<\/pre>|\n/g, function(m0) { return m0 === '\n' ? '<br>' : m0; });
}

/* ================================================================
   TextMate 语法高亮（shiki = VS Code TextMate 引擎的浏览器实现）
   - 懒加载：出现代码块才从 CDN 拉 shiki + oniguruma WASM（首次约 7MB，之后浏览器缓存）
   - 渲染后对 .codebox pre code 二次高亮；未就绪/离线/未知语言时回退到上面的 hlCode
   ================================================================ */
var _shiki = null, _shikiReady = false, _shikiLoading = false, _shikiWait = [];
var _SHIKI_CDN = 'https://cdn.jsdelivr.net/npm/shiki@1/';

function loadShiki() {
    if (_shikiReady) return Promise.resolve();
    if (_shikiLoading) return new Promise(function (r) { _shikiWait.push(r); });
    _shikiLoading = true;
    return Promise.all([
        import(_SHIKI_CDN + '+esm'),
        // 用户自写 PVM2 扩展的 TextMate 语法（apps/pvm2/vscode-pvm2）
        fetch('../../apps/pvm2/vscode-pvm2/syntaxes/pvm2.tmLanguage.json')
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
    ]).then(function (res) {
        var mod = res[0], tm = res[1];
        var langs = ['bash','shell','sh','php','javascript','js','typescript','ts','jsx','tsx','python','py','sql','html','xml','css','json','yaml','yml','markdown','md','ini','toml','java','c','cpp','csharp','go','rust','ruby','kotlin','swift','dockerfile','makefile','powershell','plaintext','text'];
        if (tm) langs.push(Object.assign({}, tm, { name: 'pvm2', aliases: ['pve', 'pvm', 'pvs'] }));
        return mod.createHighlighter({
            langs: langs,
            themes: ['github-dark']
        });
    }).then(function (h) {
        _shiki = h; _shikiReady = true;
        var w = _shikiWait; _shikiWait = [];
        for (var i = 0; i < w.length; i++) w[i]();
        highlightAllCodeboxes();
    }).catch(function (e) {
        _shikiLoading = false;
        var w = _shikiWait; _shikiWait = [];
        for (var i = 0; i < w.length; i++) w[i]();
        console.error('[shiki] 加载失败，回退到内置高亮', e);
    });
}

function highlightCodebox(codeEl) {
    if (!_shikiReady || !codeEl || codeEl.getAttribute('data-shiki')) return;
    var m = (codeEl.className || '').match(/language-([\w+#-]+)/);
    var lang = m ? m[1] : 'text';
    if (lang === 'text' || lang === 'plaintext' || lang === 'txt') lang = 'text';
    var code = codeEl.textContent;
    if (!code) return;
    try {
        var html = _shiki.codeToHtml(code, { lang: lang === 'text' ? 'plaintext' : lang, theme: 'github-dark' });
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        var c = tmp.querySelector('code');
        if (c && c.innerHTML) {
            codeEl.innerHTML = c.innerHTML; // 只取 token spans，保留我们自己的 .codebox 外壳
            codeEl.setAttribute('data-shiki', '1');
            codeEl.classList.add('shiki-hl');
        }
    } catch (e) { /* 未知语言：保留 hlCode 结果 */ }
}
function highlightAllCodeboxes() {
    var list = document.querySelectorAll('.codebox pre code');
    for (var i = 0; i < list.length; i++) highlightCodebox(list[i]);
}
// 监听消息区 DOM：新出现的代码块自动触发 shiki（懒加载）
function initShikiObserver() {
    var root = document.querySelector('.main-content') || document.body;
    if (!root || !window.MutationObserver) return;
    new MutationObserver(function (muts) {
        var need = false;
        for (var i = 0; i < muts.length && !need; i++) {
            var nodes = muts[i].addedNodes;
            for (var j = 0; j < nodes.length; j++) {
                var el = nodes[j];
                if (el.nodeType !== 1) continue;
                if ((el.classList && el.classList.contains('codebox')) || (el.querySelector && el.querySelector('.codebox'))) { need = true; break; }
            }
        }
        if (!need) return;
        requestAnimationFrame(function () {
            if (document.querySelector('.codebox pre code')) {
                loadShiki().then(function () { highlightAllCodeboxes(); });
            }
        });
    }).observe(root, { childList: true, subtree: true });
}
initShikiObserver();

function onMdInput(previewId, inputId, checkId) {
    var cb = document.getElementById(checkId),
        preview = document.getElementById(previewId);
    if (!cb.checked) {
        preview.classList.remove('active');
        preview.innerHTML = '';
        return;
    }
    var text = document.getElementById(inputId).value;
    if (text.trim().length === 0) {
        preview.classList.remove('active');
        return;
    }
    preview.innerHTML = renderMd(text);
    preview.classList.add('active');
}

function previewAttachment(input, sendFn, btnId) {
    var f = input.files[0];
    if (!f) return;
    attFile = f;
    attSendFn = sendFn;
    attBtnId = btnId;
    var tN = D || 'Announcements';
    document.getElementById('attachTitle').textContent = T('title_send_attachment');
    document.getElementById('attachTo').textContent = tN;
    var pH = '',
        iH = 'Name: ' + f.name + '<br>Size: ' + fmtSize(f.size);
    // Large files: skip decoding preview entirely (avoids freezing from full-file read/decode).
    // Images >8MB, videos >20MB, or any other big file: show a lightweight card only.
    var isImg = f.type.startsWith('image/');
    var isVid = f.type.startsWith('video/');
    var previewOk = (isImg && f.size <= 8 * 1024 * 1024) || (isVid && f.size <= 20 * 1024 * 1024) || (!isImg && !isVid && f.size <= 8 * 1024 * 1024);
    if (previewOk) {
        if (isImg) {
            var reader = new FileReader();
            reader.onload = function(ev) {
                document.getElementById('attachPreview').innerHTML = '<img src="' + ev.target.result + '" class="att-preview" alt="">';
            };
            reader.readAsDataURL(f);
        } else if (isVid) {
            var url = URL.createObjectURL(f);
            pH = '<video src="' + url + '" controls preload="metadata" class="att-preview" style="max-width:320px;max-height:200px"></video>';
        } else {
            pH = '<div style="color:#888;padding:20px">No preview available</div>';
        }
    } else {
        // Large file: friendly note instead of heavy preview
        pH = '<div style="color:#aaa;padding:20px;text-align:center"><img src="../../data/res/cil/cil-file.svg" style="width:16px;height:16px;vertical-align:-2px;margin-right:4px"> ' + f.name + '<br><span style="color:#888;font-size:.8em">' + fmtSize(f.size) + ' · Large file (preview skipped)</span></div>';
    }
    document.getElementById('attachPreview').innerHTML = pH;
    document.getElementById('attachInfo').innerHTML = iH;
    document.getElementById('attachModal').classList.add('active');
    input.value = '';
}

function cancelAttachment() {
    document.getElementById('attachModal').classList.remove('active');
    attFile = null;
    attSendFn = null;
    attBtnId = null;
}

/* ==================== 批量文件：多选/拖拽 → 预览数量 → 确认 → 逐个普通发送 ==================== */
function mediaFilesChosen(input, kind) {
    var files = input.files ? Array.prototype.slice.call(input.files) : [];
    if (!files.length) return;
    if (files.length === 1) {
        // 单文件保持原行为：普通附件预览 → 确认发送
        previewAttachment(input, kind === 'ann' ? sendAnnouncement : sendDmMessage, kind === 'ann' ? 'sendBtn' : 'dmSendBtn');
        return;
    }
    input.value = '';
    openBatchPreview(files, kind);
}

var _batchFiles = [];
var _batchKind = '';
function openBatchPreview(files, kind) {
    _batchFiles = files;
    _batchKind = kind;
    var recipient = (kind === 'ann') ? T('title_announcements', 'Announcements') : (D || 'this chat');
    document.getElementById('batchTitle').textContent = T('batch_title', 'Send files');
    document.getElementById('batchTo').textContent = T('batch_to', 'Send %s files to').replace('%s', files.length) + ' ' + recipient;
    var list = document.getElementById('batchList');
    list.innerHTML = '';
    var total = 0;
    for (var i = 0; i < files.length; i++) {
        total += (files[i].size || 0);
        var li = document.createElement('div');
        li.className = 'batch-file';
        li.innerHTML = '<span class="bf-ico">📄</span><span class="bf-name">' + eh(files[i].name) + '</span><span class="bf-size">' + fmtSize(files[i].size || 0) + '</span>';
        list.appendChild(li);
    }
    document.getElementById('batchInfo').textContent = T('batch_total', 'Total: %s').replace('%s', fmtSize(total));
    document.getElementById('batchModal').classList.add('active');
}
function cancelBatch() {
    document.getElementById('batchModal').classList.remove('active');
    _batchFiles = []; _batchKind = '';
}
function confirmBatch() {
    var files = _batchFiles, kind = _batchKind;
    _batchFiles = []; _batchKind = '';
    document.getElementById('batchModal').classList.remove('active');
    sendFilesOneByOne(files, kind);
}
async function sendFilesOneByOne(files, kind) {
    for (var i = 0; i < files.length; i++) {
        await sendOneNormalAttachment(files[i], kind);
    }
}
function sendOneNormalAttachment(file, kind) {
    return new Promise(function(resolve) {
        var reader = new FileReader();
        reader.onerror = function() { resolve(false); };
        reader.onload = async function(ev) {
            var obj = { data: ev.target.result, name: file.name, type: file.type };
            try {
                if (kind === 'ann') { pendingMedia = obj; await sendAnnouncement(); }
                else { pendingDmMedia = obj; await sendDmMessage(); }
            } catch (e) {}
            resolve(true);
        };
        reader.readAsDataURL(file);
    });
}

/* 拖拽文件到消息区 → 批量预览 */
function setupDropZone(el, kind) {
    if (!el) return;
    ['dragenter', 'dragover'].forEach(function(evt) {
        el.addEventListener(evt, function(e) {
            e.preventDefault();
            e.stopPropagation();
            el.classList.add('drop-target');
        });
    });
    ['dragleave', 'drop'].forEach(function(evt) {
        el.addEventListener(evt, function(e) {
            e.preventDefault();
            e.stopPropagation();
            el.classList.remove('drop-target');
        });
    });
    el.addEventListener('drop', function(e) {
        e.preventDefault();
        var files = (e.dataTransfer && e.dataTransfer.files) ? Array.prototype.slice.call(e.dataTransfer.files) : [];
        if (!files.length) return;
        if (kind === 'dm' && !D) { xalert(T('batch_need_chat', '请先选择一个聊天对象')); return; }
        openBatchPreview(files, kind);
    });
}
function initDropZones() {
    setupDropZone(document.getElementById('dmMessagesArea'), 'dm');
    setupDropZone(document.getElementById('messagesArea'), 'ann');
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initDropZones);
else initDropZones();

/* ============ 语音消息（MediaRecorder → 音频附件） ============ */
var _voiceRec = null;
var _voiceTimerInt = null;
var _voiceMime = (function () {
    var cs = ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm'];
    for (var i = 0; i < cs.length; i++) {
        try { if (window.MediaRecorder && MediaRecorder.isTypeSupported(cs[i])) return cs[i]; } catch (e) {}
    }
    return '';
})();

function _voiceTick() {
    var el = document.getElementById('dmRecTimer');
    if (!el) return;
    var t = _voiceRec ? Math.max(0, Math.floor((Date.now() - _voiceRec.startTime) / 1000)) : 0;
    el.textContent = Math.floor(t / 60) + ':' + ('0' + (t % 60)).slice(-2);
}
function updateVoiceRecUI() {
    var rec = _voiceRec !== null;
    var bar = document.getElementById('dmRecBar');
    var mic = document.getElementById('dmMicBtn');
    if (mic) mic.classList.toggle('recording', rec);
    if (bar) bar.style.display = rec ? 'flex' : 'none';
    if (!rec && _voiceTimerInt) { clearInterval(_voiceTimerInt); _voiceTimerInt = null; }
}
function toggleVoiceRec() {
    if (_voiceRec) stopVoiceRec();
    else startVoiceRec();
}
function startVoiceRec() {
    if (S) return;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { xalert('此浏览器不支持录音'); return; }
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        var rec;
        try { rec = _voiceMime ? new MediaRecorder(stream, { mimeType: _voiceMime }) : new MediaRecorder(stream); }
        catch (e) { rec = new MediaRecorder(stream); }
        var chunks = [];
        rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
        rec.onstop = function () {
            stream.getTracks().forEach(function (t) { t.stop(); });
            var discard = _voiceRec ? _voiceRec.discard : false;
            _voiceRec = null;
            updateVoiceRecUI();
            if (discard) return;
            var blob = new Blob(chunks, { type: rec.mimeType || _voiceMime || 'audio/mp4' });
            if (blob.size < 300) return; // 太短，忽略
            var reader = new FileReader();
            reader.onload = function () {
                pendingDmMedia = { data: reader.result, name: 'voice.m4a' };
                sendDmMessage();
            };
            reader.readAsDataURL(blob);
        };
        rec.start();
        _voiceRec = { rec: rec, stream: stream, startTime: Date.now(), discard: false };
        updateVoiceRecUI();
        _voiceTimerInt = setInterval(_voiceTick, 500);
        _voiceTick();
    }).catch(function () { xalert('无法访问麦克风，请检查权限'); });
}
function stopVoiceRec() { if (_voiceRec) _voiceRec.rec.stop(); }
function cancelVoiceRec() { if (_voiceRec) { _voiceRec.discard = true; _voiceRec.rec.stop(); } }
document.getElementById('attachSendBtn').addEventListener('click', function() {
    if (!attFile) return;
    var _f = attFile,
        _b = attBtnId,
        _s = attSendFn;
    cancelAttachment();
    var reader = new FileReader();
    reader.onload = function(ev) {
        var obj = { data: ev.target.result, name: _f.name, type: _f.type };
        if (_b === 'sendBtn') pendingMedia = obj;
        else pendingDmMedia = obj;
        _s();
    };
    reader.readAsDataURL(_f);
});

function toggleGroup(n) {
    var b = document.getElementById('body-' + n),
        a = document.getElementById('arrow-' + n);
    b.classList.toggle('op');
    a.classList.toggle('op');
}

function switchPanel(n) {
    document.querySelectorAll('.panel').forEach(function(p) {
        p.classList.remove('active')
    });
    var p = document.getElementById('panel-' + n);
    if (p) p.classList.add('active');
    if (n === 'announcements') {
        var i = document.getElementById('messageInput');
        if (i) i.focus()
    }
    if (n === 'dm' && D) {
        document.getElementById('dmMessageInput').focus();
        unreadCounts[D] = 0;
        updateUnreads();
    }
    if (n === 'search') discoverUsers(1);
    if (n === 'public-emoji') loadPublicEmoji();
    if (n === 'requests') loadRequestsPanel();
    if (n === 'users') adminList(1);
    if (n === 'reports') loadReports();
    if (n === 'roles') loadRoleList();
    if (n === 'music' || n === 'dscview' || n === 'midi' || n === 'proxy' || n === 'filemgr' || n === 'spessasynth') loadAppPanel(n);
    if (n === 'donations') loadDonations(1);
    if (n === 'profile-mgmt') loadPm();
    if (n === 'logs') loadAdminLogs(1);
    if (n === 'support') loadSupportTickets('open');
    if (n === 'level') loadLevelPanel();
    if (n === 'dbadmin') dbLoadTables();
}

// Apps 懒加载：侧边栏选中对应 app 才加载 iframe，避免不用 app 也拖慢首屏
function loadAppPanel(n) {
    var p = document.getElementById('panel-' + n);
    if (!p) return;
    var f = p.querySelector('iframe');
    if (!f || f.getAttribute('src')) return; // 已加载则跳过
    var src = f.getAttribute('data-src');
    if (src) f.setAttribute('src', src);
}

var _logTab = 'admin', _logPage = 1;
function _logHeaders(tabKind) {
    var th = '<th>Time</th>';
    if (tabKind === 'admin') th += '<th>Admin</th><th>Action</th><th>Target</th><th>Details</th><th>IP</th>';
    else if (tabKind === 'exp') th += '<th>User</th><th>UID</th><th>Type</th><th>EXP</th><th>Detail</th>';
    else th += '<th>User</th><th>UID</th><th>Success</th><th>IP</th><th>User Agent</th>';
    var tr = document.querySelector('#panel-logs table thead tr');
    if (tr) tr.innerHTML = th;
}
function loadAdminLogs(page) { _logTab = 'admin'; _logFetch('admin_logs', page || 1); }
function loadLoginLogs(page) { _logTab = 'login'; _logFetch('login_logs', page || 1); }
function loadExpLogs(page) { _logTab = 'exp'; _logFetch('exp_logs', page || 1); }
function loadLogs(page) { if (_logTab === 'login') { loadLoginLogs(page); } else if (_logTab === 'exp') { loadExpLogs(page); } else { loadAdminLogs(page); } }
function _logFetch(action, page) {
    _logPage = page || 1;
    var tabKind = action === 'admin_logs' ? 'admin' : (action === 'exp_logs' ? 'exp' : 'login');
    _logHeaders(tabKind);
    var input = document.getElementById('logSearch');
    var q = input ? input.value : '';
    fetch('../../api/admin.php?action=' + action + '&q=' + encodeURIComponent(q) + '&page=' + _logPage).then(function(r) {
        return r.json()
    }).then(function(d) {
        if (!d.success) return;
        var tb = document.getElementById('logsTable');
        if (!tb) return;
        var h = '';
        if (!d.logs || d.logs.length === 0) {
            h = '<tr><td colspan="6" style="text-align:center;color:#555;padding:12px">No logs found.</td></tr>';
        } else {
            for (var i = 0; i < d.logs.length; i++) {
                var r = d.logs[i];
                if (tabKind === 'admin') {
                    h += '<tr><td>' + eh(r.created_at) + '</td><td>' + eh(r.admin_username) + '</td><td>' + eh(r.action) + '</td><td>' + eh(r.target_username || '-') + '</td><td>' + eh(r.details || '') + '</td><td>' + eh(r.ip_address || '') + '</td></tr>';
                } else if (tabKind === 'exp') {
                    h += '<tr><td>' + eh(r.created_at) + '</td><td>' + eh(r.username || ('uid:' + r.user_id)) + '</td><td>' + eh(r.user_id) + '</td><td>' + eh(r.type) + '</td><td>+' + (r.exp || 0) + '</td><td>' + eh(r.detail || '') + '</td></tr>';
                } else {
                    h += '<tr><td>' + eh(r.created_at) + '</td><td>' + eh(r.username) + '</td><td>' + eh(r.user_id) + '</td><td>' + (r.success ? 'Yes' : 'No') + '</td><td>' + eh(r.ip_address || '') + '</td><td>' + eh(r.user_agent || '') + '</td></tr>';
                }
            }
        }
        tb.innerHTML = h;
        var tp = Math.ceil(d.total / d.per_page),
            pg = '',
            info = 'Showing ' + ((_logPage - 1) * d.per_page + 1) + '-' + Math.min(_logPage * d.per_page, d.total) + ' of ' + d.total;
        pg += '<button class="bsm" ' + (_logPage > 1 ? 'onclick="loadLogs(' + (_logPage - 1) + ')"' : 'disabled') + '>Prev</button> ';
        pg += '<button class="bsm" ' + (_logPage < tp ? 'onclick="loadLogs(' + (_logPage + 1) + ')"' : 'disabled') + '>Next</button>';
        document.getElementById('logInfo').textContent = info;
        document.getElementById('logBtns').innerHTML = pg;
    });
}

function eh(t) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(t));
    return d.innerHTML
}
// eh() 不转义双引号（文本上下文），属性值需额外把 " 转成 &quot;
function ehAttr(t) {
    return eh(t).replace(/"/g, '&quot;');
}

// messages.time 列已改为 BIGINT（UNIX 秒级 UTC 时间戳），前端 new Date(ts*1000) 即可；
// 兼容旧格式：datetime 列仍是 'Y-m-d H:i:s'（服务器本地 Asia/Hong_Kong 钟面，chat.php 注入 SERVER_TZ），
// 解析旧字符串时必须显式按该偏移换算，绝不能追加 'Z' 当 UTC 用。
var SERVER_TZ = (typeof SERVER_TZ !== 'undefined') ? SERVER_TZ : '+08:00';

// 把一条消息的 time 统一换算成毫秒时间戳：新版（epoch 秒）或旧字符串（服务器本地钟面）
function timeToTs(s) {
    if (s === null || s === undefined || s === '') return null;
    // 新版 messages.time = UNIX 秒
    if (typeof s === 'number' || /^\d{9,11}$/.test(String(s).trim())) {
        return Number(s) * 1000;
    }
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/);
    if (!m) return null;
    var mt = String(SERVER_TZ).match(/^([+-])(\d{2}):(\d{2})$/);
    var offMin = 0;
    if (mt) offMin = (mt[2] * 60 + +mt[3]) * (mt[1] === '-' ? -1 : 1);
    return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]) - offMin * 60000;
}

function relTime(dt) {
    if (!dt) return '';
    var ts = timeToTs(dt);
    if (ts === null) return dt;
    var now = Date.now(),
        diff = Math.floor((now - ts) / 1000);
    if (diff < 60) return diff + 's ago';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    var d = Math.floor(diff / 86400),
        h = Math.floor((diff % 86400) / 3600),
        m = Math.floor((diff % 3600) / 60);
    return d + 'd ' + (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ' ago';
}

function fmtTime(utc) {
    var ts = timeToTs(utc);
    if (ts === null) return eh(utc);
    var m = TZ.match(/^([+-])(\d{2}):(\d{2})$/);
    if (!m) m = String(SERVER_TZ).match(/^([+-])(\d{2}):(\d{2})$/);
    if (m) {
        var off = (m[2] * 60 + +m[3]) * (m[1] === '-' ? -1 : 1);
        ts += off * 60000
    }
    var d = new Date(ts);
    var p = function(v) {
        return v < 10 ? '0' + v : '' + v
    };
    return d.getUTCFullYear() + '/' + p(d.getUTCMonth() + 1) + '/' + p(d.getUTCDate()) + ' ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ':' + p(d.getUTCSeconds())
}

function ping() {
    fetch('../../api/status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'action=ping'
    }).catch(function() {})
}
// 在线状态判定依赖 last_ping < 15s，必须保持 5s HTTP ping（WS 只负责消息接收）
setInterval(ping, 5000);
ping();
async function toggleDnd() {
    if (RSTR) return;
    var f = new URLSearchParams();
    f.append('action', 'toggle_dnd');
    var r = await fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    });
    var d = await r.json();
    if (d.success) {
        DND = d.dnd;
        updateDndUI();
        if (DND) {
            var rf = new URLSearchParams();
            rf.append('action', 'ping');
            fetch('../../api/status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: rf.toString()
            })
        }
    }
}

function updateDndUI() {
    var el = document.getElementById('dndToggle');
    if (RSTR) {
        el.textContent = 'Restricted';
        el.className = 'sdnd rstr';
        return;
    }
    if (DND) {
        el.textContent = 'Do Not Disturb';
        el.className = 'sdnd dnd';
    } else {
        el.textContent = 'Online';
        el.className = 'sdnd on';
    }
}
async function toggleDataSaver() {
    var f = new URLSearchParams();
    f.append('action', 'toggle_data_saver');
    var r = await fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    });
    var d = await r.json();
    if (d.success) {
        DS = d.data_saver;
    }
}

async function toggleAutoFocus() {
    var f = new URLSearchParams();
    f.append('action', 'toggle_auto_focus');
    var r = await fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    });
    var d = await r.json();
    if (d.success) {
        AUTO_FOCUS = d.auto_focus_input;
    }
}

function updateUnreads() {
    var total = 0;
    for (var k in unreadCounts) {
        if (unreadCounts[k] > 0) total += unreadCounts[k];
    }
    document.title = (total > 0 ? '(' + total + ') ' : '') + 'ChatApp';
    var items = document.querySelectorAll('.csi[data-cuser]');
    for (var i = 0; i < items.length; i++) {
        var u = items[i].getAttribute('data-cuser'),
            cn = items[i].querySelector('.cn');
        if (!cn) continue;
        var orig = cn.getAttribute('data-original') || cn.textContent;
        cn.setAttribute('data-original', orig);
        if (unreadCounts[u] && unreadCounts[u] > 0) {
            cn.classList.add('unread-cnt');
            cn.textContent = orig + ' (' + unreadCounts[u] + ')';
        } else {
            cn.classList.remove('unread-cnt');
            cn.textContent = orig;
            cn.removeAttribute('data-original');
        }
    }
}

function checkOnline() {
    var items = document.querySelectorAll('.csi[data-cuser]');
    var users = [];
    for (var i = 0; i < items.length; i++) users.push(items[i].getAttribute('data-cuser'));
    if (users.length === 0) return;
    fetch('../../api/status.php?action=check&users=' + encodeURIComponent(users.join(','))).then(function(r) {
        return r.json()
    }).then(function(d) {
        if (!d.success) return;
        var s = d.status;
        for (var i = 0; i < items.length; i++) {
            var u = items[i].getAttribute('data-cuser'),
                cn = items[i].querySelector('.cn');
            if (!cn) continue;
            cn.classList.remove('on', 'dnd', 'rstr', 'off');
            if (s.restricted && s.restricted[u]) cn.classList.add('rstr');
            else if (s.dnd && s.dnd[u]) cn.classList.add('dnd');
            else if (s.online && s.online[u]) cn.classList.add('on');
            else if (s.enabled !== undefined && !s.enabled[u]) cn.classList.add('off');
        }
        if (D && s.typing && s.typing[D]) {
            document.getElementById('typingIndicator').style.display = 'block';
            document.getElementById('typingIndicator').textContent = D + ' is typing...';
        } else {
            document.getElementById('typingIndicator').style.display = 'none';
        }
    }).catch(function() {
        document.getElementById('typingIndicator').style.display = 'none';
    });
}

function autoResize(ta) {
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight, parseFloat(getComputedStyle(ta).maxHeight) || 300) + 'px';
}
// 在线状态/打字指示仍走 HTTP（WS 只推消息，不替代在线轮询）
setInterval(checkOnline, 3000);

// 支持与Bug反馈徽章：管理员显示开启工单分类计数 (bugs+rec+acc)，反馈者显示自己被回复数
function loadSupportBadge() {
    fetch('../../api/incident.php?action=count').then(function(r) { return r.json(); }).then(function(d) {
        if (!d || !d.success) return;
        if (d.is_admin) {
            var el = document.getElementById('supAdminCount');
            if (el) el.textContent = '(' + (d.bugs || 0) + '+' + (d.recommendation || 0) + '+' + (d.account_issue || 0) + ')';
        } else {
            var el = document.getElementById('supBadge');
            if (el) {
                var n = d.reply_count || 0;
                el.textContent = n;
                el.style.display = n > 0 ? 'inline-flex' : 'none';
            }
        }
    }).catch(function() {});
}
setInterval(loadSupportBadge, 15000);
loadSupportBadge();

function loadContacts() {
    fetch('../../api/contacts.php?action=list').then(r => r.json()).then(function(d) {
        var e = document.getElementById('friendContacts');
        if (typeof d.pin_self !== 'undefined') { _pinnedSelf = d.pin_self ? 1 : 0; renderSelfPin(); }
        if (d.success && d.contacts.length > 0) {
            var h = '';
            var sorted = d.contacts.slice().sort(function(a, b) { return ((b.pinned ? 1 : 0) - (a.pinned ? 1 : 0)); });
            for (var i = 0; i < sorted.length; i++) {
                var c = sorted[i],
                    a = c.avatar ? '<img src="' + ehAttr(c.avatar) + '">' : '<img src="../../data/profile_empty.png">';
                _contactNotes[c.username] = c.note || '';
                _pinned[c.username] = c.pinned ? 1 : 0;
                // ca 直接内联 onclick（Edge 兼容）：点头像打开个人资料，stopPropagation 避免触发 openDm
                h += '<div class="csi' + (_pinned[c.username] ? ' pinned' : '') + '" data-cuser="' + c.username + '" onclick="openDm(\'' + c.username + '\')"><div class="ca" onclick="event.stopPropagation();event.preventDefault();openMyProfile(\'' + c.username + '\')">' + a + '</div><div class="cn" data-original="' + eh(c.note || c.display_name || c.username) + '">' + eh(c.note || c.display_name || c.username) + '</div></div>';
            }
            e.innerHTML = h;
            updateUnreads();
        } else e.innerHTML = '';
    });
}

function loadPending() {
    fetch('../../api/contacts.php?action=pending').then(r => r.json()).then(function(d) {
        if (d.success && d.pending.length > 0) {
            document.getElementById('pendingBadge').style.display = 'block';
            document.getElementById('pendingCount').textContent = d.pending.length;
            document.getElementById('reqBadge').style.display = 'inline';
            document.getElementById('reqBadge').textContent = d.pending.length;
            var h = '';
            for (var i = 0; i < d.pending.length; i++) {
                var p = d.pending[i];
                h += '<div class="pi"><span style="flex:1">' + eh(p.display_name || p.username) + '</span><button class="bt ac" onclick="showNoteModal(\'' + p.username + '\')">Accept</button><button class="bt rj" onclick="respondRequest(\'' + p.username + '\',\'reject\')">Reject</button></div>';
            }
            document.getElementById('pendingList').innerHTML = h;
        } else {
            document.getElementById('pendingBadge').style.display = 'none';
            document.getElementById('pendingList').style.display = 'none';
            document.getElementById('reqBadge').style.display = 'none';
        }
    });
}

function togglePendingSidebar() {
    var e = document.getElementById('pendingList');
    e.style.display = e.style.display === 'none' ? 'block' : 'none'
}
async function respondRequest(u, r, note) {
    var f = new URLSearchParams();
    f.append('action', 'respond');
    f.append('username', u);
    f.append('response', r);
    if (note) f.append('note', note);
    var d = await fetch('../../api/contacts.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) {
        loadPending();
        loadContacts();
        loadRequestsPanel()
    } else if (d.error === 'Contact limit reached') {
        xalert('Contact limit reached for your level. Max: ' + d.max_contacts);
    } else {
        xalert(d.error || 'Something went wrong.');
    }
}
var noteTarget = null;

function showNoteModal(u) {
    noteTarget = u;
    document.getElementById('noteModal').classList.add('active');
    document.getElementById('noteInput').value = u;
    document.getElementById('noteInput').focus();
}

function closeNoteModal() {
    document.getElementById('noteModal').classList.remove('active');
    noteTarget = null;
}
async function doAcceptWithNote() {
    var n = document.getElementById('noteInput').value.trim();
    var t = noteTarget;
    closeNoteModal();
    if (t) await respondRequest(t, 'accept', n);
}

function toggleAddContact() {
    var b = document.getElementById('addContactBox');
    b.style.display = b.style.display === 'none' ? 'block' : 'none';
    if (b.style.display === 'block') document.getElementById('searchInput').focus();
    else {
        document.getElementById('searchResults').innerHTML = '';
        document.getElementById('searchInput').value = ''
    }
}
var ST = null;

function searchUsers() {
    clearTimeout(ST);
    var q = document.getElementById('searchInput').value.trim();
    if (q.length < 1) {
        document.getElementById('searchResults').innerHTML = '';
        return
    }
    ST = setTimeout(function() {
        fetch('../../api/contacts.php?action=search&q=' + encodeURIComponent(q)).then(r => r.json()).then(function(d) {
            var e = document.getElementById('searchResults');
            if (d.success && d.users.length > 0) {
                var h = '';
                for (var i = 0; i < d.users.length; i++) {
                    var u = d.users[i];
                    // 整栏可点击：打开该用户个人主页（Add 按钮单独 stopPropagation）
                    var b = '';
                    if (u.relation === 'accepted') b = '<span style="color:#666">Friends</span>';
                    else if (u.relation === 'pending') b = '<span style="color:#e0a040">Pending</span>';
                    else b = '<button class="bt" onclick="event.stopPropagation();event.preventDefault();sendFriendRequest(\'' + u.username + '\')">Add</button>';
                    h += '<div class="sri" style="cursor:pointer" onclick="openMyProfile(\'' + u.username + '\')"><span>' + eh(u.username) + ' (' + u.user_id + ')</span>' + b + '</div>';
                }
                e.innerHTML = h;
            } else e.innerHTML = '<div class="sri"><span>' + T('msg_no_users_found') + '</span></div>';
        });
    }, 300);
}

function sendFriendRequest(u) {
    xprompt('Nickname for ' + u + ':', u).then(function(n) {
        if (n === null || n === false) return;
        var nn = n || '';
        var f = new URLSearchParams();
        f.append('action', 'send_request');
        f.append('username', u);
        f.append('note', nn);
        fetch('../../api/contacts.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        }).then(r => r.json()).then(function(d) {
            if (d.success) searchUsers();
            else xalert(d.error || 'Something went wrong.');
        });
    });
}
var dsPage = 1;

function discoverUsers(page) {
    dsPage = page || 1;
    var q = document.getElementById('discoverSearch').value.trim();
    fetch('../../api/settings.php?action=discover&q=' + encodeURIComponent(q) + '&page=' + dsPage).then(r => r.json()).then(function(d) {
        var t = document.getElementById('discoverTable'),
            h = '';
        if (!d.success || d.users.length === 0) h = '<tr><td colspan="5" style="text-align:center;color:#555">' + T('msg_no_users_found') + '</td></tr>';
        else
            for (var i = 0; i < d.users.length; i++) {
                var u = d.users[i],
                    av = u.avatar ? '<span class="srch-avatar"><img src="' + ehAttr(u.avatar) + '" alt=""></span>' : '<span class="srch-avatar"></span>';
                // 整行可点击 → 打开该用户个人主页（Add Friend 按钮单独 stopPropagation）
                h += '<tr style="cursor:pointer" onclick="openMyProfile(\'' + u.username + '\')"><td>' + u.user_id + '</td><td>' + av + eh(u.display_name || u.username) + '</td><td>' + eh(u.username) + '</td><td><button class="srch-btn" onclick="event.stopPropagation();event.preventDefault();openFriendReqModal(\'' + u.username + '\')">Add Friend</button></td></tr>';
            }
        t.innerHTML = h;
        var tp = Math.ceil(d.total / d.per_page),
            pg = '',
            info = 'Showing ' + ((dsPage - 1) * d.per_page + 1) + '-' + Math.min(dsPage * d.per_page, d.total) + ' of ' + d.total;
        pg += '<button ' + (dsPage > 1 ? 'onclick="discoverUsers(' + (dsPage - 1) + ')"' : 'disabled') + '>Prev</button> ';
        pg += '<button ' + (dsPage < tp ? 'onclick="discoverUsers(' + (dsPage + 1) + ')"' : 'disabled') + '>Next</button>';
        document.getElementById('discoverInfo').textContent = info;
        document.getElementById('discoverBtns').innerHTML = pg;
    });
}
var friendReqTarget = null;

function openFriendReqModal(u) {
    friendReqTarget = u;
    document.getElementById('friendReqTitle').textContent = T('btn_add_friend') + ': ' + u;
    document.getElementById('friendReqMsg').value = '';
    document.getElementById('friendReqModal').classList.add('active');
    document.getElementById('friendReqMsg').focus();
}

function closeFriendReqModal() {
    document.getElementById('friendReqModal').classList.remove('active');
    friendReqTarget = null;
}
document.getElementById('friendReqSendBtn').addEventListener('click', function() {
    if (!friendReqTarget) return;
    var f = new URLSearchParams(),
        msg = document.getElementById('friendReqMsg').value.trim();
    f.append('action', 'send_request');
    f.append('username', friendReqTarget);
    f.append('msg', msg);
    fetch('../../api/contacts.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json()).then(function(d) {
        if (d.success) {
            closeFriendReqModal();
            discoverUsers(dsPage)
        } else alert(d.error || 'Something went wrong.');
    });
});

function loadRequestsPanel() {
    fetch('../../api/contacts.php?action=pending').then(r => r.json()).then(function(d) {
        var a = document.getElementById('reqArea');
        if (!d.success || d.pending.length === 0) {
            a.innerHTML = '<div class="es"><p>' + T('msg_no_pending') + '</p></div>';
            return;
        }
        var h = '';
        for (var i = 0; i < d.pending.length; i++) {
            var p = d.pending[i],
                av = p.avatar ? '<span class="req-av"><img src="' + ehAttr(p.avatar) + '" alt=""></span>' : '<span class="req-av"></span>';
            h += '<div class="req-item">' + av + '<div class="req-info"><div class="req-name">' + eh(p.display_name || p.username) + '</div><div class="req-time">' + eh(p.created_at || '') + '</div><div class="req-msg">' + (p.msg ? eh(p.msg) : '') + '</div></div><div class="req-actions"><button class="ac" onclick="showNoteModal(\'' + p.username + '\')">Accept</button><button class="rj" onclick="respondRequest(\'' + p.username + '\',\'reject\')">Reject</button></div></div>';
        }
        a.innerHTML = h;
    });
}

function loadReports() {
    fetch('../../api/report.php?action=list').then(r => r.json()).then(function(d) {
        var a = document.getElementById('reportsArea');
        if (!d.success || d.reports.length === 0) {
            a.innerHTML = '<div class="es"><p>' + T('msg_no_pending') + '</p></div>';
            return;
        }
        var h = '';
        for (var i = 0; i < d.reports.length; i++) {
            var r = d.reports[i],
                mids = r.message_ids ? JSON.parse(r.message_ids).join(', #') : '';
            h += '<div class="report-item"><div class="rep-header"><strong>' + eh(r.reporter_display) + '</strong> &rarr; <strong>' + eh(r.target_display) + '</strong></div>';
            if (r.reason) h += '<div class="rep-reason">' + eh(r.reason) + '</div>';
            if (mids) h += '<div class="rep-msgs">Messages: #' + mids + '</div>';
            h += '<div class="rep-time">' + relTime(r.created_at) + '</div>';
            h += '<div class="rep-actions"><button class="bsm" onclick="openReportInSupport(' + r.id + ')" style="color:#c0a020">More Info</button> <button class="bsm" onclick="resolveReport(' + r.id + ')">Resolve</button> <button class="bsm danger" onclick="banResolveReport(' + r.id + ')">Ban & Resolve</button> <button class="bsm" onclick="goToAllUsers(\'' + r.target_username + '\')">Go to All Users</button></div></div>';
        }
        a.innerHTML = h;
    });
}

function loadRepCount() {
    fetch('../../api/report.php?action=count').then(r => r.json()).then(function(d) {
        if (!d.success) return;
        var b = document.getElementById('repBadge');
        if (d.count > 0) {
            b.style.display = 'inline';
            b.textContent = d.count;
        } else b.style.display = 'none';
    });
}
async function resolveReport(id) {
    var f = new URLSearchParams();
    f.append('action', 'resolve');
    f.append('id', id);
    var r = await fetch('../../api/report.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    });
    var d = await r.json();
    if (d.success) {
        loadReports();
        loadRepCount();
    } else alert('Failed to resolve.');
}
async function banResolveReport(id) {
    var reason = await xprompt('Restrict reason for this user:', '');
    if (reason === null || reason === false) return;
    var f = new URLSearchParams();
    f.append('action', 'resolve');
    f.append('id', id);
    f.append('ban', '1');
    f.append('reason', reason);
    var r = await fetch('../../api/report.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    });
    var d = await r.json();
    if (d.success) {
        loadReports();
        loadRepCount();
    } else alert('Failed to ban.');
}

function goToAllUsers(username) {
    switchPanel('users');
    setTimeout(function() {
        selectUserInAdmin(username);
    }, 300);
}
var _pendingSupportOpenId = null;

function openReportInSupport(id) {
    _pendingSupportOpenId = id;
    switchPanel('support');
    loadSupportTickets('all', 1);
}

function selectUserInAdmin(username) {
    var rows = document.querySelectorAll('#admTable tr');
    for (var i = 0; i < rows.length; i++) {
        var td = rows[i].querySelector('.adm-clickable');
        if (td && td.textContent.trim() === username) {
            rows[i].style.background = '#2a3a2a';
            rows[i].scrollIntoView();
            openUserDetail(username);
            break;
        }
    }
}


function reportMsgFromMenu(el, username) {
    if (!username) return;
    closeAllMsgMenus();
    repTarget = username;
    document.getElementById('reportTitle').textContent = T('title_report_user') + ': ' + username;
    document.getElementById('reportReason').value = '';
    var bubble = el && el.closest ? el.closest('.mr') : null;
    var area = (bubble && bubble.closest('#dmMessagesArea')) ? document.getElementById('dmMessagesArea') : document.getElementById('messagesArea');
    var checkboxes = document.getElementById('reportMsgCheckboxes');
    var msgs = area ? area.querySelectorAll('[data-msgid]') : [];
    var thisMsgId = bubble ? bubble.getAttribute('data-msgid') : null;
    var h = '<div style="color:#aaa;font-size:.75em;margin-bottom:6px">Include messages:</div>';
    for (var i = 0; i < msgs.length; i++) {
        var mid = msgs[i].getAttribute('data-msgid'),
            mt = msgs[i].querySelector('.mt'),
            preview = mt ? mt.textContent.substring(0, 60) : '';
        h += '<label class="msg-cb"><input type="checkbox" value="' + mid + '"' + (String(mid) === String(thisMsgId) ? ' checked' : '') + '> #' + mid + ' ' + eh(preview) + '</label>';
    }
    checkboxes.innerHTML = h;
    document.getElementById('reportModal').classList.add('active');
}

function reportDmUser(u) {
    var t = u || D;
    if (!t) return;
    document.getElementById('dmOptionsMenu').classList.remove('active');
    repTarget = t;
    document.getElementById('reportTitle').textContent = T('title_report_user') + ': ' + t;
    document.getElementById('reportReason').value = '';
    var checkboxes = document.getElementById('reportMsgCheckboxes');
    var msgs = document.getElementById('dmMessagesArea').querySelectorAll('[data-msgid]');
    var h = '<div style="color:#aaa;font-size:.75em;margin-bottom:6px">Include messages:</div>';
    // 右键举报非当前聊天对象时，不附带消息（避免夹带别人的聊天）
    if (u && u !== D) {
        h += '<div style="color:#666;font-size:.75em">Chat not open — no messages attached.</div>';
    } else {
        for (var i = 0; i < msgs.length; i++) {
            var mid = msgs[i].getAttribute('data-msgid'),
                mt = msgs[i].querySelector('.mt'),
                preview = mt ? mt.textContent.substring(0, 60) : '';
            h += '<label class="msg-cb"><input type="checkbox" value="' + mid + '"> #' + mid + ' ' + eh(preview) + '</label>';
        }
    }
    checkboxes.innerHTML = h;
    document.getElementById('reportModal').classList.add('active');
}

function closeReportModal() {
    document.getElementById('reportModal').classList.remove('active');
    repTarget = null;
}
async function doReport() {
    if (!repTarget) return;
    var reason = document.getElementById('reportReason').value.trim();
    var checked = [];
    var cbs = document.querySelectorAll('#reportMsgCheckboxes input:checked');
    for (var i = 0; i < cbs.length; i++) checked.push(cbs[i].value);
    var f = new URLSearchParams();
    f.append('action', 'submit');
    f.append('target', repTarget);
    f.append('reason', reason);
    f.append('message_ids', JSON.stringify(checked));
    var r = await fetch('../../api/report.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    });
    var d = await r.json();
    if (d.success) {
        closeReportModal();
        alert('Report submitted.');
    } else alert(d.error || 'Something went wrong.');
}

var _cdResolve = null;

function customDialog(title, msg, type) {
    return new Promise(function(resolve) {
        _cdResolve = resolve;
        document.getElementById('customDialog').classList.remove('cd-danger'); // 普通对话框恢复默认蓝色主题
        document.getElementById('cdTitle').textContent = title;
        document.getElementById('cdMsg').textContent = msg || '';
        var inp = document.getElementById('cdInput'),
            ok = document.getElementById('cdOk'),
            cancel = document.getElementById('cdCancel');
        inp.style.display = type === 'prompt' ? 'block' : 'none';
        inp.value = '';
        if (type === 'prompt') inp.focus();
        if (type === 'alert') {
            cancel.style.display = 'none';
            ok.style.display = 'block';
            ok.textContent = T('btn_ok');
        } else if (type === 'confirm') {
            cancel.style.display = 'block';
            cancel.textContent = T('btn_cancel');
            ok.textContent = T('btn_confirm');
            ok.style.display = 'block';
        } else if (type === 'prompt') {
            cancel.style.display = 'block';
            cancel.textContent = T('btn_cancel');
            ok.textContent = T('btn_ok');
            ok.style.display = 'block';
        }
        ok.onclick = function() {
            closeCustomDialog(true)
        };
        cancel.onclick = function() {
            closeCustomDialog(false)
        };
        inp.onkeydown = function(e) {
            if (e.key === 'Enter') closeCustomDialog(true)
        };
        document.getElementById('customDialog').classList.add('active');
    });
}

function closeCustomDialog(ok) {
    var v = ok ? (document.getElementById('cdInput').value || true) : null;
    document.getElementById('customDialog').classList.remove('active');
    if (_cdResolve) {
        _cdResolve(v);
        _cdResolve = null;
    }
}

function cdResolve(ok) {
    closeCustomDialog(ok);
}

function xalert(m) {
    return customDialog('ChatApp', m, 'alert');
}

function xconfirm(m) {
    return customDialog('ChatApp', m, 'confirm');
}

// 外部链接警告：点外部链接先弹确认（No/Yes），确认后才新标签打开
function confirmExternal(url) {
    var p = customDialog('外部链接', '你即将要前往外部链接，是否确认？\n链接: ' + url, 'confirm');
    var msg = document.getElementById('cdMsg');
    if (msg) msg.style.whiteSpace = 'pre-wrap'; // 保留 \n 换行
    var ok = document.getElementById('cdOk'), cancel = document.getElementById('cdCancel');
    if (ok) ok.textContent = 'Yes';
    if (cancel) cancel.textContent = 'No';
    return p.then(function (v) { if (v) window.open(url, '_blank', 'noopener'); });
}

function xprompt(m, d) {
    document.getElementById('cdInput').value = d || '';
    return customDialog('ChatApp', m, 'prompt');
}

function showAddUserModal() {
    document.getElementById('addUserModal').classList.add('active');
    document.getElementById('addUserName').value = '';
    document.getElementById('addUserPwd').value = '';
}

function closeAddUserModal() {
    document.getElementById('addUserModal').classList.remove('active');
}
async function doAddUser() {
    var u = document.getElementById('addUserName').value.trim(),
        p = document.getElementById('addUserPwd').value;
    if (!u || !p) return;
    var f = new URLSearchParams();
    f.append('action', 'add_user');
    f.append('username', u);
    f.append('password', p);
    f.append('language', 'en');
    var r = await fetch('../../api/admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    });
    var d = await r.json();
    if (d.success) {
        closeAddUserModal();
        adminList(admPage);
    } else xalert(d.error || 'Something went wrong.');
}

function showAddPlaceholderModal() {
    document.getElementById('addPlaceholderModal').classList.add('active');
    document.getElementById('addPhName').value = '';
}

function closeAddPlaceholderModal() {
    document.getElementById('addPlaceholderModal').classList.remove('active');
}
async function doAddPlaceholder() {
    var u = document.getElementById('addPhName').value.trim();
    if (!u) return;
    var f = new URLSearchParams();
    f.append('action', 'add_placeholder');
    f.append('username', u);
    var r = await fetch('../../api/admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    });
    var d = await r.json();
    if (d.success) {
        closeAddPlaceholderModal();
        adminList(admPage);
    } else xalert(d.error || 'Something went wrong.');
}

var admPage = 1;

var _admSort = 'username', _admDir = 'asc';
function adminSort(col) {
    if (_admSort === col) _admDir = (_admDir === 'asc') ? 'desc' : 'asc';
    else { _admSort = col; _admDir = 'asc'; }
    document.querySelectorAll('#panel-users thead th').forEach(function(th) { th.classList.remove('sort-asc','sort-desc'); });
    var sel = null;
    document.querySelectorAll('#panel-users thead th').forEach(function(th) {
        var lbl = (th.getAttribute('onclick') || '').replace(/[^a-z_]/g, '');
        if (lbl === col || lbl === ('adminSort' + col)) sel = th;
    });
    if (sel) sel.classList.add(_admDir === 'asc' ? 'sort-asc' : 'sort-desc');
    adminList(1);
}
function adminList(page) {
    admPage = page || 1;
    var q = document.getElementById('admSearch').value.trim(),
        re = document.getElementById('admRegex').checked ? '1' : '0',
        de = document.getElementById('admDeleted').checked ? '1' : '0';
    fetch('../../api/admin.php?action=list&search=' + encodeURIComponent(q) + '&regex=' + re + '&deleted=' + de + '&page=' + admPage + '&sort=' + encodeURIComponent(_admSort) + '&dir=' + encodeURIComponent(_admDir)).then(r => r.json()).then(function(d) {
        if (!d.success) return;
        var t = document.getElementById('admTable'),
            h = '';
        if (d.users.length === 0) h = '<tr><td colspan="5" style="text-align:center;color:#555">' + T('msg_no_users_found') + '</td></tr>';
        else
            for (var i = 0; i < d.users.length; i++) {
                var u = d.users[i],
                    badge;
                if (u.placeholder) badge = '<span class="adm-badge ph" onclick="openChangeStatusModal(\'' + u.username + '\')">Placeholder</span>';
                else if (u.restricted) badge = '<span class="adm-badge rstr" onclick="openChangeStatusModal(\'' + u.username + '\')">Restricted</span>';
                else if (u.enabled) badge = '<span class="adm-badge on" onclick="openChangeStatusModal(\'' + u.username + '\')">Enabled</span>';
                else badge = '<span class="adm-badge off" onclick="openChangeStatusModal(\'' + u.username + '\')">Disabled</span>';
                var ll = u.last_login ? relTime(u.last_login) : '-';
                h += '<tr><td><span class="adm-clickable" onclick="openUserDetail(\'' + u.username + '\')">' + eh(u.username) + '</span></td><td>' + eh(u.user_id || '') + '</td><td>' + badge + '</td><td>' + ll + '</td><td>' + eh(u.created_at) + '</td></tr>';
            }
        t.innerHTML = h;
        var tp = Math.ceil(d.total / d.per_page),
            pg = '',
            info = 'Showing ' + ((admPage - 1) * d.per_page + 1) + '-' + Math.min(admPage * d.per_page, d.total) + ' of ' + d.total;
        pg += '<button ' + (admPage > 1 ? 'onclick="adminList(' + (admPage - 1) + ')"' : 'disabled') + '>Prev</button> ';
        pg += '<button ' + (admPage < tp ? 'onclick="adminList(' + (admPage + 1) + ')"' : 'disabled') + '>Next</button>';
        document.getElementById('admInfo').textContent = info;
        document.getElementById('admBtns').innerHTML = pg;
    });
}

function showUsersSubTab() {
    document.getElementById("usersSubTab").style.display = "block";
    document.getElementById("rolesSubTab").style.display = "none";
    document.getElementById("usrTabBtn").classList.add("active");
    document.getElementById("roleTabBtn").classList.remove("active");
}

function showRolesSubTab() {
    document.getElementById("usersSubTab").style.display = "none";
    document.getElementById("rolesSubTab").style.display = "block";
    document.getElementById("usrTabBtn").classList.remove("active");
    document.getElementById("roleTabBtn").classList.add("active");
    loadRoleList();
}

function admRegexToggled() {
    if (document.getElementById('admRegex').checked) document.getElementById('admDeleted').checked = false;
    adminList(1);
}

function admDeletedToggled() {
    if (document.getElementById('admDeleted').checked) document.getElementById('admRegex').checked = false;
    adminList(1);
}

async function openUserDetail(username) {
    if (admSelUser === username) {
        closeUserDetail();
        return;
    }
    admSelUser = username;
    if (!_sidebarProfileSaved) {
        _sidebarProfileSaved = document.getElementById('sidebarProfile').innerHTML;
        _sidebarNavSaved = document.getElementById('sidebarNavDefault').innerHTML;
    }
    var d = await fetch('../../api/admin.php?action=user_detail&username=' + encodeURIComponent(username)).then(r => r.json());
    if (!d.success) return;
    var u = d.user;
    var stLabel = u.status_label;
    var dndLabel = u.dnd ? 'DND' : 'Online';
    var prof = document.getElementById('sidebarProfile');
    prof.querySelector('.sa').innerHTML = (u.avatar ? ('<img src="' + ehAttr(u.avatar) + '" alt="">') : '');
    prof.querySelector('.sun').textContent = eh(u.display_name || u.username) + ' (' + u.user_id + ')';
    var dndEl = prof.querySelector('.sdnd');
    dndEl.textContent = dndLabel + ' &mdash; ' + stLabel;
    dndEl.className = 'sdnd';
    dndEl.style.cssText = 'color:#888;font-size:.72em;cursor:default';
    dndEl.removeAttribute('onclick');
    var nav = document.getElementById('sidebarNavUser');
    nav.style.cssText = 'display:block;overflow-y:auto;flex:1';
    var h = '<div class="ng"><div class="ngh" onclick="closeUserDetail()" style="cursor:pointer"><span>Back</span></div></div>';
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_change_display_name\')" style="cursor:pointer"><span>Change display name</span></div></div>';
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_change_username\')" style="cursor:pointer"><span>Change username</span></div></div>';
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_change_password\')" style="cursor:pointer"><span>Change password</span></div></div>';
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_change_status\')" style="cursor:pointer"><span>Change status</span></div></div>';
    if (u.role !== 'root') {
        h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_change_role\')" style="cursor:pointer"><span>Change role</span></div></div>';
    }
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_set_restrict_reason\')" style="cursor:pointer"><span>Set restrict reason</span></div></div>';
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_toggle_dnd\')" style="cursor:pointer"><span>Toggle DND</span></div></div>';
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_expire_tokens\')" style="cursor:pointer"><span>Expire all login tokens</span></div></div>';
    if (u.locked) {
        h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_unlock\')" style="cursor:pointer"><span>Unlock account (reset failed attempts)</span></div></div>';
    }
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_send_friend_request\')" style="cursor:pointer"><span>Send friend request</span></div></div>';
    if (u.friend_relation === 'accepted') {
        h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_remove_friend\')" style="cursor:pointer"><span>Remove friend</span></div></div>';
    } else {
        h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_add_as_friend\')" style="cursor:pointer"><span>Add as friend</span></div></div>';
    }
    // Level system admin controls (Adjust level / Adjust EXP / Reset EXP)
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_adjust_level\')" style="cursor:pointer"><span>Adjust level</span></div></div>';
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_adjust_exp\')" style="cursor:pointer"><span>Adjust EXP</span></div></div>';
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_reset_exp\')" style="cursor:pointer;color:#e06060"><span>Reset EXP</span></div></div>';
    if (u.user_id !== 10000) {
        h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_login_as\')" style="cursor:pointer"><span>Login as user</span></div></div>';
    }
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_change_uid\')" style="cursor:pointer;color:#e06060"><span>Change UID</span></div></div>';
    h += '<div class="ng"><div class="ngh" onclick="usAction(\'us_delete_user\')" style="cursor:pointer;color:#e06060"><span>Delete user</span></div></div>';
    h += '<div style="padding:10px 14px;font-size:.73em;color:#777;line-height:1.8;border-top:1px solid #3a3a3a;margin-top:4px">' +
        'Display name: ' + eh(u.display_name || '-') + '<br>' +
        'Username: ' + eh(u.username) + '<br>' +
        'UID: ' + eh(String(u.user_id)) + '<br>' +
        'Role: ' + eh(u.role) + '<br>' +
        'Status: ' + eh(stLabel) + '<br>' +
        'Restrict reason: ' + eh(u.restricted_reason || '-') + '<br>' +
        'Level: ' + eh(u.level || 1) + '<br>' +
        'Total Exp: ' + eh(u.exp || 0) + '<br>' +
        'DND: ' + (u.dnd ? 'Yes' : 'No') + '<br>' +
        (u.locked ? ('Locked: Yes (until ' + eh(u.locked_until || '-') + ')') : ('Failed attempts: ' + eh(String(u.failed_attempts || 0)))) + '</div>';
    nav.innerHTML = h;
    document.getElementById('sidebarNavDefault').style.display = 'none';
}

function closeUserDetail() {
    admSelUser = null;
    if (_sidebarProfileSaved) document.getElementById('sidebarProfile').innerHTML = _sidebarProfileSaved;
    if (_sidebarNavSaved) document.getElementById('sidebarNavDefault').innerHTML = _sidebarNavSaved;
    document.getElementById('sidebarNavDefault').style.display = 'block';
    document.getElementById('sidebarNavUser').style.display = 'none';
    _sidebarProfileSaved = null;
    _sidebarNavSaved = null;
    loadContacts();
    loadPending();
}
async function usAction(type) {
    if (!admSelUser) return;
    var u = admSelUser;
    if (type === 'us_change_status') {
        openChangeStatusModal(u);
        return;
    }
    if (type === 'us_change_display_name') {
        var dn = await xprompt('New display name:', u.display_name || u.username);
        if (dn === null || dn === false) return;
        var f = new URLSearchParams();
        f.append('action', 'change_display_name_adm');
        f.append('username', u);
        f.append('display_name', dn);
        var r = await fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) openUserDetail(u);
        else xalert('Failed.');
        return;
    }
    if (type === 'us_change_username') {
        var nn = await xprompt('New username:');
        if (!nn) return;
        var f = new URLSearchParams();
        f.append('action', 'change_username');
        f.append('username', u);
        f.append('new_username', nn);
        var r = await fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) {
            closeUserDetail();
            adminList(admPage);
        } else xalert(d.error || 'Failed.');
        return;
    }
    if (type === 'us_change_password') {
        var pw = await xprompt('New password (min 8 chars):');
        if (!pw) return;
        var f = new URLSearchParams();
        f.append('action', 'change_password');
        f.append('username', u);
        f.append('new_password', pw);
        var r = await fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        xalert(d.success ? 'Password changed.' : d.error || 'Failed.');
        return;
    }
    if (type === 'us_toggle_dnd') {
        var f = new URLSearchParams();
        f.append('action', 'toggle_dnd_adm');
        f.append('username', u);
        var r = await fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) openUserDetail(u);
        return;
    }
    if (type === 'us_expire_tokens') {
        if ((await xconfirm('Force logout this user?')) !== true) return;
        var f = new URLSearchParams();
        f.append('action', 'expire_tokens');
        f.append('username', u);
        var r = await fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        xalert(d.success ? 'Logged out.' : 'Failed.');
        return;
    }
    if (type === 'us_unlock') {
        if ((await xconfirm('Unlock account ' + u + '? This resets failed login attempts.')) !== true) return;
        var f = new URLSearchParams();
        f.append('action', 'unlock_account');
        f.append('username', u);
        var r = await fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) openUserDetail(u);
        else xalert('Failed.');
        return;
    }
    if (type === 'us_login_as') {
        if ((await xconfirm('Login as ' + u + '?')) !== true) return;
        var f = new URLSearchParams();
        f.append('action', 'login_as');
        f.append('username', u);
        var r = await fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) window.location.href = 'chat.php';
        return;
    }
    if (type === 'us_send_friend_request') {
        var f = new URLSearchParams();
        f.append('action', 'send_request');
        f.append('username', u);
        var r = await fetch('../../api/contacts.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) {
            xalert('Friend request sent to ' + u);
        } else xalert(d.error || 'Failed.');
        return;
    }
    if (type === 'us_delete_user') {
        if ((await xconfirm('Permanently delete ' + u + '?')) !== true) return;
        var f = new URLSearchParams();
        f.append('action', 'delete');
        f.append('username', u);
        var r = await fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) {
            closeUserDetail();
            adminList(admPage);
        } else xalert('Something went wrong.');
        return;
    }
    if (type === 'us_add_as_friend') {
        var f = new URLSearchParams();
        f.append('action', 'force_add');
        f.append('username', u);
        var r = await fetch('../../api/contacts.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) {
            openUserDetail(u);
            loadContacts();
        } else xalert(d.error || 'Failed.');
        return;
    }
    if (type === 'us_change_role') {
        var rv = await xprompt('Change role for ' + u + ' to (admin/user):', 'New role:');
        if (!rv) return;
        var f = new URLSearchParams();
        f.append('action', 'set_role');
        f.append('username', u);
        f.append('role', rv);
        var r = await fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) openUserDetail(u);
        else xalert(d.error || 'Failed.');
        return;
    }
    if (type === 'us_set_restrict_reason') {
        var curReason = '';
        try {
            var dd = await fetch('../../api/admin.php?action=user_detail&username=' + encodeURIComponent(u)).then(r => r.json());
            if (dd.success) curReason = dd.user.restricted_reason || '';
        } catch (e) {}
        var rr = await xprompt('Set restrict reason for ' + u + ':', curReason);
        if (rr === null || rr === false) return;
        var f = new URLSearchParams();
        f.append('action', 'set_restrict_reason');
        f.append('username', u);
        f.append('reason', rr);
        var r = await fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) openUserDetail(u);
        else xalert(d.error || 'Failed.');
        return;
    }
    if (type === 'us_adjust_level') {
        var nl = await xprompt('Set level for ' + u + ' (1-100):', '');
        if (!nl) return;
        nl = parseInt(nl, 10);
        if (isNaN(nl) || nl < 1 || nl > 100) { xalert('Level must be 1-100.'); return; }
        var f = new URLSearchParams();
        f.append('action', 'adjust_level');
        f.append('username', u);
        f.append('level', nl);
        var r = await fetch('../../api/admin.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString()
        });
        var d = await r.json();
        if (d.success) {
            closeUserDetail();
            openUserDetail(u);
        }
        else xalert(d.error || 'Failed.');
        return;
    }
    if (type === 'us_adjust_exp') {
        var ne = await xprompt('Set EXP for ' + u + ' (>=0):', '');
        if (ne === null || ne === false || ne === '') return;
        ne = parseInt(ne, 10);
        if (isNaN(ne) || ne < 0) { xalert('EXP must be >= 0.'); return; }
        var f = new URLSearchParams();
        f.append('action', 'adjust_exp');
        f.append('username', u);
        f.append('exp', ne);
        var r = await fetch('../../api/admin.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString()
        });
        var d = await r.json();
        if (d.success) {
            closeUserDetail();
            openUserDetail(u);
        }
        else xalert(d.error || 'Failed.');
        return;
    }
    if (type === 'us_reset_exp') {
        if ((await xconfirm('Reset EXP of ' + u + ' to 0?')) !== true) return;
        var f = new URLSearchParams();
        f.append('action', 'reset_exp');
        f.append('username', u);
        var r = await fetch('../../api/admin.php', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString()
        });
        var d = await r.json();
        if (d.success) {
            closeUserDetail();
            openUserDetail(u);
        }
        else xalert(d.error || 'Failed.');
        return;
    }
    if (type === 'us_change_uid') {
        var r1 = await xconfirm('Change UID for ' + u + '?\nChanging UID may cause database corruption. Chat history and related DB records will NOT be updated.');
        if (r1 !== true) return;
        var newUid = await xprompt('New UID (number):', '');
        if (!newUid || !newUid.match) return;
        newUid = newUid.trim();
        if (!/^\d+$/.test(newUid)) {
            xalert('UID must be a number.');
            return
        }
        var r2 = await xprompt('Confirm new UID:', '');
        if (r2 !== newUid) {
            xalert('UIDs do not match.');
            return
        }
        var f = new URLSearchParams();
        f.append('action', 'change_uid');
        f.append('username', u);
        f.append('new_uid', newUid);
        var r = await fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) {
            closeUserDetail();
            adminList(admPage);
        } else xalert(d.error || 'Failed.');
        return;
    }
    if (type === 'us_remove_friend') {
        if ((await xconfirm('Remove friend ' + u + '?')) !== true) return;
        var f = new URLSearchParams();
        f.append('action', 'delete');
        f.append('username', u);
        var r = await fetch('../../api/contacts.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        });
        var d = await r.json();
        if (d.success) {
            openUserDetail(u);
            loadContacts();
        } else xalert('Failed.');
        return;
    }
}

var statusUser = null;

function openChangeStatusModal(username) {
    statusUser = username;
    var m = document.getElementById('changeStatusModal');
    var a = m.querySelector('.modal-actions');
    a.innerHTML = '';
    ['enabled', 'disabled', 'restricted', 'placeholder'].forEach(function(s) {
        var btn = document.createElement('button');
        btn.className = 'bsm';
        btn.textContent = s.charAt(0).toUpperCase() + s.slice(1);
        btn.onclick = function() {
            doChangeStatus(statusUser, s)
        };
        a.appendChild(btn);
    });
    m.classList.add('active');
}

function closeChangeStatusModal() {
    document.getElementById('changeStatusModal').classList.remove('active');
    statusUser = null;
}
async function doChangeStatus(username, newStatus) {
    var f = new URLSearchParams();
    f.append('action', 'change_status');
    f.append('username', username);
    f.append('status', newStatus);
    var r = await fetch('../../api/admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    });
    var d = await r.json();
    if (d.success) {
        closeChangeStatusModal();
        adminList(admPage);
    } else xalert('Failed.');
}

function scrollChatToBottom(el) {
    if (!el) return;
    var doScroll = function() {
        if (!el.isConnected) return;
        var max = Math.max(0, el.scrollHeight - el.clientHeight);
        el.scrollTop = max;
    };
    requestAnimationFrame(function() {
        doScroll();
        requestAnimationFrame(function() {
            doScroll();
            setTimeout(doScroll, 80);
            setTimeout(doScroll, 220);
        });
    });
    var imgs = el.querySelectorAll('img,video');
    for (var i = 0; i < imgs.length; i++) {
        var img = imgs[i];
        if (img.complete) {
            continue;
        }
        img.addEventListener('load', doScroll, {
            once: true
        });
        img.addEventListener('loadedmetadata', doScroll, {
            once: true
        });
    }
}

// E2EE 状态徽章：双方都开=绿锁；一方开=黄警告；都没开=隐藏
function updateDmE2eeBadge(u) {
    var badge = document.getElementById('dmE2eeBadge');
    if (!badge) return;
    if (!window.E2EE || !window.nacl || !u) { badge.style.display = 'none'; return; }
    E2EE.getStatus(u).then(function(on) {
        if (on) {
            badge.textContent = T('e2ee_badge_on', '端到端加密已开启');
            badge.className = 'dm-e2ee-badge on';
            badge.style.display = 'inline';
        } else {
            badge.style.display = 'none';
        }
    }).catch(function() { badge.style.display = 'none'; });
}

function openDm(u) {
    G = null;
    D = u;
    document.getElementById('dmTitle').textContent = T('title_chat') + ': ' + (_contactNotes[u] || u) + ' (' + u + ')';
    switchPanel('dm');
    updateDmE2eeBadge(u);
    updateDmOptionsMenu();
    document.getElementById('dmMessagesArea').innerHTML = '<div class="es"><p>' + T('msg_loading') + '</p></div>';
    seenMsgIds = {};
    loadDmMessages(0);
    if (AUTO_FOCUS) document.getElementById('dmMessageInput').focus();
    _replyTarget = null;
    _replyData = null;
    updateReplyIndicator();
    var f = new URLSearchParams();
    apiRequest('mark_read', { from: u }).catch(function() {});
    unreadCounts[u] = 0;
    updateUnreads();
}

function closeDm() {
    G = null;
    if (D) {
        fetch('../../api/status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'action=clear_typing'
        }).catch(function() {});
    }
    D = null;
    document.getElementById('typingIndicator').style.display = 'none';
    switchPanel('announcements')
}

function toggleDmOptions(e) {
    e.stopPropagation();
    document.getElementById('dmOptionsMenu').classList.toggle('active');
}

function viewDmProfile(u) {
    document.getElementById('dmOptionsMenu').classList.remove('active');
    if (G && !u) { openGroupInfo(); return; }
    var t = u || D;
    if (!t) return;
    openMyProfile(t);
}

// ---- 群信息页（右侧抽屉） ----
function openGroupInfo() {
    var m = document.getElementById('dmOptionsMenu');
    if (m) m.classList.remove('active');
    if (!G) return;
    var fr = document.getElementById('profileFrame');
    var sb = document.getElementById('userSidebar');
    var ov = document.getElementById('profileOverlay');
    if (!fr || !sb || !ov) return;
    fr.src = '/modern/wp/groupinfo.php?gid=' + G;
    sb.classList.add('active');
    ov.classList.add('active');
}

// 群聊模式下切换 ⋯ 菜单项：显示群相关项，隐藏 DM 相关项
function updateDmOptionsMenu() {
    var menu = document.getElementById('dmOptionsMenu');
    if (!menu) return;
    var isGrp = !!G;
    menu.querySelectorAll('.grp-opt').forEach(function(b) { b.style.display = isGrp ? '' : 'none'; });
    menu.querySelectorAll('.dm-opt').forEach(function(b) { b.style.display = isGrp ? 'none' : ''; });
    // Reload Client：仅 admin/root（私聊会话）
    var dmReload = document.getElementById('dmReloadBtn');
    if (dmReload) dmReload.style.display = (ADMIN && !isGrp) ? '' : 'none';
    // 置顶按钮文案随当前会话置顶状态切换
    var dmPin = document.getElementById('dmPinBtn');
    if (dmPin) {
        var isSelfDm = !!(D && U && D === U);
        dmPin.textContent = (isSelfDm ? _pinnedSelf : _pinned[D]) ? T('d_unpin') : T('d_pin');
    }
    var grpPin = document.getElementById('grpPinBtn');
    if (grpPin) grpPin.textContent = _pinnedGroup[G] ? T('d_unpin') : T('d_pin');
    // E2EE 开关文案随当前对话状态切换
    var dmE2 = document.getElementById('dmE2eeBtn');
    if (dmE2 && D) {
        dmE2.disabled = true;
        E2EE.getStatus(D).then(function (on) {
            _dmE2eeOn = !!on;
            dmE2.textContent = on ? '🔓 ' + T('d_e2ee_off', '关闭端到端加密') : '🔒 ' + T('d_e2ee_on', '开启端到端加密');
            dmE2.disabled = false;
        }).catch(function () { dmE2.disabled = false; });
    }
}

// 切换当前对话的 E2EE（Options 菜单里，套用于对话：任一方开/关即整体开/关）；
// 成功后本地立即渲染自己的系统提示（WSS 不会回声自己的消息），并刷新徽章/菜单文案
function toggleDmE2ee(u) {
    u = u || D;
    if (!u || !window.E2EE) return;
    var btn = document.getElementById('dmE2eeBtn');
    if (btn) btn.disabled = true;
    E2EE.getStatus(u).then(function (cur) {
        return E2EE.setStatus(!cur, u);
    }).then(function (d) {
        if (btn) btn.disabled = false;
        if (d && d.success) {
            _dmE2eeOn = !!d.enabled;
            if (d.message_id) {
                var m = { id: d.message_id, msg_type: 'sys_e2ee', message: d.enabled ? 'on' : 'off', username: U, recipient: u };
                addDmMessage(m);
            }
        }
        updateDmE2eeBadge(u);
        updateDmOptionsMenu();
    }).catch(function () {
        if (btn) btn.disabled = false;
    });
}

/* ---------- 端到端加密安全码（WhatsApp 式 60 位比对） ---------- */
var _safetyNumberText = '';
function openSafetyVerify() {
    if (!D) return;
    var modal = document.getElementById('safetyVerifyModal');
    var numEl = document.getElementById('safetyVerifyNum');
    var sub = document.getElementById('safetyVerifySub');
    if (!modal) return;
    modal.classList.add('active');
    if (sub) sub.textContent = T('sv_with').replace('%s', _contactNotes[D] || D);
    if (numEl) numEl.textContent = T('sv_calculating');
    _safetyNumberText = '';
    if (window.E2EE && typeof E2EE.safetyNumber === 'function') {
        E2EE.safetyNumber(D).then(function (s) {
            _safetyNumberText = s || '';
            if (numEl) numEl.textContent = s || '（无）';
        }).catch(function () {
            if (numEl) numEl.textContent = T('sv_error');
        });
    } else {
        if (numEl) numEl.textContent = T('sv_unavailable');
    }
}
function closeSafetyVerify() {
    var m = document.getElementById('safetyVerifyModal');
    if (m) m.classList.remove('active');
}
function copySafetyNumber() {
    if (!_safetyNumberText) { xalert(T('sv_not_ready')); return; }
    try {
        navigator.clipboard.writeText(_safetyNumberText.replace(/\s/g, ''));
        xalert(T('sv_copied'));
    } catch (e) { xalert(T('sv_copy_fail')); }
}
function ctxOpenSafetyVerify() {
    var u = _ctxUser;
    if (!u) return;
    openDm(u);
    openSafetyVerify();
}

function togglePinContact(u) {
    var t = u || D;
    if (!t) return;
    if (t === U) { togglePinSelf(); return; }
    closeUserCtxMenu();
    document.getElementById('dmOptionsMenu').classList.remove('active');
    var f = new URLSearchParams();
    f.append('action', 'toggle_pin');
    f.append('username', t);
    fetch('../../api/contacts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            _pinned[t] = _pinned[t] ? 0 : 1;
            loadContacts();
            updateDmOptionsMenu();
            xalert(_pinned[t] ? T('msg_pinned', '已置顶') : T('msg_unpinned', '已取消置顶'));
        } else xalert('Something went wrong.');
    });
}

function togglePinGroup() {
    if (!G) return;
    document.getElementById('dmOptionsMenu').classList.remove('active');
    var f = new URLSearchParams();
    f.append('action', 'toggle_pin');
    f.append('group_id', G);
    fetch('../../api/group.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            _pinnedGroup[G] = _pinnedGroup[G] ? 0 : 1;
            loadMyGroups();
            updateDmOptionsMenu();
            xalert(_pinnedGroup[G] ? T('msg_pinned', '已置顶') : T('msg_unpinned', '已取消置顶'));
        } else xalert('Something went wrong.');
    });
}

function togglePinSelf() {
    closeUserCtxMenu();
    document.getElementById('dmOptionsMenu').classList.remove('active');
    var f = new URLSearchParams();
    f.append('action', 'toggle_pin_self');
    fetch('../../api/contacts.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            _pinnedSelf = _pinnedSelf ? 0 : 1;
            renderSelfPin();
            updateDmOptionsMenu();
            xalert(_pinnedSelf ? T('msg_pinned', '已置顶') : T('msg_unpinned', '已取消置顶'));
        } else xalert('Something went wrong.');
    });
}
function renderSelfPin() {
    var self = document.getElementById('contactSelfAvatar');
    var csi = self ? self.closest('.csi') : null;
    if (csi) csi.classList.toggle('pinned', !!_pinnedSelf);
    var mark = document.getElementById('selfPinMark');
    if (mark) mark.style.display = _pinnedSelf ? 'inline' : 'none';
}

// 从群信息页退出/解散后，关闭聊天面板并刷新群列表
function afterGroupLeave() {
    closeDm();
    loadMyGroups();
    closeMyProfile();
}

function deleteDmContact(u) {
    var t = u || D;
    if (!t) return;
    document.getElementById('dmOptionsMenu').classList.remove('active');
    xconfirm('Permanently delete ' + t + '?').then(function(ok) {
        if (!ok) return;
        var f = new URLSearchParams();
        f.append('action', 'delete');
        f.append('username', t);
        fetch('../../api/contacts.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        }).then(function(r) {
            return r.json()
        }).then(function(d) {
            if (d.success) {
                if (D === t) closeDm();
                loadContacts();
                loadPending()
            } else xalert('Something went wrong.');
        });
    });
}

function changeNickname(u) {
    var t = u || D;
    if (!t) return;
    document.getElementById('dmOptionsMenu').classList.remove('active');
    xprompt('Nickname for ' + t + ':', t).then(function(n) {
        if (n === null || n === false) return;
        var f = new URLSearchParams();
        f.append('action', 'change_nickname');
        f.append('username', t);
        f.append('note', n);
        fetch('../../api/contacts.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        }).then(function(r) {
            return r.json()
        }).then(function(d) {
            if (d.success) {
                _contactNotes[t] = n || '';
                loadContacts();
                loadPending();
            } else xalert('Something went wrong.');
        });
    });
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dm-options-wrap')) document.getElementById('dmOptionsMenu').classList.remove('active');
});


function fmtSpeed(bps) {
    if (!isFinite(bps) || bps < 0) bps = 0;
    if (bps < 1000) return Math.round(bps) + ' B/s';
    if (bps < 1000000) return (bps / 1000).toFixed(1) + ' kB/s';
    return (bps / 1000000).toFixed(2) + ' MB/s';
}
function loadImageWithProgress(url, container) {
    if (container.querySelector('.img-loading-wrap') || container.querySelector('img')) return;
    var xhr = new XMLHttpRequest();
    var wrap = document.createElement('div');
    wrap.className = 'img-loading-wrap';
    wrap.innerHTML = '<div class="img-spinner"></div><div class="img-progress-bar"><div></div></div><div class="img-progress-text">0%</div>';
    container.appendChild(wrap);
    var bar = wrap.querySelector('.img-progress-bar div');
    var txt = wrap.querySelector('.img-progress-text');
    var lastLoaded = 0, lastTime = Date.now();
    var failed = false;
    function showError() {
        failed = true;
        wrap.innerHTML = '<div class="img-load-error">' + T('msg_img_load_fail') + '</div>';
        wrap.onclick = function() {
            if (wrap.parentNode) {
                wrap.parentNode.removeChild(wrap);
                loadImageWithProgress(url, container);
            }
        };
    }
    xhr.onprogress = function(ev) {
        if (failed || !ev.lengthComputable || ev.total <= 0) return;
        var pct = Math.round(ev.loaded / ev.total * 100);
        var now = Date.now();
        var dt = (now - lastTime) / 1000;
        var inst = 0;
        if (dt > 0.05) {
            inst = (ev.loaded - lastLoaded) / dt;
            lastLoaded = ev.loaded;
            lastTime = now;
        }
        bar.style.width = pct + '%';
        txt.textContent = pct + '% \u00b7 ' + fmtSpeed(inst);
    };
    xhr.onload = function() {
        if (xhr.status < 200 || xhr.status >= 300) { showError(); return; }
        var blob = xhr.response;
        var objUrl = URL.createObjectURL(blob);
        var img = document.createElement('img');
        img.src = objUrl;
        img.alt = '';
        if (container.classList.contains('img-fullscreen')) {
            img.style.cssText = 'max-width:95vw;max-height:95vh;object-fit:contain';
        } else {
            img.style.cssText = 'max-width:100%;max-height:200px;object-fit:contain;cursor:pointer';
        }
        img.onclick = function(ev) {
            if (container.classList.contains('img-fullscreen')) return;
            ev.stopPropagation();
            openFullscreen(url);
        };
        img.onload = function() {
            URL.revokeObjectURL(objUrl);
        };
        container.replaceChild(img, wrap);
    };
    xhr.onerror = showError;
    xhr.open('GET', url, true);
    xhr.responseType = 'blob';
    xhr.send();
}
function startImagesIn(node) {
    var boxes = node.querySelectorAll ? node.querySelectorAll('.msg-media[data-url]') : [];
    for (var i = 0; i < boxes.length; i++) {
        var url = boxes[i].getAttribute('data-url');
        if (url) loadImageWithProgress(url, boxes[i]);
    }
}

function attachmentHtml(attUrl, msgType) {
    if (!attUrl) return '';
    var isAudio = msgType === 'audio' || /\.(mp3|m4a|wav|aac|opus|flac|ogg)$/i.test(attUrl) || attUrl.indexOf('audio/') > -1;
    if (isAudio) {
        // 自定义可拖拽迷你播放器窗口（替代原生 <audio controls>）+ 下载按钮
        var _afn = this && this.attName ? this.attName : 'audio';
        var _as = this && this.attSize ? fmtSize(this.attSize) : '';
        return '<div class="msg-media msg-audio-card" data-url="' + attUrl + '" data-name="' + eh(_afn) + '" data-size="' + eh(_as) + '" onclick="event.stopPropagation();openAudioWin(this)">'
            + '<span class="audio-ico">&#127925;</span>'
            + '<span class="audio-name">' + eh(_afn) + '</span>'
            + '<span class="audio-size">' + eh(_as) + '</span>'
            + '<span class="audio-btn">' + T('btn_play', 'Play') + '</span>'
            + '</div>';
    }
    var isVideo = /\.(mp4|webm|mov|ogg)$/i.test(attUrl) || attUrl.indexOf('video/') > -1;
    if (isVideo) {
        if (DS) return '<div class="msg-media"><span class="click-to-load" data-video="' + attUrl + '" onclick="var v=document.createElement(\'video\');v.src=this.getAttribute(\'data-video\');v.controls=true;v.preload=\'none\';v.style.cssText=\'max-width:100%;max-height:200px;\';this.parentNode.replaceChild(v,this)">Click to load</span></div>';
        return '<div class="msg-media"><video src="' + attUrl + '" controls preload="none" style="max-width:100%;max-height:200px" onclick="event.stopPropagation();openFullscreen(\'' + attUrl + '\')"></video></div>';
    }
    if (msgType === 'photo' || /\.(jpg|png|gif|webp)$/i.test(attUrl)) {
        if (DS) return '<div class="msg-media"><span class="click-to-load" onclick="loadImageWithProgress(\'' + attUrl + '\',this.parentNode)">Click to load</span></div>';
        return '<div class="msg-media" data-url="' + attUrl + '"></div>';
    }
    // Generic file: show a polished download card with icon + name + size + button
    if (msgType === 'file' || /^[^.]+$/.test(attUrl) || !/\.(jpg|jpeg|png|gif|webp)$/i.test(attUrl)) {
        var fname = this && this.attName || '';
        var fsize = this && this.attSize || null;
        var sizeTxt = fsize ? fmtSize(fsize) : '';
        var nameTxt = fname ? eh(fname) : 'Download';
        var dlUrl = attUrl + (attUrl.indexOf('dl=1') >= 0 ? '' : (attUrl.indexOf('?') >= 0 ? '&dl=1' : '?dl=1'));
        var extTxt = fname ? (fname.split('.').pop() || '').substring(0,6).toUpperCase() : 'FILE';
        var hashTxt = '';
        var _mf = attUrl.match(/[?&]f=([^&]+)/);
        if (_mf && _mf[1]) hashTxt = _mf[1].replace(/\.[a-zA-Z0-9]+$/, '');
        var isCode = !!fname && !!CODE_EXT[(fname.split('.').pop() || '').toLowerCase()];
        var pvBtn = isCode ? '<button class="file-pv-btn" data-url="' + attUrl + '" data-name="' + eh(fname) + '" data-size="' + (fsize || 0) + '" onclick="previewCodeFile(this)">\u{1F441} ' + T('btn_preview', '预览') + '</button>' : '';
        return '<div class="file-card"><div class="file-card-body"><span class="file-icon">\u{1F4C4}</span><div class="file-info"><div class="file-name">' + nameTxt + '</div><div class="file-meta">' + extTxt + (sizeTxt ? ' \u00b7 ' + sizeTxt : '') + '</div>' + (hashTxt ? '<div class="file-hash">sha256: ' + hashTxt + '</div>' : '') + '</div></div><div class="file-actions">' + pvBtn + '<a class="file-dl-btn" href="' + dlUrl + '" target="_blank" download>\u2B07 ' + T('btn_download', '下载') + '</a></div></div>';
    }
    return '<div class="msg-media"><a href="' + attUrl + '" target="_blank">Download</a></div>';
}

/* ==================== 可拖拽迷你音频播放窗口（FileMgr 风格虚拟窗口） ==================== */
function fmtDur(sec) {
    if (!isFinite(sec) || sec < 0) sec = 0;
    sec = Math.floor(sec);
    return Math.floor(sec / 60) + ':' + ((sec % 60) < 10 ? '0' : '') + (sec % 60);
}
function openAudioWin(el) {
    var win = document.getElementById('audioWin');
    if (!win) return;
    var url = el.getAttribute('data-url') || '';
    var name = el.getAttribute('data-name') || 'audio';
    var size = el.getAttribute('data-size') || '';
    document.getElementById('audioWinTitle').textContent = name;
    document.getElementById('audioWinSub').textContent = size;
    var a = document.getElementById('audioWinAudio');
    a.src = url;
    a.load();
    document.getElementById('audioWinDownload').href = url;
    document.getElementById('audioWinDownload').setAttribute('download', name);
    document.getElementById('audioWinCur').textContent = '0:00';
    document.getElementById('audioWinDur').textContent = '0:00';
    document.getElementById('audioWinSeek').value = 0;
    document.getElementById('audioWinPlay').textContent = '\u25B6';
    if (!window._audioWinPos) {
        win.style.left = Math.max(8, (window.innerWidth - 330) / 2) + 'px';
        win.style.top = Math.max(8, (window.innerHeight - 180) / 2) + 'px';
        window._audioWinPos = true;
    }
    win.style.display = 'block';
    a.play().catch(function(){});
}
function closeAudioWin() {
    var a = document.getElementById('audioWinAudio');
    a.pause();
    a.removeAttribute('src');
    a.load();
    document.getElementById('audioWin').style.display = 'none';
}
function toggleAudioWinPlay() {
    var a = document.getElementById('audioWinAudio');
    if (a.paused) a.play().catch(function(){}); else a.pause();
}
function audioWinTime() {
    var a = document.getElementById('audioWinAudio');
    var cur = document.getElementById('audioWinCur');
    var dur = document.getElementById('audioWinDur');
    var seek = document.getElementById('audioWinSeek');
    var play = document.getElementById('audioWinPlay');
    cur.textContent = fmtDur(a.currentTime);
    if (isFinite(a.duration) && a.duration > 0) {
        dur.textContent = fmtDur(a.duration);
        seek.max = Math.floor(a.duration * 1000);
        if (!document.activeElement || document.activeElement.id !== 'audioWinSeek') seek.value = Math.floor(a.currentTime * 1000);
    }
    play.textContent = a.paused ? '\u25B6' : '\u275A\u275A';
}
function audioWinSeekInput() {
    var a = document.getElementById('audioWinAudio');
    if (isFinite(a.duration) && a.duration > 0) {
        a.currentTime = parseInt(document.getElementById('audioWinSeek').value, 10) / 1000;
    }
}
function audioWinVolInput() {
    var a = document.getElementById('audioWinAudio');
    a.volume = parseInt(document.getElementById('audioWinVol').value, 10) / 100;
}
function initAudioWinDrag() {
    var win = document.getElementById('audioWin');
    var bar = document.getElementById('audioWinBar');
    if (!win || !bar) return;
    var dragging = false, offX = 0, offY = 0;
    bar.addEventListener('mousedown', function(e) {
        if (e.target.closest && e.target.closest('.awin-close')) return;
        dragging = true;
        var r = win.getBoundingClientRect();
        offX = e.clientX - r.left;
        offY = e.clientY - r.top;
        e.preventDefault();
    });
    document.addEventListener('mousemove', function(e) {
        if (!dragging) return;
        win.style.left = Math.min(Math.max(4, e.clientX - offX), window.innerWidth - win.offsetWidth - 4) + 'px';
        win.style.top = Math.min(Math.max(4, e.clientY - offY), window.innerHeight - win.offsetHeight - 4) + 'px';
        window._audioWinPos = true;
    });
    document.addEventListener('mouseup', function() { dragging = false; });
}
(function initAudioWin() {
    var a = document.getElementById('audioWinAudio');
    if (a) {
        a.addEventListener('timeupdate', audioWinTime);
        a.addEventListener('loadedmetadata', audioWinTime);
        a.addEventListener('play', audioWinTime);
        a.addEventListener('pause', audioWinTime);
        a.addEventListener('ended', audioWinTime);
    }
    initAudioWinDrag();
})();

// ---- 代码文件预览（Monaco = VS Code 网页版编辑器；加载失败降级为纯文本） ----
var CODE_EXT = {
    'py':'python','pyw':'python','cpp':'cpp','cc':'cpp','cxx':'cpp','hpp':'cpp','hh':'cpp',
    'c':'c','h':'c','js':'javascript','mjs':'javascript','cjs':'javascript','jsx':'javascript',
    'ts':'typescript','tsx':'typescript','java':'java','go':'go','rs':'rust','php':'php',
    'html':'html','htm':'html','vue':'html','css':'css','scss':'scss','less':'less',
    'sh':'shell','bash':'shell','zsh':'shell','rb':'ruby','swift':'swift','kt':'kotlin','kts':'kotlin',
    'sql':'sql','json':'json','xml':'xml','yml':'yaml','yaml':'yaml','md':'markdown','markdown':'markdown',
    'ini':'ini','toml':'ini','cfg':'ini','conf':'ini','lua':'lua','pl':'perl','pm':'perl',
    'r':'r','dart':'dart','cs':'csharp','vb':'vb','ps1':'powershell','bat':'bat','cmd':'bat',
    'dockerfile':'dockerfile','makefile':'makefile','cmake':'cmake','gradle':'groovy','groovy':'groovy',
    'pve':'pvm2','pvm':'pvm2','pvs':'pvm2'   // PVM2 虚拟机汇编（用户自写扩展语法）
};
var _monacoBase = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.52.2/';
var _monacoPromise = null;
var _cpEditor = null;

function ensureMonaco() {
    if (window.monaco && window.monaco.editor && window.monaco.editor.create) return Promise.resolve(window.monaco);
    if (_monacoPromise) return _monacoPromise;
    _monacoPromise = new Promise(function(resolve, reject) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = _monacoBase + 'min/vs/editor/editor.main.min.css';
        document.head.appendChild(link);
        var prevRequire = window.require;
        window.require = { paths: { vs: _monacoBase + 'min/vs' } };
        var l = document.createElement('script');
        l.src = _monacoBase + 'min/vs/loader.js';
        l.onload = function() {
            var m = document.createElement('script');
            m.src = _monacoBase + 'min/vs/editor/editor.main.js';
            m.onload = function() {
                // monaco 命名空间同步出现，但 editor 模块异步加载完才有 .editor —— 轮询等待
                var tries = 0;
                (function poll() {
                    if (window.monaco && window.monaco.editor && window.monaco.editor.create) { resolve(window.monaco); return; }
                    if (++tries > 120) { window.require = prevRequire; reject(new Error('monaco editor not ready')); return; }
                    setTimeout(poll, 50);
                })();
            };
            m.onerror = function() { window.require = prevRequire; reject(new Error('monaco main load failed')); };
            document.head.appendChild(m);
        };
        l.onerror = function() { window.require = prevRequire; reject(new Error('monaco loader failed')); };
        document.head.appendChild(l);
    });
    return _monacoPromise;
}

// PVM2 自定义语言（Monarch tokenizer，对照用户自写扩展的 TextMate 语法实现）
function registerPvm2Language(mon) {
    if (!mon || !mon.languages || mon.__pvm2Reg) return;
    mon.__pvm2Reg = true;
    var id = 'pvm2', exists = false;
    try { exists = mon.languages.getLanguages().some(function (l) { return l.id === id; }); } catch (e) {}
    if (exists) return;
    mon.languages.register({ id: id, extensions: ['.pve', '.pvm', '.pvs'], aliases: ['PVM2', 'pvm2'] });
    mon.languages.setMonarchTokensProvider(id, {
        defaultToken: '',
        tokenPostfix: '.pvm2',
        keywords: ['function','if','elif','else','while','switch','case','break','return','exit'],
        types: ['uint64','uint32','uint16','uint8','int64','int32','int16','int8','RTLM','string','float','auto','void','any','int'],
        commands: ['mcpystr','mclear','mpush','mpop','mfind','mget','mset','mnew','mdel','mlen','mstr','print','sleep','read','echo','prn','inp','mov','inc','chr','ord','hlt','abrt','and','or','xor','shl','shr','sqrt','abs','neg','dec','not','add','sub','mul','div','pow','mod','rnd','eq','neq','gtr','gte','lte','lt'],
        brackets: [
            { open: '{', close: '}', token: 'delimiter.curly' },
            { open: '[', close: ']', token: 'delimiter.bracket' },
            { open: '(', close: ')', token: 'delimiter.parenthesis' }
        ],
        tokenizer: {
            root: [
                [/\/\*/, 'comment.block', '@blockComment'],
                [/\/\/.*$/, 'comment.line'],
                [/[ \t\r\n]+/, 'white'],
                [/[fF]"/, { token: 'string', next: '@fstrD' }],
                [/[fF]'/, { token: 'string', next: '@fstrS' }],
                [/"/, { token: 'string', next: '@strD' }],
                [/'/, { token: 'string', next: '@strS' }],
                [/!!?[A-Za-z][A-Za-z0-9]*/, 'keyword.directive'],
                [/\b0[xX][0-9a-fA-F]+/, 'number.hex'],
                [/-?\d+\.\d+/, 'number.float'],
                [/-?\d+/, 'number'],
                [/%[0-9]/, 'variable.register'],
                [/\bT[0-9]{1,2}\b/, 'variable.tmp'],
                [/\bL[0-9]{1,4}\b/, 'variable.local'],
                [/\bM[0-9]{1,4}\b/, 'variable.memory'],
                [/\$[0-9]+/, 'variable.parameter'],
                [/\b(function)\s+([A-Za-z_][A-Za-z0-9_]*)/, ['keyword', 'entity.name.function']],
                [/[a-zA-Z_][a-zA-Z0-9_]*/, { cases: { '@keywords': 'keyword', '@types': 'type', '@commands': 'support.function', '@default': 'identifier' } }],
                [/[{}()\[\]]/, '@brackets'],
                [/==|!=|>=|<=|>>|<<|&&|\|\||[+\-*/%=<>!]/, 'operator'],
                [/[;,]/, 'delimiter'],
                [/:/, 'delimiter']
            ],
            blockComment: [
                [/\*\//, 'comment.block', '@pop'],
                [/./, 'comment.block']
            ],
            strD: [ [/[^"\\]+/, 'string'], [/\\./, 'string.escape'], [/"/, { token: 'string', next: '@pop' }] ],
            strS: [ [/[^'\\]+/, 'string'], [/\\./, 'string.escape'], [/'/, { token: 'string', next: '@pop' }] ],
            fstrD: [ [/[^"{}\\]+/, 'string'], [/\\./, 'string.escape'], [/\{/, { token: 'string.escape', next: '@interp' }], [/"/, { token: 'string', next: '@pop' }] ],
            fstrS: [ [/[^'{}\\]+/, 'string'], [/\\./, 'string.escape'], [/\{/, { token: 'string.escape', next: '@interp' }], [/'/, { token: 'string', next: '@pop' }] ],
            interp: [
                [/[a-zA-Z_][a-zA-Z0-9_]*/, { cases: { '@types': 'type', '@commands': 'support.function', '@default': 'variable' } }],
                [/%[0-9]/, 'variable.register'],
                [/\bT[0-9]{1,2}\b/, 'variable.tmp'],
                [/\bL[0-9]{1,4}\b/, 'variable.local'],
                [/\bM[0-9]{1,4}\b/, 'variable.memory'],
                [/\$[0-9]+/, 'variable.parameter'],
                [/-?\d+/, 'number'],
                [/\}/, { token: 'string.escape', next: '@pop' }]
            ]
        }
    });
}

function previewCodeFile(btn) {
    if (!btn) return;
    var url = btn.getAttribute('data-url');
    var name = btn.getAttribute('data-name') || 'file';
    var size = btn.getAttribute('data-size') || '';
    var lang = CODE_EXT[(name.split('.').pop() || '').toLowerCase()] || 'plaintext';
    document.getElementById('cpName').textContent = name;
    document.getElementById('cpLang').textContent = lang;
    document.getElementById('cpSize').textContent = size ? fmtSize(size) : '';
    document.getElementById('codePreviewModal').classList.add('active');
    var loading = document.getElementById('cpLoading'),
        ed = document.getElementById('cpEditor');
    loading.style.display = 'block';
    ed.style.display = 'none';
    if (_cpEditor) { try { _cpEditor.dispose(); } catch (e) {} _cpEditor = null; }
    fetch(url).then(function(r) { return r.text(); }).then(function(text) {
        loading.style.display = 'none';
        ed.style.display = 'block';
        ensureMonaco().then(function(mon) {
            registerPvm2Language(mon);
            try {
                _cpEditor = mon.editor.create(ed, {
                    value: text,
                    language: lang,
                    theme: 'vs-dark',
                    readOnly: true,
                    minimap: { enabled: false },
                    fontSize: 13,
                    lineNumbers: 'on',
                    automaticLayout: true,
                    scrollBeyondLastLine: false,
                    renderWhitespace: 'selection'
                });
            } catch (e) { fallbackCodePreview(text); }
        }).catch(function() { fallbackCodePreview(text); });
    }).catch(function() {
        loading.style.display = 'none';
        ed.style.display = 'block';
        fallbackCodePreview(T('msg_load_failed', '加载失败'));
    });
}

function fallbackCodePreview(text) {
    var ed = document.getElementById('cpEditor');
    if (_cpEditor) { try { _cpEditor.dispose(); } catch (e) {} _cpEditor = null; }
    ed.innerHTML = '<pre class="cp-fallback">' + eh(text) + '</pre>';
}

function closeCodePreview() {
    document.getElementById('codePreviewModal').classList.remove('active');
    if (_cpEditor) { try { _cpEditor.dispose(); } catch (e) {} _cpEditor = null; }
}

function copyCodePreview() {
    var ed = document.getElementById('cpEditor');
    var text = '';
    if (_cpEditor) text = _cpEditor.getValue();
    else { var pre = ed.querySelector('.cp-fallback'); if (pre) text = pre.textContent; }
    if (!text) { xalert('Nothing to copy'); return; }
    function fallbackCopy(t) {
        var ta = document.createElement('textarea');
        ta.value = t;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
    }
    function done() { xalert(T('msg_copied', '已复制')); }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function() { fallbackCopy(text); done(); });
    } else fallbackCopy(text);
}

function replyMessage(id, name, preview) {
    _replyTarget = id;
    _replyData = {
        id: id,
        name: name,
        msg: preview
    };
    updateReplyIndicator();
    document.getElementById('dmMessageInput').focus();
}

function updateReplyIndicator() {
    var b = document.getElementById('replyBar');
    if (!b) return;
    if (_replyTarget) {
        b.style.display = 'flex';
        document.getElementById('replyBarText').textContent = T('msg_replying_to') + ' ' + eh(_replyData.name) + ': ' + _replyData.msg;
    } else {
        b.style.display = 'none';
    }
}

function cancelReply() {
    _replyTarget = null;
    _replyData = null;
    updateReplyIndicator();
}

function addDmMessage(m, prepend) {
    // 会话归属守卫：只渲染属于当前打开会话(D)的私聊消息——自聊/其它会话消息一律不串台
    // （群消息无 recipient，不受此守卫影响）
    if (D && m && m.recipient && !((m.username === U && m.recipient === D) || (m.username === D && m.recipient === U))) {
        return;
    }
    var a = document.getElementById('dmMessagesArea');
    // 点赞行允许重复渲染（原地更新次数/删除）；普通消息若已渲染过（seen 或 DOM 存在）则跳过，防止 loadDmMessages 全量重渲染产生重复
    if (m.msg_type !== 'like') {
        if (seenMsgIds['dm_' + m.id] || (a && a.querySelector('[data-msgid="' + m.id + '"]'))) return;
        seenMsgIds['dm_' + m.id] = 1;
    } else {
        seenMsgIds['dm_' + m.id] = 1;
    }
    var es = a.querySelector('.es');
    if (es) es.remove();
    if (m.msg_type === 'temp' && m.temp_upload_id) _removeFlashOptByTemp(m.temp_upload_id);
    var own = (m.username === U);
    // ---- 点赞系统消息：聊天中间灰色字，链接到被赞方个人主页；连续点赞合并为 ×n ----
    if (m.msg_type === 'like') {
        // 已撤回/合并废弃的点赞行：从 DOM 移除（历史行合并后服务端标 is_deleted）
        if (m.is_deleted) {
            var gone = a.querySelector('.like-sysline[data-msgid="' + m.id + '"]');
            if (gone) gone.remove();
            return;
        }
        var likedU = m.message || m.username || '';
        var likerN = eh(_contactNotes[m.username] || m.display_name || m.username);
        var likedN = eh(_contactNotes[likedU] || likedU);
        var lkMeta = {};
        try { lkMeta = JSON.parse(m.attachment || '') || {}; } catch (e) { lkMeta = {}; }
        var lkN = Math.max(1, parseInt(lkMeta.n || 1, 10) || 1);
        var lkHtml = '<a href="javascript:void(0)" onclick="openMyProfile(\'' + eh(likedU) + '\')">' + likerN + ' ' + T('p_liked_word', '赞了') + ' ' + likedN + (lkN > 1 ? ' ×' + lkN : '') + '</a>';
        var existing = a.querySelector('.like-sysline[data-msgid="' + m.id + '"]');
        if (existing) {
            if (existing.innerHTML !== lkHtml) existing.innerHTML = lkHtml;
            return;
        }
        var le = document.createElement('div');
        le.className = 'like-sysline';
        le.setAttribute('data-msgid', m.id);
        le.innerHTML = lkHtml;
        if (prepend) { le.style.order = '-1'; a.insertBefore(le, a.firstChild); }
        else a.appendChild(le);
        return;
    }
    // ---- E2EE 状态系统提示：聊天中间灰字（参照 like 系统行） ----
    if (m.msg_type === 'sys_e2ee') {
        var e2On = (m.message === 'on');
        var e2Txt = own
            ? (e2On ? T('e2ee_notice_me_on', '你已开启端到端加密') : T('e2ee_notice_me_off', '你已关闭端到端加密'))
            : (e2On ? T('e2ee_notice_them_on', '对方已开启端到端加密') : T('e2ee_notice_them_off', '对方已关闭端到端加密'));
        var e2el = document.createElement('div');
        e2el.className = 'like-sysline e2ee-sysline';
        e2el.setAttribute('data-msgid', m.id);
        e2el.textContent = e2Txt;
        if (prepend) { e2el.style.order = '-1'; a.insertBefore(e2el, a.firstChild); }
        else a.appendChild(e2el);
        return;
    }
    // ---- E2EE 密文消息：占位 → 异步解密 → 替换为明文行 ----
    if (m.msg_type === 'e2ee') {
        return addDmE2ee(m, prepend);
    }
    var d = buildDmMsgRow(m, own);
    appendDmMsgRow(a, d, m, prepend);
}

/** 聊天记录卡片（QQ 式）：attachment = JSON {peer, msgs:[{n,t,time}]} */
function chatlogCardHtml(m) {
    var data = {};
    try { data = JSON.parse(m.attachment || '{}'); } catch(e) {}
    var msgs = Array.isArray(data.msgs) ? data.msgs : [];
    var peer = data.peer || '';
    // eh() 不转义双引号（文本上下文），存属性值需手动把 " 转成 &quot;
    var clJson = eh(m.attachment || '').replace(/"/g, '&quot;');
    var h = '<div class="chatlog-card" onclick="openChatlogDetail(this)" data-cl="' + clJson + '">';
    h += '<div class="cl-head">' + eh(T('cl_title').replace('%s', peer || '…')) + '</div>';
    h += '<div class="cl-body">';
    for (var i = 0; i < msgs.length; i++) {
        var mm = msgs[i] || {};
        var t = mm.t != null ? String(mm.t) : '';
        var nm = mm.n != null ? String(mm.n) : '';
        h += '<div class="cl-line"><span class="cl-name">' + eh(nm) + '</span>' + (t !== '' ? ': ' + eh(t) : '') + '</div>';
    }
    h += '</div>';
    h += '<div class="cl-foot">' + T('cl_footer') + '</div>';
    h += '</div>';
    return h;
}
/** 点卡片打开聊天记录详情弹层（QQ 式） */
function openChatlogDetail(el) {
    var raw = el && el.dataset ? (el.dataset.cl || '') : ''; // dataset 会自动解码 HTML 实体
    var data = {};
    try { data = JSON.parse(raw || '{}'); } catch(e) {}
    var msgs = Array.isArray(data.msgs) ? data.msgs : [];
    var peer = data.peer || '';
    var title = document.getElementById('chatlogModalTitle');
    if (title) title.textContent = T('cl_title').replace('%s', peer || '…');
    var body = document.getElementById('chatlogModalBody');
    if (body) {
        if (!msgs.length) {
            body.innerHTML = '<div style="padding:20px;text-align:center;color:#666">…</div>';
        } else {
            var h = '';
            for (var i = 0; i < msgs.length; i++) {
                var mm = msgs[i] || {};
                var t = mm.t != null ? String(mm.t) : '';
                var nm = mm.n != null ? String(mm.n) : '';
                var time = mm.time || '';
                h += '<div class="cl-detail-line"><span class="cl-d-name">' + eh(nm) + '</span>' + (time ? '<span class="cl-d-time">' + eh(time) + '</span>' : '') + '<div class="cl-d-text">' + eh(t) + '</div></div>';
            }
            body.innerHTML = h;
        }
    }
    document.getElementById('chatlogModal').classList.add('active');
}
function closeChatlogDetail() {
    document.getElementById('chatlogModal').classList.remove('active');
}

/** 构建普通 DM 消息行（E2EE 解密后也复用）。 */
function buildDmMsgRow(m, own) {
    var d = document.createElement('div');
    d.className = 'mr' + (own ? ' own' : '');
    d.setAttribute('data-msgid', m.id);
    d.setAttribute('data-msguser', m.username);
    d.setAttribute('data-raw', m.message || '');
    var dl = m.is_deleted === true,
        dc = dl ? ' dl' : '',
        rh = '';
    var av = '';
    if (m.avatar) av = '<div class="msg-avatar" onclick="event.stopPropagation();openMyProfile(\'' + m.username + '\')"><img src="' + ehAttr(m.avatar) + '" alt=""></div>';
    var md;
    if (m.msg_type === 'temp' && m.temp_upload_id) md = tempCardHtml(m);
    else if (m.msg_type === 'doodle') md = doodleCardHtml(m);
    else if (m.msg_type === 'chatlog') md = chatlogCardHtml(m);
    else md = attachmentHtml.call({ attName: m.attachment_name || '', attSize: m.attachment_size || null }, m.attachment_url, m.msg_type);
    var rq = '';
    if (m.reply_data) {
        rq = '<div class="msg-reply-quote"><strong>' + eh(m.reply_data.display_name) + '</strong>: ' + m.reply_data.message + '</div>';
    }
    var msgContent = m.is_markdown ? renderMd(m.message) : renderEmoji(m.message);
    var emojiCode = extractFirstEmojiCode(msgContent);
    var emojiMenuItem = emojiCode ? '<div class="msg-emoji-add" data-emoji-code="' + eh(emojiCode) + '">' + T('menu_add_emoji') + '</div>' : '';
    var reportMenuItem = '<div class="msg-report" onclick="reportMsgFromMenu(this,\'' + m.username + '\');closeAllMsgMenus()">' + T('menu_report') + '</div>';
    // Flash messages: custom context menu (download/forward/reply/revoke+interrupt)
    var tempMenu = '';
    if (m.msg_type === 'temp' && m.temp_upload_id) {
        var isTempOwner = (m.username === U);
        var revokedNow = m.temp_revoked ? 1 : 0;
        if (!dl) {
            var dlItem = revokedNow
                ? '<div style="color:#555;cursor:not-allowed">' + T('btn_download', '下载') + '</div>'
                : '<div class="msg-fwd" onclick="tempDownload(' + m.temp_upload_id + ');closeAllMsgMenus()">' + T('btn_download', '下载') + '</div>';
            var fwdItem = revokedNow
                ? '<div style="color:#555;cursor:not-allowed">' + T('menu_forward') + '</div>'
                : '<div class="msg-fwd" onclick="flashForward(this,' + m.temp_upload_id + ');closeAllMsgMenus()">' + T('menu_forward') + '</div>';
            var revokeItem = '<div class="flash-revoke" onclick="flashInterrupt(this,' + m.temp_upload_id + ');closeAllMsgMenus()" style="color:#e06060">' + (isTempOwner ? T('flash_revoke_interrupt', '撤回并中断') : T('menu_revoke')) + '</div>';
            tempMenu = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu"><div class="msg-multi" onclick="enterMsgSelectMode(this);closeAllMsgMenus()">' + T('menu_multiselect') + '</div>' + dlItem + fwdItem + '<div onclick="replyDmMessage(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + revokeItem + reportMenuItem + '</div>';
        }
        rh = (own && !dl) ? tempMenu : ((!dl) ? tempMenu : '');
    } else if (own && !dl) rh = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu"><div class="msg-multi" onclick="enterMsgSelectMode(this);closeAllMsgMenus()">' + T('menu_multiselect') + '</div><div class="msg-fwd" onclick="openForwardModal(this);closeAllMsgMenus()">' + T('menu_forward') + '</div><div onclick="replyDmMessage(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + emojiMenuItem + reportMenuItem + '<div onclick="revokeDmMessage(' + m.id + ');closeAllMsgMenus()">' + T('menu_revoke') + '</div></div>';
    else if (!dl) rh = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu"><div class="msg-multi" onclick="enterMsgSelectMode(this);closeAllMsgMenus()">' + T('menu_multiselect') + '</div><div class="msg-fwd" onclick="openForwardModal(this);closeAllMsgMenus()">' + T('menu_forward') + '</div><div onclick="replyDmMessage(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + emojiMenuItem + reportMenuItem + '<div style="color:#555;cursor:not-allowed">' + T('menu_revoke') + '</div></div>';
    d.innerHTML = av + '<div class="mc"><div class="mb"><div class="mu">' + eh(_contactNotes[m.username] || m.display_name || m.username) + '</div>' + rq + '<div class="mt' + dc + '">' + msgContent + '</div>' + md + '<div class="mti">' + fmtTime(m.time) + '</div></div>' + rh + '</div>';
    return d;
}
function appendDmMsgRow(a, d, m, prepend) {
    if (prepend) d.style.order = '-1';
    // Start status polling for flash cards
    if (m.msg_type === 'temp' && m.temp_upload_id) {
        startTempPoll(d);
    }
    if (prepend) a.insertBefore(d, a.firstChild);
    else a.appendChild(d);
    startImagesIn(d);
    maybeAutoPlayDoodle(m);
}

/** E2EE 消息：占位 → 异步解密 → 替换为明文行；解密结果写入本地缓存（避免重载再解密失败）。 */
function addDmE2ee(m, prepend) {
    var a = document.getElementById('dmMessagesArea');
    var peer = (m.username === U) ? D : m.username;
    // 本地缓存里已有解密版本：直接渲染明文
    if (m._e2ee_decrypted) {
        var dd = buildDmMsgRow(m, m.username === U);
        appendDmMsgRow(a, dd, m, prepend);
        return;
    }
    var ph = document.createElement('div');
    ph.className = 'mr' + (m.username === U ? ' own' : '') + ' e2ee-decrypting';
    ph.setAttribute('data-msgid', m.id);
    ph.innerHTML = '<div class="mc"><div class="mb"><div class="mt">🔒 ' + T('e2ee_decrypting', '正在解密…') + '</div></div></div>';
    if (prepend) { ph.style.order = '-1'; a.insertBefore(ph, a.firstChild); }
    else a.appendChild(ph);
    var p = (window.E2EE && typeof E2EE.decrypt === 'function')
        ? E2EE.decrypt(peer, m.message)
        : Promise.reject(new Error('no-e2ee'));
    p.then(function (res) {
        if (res && res.plaintext != null) {
            m.message = res.plaintext;
            m.is_markdown = !!res.isMarkdown;
            m._e2ee_decrypted = true;
            lcPersistDecrypted('dm_' + (D || peer), m);
            var dd2 = buildDmMsgRow(m, m.username === U);
            if (ph.parentNode) ph.parentNode.replaceChild(dd2, ph);
        } else {
            throw new Error('empty');
        }
    }).catch(function () {
        ph.innerHTML = '<div class="mc"><div class="mb"><div class="mt e2ee-undecryptable"><a href="javascript:void(0)" onclick="event.stopPropagation();showE2eeCantDecryptTip()">🔒 ' + T('e2ee_cant_decrypt', '无法解密此消息') + '</a></div></div></div>';
    });
}

// 「无法解密此消息」点击提示：常见原因是双方浏览器/设备不同（密钥只保存在各自设备）
function showE2eeCantDecryptTip() {
    xalert(T('e2ee_cant_decrypt_hint', '无法解密此消息：常见原因是你与对方使用的浏览器或设备不同。端到端加密的密钥只保存在各自的设备上，换浏览器/设备后无法解密对方在旧设备上发的消息。'));
}

/** 用解密后的明文版本替换本地缓存中同 id 的条目（lcPersistMsg 只加不换）。 */
function lcPersistDecrypted(key, m) {
    if (!LC_READY || !m || !m.id) return;
    lcLoadChannel(key).then(function (msgs) {
        msgs = msgs || [];
        var replaced = false;
        for (var i = 0; i < msgs.length; i++) {
            if (msgs[i].id === m.id) { msgs[i] = m; replaced = true; break; }
        }
        if (!replaced) msgs.push(m);
        msgs.sort(function (x, y) { return (x.id || 0) - (y.id || 0); });
        if (msgs.length > 2000) msgs = msgs.slice(-2000);
        lcSaveChannel(key, msgs);
    });
}

function replyDmMessage(id) {
    var el = document.querySelector('#dmMessagesArea [data-msgid="' + id + '"]');
    if (!el) return;
    var mu = el.querySelector('.mu'),
        mt = el.querySelector('.mt');
    var name = mu ? mu.textContent : '';
    var preview = mt ? mt.textContent.substring(0, 50) : '';
    replyMessage(id, name, preview);
}
async function loadDmMessages(before) {
    if (!D) return;
    if (!before) {
        lcLoadChannel('dm_' + D).then(function(msgs) {
            if (msgs && msgs.length > 0) {
                var area = document.getElementById('dmMessagesArea');
                if (area && !area.querySelector('[data-msgid]')) {
                    _doodleBulk = true;
                    for (var i = 0; i < msgs.length; i++) {
                        var m = msgs[i];
                        if (m && m.id && !m.is_deleted) {
                            delete seenMsgIds['dm_' + m.id];
                            addDmMessage(m);
                        }
                    }
                    _doodleBulk = false;
                    var a = document.getElementById('dmMessagesArea');
                    if (a) scrollChatToBottom(a);
                }
            }
        }).catch(function() {});
    }
    try {
        var url = '../../api/chat.php?action=all&limit=50&dm=' + encodeURIComponent(D);
        if (before) url += '&before=' + before;
        var r = await fetch(url),
            d = await r.json();
        if (d.success) {
            if (!before && !document.getElementById('dmMessagesArea').querySelector('[data-msgid]')) {
                document.getElementById('dmMessagesArea').innerHTML = '<div class="es"><p>' + T('msg_start_chatting') + ' ' + eh(D) + '</p></div>';
            }
            var lm = document.getElementById('loadMoreDmBtn');
            if (lm) lm.remove();
            var maxId = 0;
            _doodleBulk = true;
            for (var i = 0; i < d.messages.length; i++) {
                var m = d.messages[i];
                if (m.id > maxId) maxId = m.id;
                if (m.recipient && ((m.username === U && m.recipient === D) || (m.username === D && m.recipient === U))) {
                    // 被废弃的点赞行（历史合并后标 is_deleted）：同步清理本地缓存副本
                    if (m.msg_type === 'like' && m.is_deleted) lcMarkRevoked('dm_' + D, m.id);
                    delete seenMsgIds['dm_' + m.id];
                    addDmMessage(m, !!before)
                }
            }
            _doodleBulk = false;
            lcPersistBatch('dm_' + D, d.messages);
            if (maxId > L) L = maxId;
            _dmOldest = d.oldest_id || 0;
            // 渲染完成后按 msgid 重排 DOM，修正任何乱序（脏缓存等）
            if (!before) {
                var area2 = document.getElementById('dmMessagesArea');
                if (area2) {
                    var arr = Array.prototype.slice.call(area2.children);
                    arr.sort(function(x, y) {
                        return (+(x.getAttribute('data-msgid') || 0)) - (+(y.getAttribute('data-msgid') || 0));
                    });
                    for (var k = 0; k < arr.length; k++) area2.appendChild(arr[k]);
                }
            }
            if (!before) {
                var a = document.getElementById('dmMessagesArea');
                if (a) scrollChatToBottom(a);
            } else {
                _dmLoading = false;
            }
        }
    } catch (e) {}
}

function onDmInput() {
    if (!D || S) return;
    clearTimeout(typingTimer);
    // 「我的输入状态可见」关闭时不发送打字指示
    if (typeof TYPING_VIS !== 'undefined' && !TYPING_VIS) return;
    // typing 走 HTTP（原样保留）
    fetch('../../api/status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'action=typing&to=' + encodeURIComponent(D)
    }).catch(function() {});
    typingTimer = setTimeout(function() {
        fetch('../../api/status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'action=clear_typing'
        }).catch(function() {});
    }, 3000);
}
async function sendDmMessage() {
    if (CLIENT_LOCKED || !D || S || RSTR) return;
    var i = document.getElementById('dmMessageInput'),
        m = i.value.trim();
    if (!m && !pendingDmMedia) return;
    if (!m && pendingDmMedia) m = '';
    S = true;
    document.getElementById('dmSendBtn').disabled = true;
    try {
        var pDm = {
            message: m,
            recipient: D
        };
        if (_replyTarget) pDm.reply_to = _replyTarget;
        if (document.getElementById('mdCheckDm').checked) pDm.md = '1';
        if (pendingDmMedia) {
            var _at = pendingDmMedia;
            pendingDmMedia = null;
            pDm.attachment = _at.data || _at;
            if (_at.name) pDm.filename = _at.name;
        }
        // E2EE：对话已开启且无附件 → 加密后发送（msg_type='e2ee'，服务器只存密文 envelope）
        // 安全策略：加密失败绝不静默明文发送（fail-closed），而是阻止发送并明确提示。
        if (!pendingDmMedia && D && _dmE2eeOn && window.E2EE && window.nacl) {
            var e2On = await E2EE.getStatus(D).catch(function () { return false; });
            if (e2On) {
                try {
                    var env = await E2EE.encrypt(D, m, !!pDm.md);
                    pDm.message = JSON.stringify(env);
                    pDm.msg_type = 'e2ee';
                    delete pDm.md; // 密文里已带 md 标志，明文渲染时恢复
                } catch (err) {
                    i.focus();
                    xalert(T('e2ee_encrypt_fail', '🔒 无法加密：对方尚未准备好端到端加密密钥，或会话不可用。消息未发送（不会明文发送）。'));
                    return;
                }
            }
        }
        var d = await apiRequest('send', pDm);
        if (d.success) {
            i.value = '';
            _replyTarget = null;
            _replyData = null;
            updateReplyIndicator();
            document.getElementById('mdPreviewDm').classList.remove('active');
            delete seenMsgIds['dm_' + d.message_id];
            await loadDmMessages();
            requestAnimationFrame(function() {
                var da = document.getElementById('dmMessagesArea');
                if (da) scrollChatToBottom(da);
            });
            document.getElementById('dmMediaFile').value = '';
        } else if (d.error && d.error.indexOf('restricted') >= 0) {
            xalert('Failed to send: The user is restricted.');
        } else if (d.error === 'Too large') {
            xalert('Attachment too large for your level. Max: ' + fmtSize((d.max_attach_kb || 0) * 1024));
        } else xalert(d.error || 'Something went wrong.');
    } catch (e) {
        xalert('Something went wrong.');
    } finally {
        S = false;
        document.getElementById('dmSendBtn').disabled = false;
        i.focus()
    }
}
async function sendGroupMessage() {
    if (CLIENT_LOCKED || !G || S || RSTR) return;
    var i = document.getElementById('dmMessageInput'),
        m = i.value.trim();
    if (!m && !pendingDmMedia) return;
    if (!m && pendingDmMedia) m = '';
    S = true;
    document.getElementById('dmSendBtn').disabled = true;
    try {
        var f = new URLSearchParams();
        f.append('action', 'send');
        f.append('group_id', G);
        f.append('message', m);
        if (pendingDmMedia) {
            var _at = pendingDmMedia;
            pendingDmMedia = null;
            f.append('attachment', _at.data || _at);
            if (_at.name) f.append('filename', _at.name);
        }
        var r = await fetch('../../api/group.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: f.toString()
            }),
            d = await r.json();
        if (d.success) {
            i.value = '';
            _replyTarget = null;
            _replyData = null;
            updateReplyIndicator();
            document.getElementById('mdPreviewDm').classList.remove('active');
            document.getElementById('dmMediaFile').value = '';
            loadGroupMessages(G);
        } else {
            xalert(d.error || T('flash_fail', '发送失败'));
        }
    } catch (e) {
        xalert(T('flash_fail', '发送失败'));
    } finally {
        S = false;
        document.getElementById('dmSendBtn').disabled = false;
        i.focus()
    }
}
var _glast = 0;
async function loadGroupMessages(gid) {
    if (!G || G !== gid) return;
    var url = '../../api/group.php?action=fetch&group_id=' + gid + '&after=' + _glast;
    var d = await fetch(url).then(r => r.json());
    if (!d.success) return;
    if (d.messages.length > 0) {
        for (var i = 0; i < d.messages.length; i++) {
            var m = d.messages[i];
            if (m.id > _glast) _glast = m.id;
            delete seenMsgIds['dm_' + m.id];
            addDmMessage(m);
            lcPersistMsg('group_' + gid, m);
        }
        var a = document.getElementById('dmMessagesArea');
        if (a) scrollChatToBottom(a);
    } else if (d.latest_id > _glast) {
        _glast = d.latest_id;
    }
}
var _gPoll = null;
function maybePollGroups() {
    if (G) loadGroupMessages(G);
}
// 群轮询保持原 1500ms（WS 只推当前订阅的群，轮询兜底保证新加入的群不漏消息）
if (_gPoll === null) {
    _gPoll = setInterval(maybePollGroups, 1500);
}
async function revokeDmMessage(id) {
    var d = await apiRequest('revoke', { message_id: id });
    if (d.success) {
        var el = document.querySelector('#dmMessagesArea [data-msgid="' + id + '"]');
        if (el) {
            var t = el.querySelector('.mt');
            if (t) {
                t.textContent = T('msg_revoked');
                t.classList.add('dl')
            }
            var md = el.querySelector('.msg-media');
            if (md) md.remove();
            var rv = el.querySelector('.mrv');
            if (rv) rv.remove()
        }
    } else xalert('Something went wrong.')
}

function addAnnouncement(m, prepend) {
    if (m.recipient) return;
    if (seenMsgIds['an_' + m.id]) return;
    seenMsgIds['an_' + m.id] = 1;
    var a = document.getElementById('messagesArea'),
        es = a.querySelector('.es');
    if (es) es.remove();
    if (m.msg_type === 'temp' && m.temp_upload_id) _removeFlashOptByTemp(m.temp_upload_id);
    var own = (m.username === U),
        d = document.createElement('div');
    d.className = 'mr' + (own ? ' own' : '');
    d.setAttribute('data-msgid', m.id);
    d.setAttribute('data-msguser', m.username);
    d.setAttribute('data-raw', m.message || '');
    if (prepend) d.style.order = '-1';
    var dl = m.is_deleted === true,
        dc = dl ? ' dl' : '',
        rh = '';
    var av = '';
    if (m.avatar) av = '<div class="msg-avatar" onclick="event.stopPropagation();openMyProfile(\'' + m.username + '\')"><img src="' + ehAttr(m.avatar) + '" alt=""></div>';
    var md = (m.msg_type === 'temp' && m.temp_upload_id)
        ? tempCardHtml(m)
        : (m.msg_type === 'chatlog' ? chatlogCardHtml(m) : attachmentHtml.call({ attName: m.attachment_name || '', attSize: m.attachment_size || null }, m.attachment_url, m.msg_type));
    var rq = '';
    if (m.reply_data) {
        rq = '<div class="msg-reply-quote"><strong>' + eh(m.reply_data.display_name) + '</strong>: ' + m.reply_data.message + '</div>';
    }
    var msgContent = m.is_markdown ? renderMd(m.message) : renderEmoji(m.message);
    var emojiCode = extractFirstEmojiCode(msgContent);
    var emojiMenuItem = emojiCode ? '<div class="msg-emoji-add" data-emoji-code="' + eh(emojiCode) + '">' + T('menu_add_emoji') + '</div>' : '';
    var reportMenuItem = '<div class="msg-report" onclick="reportMsgFromMenu(this,\'' + m.username + '\');closeAllMsgMenus()">' + T('menu_report') + '</div>';
    if (m.msg_type === 'temp' && m.temp_upload_id) {
        var isTempOwner = (m.username === U);
        var revokedNow = m.temp_revoked ? 1 : 0;
        if (!dl) {
            var dlItem = revokedNow
                ? '<div style="color:#555;cursor:not-allowed">' + T('btn_download', '下载') + '</div>'
                : '<div class="msg-fwd" onclick="tempDownload(' + m.temp_upload_id + ');closeAllMsgMenus()">' + T('btn_download', '下载') + '</div>';
            var fwdItem = revokedNow
                ? '<div style="color:#555;cursor:not-allowed">' + T('menu_forward') + '</div>'
                : '<div class="msg-fwd" onclick="flashForward(this,' + m.temp_upload_id + ');closeAllMsgMenus()">' + T('menu_forward') + '</div>';
            var revokeItem = '<div class="flash-revoke" onclick="flashInterrupt(this,' + m.temp_upload_id + ');closeAllMsgMenus()" style="color:#e06060">' + (isTempOwner ? T('flash_revoke_interrupt', '撤回并中断') : T('menu_revoke')) + '</div>';
            rh = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu"><div class="msg-multi" onclick="enterMsgSelectMode(this);closeAllMsgMenus()">' + T('menu_multiselect') + '</div>' + dlItem + fwdItem + '<div onclick="replyAnnouncement(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + revokeItem + reportMenuItem + '</div>';
        }
    } else if (own && !dl) rh = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu"><div class="msg-multi" onclick="enterMsgSelectMode(this);closeAllMsgMenus()">' + T('menu_multiselect') + '</div><div class="msg-fwd" onclick="openForwardModal(this);closeAllMsgMenus()">' + T('menu_forward') + '</div><div onclick="replyAnnouncement(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + emojiMenuItem + reportMenuItem + '<div onclick="revokeAnnouncement(' + m.id + ');closeAllMsgMenus()">' + T('menu_revoke') + '</div></div>';
    else if (!dl) rh = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu"><div class="msg-multi" onclick="enterMsgSelectMode(this);closeAllMsgMenus()">' + T('menu_multiselect') + '</div><div class="msg-fwd" onclick="openForwardModal(this);closeAllMsgMenus()">' + T('menu_forward') + '</div><div onclick="replyAnnouncement(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + emojiMenuItem + reportMenuItem + '<div style="color:#555;cursor:not-allowed">' + T('menu_revoke') + '</div></div>';
    d.innerHTML = av + '<div class="mc"><div class="mb"><div class="mu">' + eh(_contactNotes[m.username] || m.display_name || m.username) + '</div>' + rq + '<div class="mt' + dc + '">' + msgContent + '</div>' + md + '<div class="mti">' + fmtTime(m.time) + '</div></div>' + rh + '</div>';
    if (m.msg_type === 'temp' && m.temp_upload_id) {
        startTempPoll(d);
    }
    if (prepend) a.insertBefore(d, a.firstChild);
    else a.appendChild(d);
    startImagesIn(d);
}

function replyAnnouncement(id) {
    var el = document.querySelector('#messagesArea [data-msgid="' + id + '"]');
    if (!el) return;
    var mu = el.querySelector('.mu'),
        mt = el.querySelector('.mt');
    var name = mu ? mu.textContent : '';
    var preview = mt ? mt.textContent.substring(0, 50) : '';
    replyMessage(id, name, preview);
}
async function loadMoreAnnouncements() {
    _annLoading = true;
    try {
        var oldest = 0,
            children = document.getElementById('messagesArea').children;
        for (var i = 0; i < children.length; i++) {
            var id = parseInt(children[i].getAttribute('data-msgid'));
            if (id > 0 && (oldest === 0 || id < oldest)) oldest = id;
        }
        if (!oldest) { _annLoading = false; return; }
        var r = await fetch('../../api/chat.php?action=all&before=' + oldest + '&limit=50'),
            d = await r.json();
        if (d.success) {
            var lm = document.getElementById('loadMoreBtn');
            if (lm) lm.remove();
            for (var i = 0; i < d.messages.length; i++) {
                var m = d.messages[i];
                if (!m.recipient) addAnnouncement(m, true)
            }
            if (d.has_more) {
                var btn = document.createElement('div');
                btn.id = 'loadMoreBtn';
                btn.style.cssText = 'text-align:center;padding:8px;color:#888;cursor:pointer;font-size:.72em';
                btn.textContent = T('btn_load_older');
                btn.onclick = loadMoreAnnouncements;
                var area = document.getElementById('messagesArea');
                if (area.firstChild) area.insertBefore(btn, area.firstChild);
                else area.appendChild(btn);
            }
        }
    } catch (e) {}
    _annLoading = false;
}
async function loadGroupHistoryChunk(before) {
    if (!G || _grpLoading) return;
    _grpLoading = true;
    try {
        var d2 = await fetch('../../api/group.php?action=history&group_id=' + G + '&before=' + before).then(function(r) { return r.json(); });
        if (!d2.success) { _grpLoading = false; return; }
        for (var j = 0; j < d2.messages.length; j++) addDmMessage(d2.messages[j], true);
        _dmOldest = d2.oldest_id || 0;
    } catch (e) {}
    _grpLoading = false;
    _dmLoading = false;
}
/* ============ 新消息提醒（浏览器通知 + App 内横幅） ============ */
/* 门控：DND（勿扰）> NOTIF_SYS（系统消息通知）> NOTIF_BANNER（App 内横幅） */
window.notifyNewMessage = function(m) {
    if (!m) return;
    if (typeof DND !== 'undefined' && DND) return;
    var nTitle = m.display_name || m.username;
    var nBody = m.message || '';
    if (m.attachment_url) nBody = nBody || '[Photo/Video]';
    // 浏览器系统通知
    if (typeof NOTIF_SYS === 'undefined' || NOTIF_SYS) {
        if ('Notification' in window && Notification.permission === 'granted') {
            var nOpt = { body: nBody, tag: 'chatapp-' + (m.id || Date.now()) };
            if (m.avatar) nOpt.icon = m.avatar;
            try {
                var notif = new Notification(nTitle, nOpt);
                notif.onclick = function() {
                    window.focus();
                    if (m.recipient) openDm(m.username);
                    else switchPanel('announcements');
                    notif.close();
                };
            } catch (e) {}
        }
    }
    // App 内横幅
    if (typeof NOTIF_BANNER === 'undefined' || NOTIF_BANNER) {
        showInAppBanner(nTitle, nBody, m.username);
    }
};

var _inAppBannerTimer = null;
function showInAppBanner(title, body, username) {
    var host = document.getElementById('inAppBanner');
    if (!host) {
        host = document.createElement('div');
        host.id = 'inAppBanner';
        document.body.appendChild(host);
    }
    host.innerHTML = '';
    var el = document.createElement('div');
    el.className = 'app-banner';
    el.innerHTML = '<b>' + eh(title) + '</b><span>' + eh(body || '') + '</span>';
    if (username) {
        el.addEventListener('click', function() {
            if (typeof openDm === 'function') { openDm(username); closeInAppBanner(); }
        });
    }
    host.appendChild(el);
    if (_inAppBannerTimer) clearTimeout(_inAppBannerTimer);
    _inAppBannerTimer = setTimeout(closeInAppBanner, 4000);
}
function closeInAppBanner() {
    var host = document.getElementById('inAppBanner');
    if (host) { host.innerHTML = ''; }
}

async function pm() {
    if (!_loaded) return;
    // WSS 在线时跳过 HTTP 轮询：新消息已由 WSS 推送（type:msg）
    if (typeof window.wssRequestAvailable === 'function' && window.wssRequestAvailable()) return;
    try {
        // 打开私聊时把轮询收敛到该会话（服务端按 dm 过滤），避免自聊/其它会话消息串台
        var pollUrl = '../../api/chat.php?action=fetch&after=' + L + (D ? '&dm=' + encodeURIComponent(D) : '');
        var r = await fetch(pollUrl),
            d = await r.json();
        if (d.success && d.messages.length > 0) {
            for (var i = 0; i < d.messages.length; i++) {
                var m = d.messages[i];
                if (!m.recipient) { addAnnouncement(m); lcPersistMsg('announcement', m); }
                if (D && m.recipient && ((m.username === U && m.recipient === D) || (m.username === D && m.recipient === U))) { addDmMessage(m); lcPersistMsg('dm_' + D, m); }
                if (m.recipient && m.username !== U && m.username !== D) {
                    if (!unreadCounts[m.username]) unreadCounts[m.username] = 0;
                    unreadCounts[m.username]++;
                }
                if (m.username !== U && !m.is_deleted && !seenMsgIds['notif_' + m.id] && m.id > L) {
                    seenMsgIds['notif_' + m.id] = 1;
                    notifyNewMessage(m);
                }
            }
        }
        L = d.latest_id;
        updateUnreads();
    } catch (e) {}
}
async function sendAnnouncement() {
    if (S || RSTR) return;
    var i = document.getElementById('messageInput'),
        m = i.value.trim();
    if (!m && !pendingMedia) return;
    if (!m && pendingMedia) m = '';
    S = true;
    document.getElementById('sendBtn').disabled = true;
    try {
        var pAnn = {
            message: m
        };
        if (_replyTarget) pAnn.reply_to = _replyTarget;
        if (document.getElementById('mdCheckAnn').checked) pAnn.md = '1';
        if (pendingMedia) {
            var _at = pendingMedia;
            pendingMedia = null;
            pAnn.attachment = _at.data || _at;
            if (_at.name) pAnn.filename = _at.name;
        }
        var d = await apiRequest('send', pAnn);
        if (d.success) {
            i.value = '';
            _replyTarget = null;
            _replyData = null;
            updateReplyIndicator();
            document.getElementById('mdPreviewAnn').classList.remove('active');
            pm();
            // 自己发的公告：WSS 不会回声自己的消息（ws_refresh_client 过滤 username===自己），
            // 且 pm() 在 WSS 在线时会跳过 HTTP 轮询 → 这里显式拉取并本地渲染，否则自己看不到自己发的公告。
            try {
                var rf = await fetch('../../api/chat.php?action=fetch&after=' + L);
                var rd = await rf.json();
                if (rd.success && rd.messages.length > 0) {
                    for (var j = 0; j < rd.messages.length; j++) {
                        var nm = rd.messages[j];
                        if (!nm.recipient) { addAnnouncement(nm); lcPersistMsg('announcement', nm); }
                    }
                    if (rd.latest_id > L) L = rd.latest_id;
                }
            } catch (e) {}
            requestAnimationFrame(function() {
                var ma = document.getElementById('messagesArea');
                if (ma) scrollChatToBottom(ma);
            });
            document.getElementById('mediaFile').value = '';
        } else if (d.error === 'Too large') {
            xalert('Attachment too large for your level. Max: ' + fmtSize((d.max_attach_kb || 0) * 1024));
        } else xalert('Something went wrong.');
    } catch (e) {
        xalert('Something went wrong.');
    } finally {
        S = false;
        document.getElementById('sendBtn').disabled = false;
        i.focus()
    }
}
async function revokeAnnouncement(id) {
    var d = await apiRequest('revoke', { message_id: id });
    if (d.success) {
        var el = document.querySelector('#messagesArea [data-msgid="' + id + '"]');
        if (el) {
            var t = el.querySelector('.mt');
            if (t) {
                t.textContent = T('msg_revoked');
                t.classList.add('dl')
            }
            var md = el.querySelector('.msg-media');
            if (md) md.remove();
            var rv = el.querySelector('.mrv');
            if (rv) rv.remove()
        }
    } else xalert('Something went wrong.')
}
async function initialLoad() {
    try {
        var r = await fetch('../../api/chat.php?action=all'),
            d = await r.json();
        if (d.success && d.messages.length > 0) {
            for (var i = 0; i < d.messages.length; i++) addAnnouncement(d.messages[i]);
            L = d.latest_id;
        }
        _loaded = true;
        loadBg();
        requestAnimationFrame(function() {
            scrollChatToBottom(document.getElementById('messagesArea'));
        });
    } catch (e) {}
}

function openFullscreen(src) {
    var o = document.getElementById('imgFullscreen');
    var isVid = /\.(mp4|webm|mov|ogg)$/i.test(src);
    o.onclick = function() {
        o.classList.remove('active');
        o.innerHTML = '';
    };
    o.classList.add('active');
    if (isVid) {
        o.innerHTML = '<video src="' + src + '" controls style="max-width:95vw;max-height:95vh;object-fit:contain" autoplay></video>';
        return;
    }
    o.innerHTML = '';
    loadImageWithProgress(src, o);
}

function sm(t, m) {
    var e = document.getElementById(t + 'Msg');
    e.textContent = m;
    e.classList.add('show');
    setTimeout(function() {
        e.classList.remove('show')
    }, 3000)
}
async function changeLanguage() {
    var lang = document.getElementById('languageSelect').value;
    var f = new URLSearchParams();
    f.append('action', 'change_language');
    f.append('language', lang);
    var d = await fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) {
        location.reload();
    } else sm('error', 'Something went wrong.')
}
async function changeDisplayName() {
    var dn = document.getElementById('displayNameInput').value.trim();
    var f = new URLSearchParams();
    f.append('action', 'change_display_name');
    f.append('display_name', dn);
    var d = await fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) {
        sm('success', 'Display name updated.');
        document.querySelector('.sun').textContent = dn || U;
    } else sm('error', 'Something went wrong.')
}

function toggleCustomTitle() {
    var f = document.getElementById('customTitleField'),
        b = document.getElementById('customTitleBtn'),
        s = document.getElementById('customTitleStatus');
    if (f.style.display === 'none') {
        f.style.display = 'block';
        b.textContent = 'Disable';
        s.textContent = 'Custom title is ON';
    } else {
        f.style.display = 'none';
        b.textContent = 'Enable';
        s.textContent = 'Custom title is OFF';
        var ff = new URLSearchParams();
        ff.append('action', 'change_custom_title');
        ff.append('custom_title', '');
        fetch('../../api/settings.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: ff.toString()
        }).then(r => r.json()).then(function(d) {
            if (d.success) document.title = 'ChatApp';
        }); 
    }
}
async function saveCustomTitle() {
    var t = document.getElementById('customTitleInput').value.trim();
    var f = new URLSearchParams();
    f.append('action', 'change_custom_title');
    f.append('custom_title', t);
    var d = await fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) {
        document.title = t || 'ChatApp';
        sm('success', 'Custom title saved.')
    } else sm('error', 'Something went wrong.');
}
async function changePassword(e) {
    e.preventDefault();
    var c = document.getElementById('currentPassword').value,
        n = document.getElementById('newPassword').value;
    var f = new URLSearchParams();
    f.append('action', 'change_password');
    f.append('current_password', c);
    f.append('new_password', n);
    var d = await fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) {
        sm('success', 'Password changed.');
        document.getElementById('currentPassword').value = '';
        document.getElementById('newPassword').value = '';
    } else sm('error', 'Something went wrong.')
}
async function savePrivacySettings() {
    var s = document.getElementById('privacySearchable').checked ? 1 : 0;
    var u = document.getElementById('privacySearchableByUid').checked ? 1 : 0;
    var f = new URLSearchParams();
    f.append('action', 'save_privacy');
    f.append('searchable', s);
    f.append('searchable_by_uid', u);
    var d = await fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) {
        sm('success', 'Privacy settings saved.')
    } else sm('error', 'Something went wrong.')
}
async function changeTimezone() {
    var tz = document.getElementById('timezoneInput').value.trim();
    if (!/^[+-]\d{2}:\d{2}$/.test(tz)) {
        sm('error', 'Something went wrong.');
        return
    }
    var f = new URLSearchParams();
    f.append('action', 'change_timezone');
    f.append('timezone', tz);
    var d = await fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) {
        TZ = tz;
        sm('success', 'Timezone updated.')
    } else sm('error', 'Something went wrong.')
}
var CI = null,
    cx1 = 0,
    cy1 = 0,
    cx2 = 0,
    cy2 = 0,
    cA = false;
document.getElementById('avatarFile').addEventListener('change', function(e) {
    var f = e.target.files[0];
    if (!f) return;
    var r = new FileReader();
    r.onload = function(ev) {
        var i = new Image();
        i.onload = function() {
            CI = i;
            openCropModal()
        };
        i.src = ev.target.result
    };
    r.readAsDataURL(f);
});

function openCropModal() {
    if (!CI) return;
    var c = document.getElementById('cropCanvas'),
        s = Math.min(window.innerWidth * .85 / CI.width, window.innerHeight * .55 / CI.height, 1);
    c.width = CI.width * s;
    c.height = CI.height * s;
    var ctx = c.getContext('2d');
    ctx.drawImage(CI, 0, 0, c.width, c.height);
    cx1 = 0;
    cy1 = 0;
    cx2 = c.width;
    cy2 = c.height;
    document.getElementById('cropOverlay').classList.add('active');
}

function drawCrop() {
    var c = document.getElementById('cropCanvas'),
        ctx = c.getContext('2d'),
        img = new Image();
    img.onload = function() {
        ctx.clearRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0, c.width, c.height);
        var x1 = Math.min(cx1, cx2),
            y1 = Math.min(cy1, cy2),
            x2 = Math.max(cx1, cx2),
            y2 = Math.max(cy1, cy2),
            w = x2 - x1,
            h = y2 - y1;
        ctx.fillStyle = 'rgba(0,0,0,0.5)';
        ctx.fillRect(0, 0, c.width, y1);
        ctx.fillRect(0, y2, c.width, c.height - y2);
        ctx.fillRect(0, y1, x1, h);
        ctx.fillRect(x2, y1, c.width - x2, h);
        ctx.strokeStyle = '#888';
        ctx.lineWidth = 2;
        ctx.strokeRect(x1, y1, w, h);
    };
    img.src = CI.src;
}
document.getElementById('cropCanvas').addEventListener('mousedown', function(e) {
    var r = this.getBoundingClientRect();
    cx1 = e.clientX - r.left;
    cy1 = e.clientY - r.top;
    cA = true
});
document.getElementById('cropCanvas').addEventListener('mousemove', function(e) {
    if (!cA) return;
    var r = this.getBoundingClientRect();
    cx2 = e.clientX - r.left;
    cy2 = e.clientY - r.top;
    drawCrop()
});
document.getElementById('cropCanvas').addEventListener('mouseup', function(e) {
    if (cA) {
        var r = this.getBoundingClientRect();
        cx2 = e.clientX - r.left;
        cy2 = e.clientY - r.top;
        cA = false;
        drawCrop()
    }
});

function doCrop() {
    var c = document.getElementById('cropCanvas'),
        x1 = Math.min(cx1, cx2),
        y1 = Math.min(cy1, cy2),
        x2 = Math.max(cx1, cx2),
        y2 = Math.max(cy1, cy2),
        w = x2 - x1,
        h = y2 - y1;
    if (w < 10 || h < 10) {
        xalert('Too small');
        return
    }
    var cc = document.createElement('canvas');
    cc.width = 150;
    cc.height = 150;
    var ctx = cc.getContext('2d');
    ctx.drawImage(CI, x1 / c.width * CI.width, y1 / c.height * CI.height, w / c.width * CI.width, h / c.height * CI.height, 0, 0, 150, 150);
    uploadAvatarToServer(cc.toDataURL('image/png'));
    cancelCrop();
}

function cancelCrop() {
    document.getElementById('cropOverlay').classList.remove('active');
    CI = null;
    document.getElementById('avatarFile').value = '';
}

function uploadAvatar() {
    document.getElementById('avatarFile').click();
}
async function uploadAvatarToServer(b64) {
    var f = new URLSearchParams();
    f.append('action', 'upload_avatar');
    f.append('avatar', b64);
    var d = await fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) {
        sm('success', 'Profile photo updated.');

        function ui(id) {
            var av = document.getElementById(id),
                i = av.querySelector('img');
            if (!i) {
                i = document.createElement('img');
                i.alt = '';
                av.appendChild(i);
            }
            i.src = b64;
        }
        ui('sidebarAvatar');
        ui('moreAvatar');
        ui('contactSelfAvatar');
    } else sm('error', 'Something went wrong.')
}

function showDeleteModal() {
    document.getElementById('deleteModal').classList.add('active');
}

function hideDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
    document.getElementById('deletePassword').value = '';
}
async function confirmDeleteAccount() {
    var p = document.getElementById('deletePassword').value;
    if (!p) {
        sm('error', 'Something went wrong.');
        return
    }
    if (!document.getElementById('deleteConfirm').checked) {
        sm('error', 'Something went wrong.');
        return
    }
    var mode = 'delete';
    var checked = document.querySelector('input[name="delMode"]:checked');
    if (checked) mode = checked.value;
    var f = new URLSearchParams();
    f.append('action', 'delete_account');
    f.append('password', p);
    f.append('mode', mode);
    var d = await fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) {
        if (mode === 'revoke') {
            hideDeleteModal();
            sm('success', 'Chat records cleared');
        } else {
            window.location.href = 'login.php';
        }
    } else sm('error', 'Something went wrong.')
}
async function logout() {
    var f = new URLSearchParams();
    f.append('action', 'logout');
    var r = await fetch('../../api/auth.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    });
    var d = await r.json();
    if (d.admin_restored) {
        window.location.href = 'chat.php?goto=users';
    } else {
        window.location.href = 'login.php';
    }
}



var supPage = 1,
    supTab = 'open',
    supPerPage = 10;

function showCreateTicket() {
    document.getElementById('ticketForm').reset();
    document.getElementById('ticketImages').value = '';
    document.getElementById('ticketSubject').value = '';
    document.getElementById('ticketContent').value = '';
    if (RSTR) {
        document.getElementById('ticketType').value = 'account_issue';
        document.getElementById('ticketSubject').value = '账号解封请求';
    }
    document.getElementById('createTicketModal').classList.add('active');
}

function closeCreateTicket() {
    document.getElementById('createTicketModal').classList.remove('active');
}
async function doCreateTicket() {
    var t = document.getElementById('ticketType').value,
        s = document.getElementById('ticketSubject').value.trim(),
        r = document.getElementById('ticketContent').value.trim(),
        p = document.getElementById('ticketPriority').value;
    if (!s) {
        xalert('Subject required.');
        return
    }
    var images = await readTicketImages();
    var f = new URLSearchParams();
    f.append('action', 'create');
    f.append('type', t);
    f.append('subject', s);
    f.append('reason', r);
    f.append('priority', p);
    if (images) f.append('images', images);
    var d = await fetch('../../api/incident.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) {
        closeCreateTicket();
        loadSupportTickets(supTab);
    } else xalert(d.error || 'Something went wrong.');
}
async function readTicketImages() {
    var inp = document.getElementById('ticketImages');
    if (!inp.files || inp.files.length === 0) return null;
    var arr = [];
    for (var i = 0; i < inp.files.length && i < 5; i++) {
        var b64 = await new Promise(function(res) {
            var reader = new FileReader();
            reader.onload = function(e) {
                res(e.target.result)
            };
            reader.readAsDataURL(inp.files[i])
        });
        arr.push(b64);
    }
    return JSON.stringify(arr);
}

function priBadge(p) {
    if (!p || p === 'normal' || p === 'low' || p === 'task') return p || 'Normal';
    if (p === 'nopriority') return '<span class="pri-none">No Priority</span>';
    return '<span class="pri-' + p + '">' + p.charAt(0).toUpperCase() + p.slice(1) + '</span>';
}

function statBadge(s) {
    var cls = s;
    if (s === 'open') cls = 'open';
    else if (s === 'in_progress') cls = 'in_progress';
    else if (s === 'resolved') cls = 'resolved';
    else cls = 'closed';
    return '<span class="st-badge ' + cls + '">' + s.replace('_', ' ') + '</span>';
}

function changeSupPerPage(v) {
    supPerPage = parseInt(v) || 10;
    loadSupportTickets(supTab, 1);
}
async function loadSupportTickets(tab, page) {
    supTab = tab || supTab;
    supPage = page || 1;
    var q = document.getElementById('supSearch') ? document.getElementById('supSearch').value : '';
    var statusParam = (supTab === 'open' ? 'open' : (supTab === 'closed' ? 'closed' : 'all'));
    var u = '../../api/incident.php?action=list&status=' + statusParam + '&page=' + supPage + '&per_page=' + supPerPage;
    if (q) u += '&search=' + encodeURIComponent(q);
    var btns = document.querySelectorAll('.support-tabs button');
    for (var i = 0; i < btns.length; i++) {
        btns[i].classList.remove('active');
        if (btns[i].textContent.toLowerCase().indexOf(supTab) >= 0) btns[i].classList.add('active');
    }
    var d = await fetch(u).then(r => r.json());
    var a = document.getElementById('supportList');
    if (!d.success || d.incidents.length === 0) {
        a.innerHTML = '<div class="es"><p>' + T('sup_no_tickets') + '</p></div>';
        return;
    }
    var h = '';
    for (var k = 0; k < d.incidents.length; k++) {
        var inc = d.incidents[k];
        h += '<div class="support-row" onclick="toggleSupportDetail(' + inc.id + ')"><span class="sid">#' + inc.id + '</span><span class="ssub">' + eh(inc.subject) + '</span><span class="stype">' + eh(inc.type) + '</span><span class="spri">' + priBadge(inc.priority) + '</span><span class="sstat">' + statBadge(inc.status) + '</span></div>';
        h += '<div class="support-detail" id="supDtl' + inc.id + '"></div>';
    }
    a.innerHTML = h;
    var tp = Math.ceil(d.total / supPerPage),
        pg = '',
        info = T('sup_showing').replace('%s', String((supPage - 1) * supPerPage + 1)).replace('%s', String(Math.min(supPage * supPerPage, d.total))).replace('%s', String(d.total));
    pg += '<button class="bsm" ' + (supPage > 1 ? 'onclick="loadSupportTickets(supTab,' + (supPage - 1) + ')"' : 'disabled') + '>' + T('sup_prev') + '</button> ';
    pg += '<button class="bsm" ' + (supPage < tp ? 'onclick="loadSupportTickets(supTab,' + (supPage + 1) + ')"' : 'disabled') + '>' + T('sup_next') + '</button>';
    document.getElementById('supInfo').textContent = info;
    document.getElementById('supBtns').innerHTML = pg;
    if (_pendingSupportOpenId) {
        setTimeout(function() {
            var pid = _pendingSupportOpenId;
            _pendingSupportOpenId = null;
            var el = document.getElementById('supDtl' + pid);
            if (el) {
                toggleSupportDetail(pid);
                el.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }
        }, 200);
    }
}
// ---- 工单聊天自动刷新：展开中的工单详情定时轮询，有新回复自动更新 ----
var _supPollTimer = null,
    _supPollSeen = {};
function _supRespFingerprint(inc) {
    var rs = inc.responses || [];
    return rs.length + ':' + (rs.length ? rs[rs.length - 1].id : 0);
}
function _startSupportPoll() {
    if (_supPollTimer) return;
    _supPollTimer = setInterval(_tickSupportPoll, 3000);
}
function _stopSupportPoll() {
    if (_supPollTimer) { clearInterval(_supPollTimer); _supPollTimer = null; }
}
function _tickSupportPoll() {
    var panel = document.getElementById('panel-support');
    if (!panel || !panel.classList.contains('active')) { _stopSupportPoll(); return; }
    var details = document.querySelectorAll('.support-detail.active');
    if (details.length === 0) { _stopSupportPoll(); return; }
    for (var i = 0; i < details.length; i++) {
        (function(el) {
            var id = parseInt((el.id || '').replace('supDtl', ''), 10);
            if (!id) return;
            fetch('../../api/incident.php?action=detail&id=' + id).then(function(r) { return r.json(); }).then(function(d) {
                if (!d.success) return;
                var fp = _supRespFingerprint(d.incident);
                if (_supPollSeen[id] === fp) return;
                _supPollSeen[id] = fp;
                _renderSupportResponses(el, d.incident);
            }).catch(function() {});
        })(details[i]);
    }
}
// 仅重渲染回复列表（保留回复框/输入焦点/状态下拉框，不打断正在输入）
function _renderSupportResponses(el, inc) {
    var wrap = el.querySelector('.support-detail-wrap');
    if (!wrap) return;
    var oldPosts = wrap.querySelectorAll(':scope > .sd-post');
    var h = '',
        rs = inc.responses || [];
    for (var i = 0; i < rs.length; i++) {
        var resp = rs[i];
        h += '<div class="sd-post"><div class="sd-meta"><strong>' + eh(resp.username) + (resp.is_staff ? ' <span style="color:#c0a020">(Staff)</span>' : '') + '</strong> &mdash; ' + eh(resp.created_at) + '</div><div class="sd-msg">' + eh(resp.message) + '</div></div>';
    }
    var tmp = document.createElement('div');
    tmp.innerHTML = h;
    var replyBox = el.querySelector('.support-reply-box');
    for (var j = 0; j < oldPosts.length; j++) oldPosts[j].remove();
    if (replyBox) {
        while (tmp.firstChild) wrap.insertBefore(tmp.firstChild, replyBox);
    } else {
        while (tmp.firstChild) wrap.appendChild(tmp.firstChild);
    }
}
async function toggleSupportDetail(id) {
    var el = document.getElementById('supDtl' + id);
    if (el.classList.contains('active')) {
        el.classList.remove('active');
        delete _supPollSeen[id];
        return;
    }
    if (el.getAttribute('data-loaded')) {
        el.classList.add('active');
        return;
    }
    var d = await fetch('../../api/incident.php?action=detail&id=' + id).then(r => r.json());
    if (!d.success) return;
    var inc = d.incident;
    var h = '<div class="support-detail-wrap" style="padding:12px">';
    h += '<div class="sd-first"><div class="sd-meta"><strong>' + eh(inc.reporter_name) + '</strong> &mdash; ' + eh(inc.created_at) + '</div>';
    h += '<div class="sd-msg">' + eh(inc.reason || inc.subject) + '</div></div>';
    if (inc.reported_messages && inc.reported_messages.length > 0) {
        h += '<div style="margin-top:6px;color:#c0a020;font-size:.75em">Reported Messages:</div>';
        for (var ri = 0; ri < inc.reported_messages.length; ri++) {
            var rm = inc.reported_messages[ri];
            h += '<div class="sd-post"><div class="sd-meta"><strong>' + eh(rm.sender_name) + '</strong> &mdash; msg #' + rm.id + (rm.is_revoked ? ' <span style="color:#e06060">(Revoked &mdash; showing original)</span>' : '') + '</div><div class="sd-msg">' + eh(rm.message) + '</div></div>';
        }
    }
    for (var i = 0; i < inc.responses.length; i++) {
        var resp = inc.responses[i];
        h += '<div class="sd-post"><div class="sd-meta"><strong>' + eh(resp.username) + (resp.is_staff ? ' <span style="color:#c0a020">(Staff)</span>' : '') + '</strong> &mdash; ' + eh(resp.created_at) + '</div><div class="sd-msg">' + eh(resp.message) + '</div></div>';
    }
    h += '<div class="support-reply-box"><textarea id="supReply' + id + '" placeholder="' + T('sup_reply_ph') + '"></textarea><button class="bsm" onclick="doSupportReply(' + id + ')">' + T('sup_reply') + '</button>';
    if (ADMIN) {
        h += '<select id="supStatus' + id + '" class="bsm" style="background:#1e1e1e;border:1px solid #444;color:#ccc"><option value="">' + T('sup_status_ph') + '</option><option value="open">' + T('sup_status_open') + '</option><option value="in_progress">' + T('sup_status_in_progress') + '</option><option value="resolved">' + T('sup_status_resolved') + '</option><option value="closed">' + T('sup_status_closed') + '</option></select><button class="bsm" onclick="doSupportUpdateStatus(' + id + ')">' + T('sup_update') + '</button>';
    }
    h += '</div></div>';
    el.innerHTML = h;
    el.setAttribute('data-loaded', '1');
    el.classList.add('active');
    _supPollSeen[id] = _supRespFingerprint(inc);
    _startSupportPoll();
}
async function doSupportReply(id) {
    var m = document.getElementById('supReply' + id).value.trim();
    if (!m) return;
    var f = new URLSearchParams();
    f.append('action', 'respond');
    f.append('id', id);
    f.append('message', m);
    var d = await fetch('../../api/incident.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) {
        document.getElementById('supReply' + id).value = '';
        var el = document.getElementById('supDtl' + id);
        el.removeAttribute('data-loaded');
        el.classList.remove('active');
        toggleSupportDetail(id);
        loadSupportBadge();
    }
}
async function doSupportUpdateStatus(id) {
    var st = document.getElementById('supStatus' + id).value;
    if (!st) return;
    var f = new URLSearchParams();
    f.append('action', 'update_status');
    f.append('id', id);
    f.append('status', st);
    var d = await fetch('../../api/incident.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(r => r.json());
    if (d.success) { loadSupportTickets(supTab, supPage); loadSupportBadge(); }
}

if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
apiRequest('unread_counts', {}).then(function(d) {
    if (d.success && d.counts) {
        for (var k in d.counts) unreadCounts[k] = d.counts[k];
        updateUnreads();
    }
}).catch(function() {});
initialLoad();
setTimeout(lcInit, 500);
// WS 收到新消息时会主动更新 L 并渲染（wss_client.js），此 1500ms 轮询降为 30s 兜底
P = setInterval(pm, 30000);
loadContacts();
loadPending();
loadMyGroups();
function showSearchTab(tab) {
    var userSub = document.getElementById('searchSubTabUsers');
    var msgSub = document.getElementById('searchSubTabMsgs');
    var userBtn = document.getElementById('searchTabUsers');
    var msgBtn = document.getElementById('searchTabMsgs');
    if (tab === 'users') {
        userSub.style.display = 'block';
        msgSub.style.display = 'none';
        userBtn.classList.add('active');
        msgBtn.classList.remove('active');
        discoverUsers(1);
    } else {
        userSub.style.display = 'none';
        msgSub.style.display = 'block';
        userBtn.classList.remove('active');
        msgBtn.classList.add('active');
        document.getElementById('msgSearchInput').focus();
    }
}
var _msgSearchPage = 1, _msgSearchQ = '';
function searchMessages(page) {
    _msgSearchPage = page || 1;
    var q = document.getElementById('msgSearchInput').value.trim();
    if (q.length < 2) {
        document.getElementById('msgSearchResults').innerHTML = '<div class="es"><p style="padding:20px">Type at least 2 characters to search...</p></div>';
        return;
    }
    _msgSearchQ = q;
    document.getElementById('msgSearchResults').innerHTML = '<div class="srch-spinner"><div class="srch-loading-bar"><div></div></div><div class="spin"></div>Searching messages...</div>';
    var url = '../../api/chat.php?action=search_messages&q=' + encodeURIComponent(q) + '&page=' + _msgSearchPage;
    if (D) url += '&dm=' + encodeURIComponent(D);
    fetch(url).then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) {
            document.getElementById('msgSearchResults').innerHTML = '<div class="es"><p>Search failed.</p></div>';
            return;
        }
        if (!d.messages || d.messages.length === 0) {
            document.getElementById('msgSearchResults').innerHTML = '<div class="es"><p>No messages found.</p></div>';
            document.getElementById('msgSearchPagination').style.display = 'none';
            return;
        }
        var h = '';
        for (var i = 0; i < d.messages.length; i++) {
            var m = d.messages[i];
            var name = _contactNotes[m.username] || m.display_name || m.username;
            var am = (m.msg_type === 'temp' && m.temp_upload_id) ? '<div style="font-size:.7em;color:#c0a020">[Flash] ' + eh(m.attachment_name || '') + '</div>' : '';
            var msgPreview = m.is_markdown ? m.message.replace(/<[^>]*>/g, '').substring(0, 60) : m.message.substring(0, 60);
            h += '<div class="srch-row" style="padding:8px 0;border-bottom:1px solid #2e2e2e;cursor:pointer" onclick="jumpToSearchMessage(' + m.id + ',\'' + m.username + '\')">'
                + '<div style="color:#6a9fd8;font-size:.72em">#' + m.id + ' · ' + eh(name) + ' · ' + fmtTime(m.time) + '</div>'
                + '<div style="color:#ccc;font-size:.78em;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + eh(msgPreview) + '</div>'
                + am + '</div>';
        }
        document.getElementById('msgSearchResults').innerHTML = h;
        var tp = Math.ceil(d.total / d.per_page);
        var pg = '', info = '';
        if (tp > 1) {
            info = 'Page ' + _msgSearchPage + ' of ' + tp + ' (' + d.total + ' results)';
            pg += '<button class="bsm" ' + (_msgSearchPage > 1 ? 'onclick="searchMessages(' + (_msgSearchPage - 1) + ')"' : 'disabled') + '>Prev</button> ';
            pg += '<button class="bsm" ' + (_msgSearchPage < tp ? 'onclick="searchMessages(' + (_msgSearchPage + 1) + ')"' : 'disabled') + '>Next</button>';
        } else {
            info = d.total + ' results';
        }
        document.getElementById('msgSearchInfo').textContent = info;
        document.getElementById('msgSearchBtns').innerHTML = pg;
        document.getElementById('msgSearchPagination').style.display = (tp > 1 || d.total > 0) ? 'flex' : 'none';
    });
}
function openDmSearch(u) {
    document.getElementById('dmOptionsMenu').classList.remove('active');
    if (u && u !== D) openDm(u);   // 右键搜索他人记录时，先切换到该聊天
    if (!D) return;
    var label = G ? D : (_contactNotes[D] || D);
    document.getElementById('dmSearchTitle').textContent = 'Search: ' + label + ' (' + (G ? 'GID:'+G : D) + ')';
    document.getElementById('dmSearchInput').value = '';
    document.getElementById('dmSearchResults').innerHTML = '<div class="es"><p>Enter a search term to find messages in this chat.</p></div>';
    document.getElementById('dmSearchPagination').style.display = 'none';
    switchPanel('dm-search');
    document.getElementById('dmSearchInput').focus();
}
function backToDm() {
    switchPanel('dm');
}
var _dmSearchPage = 1;
function dmSearchMessages(page) {
    _dmSearchPage = page || 1;
    var q = document.getElementById('dmSearchInput').value.trim();
    if (q.length < 2) {
        document.getElementById('dmSearchResults').innerHTML = '<div class="es"><p>Type at least 2 characters to search...</p></div>';
        return;
    }
    document.getElementById('dmSearchResults').innerHTML = '<div class="srch-spinner"><div class="srch-loading-bar"><div></div></div><div class="spin"></div>Searching messages...</div>';
    var url = '../../api/chat.php?action=search_messages&q=' + encodeURIComponent(q) + '&page=' + _dmSearchPage;
    if (G) url += '&group_id=' + G;
    else url += '&dm=' + encodeURIComponent(D);
    fetch(url).then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) {
            document.getElementById('dmSearchResults').innerHTML = '<div class="es"><p>Search failed.</p></div>';
            return;
        }
        if (!d.messages || d.messages.length === 0) {
            document.getElementById('dmSearchResults').innerHTML = '<div class="es"><p>No messages found.</p></div>';
            document.getElementById('dmSearchPagination').style.display = 'none';
            return;
        }
        var h = '';
        for (var i = 0; i < d.messages.length; i++) {
            var m = d.messages[i];
            var name = _contactNotes[m.username] || m.display_name || m.username;
            var am = (m.msg_type === 'temp' && m.temp_upload_id) ? '<div style="font-size:.7em;color:#c0a020">[Flash] ' + eh(m.attachment_name || '') + '</div>' : '';
            var msgPreview = m.is_markdown ? m.message.replace(/<[^>]*>/g, '').substring(0, 80) : m.message.substring(0, 80);
            h += '<div class="srch-row" style="padding:8px 0;border-bottom:1px solid #2e2e2e;cursor:pointer" onclick="jumpToSearchMessage(' + m.id + ',\'' + m.username + '\')">'
                + '<div style="color:#6a9fd8;font-size:.72em">#' + m.id + ' · ' + eh(name) + ' · ' + fmtTime(m.time) + '</div>'
                + '<div style="color:#ccc;font-size:.78em;margin-top:2px">' + eh(msgPreview) + '</div>'
                + am + '</div>';
        }
        document.getElementById('dmSearchResults').innerHTML = h;
        var tp = Math.ceil(d.total / d.per_page);
        var pg = '', info = '';
        if (tp > 1) {
            info = 'Page ' + _dmSearchPage + ' of ' + tp + ' (' + d.total + ' results)';
            pg += '<button class="bsm" ' + (_dmSearchPage > 1 ? 'onclick="dmSearchMessages(' + (_dmSearchPage - 1) + ')"' : 'disabled') + '>Prev</button> ';
            pg += '<button class="bsm" ' + (_dmSearchPage < tp ? 'onclick="dmSearchMessages(' + (_dmSearchPage + 1) + ')"' : 'disabled') + '>Next</button>';
        } else {
            info = d.total + ' results';
        }
        document.getElementById('dmSearchInfo').textContent = info;
        document.getElementById('dmSearchBtns').innerHTML = pg;
        document.getElementById('dmSearchPagination').style.display = 'flex';
    });
}

function jumpToSearchMessage(id, username) {
    if (username === U) {
        switchPanel('announcements');
    } else {
        openDm(username);
    }
    setTimeout(function() {
        var area = document.getElementById(D ? 'dmMessagesArea' : 'messagesArea');
        if (!area) return;
        var el = area.querySelector('[data-msgid="' + id + '"]');
        if (el) {
            el.style.background = '#2a4a2a';
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(function() { el.style.background = ''; }, 3000);
        }
    }, 800);
}
var _dmLoading = false, _dmOldest = 0;
var _annLoading = false, _grpLoading = false, _grpOldest = 0;

document.getElementById('dmMessagesArea').addEventListener('scroll', function() {
    if (this.scrollTop < 50 && _dmOldest > 0 && !_dmLoading) {
        _dmLoading = true;
        var oid = _dmOldest;
        if (G) {
            loadGroupHistoryChunk(oid);
        } else {
            loadDmMessages(oid);
        }
    }
});

document.getElementById('messagesArea').addEventListener('scroll', function() {
    if (this.scrollTop < 50 && !_annLoading) {
        _annLoading = true;
        loadMoreAnnouncements();
    }
});
setInterval(function() {
    loadPending();
    loadContacts();
    loadMyGroups();
}, 5000);

if (location.search.indexOf("goto=users") > -1) {
    switchPanel("users");
    history.replaceState(null, "", location.pathname);
}
var _annInput = document.getElementById("messageInput");
if (_annInput) {
    _annInput.addEventListener("keydown", function(e) {
        // isComposing / keyCode 229：中文输入法组词按回车=选词，绝不触发发送
        if (e.key === "Enter" && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
            e.preventDefault();
            sendAnnouncement();
        }
    });
}
var _dmInput = document.getElementById("dmMessageInput");
if (_dmInput) {
    _dmInput.addEventListener("keydown", function(e) {
        if (e.key === "Enter" && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
            e.preventDefault();
            if (G) sendGroupMessage(); else sendDmMessage();
        }
    });
}
var _dmSendBtn = document.getElementById("dmSendBtn");
if (_dmSendBtn) {
    _dmSendBtn.onclick = function() {
        if (G) sendGroupMessage(); else sendDmMessage();
    };
}
if (typeof ADMIN !== 'undefined' && ADMIN) {
    setInterval(loadRepCount, 30000);
    loadRepCount();
}

function loadRoleList() {
    fetch('../../api/admin.php?action=role_list').then(function(r) {
        return r.json()
    }).then(function(d) {
        if (!d.success) return;
        var h = '';
        for (var i = 0; i < d.roles.length; i++) {
            var r = d.roles[i],
                p = JSON.parse(r.permissions || '{}'),
                sum = JSON.stringify(p).substring(0, 50) + (JSON.stringify(p).length > 50 ? '...' : '');
            h += '<tr><td>' + eh(r.role_name) + '</td><td>' + (r.editable ? 'Yes' : 'No') + '</td><td style="font-size:.7em;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + eh(JSON.stringify(p, null, 2)) + '">' + eh(sum) + '</td><td>' + (r.editable ? '<button class="bsm" onclick="editRole(\'' + r.role_name + '\')">Edit</button> ' + (r.role_name !== 'admin' && r.role_name !== 'user' ? '<button class="bsm danger" onclick="deleteRole(\'' + r.role_name + '\')">Delete</button>' : '<span style="color:#555">Protected</span>') : '<span style="color:#555">Read-only</span>') + '</td></tr>';
        }
        document.getElementById('roleTable').innerHTML = h || '<tr><td colspan="4" style="text-align:center;color:#555">No roles found.</td></tr>';
    });
}

function editRole(name) {
    fetch('../../api/admin.php?action=role_list').then(function(r) {
        return r.json()
    }).then(function(d) {
        if (!d.success) return;
        var role;
        for (var i = 0; i < d.roles.length; i++) {
            if (d.roles[i].role_name === name) {
                role = d.roles[i];
                break
            }
        }
        if (!role) return;
        var p = JSON.parse(role.permissions || '{}');
        xprompt('Edit permissions for ' + name + ':', JSON.stringify(p, null, 2)).then(function(v) {
            if (v === null || v === false) return;
            try {
                JSON.parse(v)
            } catch (e) {
                xalert('Invalid JSON.');
                return
            }
            var f = new URLSearchParams();
            f.append('action', 'role_save');
            f.append('role_name', name);
            f.append('permissions', v);
            fetch('../../api/admin.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: f.toString()
            }).then(function(r) {
                return r.json()
            }).then(function(d) {
                if (d.success) loadRoleList();
                else xalert('Failed.');
            });
        });
    });
}

function showAddRoleModal() {
    xprompt('New role name:', '').then(function(v) {
        if (!v) return;
        var f = new URLSearchParams();
        f.append('action', 'role_save');
        f.append('role_name', v);
        f.append('permissions', '{}');
        fetch('../../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        }).then(function(r) {
            return r.json()
        }).then(function(d) {
            if (d.success) loadRoleList();
            else xalert('Failed.');
        });
    });
}
async function deleteRole(name) {
    if (!(await xconfirm('Delete role: ' + name + '? All users with this role will become user.'))) return;
    var f = new URLSearchParams();
    f.append('action', 'role_delete');
    f.append('role_name', name);
    var d = await fetch('../../api/admin.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(function(r) {
        return r.json()
    });
    if (d.success) loadRoleList();
    else xalert(d.error || 'Failed.');
}

var donPage = 1;

function loadDonations(page) {
    donPage = page || 1;
    fetch('../../api/donation.php?action=list&page=' + donPage).then(function(r) {
        return r.json()
    }).then(function(d) {
        if (!d.success) return;
        var h = '';
        for (var i = 0; i < d.donations.length; i++) {
            var o = d.donations[i];
            h += '<tr><td>' + o.id + '</td><td>' + eh(o.datetime) + '</td><td>' + o.user_id + '</td><td>' + eh(o.username) + '</td><td>' + eh(o.display_name || o.username) + '</td><td>' + (o.weixin_id ? eh(o.weixin_id) : '-') + '</td><td>' + (o.qq ? eh(o.qq) : '-') + '</td><td><button class="bsm danger" onclick="deleteDonation(' + o.id + ')" style="font-size:.7em;padding:2px 8px">Delete</button></td></tr>';
        }
        document.getElementById('donationsTable').innerHTML = h || '<tr><td colspan="8" style="text-align:center;color:#555;padding:12px">No records</td></tr>';
        var tp = Math.ceil(d.total / d.per_page),
            pg = '',
            info = 'Showing ' + ((donPage - 1) * 15 + 1) + '-' + Math.min(donPage * 15, d.total) + ' of ' + d.total;
        pg += '<button class="bsm" ' + (donPage > 1 ? 'onclick="loadDonations(' + (donPage - 1) + ')"' : 'disabled') + '>Prev</button> ';
        pg += '<button class="bsm" ' + (donPage < tp ? 'onclick="loadDonations(' + (donPage + 1) + ')"' : 'disabled') + '>Next</button>';
        document.getElementById('donInfo').textContent = info;
        document.getElementById('donBtns').innerHTML = pg;
    });
}

/* ===== 个人资料管理 (Profile Data Management) ===== */
var _pmType = 'all', _pmData = null;

function closePm() {
    var p = document.getElementById('panel-profile-mgmt');
    if (p) p.classList.remove('active');
}

function loadPm() {
    var list = document.getElementById('pmList');
    if (list) list.innerHTML = '<div class="es"><p>' + T('pmg_loading', 'Loading...') + '</p></div>';
    fetch('../../api/chat.php?action=my_content&type=' + encodeURIComponent(_pmType) + '&limit=200').then(function(r) {
        return r.json();
    }).then(function(d) {
        if (!d.success) {
            if (list) list.innerHTML = '<div class="es"><p>' + T('pmg_empty', 'Nothing here yet.') + '</p></div>';
            return;
        }
        _pmData = d.items || [];
        renderPm();
    }).catch(function() {
        if (list) list.innerHTML = '<div class="es"><p>' + T('pmg_empty', 'Nothing here yet.') + '</p></div>';
    });
}

function pmTab(type) {
    _pmType = type;
    var tabs = document.querySelectorAll('#pmgTabs .pmg-tab');
    for (var i = 0; i < tabs.length; i++) tabs[i].classList.toggle('active', tabs[i].getAttribute('data-type') === type);
    renderPm();
}

function renderPm() {
    var list = document.getElementById('pmList');
    if (!list) return;
    var items = _pmData || [];
    var h = '';
    for (var i = 0; i < items.length; i++) {
        if (_pmType !== 'all' && items[i].kind !== _pmType) continue;
        h += pmItemHtml(items[i]);
    }
    list.innerHTML = h || '<div class="es"><p>' + T('pmg_empty', 'Nothing here yet.') + '</p></div>';
}

function pmItemHtml(it) {
    var thumb;
    if (it.kind === 'photo' || it.kind === 'video') {
        thumb = '<span class="pmg-thumb">' + (it.kind === 'video' ? '<i class="pmg-play">▶</i>' : '') + '<img src="' + eh(it.url) + '" loading="lazy" alt="" onerror="this.parentNode.classList.add(\'pmg-broken\')"></span>';
    } else {
        thumb = '<span class="pmg-thumb pmg-file">' + T('pmg_file_icon', '<img src="../../data/res/cil/cil-file.svg" class="pmg-file-ico" alt="">') + '</span>';
    }
    var size = it.size ? ' · ' + fmtSize(it.size) : '';
    var kindLabel = ({ photo: 'photo', video: 'video', file: 'file' })[it.kind] || it.kind;
    return '<div class="pmg-item" data-id="' + it.id + '">' +
        thumb +
        '<div class="pmg-info">' +
        '<div class="pmg-name" title="' + eh(it.name) + '">' + eh(it.name) + '</div>' +
        '<div class="pmg-meta">' + kindLabel + size + ' · ' + fmtTime(it.time) + '</div>' +
        '</div>' +
        '<button class="bsm danger pmg-revoke" onclick="revokePm(' + it.id + ')">' + T('pmg_revoke', 'Revoke') + '</button>' +
        '</div>';
}

function revokePm(id) {
    if (!confirm(T('pmg_revoke_confirm', 'Revoke this item? Recipients will see it as revoked, not deleted.'))) return;
    var f = new URLSearchParams();
    f.append('action', 'revoke_own');
    f.append('message_id', id);
    fetch('../../api/chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) {
        return r.json();
    }).then(function(d) {
        if (d.success) { loadPm(); }
        else { alert(d.error || T('pmg_failed', 'Failed to revoke.')); }
    });
}

function showAddDonationModal() {
    document.getElementById('addDonDateTime').value = '';
    document.getElementById('addDonUserSearch').value = '';
    document.getElementById('addDonUserId').value = '';
    document.getElementById('addDonWeixin').value = '';
    document.getElementById('addDonQQ').value = '';
    document.getElementById('donUserSearchResults').innerHTML = '';
    document.getElementById('donUserSearchResults').style.display = 'none';
    document.getElementById('addDonModal').classList.add('active');
}

function closeAddDonModal() {
    document.getElementById('addDonModal').classList.remove('active');
}
var _donST = null;

function searchDonUser() {
    clearTimeout(_donST);
    _donST = setTimeout(function() {
        var q = document.getElementById('addDonUserSearch').value.trim();
        if (q.length < 1) {
            document.getElementById('donUserSearchResults').style.display = 'none';
            return
        }
        fetch('../../api/donation.php?action=search_users&q=' + encodeURIComponent(q)).then(function(r) {
            return r.json()
        }).then(function(d) {
            if (!d.success || d.users.length === 0) {
                document.getElementById('donUserSearchResults').style.display = 'none';
                return
            }
            var h = '';
            for (var i = 0; i < d.users.length; i++) {
                var u = d.users[i];
                h += '<div onclick="selectDonUser(' + u.user_id + ',\'' + u.username + '\')" style="padding:4px 8px;cursor:pointer;color:#e0e0e0;border-bottom:1px solid #333">' + eh(u.username) + ' (' + u.user_id + ') &mdash; ' + eh(u.display_name || u.username) + '</div>';
            }
            document.getElementById('donUserSearchResults').innerHTML = h;
            document.getElementById('donUserSearchResults').style.display = 'block';
        });
    }, 300);
}

function selectDonUser(uid, uname) {
    document.getElementById('addDonUserId').value = uid;
    document.getElementById('addDonUserSearch').value = uname + ' (' + uid + ')';
    document.getElementById('donUserSearchResults').style.display = 'none';
}
async function doAddDonation() {
    var dt = document.getElementById('addDonDateTime').value.trim(),
        uid = document.getElementById('addDonUserId').value,
        wx = document.getElementById('addDonWeixin').value.trim(),
        qq = document.getElementById('addDonQQ').value.trim();
    if (!dt || !uid) {
        xalert('DateTime and User are required.');
        return
    }
    var f = new URLSearchParams();
    f.append('action', 'add');
    f.append('datetime', dt);
    f.append('user_id', uid);
    f.append('weixin_id', wx);
    f.append('qq', qq);
    var d = await fetch('../../api/donation.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(function(r) {
        return r.json()
    });
    if (d.success) {
        closeAddDonModal();
        loadDonations(donPage);
    } else xalert('Failed.');
}
async function deleteDonation(id) {
    if (!(await xconfirm('Delete donation #' + id + '?'))) return;
    var f = new URLSearchParams();
    f.append('action', 'delete');
    f.append('id', id);
    var d = await fetch('../../api/donation.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(function(r) {
        return r.json()
    });
    if (d.success) loadDonations(donPage);
    else xalert('Failed.');
}

function showCreateGroupModal() {
    xprompt('Group name:', '').then(function(v) {
        if (!v) return;
        var f = new URLSearchParams();
        f.append('action', 'create');
        f.append('name', v);
        fetch('../../api/group.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        }).then(function(r) {
            return r.json()
        }).then(function(d) {
            if (d.success) {
                loadMyGroups();
                xalert('Group created! GID: ' + d.group_id);
            } else if (d.error === 'Group limit reached') {
                xalert('Group limit reached for your level. Max: ' + d.max_groups);
            } else xalert('Failed.');
        });
    });
}

function showJoinGroupModal() {
    xprompt('Enter Group ID:', '').then(function(v) {
        if (!v) return;
        var f = new URLSearchParams();
        f.append('action', 'join_by_gid');
        f.append('group_id', v);
        fetch('../../api/group.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        }).then(function(r) {
            return r.json()
        }).then(function(d) {
            if (d.success) {
                loadMyGroups();
                xalert(d.joined ? 'Joined!' : 'Request sent.');
            } else xalert(d.error || 'Failed.');
        });
    });
}

function loadMyGroups() {
    fetch('../../api/group.php?action=list_my').then(function(r) {
        return r.json()
    }).then(function(d) {
        if (!d.success) return;
        var h = '';
        var sorted = d.groups.slice().sort(function(a, b) { return ((b.pinned ? 1 : 0) - (a.pinned ? 1 : 0)); });
        for (var i = 0; i < sorted.length; i++) {
            var g = sorted[i];
            _pinnedGroup[g.group_id] = g.pinned ? 1 : 0;
            var gav = g.avatar_url ? '<img src="' + ehAttr(g.avatar_url) + '" alt="">' : '';
            h += '<div class="csi' + (_pinnedGroup[g.group_id] ? ' pinned' : '') + '" data-gid="' + g.group_id + '" onclick="openGroupChat(' + g.group_id + ',\'' + eh(g.name) + '\')"><div class="ca">' + (gav || '') + '</div><div class="cn">' + eh(g.name) + ' (GID: ' + g.group_id + ')</div></div>';
        }
        document.getElementById('myGroups').innerHTML = h || '<div style="color:#666;font-size:.72em;padding:4px 10px">No groups</div>';
    });
}

function openGroupChat(gid, gname) {
    lcLoadChannel('group_' + gid).then(function(msgs) {
        if (msgs && msgs.length > 0) {
            var area = document.getElementById('dmMessagesArea');
            if (area && !area.querySelector('[data-msgid]')) {
                for (var i = 0; i < msgs.length; i++) {
                    var m = msgs[i];
                    if (m && m.id && !m.is_deleted) { delete seenMsgIds['dm_' + m.id]; addDmMessage(m); }
                }
                _glast = msgs[msgs.length - 1].id;
            }
        }
    }).catch(function() {});
    fetch('../../api/group.php?action=history&group_id=' + gid).then(function(r) {
        return r.json()
    }).then(function(d) {
        if (!d.success) return;
        D = gname;
        G = gid;
        _glast = 0;
        seenMsgIds = {};
        document.getElementById('dmTitle').textContent = gname + ' (GID: ' + gid + ')';
        switchPanel('dm');
        updateDmOptionsMenu();
        document.getElementById('dmMessagesArea').innerHTML = '<div class="es"><p>' + T('msg_loading') + '</p></div>';
        for (var i = 0; i < d.messages.length; i++) {
            if (d.messages[i].id > _glast) _glast = d.messages[i].id;
            addDmMessage(d.messages[i]);
        }
        if (_glast > 0) loadGroupMessages(G);
        _dmOldest = d.oldest_id || 0;
    });
}
var G = null;

var _emojiBuiltin = [],
    _emojiTarget = null;
fetch('../../api/emoji.php?action=list').then(function(r) {
    return r.json()
}).then(function(d) {
    if (d.success) _emojiBuiltin = d.emojis;
});

function renderEmoji(text) {
    if (!Array.isArray(_emojiBuiltin) || _emojiBuiltin.length === 0) return text;
    var useDyn = (typeof EMOJI_CHAT !== 'undefined' ? EMOJI_CHAT : 'dynamic') === 'dynamic';
    for (var i = 0; i < _emojiBuiltin.length; i++) {
        var e = _emojiBuiltin[i];
        if (e.img && e.code && text.indexOf(e.code) >= 0) {
            var src = useDyn && e.img_dyn ? e.img_dyn : e.img;
            text = text.split(e.code).join('<img src="../../' + src + '" class="chat-emoji chat-emoji-builtin" data-emoji-code="' + eh(e.code) + '" alt="' + eh(e.code) + '">');
        }
    }
    text = text.replace(/\[emoji:([a-f0-9]{32})\]/g, function(m, h) {
        return '<img src="../../api/emoji.php?action=img&hash=' + h + '" class="chat-emoji chat-emoji-custom" data-emoji-code="[emoji:' + h + ']" alt="">';
    });
    return text;
}

function extractFirstEmojiCode(html) {
    if (!html) return '';
    var m = html.match(/data-emoji-code="([^"]*)"/);
    return m ? m[1] : '';
}

function toggleEmojiPicker(e, targetId) {
    e.stopPropagation();
    e.preventDefault();
    _emojiTarget = targetId;
    var popup = document.getElementById('emojiPopup');
    if (popup.style.display === 'flex') {
        popup.style.display = 'none';
        emojiUndockMobile();
        return;
    }
    var btn = e.target.closest('button[title=Emoji]');
    if (!btn) return;
    var rect = btn.getBoundingClientRect();
    var popW = 360;
    var left = rect.left + (rect.width - popW) / 2;
    if (left < 4) left = 4;
    if (left + popW > window.innerWidth - 4) left = window.innerWidth - popW - 4;
    var spaceAbove = rect.top;
    if (spaceAbove >= 160) {
        popup.style.top = (rect.top - 158) + 'px';
    } else {
        popup.style.top = (rect.bottom + 6) + 'px';
    }
    popup.style.left = left + 'px';
    popup.style.display = 'flex';
    emojiDockMobile();
    switchEmojiTab('builtin');
}

function switchEmojiTab(tab) {
    document.getElementById('emojiTabBuiltin').classList.toggle('active', tab === 'builtin');
    document.getElementById('emojiTabCustom').classList.toggle('active', tab === 'custom');
    var grid = document.getElementById('emojiGrid'),
        h = '';
    if (tab === 'builtin') {
        if (!Array.isArray(_emojiBuiltin) || _emojiBuiltin.length === 0) {
            fetch('../../api/emoji.php?action=list').then(function(r) {
                return r.json()
            }).then(function(d) {
                if (d.success) _emojiBuiltin = d.emojis;
                switchEmojiTab('builtin');
            });
            return;
        }
        if (Array.isArray(_emojiBuiltin)) {
            for (var i = 0; i < _emojiBuiltin.length; i++) {
                var e = _emojiBuiltin[i];
                if (e.type === 4) {
                    h += '<span class="emoji-item unicode" onclick="insertEmoji(\'' + e.code + '\')" title="' + eh(e.code) + '">' + e.id + '</span>';
                } else if (e.img) {
                    var ep = (typeof EMOJI_PANEL !== 'undefined' ? EMOJI_PANEL : 'dynamic');
                    var dyn = e.img_dyn ? '../../' + _emojiBuiltin[i].img_dyn : '';
                    if (ep === 'dynamic' && dyn) {
                        h += '<img src="' + dyn + '" class="emoji-item" onclick="insertEmoji(\'' + e.code + '\')" title="' + eh(e.code) + '">';
                    } else if (dyn && ep !== 'static') {
                        h += '<img src="../../' + e.img + '" data-dyn="' + dyn + '" class="emoji-item" onclick="insertEmoji(\'' + e.code + '\')" title="' + eh(e.code) + '" onmouseover="if(this.dataset.dyn)this.src=this.dataset.dyn" onmouseout="this.src=\'../../' + e.img + '\'">';
                    } else {
                        h += '<img src="../../' + e.img + '" class="emoji-item" onclick="insertEmoji(\'' + e.code + '\')" title="' + eh(e.code) + '">';
                    }
                }
            }
        }
    } else {
        fetch('../../api/emoji.php?action=my_custom').then(function(r) {
            return r.json()
        }).then(function(d) {
            if (!d.success || !d.custom.length) {
                grid.innerHTML = '<div style="color:#888;font-size:.72em;text-align:center;padding:20px">No custom emoji.<br><button class="bsm" onclick="document.getElementById(\'customEmojiFile\').click()" style="margin-top:8px">+ Upload</button></div>';
                return;
            }
            var h2 = '';
            for (var i = 0; i < d.custom.length; i++) {
                var c = d.custom[i];
                h2 += '<div class="emoji-item-wrap"><img src="../../' + c.img + '" class="emoji-item" onclick="insertEmoji(\'[emoji:' + c.hash + ']\')"><span class="emoji-del" onclick="deleteCustomEmoji(\'' + c.hash + '\')">&times;</span></div>';
            }
            h2 += '<div style="grid-column:1/-1;text-align:center;padding:6px"><button class="bsm" onclick="document.getElementById(\'customEmojiFile\').click()">+ Upload</button></div>';
            grid.innerHTML = h2;
        });
        return;
    }
    grid.innerHTML = h;
}

function insertEmoji(code) {
    if (!_emojiTarget) return;
    var el = document.getElementById(_emojiTarget);
    el.focus();
    var start = el.selectionStart,
        end = el.selectionEnd;
    var before = el.value.substring(0, start);
    var after = el.value.substring(end);
    el.value = before + code + after;
    el.selectionStart = el.selectionEnd = start + code.length;
    el.dispatchEvent(new Event('input'));
}

function uploadCustomEmoji() {
    var input = document.getElementById('customEmojiFile');
    var files = input.files;
    if (!files || files.length === 0) return;
    var filesArr = [];
    for (var idx = 0; idx < files.length; idx++) filesArr.push(files[idx]);
    input.value = '';
    var done = 0, total = filesArr.length;
    function uploadOne(f) {
        if (f.size > 2 * 1024 * 1024) {
            done++;
            if (done >= total) switchEmojiTab('custom');
            return;
        }
        var reader = new FileReader();
        reader.onload = function(e) {
            var b64 = e.target.result;
            fetch('../../api/emoji.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'action=upload&image=' + encodeURIComponent(b64)
            }).then(function(r) {
                return r.json()
            }).then(function(d) {
                done++;
                if (done >= total) switchEmojiTab('custom');
            });
        };
        reader.readAsDataURL(f);
    }
    for (var i = 0; i < filesArr.length; i++) uploadOne(filesArr[i]);
}
async function deleteCustomEmoji(hash) {
    if (!(await xconfirm('Delete this emoji?'))) return;
    var f = new URLSearchParams();
    f.append('action', 'delete');
    f.append('hash', hash);
    var d = await fetch('../../api/emoji.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(function(r) {
        return r.json()
    });
    if (d.success) switchEmojiTab('custom');
    else xalert('Failed.');
}

function saveEmojiSettings() {
    var pm = document.getElementById('emojiPanelMode').value;
    var cm = document.getElementById('emojiChatMode').value;
    var f = new URLSearchParams();
    f.append('action', 'save_emoji_settings');
    f.append('panel_mode', pm);
    f.append('chat_mode', cm);
    fetch('../../api/settings.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(function(r) {
        return r.json()
    }).then(function(d) {
        if (d.success) {
            EMOJI_PANEL = pm;
            EMOJI_CHAT = cm;
            sm('success', 'Emoji settings saved.');
        } else sm('error', 'Failed.');
    });
}

var selectedPublicEmoji = [];

function setPublicEmojiSize(val) {
    var grid = document.getElementById('publicEmojiGrid');
    if (grid) grid.style.setProperty('--pes-size', val + 'px');
}


function loadPublicEmoji() {
    if (typeof selectedPublicEmoji === 'undefined' || !Array.isArray(selectedPublicEmoji)) { selectedPublicEmoji = []; }
    var s = document.getElementById('publicEmojiSize');
    if (s) document.getElementById('publicEmojiGrid').style.setProperty('--pes-size', s.value + 'px');
    fetch('../../api/emoji.php?action=public_list').then(function(r) {
        return r.json()
    }).then(function(d) {
        var grid = document.getElementById('publicEmojiGrid');
        if (!grid) return;
        if (!d.success || !d.emojis || d.emojis.length === 0) {
            grid.innerHTML = '<div class="es"><p>' + T('msg_no_public_emoji', '暂无公开表情') + '</p></div>';
            var st = document.getElementById('publicEmojiStats');
            if (st) st.textContent = T('label_total_count', '总数量') + ': 0  ｜  ' + T('label_my_count', '个人上载') + ': 0';
            return;
        }
        var h = '';
        for (var i = 0; i < d.emojis.length; i++) {
            var e = d.emojis[i];
            var isSel = selectedPublicEmoji.some(function(s) { return s.hash === e.hash; });
            h += '<div class="public-emoji-item' + (isSel ? ' selected' : '') + '" title="' + eh(e.display_name || e.username || '') + '">';
            h += '<img src="../../' + e.img + '" onclick="toggleSelectedPublicEmoji(this)" data-code="[emoji:' + e.hash + ']" data-hash="' + e.hash + '" data-img="../../' + e.img + '" alt="">';
            if (e.can_delete) {
                h += '<span class="public-emoji-del" onclick="deletePublicEmoji(event,\'' + e.hash + '\')">&times;</span>';
            }
            h += '</div>';
        }
        grid.innerHTML = h;
        var st = document.getElementById('publicEmojiStats');
        if (st) {
            var total = d.emojis.length;
            var mine = 0;
            for (var i = 0; i < d.emojis.length; i++) {
                if ((parseInt(d.emojis[i].owner_uid, 10)) === parseInt(MYUID, 10)) mine++;
            }
            st.textContent = T('label_total_count', '总数量') + ': ' + total + '  ｜  ' + T('label_my_count', '个人上载') + ': ' + mine;
        }
    });
}

function uploadPublicEmoji() {
    var input = document.getElementById('publicEmojiFile');
    var files = input.files;
    if (!files || files.length === 0) return;
    var filesArr = [];
    for (var idx = 0; idx < files.length; idx++) filesArr.push(files[idx]);
    input.value = '';
    var done = 0, total = filesArr.length;
    function uploadOne(f) {
        if (f.size > 2 * 1024 * 1024) {
            done++;
            if (done >= total) loadPublicEmoji();
            return;
        }
        var reader = new FileReader();
        reader.onload = function(ev) {
            fetch('../../api/emoji.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'action=public_upload&image=' + encodeURIComponent(ev.target.result)
            }).then(function(r) {
                return r.json()
            }).then(function(d) {
                done++;
                if (done >= total) loadPublicEmoji();
            });
        };
        reader.readAsDataURL(f);
    }
    for (var i = 0; i < filesArr.length; i++) uploadOne(filesArr[i]);
}

function deletePublicEmoji(ev, hash) {
    ev.stopPropagation();
    ev.preventDefault();
    if (!(confirm('Delete this emoji?'))) return;
    var f = new URLSearchParams();
    f.append('action', 'public_delete');
    f.append('hash', hash);
    fetch('../../api/emoji.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: f.toString()
    }).then(function(r) {
        return r.json()
    }).then(function(d) {
        if (d.success) {
            selectedPublicEmoji = selectedPublicEmoji.filter(function(s) { return s.hash !== hash; });
            renderPublicSelection();
            loadPublicEmoji();
        } else xalert('Failed.');
    });
}

function toggleSelectedPublicEmoji(img) {
    if (typeof selectedPublicEmoji === 'undefined' || !Array.isArray(selectedPublicEmoji)) { selectedPublicEmoji = []; }
    var hash = img.getAttribute('data-hash');
    var code = img.getAttribute('data-code');
    var imgSrc = img.getAttribute('data-img');
    if (!hash) return;
    var idx = -1;
    for (var i = 0; i < selectedPublicEmoji.length; i++) {
        if (selectedPublicEmoji[i].hash === hash) { idx = i; break; }
    }
    if (idx >= 0) {
        selectedPublicEmoji.splice(idx, 1);
    } else {
        selectedPublicEmoji.push({ hash: hash, code: code, img: imgSrc });
    }
    renderPublicSelection();
    loadPublicEmoji();
}

function renderPublicSelection() {
    if (typeof selectedPublicEmoji === 'undefined' || !Array.isArray(selectedPublicEmoji)) { selectedPublicEmoji = []; }
    var bar = document.getElementById('publicEmojiSelected');
    if (!bar) return;
    if (selectedPublicEmoji.length === 0) {
        bar.innerHTML = '<span class="pes-tip">' + T('public_emoji_selected', '已选择') + ': 0</span>';
        return;
    }
    var h = '<span class="pes-tip">' + T('public_emoji_selected', '已选择') + '</span>';
    for (var i = 0; i < selectedPublicEmoji.length; i++) {
        var s = selectedPublicEmoji[i];
        h += '<span class="pes-item" title="' + s.code + '" onclick="removeSelectedPublicEmoji(event,\'' + s.hash + '\')"><img src="' + s.img + '" alt=""></span>';
    }
    h += '<button class="pes-add" onclick="addAllSelectedPublicEmoji()">' + T('btn_add_all', '全部添加') + '</button>';
    bar.innerHTML = h;
}

function removeSelectedPublicEmoji(ev, hash) {
    if (typeof selectedPublicEmoji === 'undefined' || !Array.isArray(selectedPublicEmoji)) { selectedPublicEmoji = []; }
    ev.stopPropagation();
    ev.preventDefault();
    var idx = -1;
    for (var i = 0; i < selectedPublicEmoji.length; i++) {
        if (selectedPublicEmoji[i].hash === hash) { idx = i; break; }
    }
    if (idx >= 0) selectedPublicEmoji.splice(idx, 1);
    renderPublicSelection();
    loadPublicEmoji();
}

function addAllSelectedPublicEmoji() {
    if (typeof selectedPublicEmoji === 'undefined' || !Array.isArray(selectedPublicEmoji)) { selectedPublicEmoji = []; }
    if (selectedPublicEmoji.length === 0) return;
    var items = selectedPublicEmoji.slice();
    var done = 0, total = items.length;
    function addOne(code) {
        var f = new URLSearchParams();
        f.append('action', 'add');
        f.append('code', code);
        fetch('../../api/emoji.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        }).then(function(r) {
            return r.json()
        }).then(function(d) {
            done++;
            if (done >= total) {
                selectedPublicEmoji = [];
                renderPublicSelection();
                loadPublicEmoji();
                var msg = T('msg_added_n', '已添加 N 个表情');
                xalert(msg.replace('N', total));
            }
        });
    }
    for (var i = 0; i < items.length; i++) addOne(items[i].code);
}

function openForwardModal(el) {
    var bubble = el && el.closest ? el.closest('.mr') : null;
    if (!bubble) return;
    _fwdRaw = bubble.getAttribute('data-raw') || '';
    openForwardPicker();
}
function openForwardPicker() {
    var list = document.getElementById('forwardTargetList');
    if (!list) return;
    list.innerHTML = '<div class="es"><p>' + T('msg_loading', '加载中...') + '</p></div>';
    document.getElementById('forwardModal').classList.add('active');
    fetch('../../api/contacts.php?action=list').then(function(r) { return r.json() }).then(function(d) {
        if (!d.success || !d.contacts || d.contacts.length === 0) {
            list.innerHTML = '<div class="es"><p>' + T('msg_no_contacts', '暂无联系人') + '</p></div>';
            return;
        }
        var h = '';
        for (var i = 0; i < d.contacts.length; i++) {
            var c = d.contacts[i];
            h += '<div class="fwd-item" onclick="forwardTo(\'' + c.username + '\')"><span class="fwd-name">' + eh(c.note || c.display_name || c.username) + '</span><span class="fwd-uid">@' + eh(c.username) + '</span></div>';
        }
        list.innerHTML = h;
    });
}
var _fwdRaw = '';
var _fwdChatlog = null;
function forwardTo(username) {
    if (!username) return;
    var p;
    if (_fwdChatlog) p = { message: '', recipient: username, msg_type: 'chatlog', chatlog: _fwdChatlog };
    else if (_fwdRaw) p = { message: _fwdRaw, recipient: username };
    else return;
    apiRequest('send', p).then(function(d) {
        if (d.success) {
            closeForwardModal();
            cancelMsgSelect(); // 多选发送完成后退出选择模式
            refreshAfterSend(username); // 及时刷新显示
        } else xalert(d.error || 'Failed.');
    });
}
function closeForwardModal() {
    document.getElementById('forwardModal').classList.remove('active');
    _fwdRaw = '';
    _fwdChatlog = null;
}
// 发送后即时刷新：若目标就是当前打开的聊天，重新加载显示
function refreshAfterSend(username) {
    if (username && D === username && typeof loadDmMessages === 'function') {
        loadDmMessages();
    }
}

/* ================= 消息多选 / 聊天记录汇出（QQ 式） ================= */
var _msgSelectMode = false, _msgSelected = {};
function enterMsgSelectMode(el) {
    _msgSelectMode = true;
    var bar = document.getElementById('msgSelectBar');
    if (bar) bar.style.display = 'flex';
    var mr = el && el.closest ? el.closest('.mr') : null;
    if (mr) toggleMsgSelect(mr, true);
}
function toggleMsgSelect(mr, forceOn) {
    if (!mr || !mr.classList) return;
    var id = mr.getAttribute('data-msgid') || '';
    var on = forceOn ? true : !mr.classList.contains('msg-selected');
    mr.classList.toggle('msg-selected', on);
    if (on) _msgSelected[id] = 1; else delete _msgSelected[id];
    updateMsgSelectBar();
}
function updateMsgSelectBar() {
    var c = document.getElementById('msgSelectCount');
    var n = Object.keys(_msgSelected).length;
    if (c) c.textContent = T('msel_count').replace('%s', n);
    var fb = document.getElementById('msgSelectForwardBtn'), eb = document.getElementById('msgSelectExportBtn');
    if (fb) fb.disabled = n === 0;
    if (eb) eb.disabled = n === 0;
}
function cancelMsgSelect() {
    _msgSelectMode = false;
    _msgSelected = {};
    document.querySelectorAll('.mr.msg-selected').forEach(function(el) { el.classList.remove('msg-selected'); });
    var bar = document.getElementById('msgSelectBar');
    if (bar) bar.style.display = 'none';
}
function collectSelectedMsgs(withTime) {
    var msgs = [];
    document.querySelectorAll('.mr.msg-selected').forEach(function(mr) {
        var mu = mr.querySelector('.mu');
        var name = (mu ? mu.textContent : '') || mr.getAttribute('data-msguser') || '';
        var raw = mr.getAttribute('data-raw') || '';
        var time = '';
        if (withTime) { var mti = mr.querySelector('.mti'); time = mti ? mti.textContent : ''; }
        msgs.push({ n: name, t: raw, time: time });
    });
    return msgs;
}
function buildChatlogData(withTime) {
    var msgs = collectSelectedMsgs(withTime);
    if (!msgs.length) return null;
    var peer = D ? (_contactNotes[D] || D) : (G ? T('cl_group', '群聊') : T('cl_ann', '公告'));
    return JSON.stringify({ peer: peer, msgs: msgs });
}
function forwardSelected() {
    var data = buildChatlogData(false);
    if (!data) { xalert(T('msel_empty', '请先选择消息')); return; }
    _fwdChatlog = data;
    _fwdRaw = '';
    openForwardPicker();
}
function exportSelected() {
    var data = buildChatlogData(true);
    if (!data) { xalert(T('msel_empty', '请先选择消息')); return; }
    _fwdChatlog = data;
    _fwdRaw = '';
    openForwardPicker();
}

function getComposerTarget() {
    if (document.activeElement && document.activeElement.id && (document.activeElement.id === 'messageInput' || document.activeElement.id === 'dmMessageInput')) return document.activeElement;
    var el = document.getElementById('messageInput');
    if (el && document.getElementById('messagesArea') && document.getElementById('messagesArea').style.display !== 'none') return el;
    return document.getElementById('dmMessageInput');
}
function insertCodeIntoComposer(code) {
    var el = getComposerTarget();
    if (!el) return false;
    var start = el.selectionStart;
    var end = el.selectionEnd;
    var before = el.value.substring(0, start);
    var after = el.value.substring(end);
    el.value = before + code + after;
    el.selectionStart = el.selectionEnd = start + code.length;
    el.dispatchEvent(new Event('input'));
    el.focus();
    return true;
}
function openMessageMenuForTarget(target, e) {
    var bubble = target && target.closest ? target.closest('.mr') : null;
    if (!bubble) return false;
    var btn = bubble.querySelector('.msg-more-btn');
    if (!btn) return false;
    toggleMsgMenu(e, btn);
    return true;
}
function closeAllMsgMenus() {
    document.querySelectorAll('.msg-menu').forEach(function(m) {
        m.style.display = 'none';
        m.style.position = '';
        m.style.top = '';
        m.style.left = '';
        m.style.bottom = '';
    });
}
function toggleMsgMenu(e, btn) {
    e.stopPropagation();
    e.preventDefault();
    var menu = btn.nextElementSibling;
    if (!menu || !menu.classList.contains('msg-menu')) return;
    var wasActive = menu.style.display === 'block';
    closeAllMsgMenus();
    if (!wasActive) {
        var r = btn.getBoundingClientRect();
        var x = r.right;
        var y = r.bottom;
        // Right-click keeps old mouse-position behavior; the 3-dot button anchors to its bottom-right.
        if (e.type === 'contextmenu' && e.clientX) {
            x = e.clientX;
            y = e.clientY;
        }
        menu.style.display = 'block';
        menu.style.position = 'fixed';
        menu.style.left = Math.max(4, x - 8) + 'px';
        menu.style.top = Math.max(4, y - 8) + 'px';
        menu.style.right = 'auto';
        menu.style.bottom = 'auto';
    }
}
var _emojiContextTimer = null;
var _emojiContextTarget = null;
function clearEmojiContextTimer() {
    if (_emojiContextTimer) {
        clearTimeout(_emojiContextTimer);
        _emojiContextTimer = null;
    }
    _emojiContextTarget = null;
}
document.addEventListener('contextmenu', function(e) {
    var target = e.target && e.target.closest ? e.target.closest('.chat-emoji, .mr') : null;
    if (target && !e.target.closest('.file-dl-btn')) {
        e.preventDefault();
        e.stopPropagation();
        openMessageMenuForTarget(target, e);
    }
});
document.addEventListener('touchstart', function(e) {
    var target = e.target && e.target.closest ? e.target.closest('.chat-emoji, .mr') : null;
    if (!target) return;
    clearEmojiContextTimer();
    _emojiContextTarget = target;
    _emojiContextTimer = setTimeout(function() {
        if (_emojiContextTarget && _emojiContextTarget.isConnected) {
            openMessageMenuForTarget(_emojiContextTarget, {
                clientX: e.touches && e.touches[0] ? e.touches[0].clientX : window.innerWidth / 2,
                clientY: e.touches && e.touches[0] ? e.touches[0].clientY : window.innerHeight / 2,
                preventDefault: function() {},
                stopPropagation: function() {}
            });
        }
        clearEmojiContextTimer();
    }, 650);
}, { passive: true });
document.addEventListener('touchmove', clearEmojiContextTimer, { passive: true });
document.addEventListener('touchend', clearEmojiContextTimer, { passive: true });
document.addEventListener('touchcancel', clearEmojiContextTimer, { passive: true });
document.addEventListener('click', function(e) {
    // 点表情选择器外部任意处 → 关闭（包括停靠状态下点消息气泡等）
    var popup = document.getElementById('emojiPopup');
    if (popup && popup.style.display === 'flex' && e.target && e.target.closest && !e.target.closest('#emojiPopup') && !e.target.closest('button[title=Emoji]') && !e.target.closest('#dmNineMenu') && !e.target.closest('#dmNineBtn')) {
        popup.style.display = 'none';
        emojiUndockMobile();
    }
    // 多选模式：点击聊天框 = 选中/取消（QQ 式），优先于其它气泡行为
    if (_msgSelectMode && e.target && e.target.closest) {
        var selMr = e.target.closest('.mr');
        if (selMr && !e.target.closest('.msg-more-btn') && !e.target.closest('.msg-menu') && !e.target.closest('.file-dl-btn')) {
            toggleMsgSelect(selMr);
            e.stopPropagation();
            e.preventDefault();
            return;
        }
    }
    // Mobile touch: tapping a message bubble opens the context menu; desktop keeps current behavior.
    var isTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints && navigator.maxTouchPoints > 0);
    var bubbling = isTouch && e.target && e.target.closest ? e.target.closest('.mr') : null;
    if (bubbling && !e.target.closest('.msg-more-btn') && !e.target.closest('.msg-menu') && !e.target.closest('.msg-emoji-add') && !e.target.closest('.file-dl-btn')) {
        openMessageMenuForTarget(bubbling, e);
        return;
    }
    var emojiAdd = e.target && e.target.closest ? e.target.closest('.msg-emoji-add') : null;
    if (emojiAdd) {
        e.stopPropagation();
        e.preventDefault();
        var code = emojiAdd.getAttribute('data-emoji-code') || '';
        closeAllMsgMenus();
        if (!code) return;
        // Add this emoji to my custom emoji list (backend records ownership per user)
        var f = new URLSearchParams();
        f.append('action', 'add');
        f.append('code', code);
        fetch('../../api/emoji.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: f.toString()
        }).then(function(r) {
            return r.json()
        }).then(function(d) {
            if (d.success) {
                // refresh custom emoji list silently (no popup)
                switchEmojiTab('custom');
            }
        });
        return;
    }
    if (!e.target.closest('.msg-more-btn') && !e.target.closest('.msg-menu')) {
        closeAllMsgMenus();
    }
});

// ================= Level system frontend =================
function _lvlTypeLabel(type) {
    var map = {
        'sign': T('lvl_type_sign', '每日签到'),
        'msg': T('lvl_type_msg', '发送消息'),
        'attach': T('lvl_type_attach', '发送附件'),
        'receive': T('lvl_type_receive', '收到消息'),
        'emoji': T('lvl_type_emoji', '上传公开表情'),
        'bug': T('lvl_type_bug', '提交 Bug'),
        'suggestion': T('lvl_type_suggestion', '提交建议'),
        'bug_resolved': T('lvl_type_bug_resolved', 'Bug 被修复'),
        'suggestion_resolved': T('lvl_type_suggestion_resolved', '建议被采纳'),
        'bonus_avatar': T('lvl_type_bonus_avatar', '首次设置头像'),
        'bonus_zh_egg': T('lvl_type_bonus_zh_egg', '使用彩蛋语言'),
        'bonus_wyw': T('lvl_type_bonus_wyw', '使用文言文'),
        'bonus_report': T('lvl_type_bonus_report', '首次举报'),
        'bonus_first_bug': T('lvl_type_bonus_first_bug', '首次提交 Bug')
    };
    return map[type] || type;
}

function loadLevelPanel() {
    fetch('../../api/level.php?action=info').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) return;
        var badge = document.getElementById('lvlBadge');
        if (badge) badge.textContent = 'Lv.' + d.level;
        var fill = document.getElementById('lvlExpFill');
        if (fill) fill.style.width = d.progress + '%';
        var meta = document.getElementById('lvlExpMeta');
        if (meta) meta.textContent = d.cur + ' / ' + d.need + ' (' + Math.max(0, d.need - d.cur) + ' left)';
        var prog = document.getElementById('lvlProgress');
        if (prog) prog.textContent = d.progress + '% to Lv.' + (d.level + 1);
        // Upgrade button (Option B: manual upgrade, exp keeps growing)
        var upBtn = document.getElementById('lvlUpgradeBtn');
        var upInfo = document.getElementById('lvlUpgradeInfo');
        if (upBtn && upInfo) {
            if (d.need > 0 && d.cur >= d.need && d.can_upgrade) {
                upBtn.style.display = 'inline-block';
                upInfo.textContent = '';
                upBtn.textContent = T('btn_upgrade', '升级') + ' → Lv.' + (d.level + 1);
            } else if (d.level >= d.max_level && d.level >= 100) {
                upBtn.style.display = 'none';
                upInfo.textContent = T('lvl_max', '已满级');
            } else {
                upBtn.style.display = 'none';
                upInfo.textContent = T('lvl_need_upgrade', '达成经验后手动升级解锁更高等级');
            }
        }
        var limitAttach = document.getElementById('lvlLimitAttach');
        if (limitAttach) limitAttach.textContent = fmtAttach(d.limits.max_attach_kb);
        var limitGroups = document.getElementById('lvlLimitGroups');
        if (limitGroups) limitGroups.textContent = d.limits.max_groups;
        var limitContacts = document.getElementById('lvlLimitContacts');
        if (limitContacts) limitContacts.textContent = d.limits.max_contacts;
        var signBtn = document.getElementById('lvlSignBtn');
        var signInfo = document.getElementById('lvlSignInfo');
        if (d.signed_today) {
            signBtn.disabled = true;
            signBtn.textContent = T('btn_signed', '今日已签到');
            signInfo.textContent = T('lvl_sign_streak', '连续签到') + ': ' + d.sign_streak + T('lvl_days', '天');
        } else {
            signBtn.disabled = false;
            signBtn.textContent = T('btn_sign_in', '签到');
            signInfo.textContent = T('lvl_sign_tomorrow', '签到得') + ' +' + d.next_sign_exp + ' EXP' + (d.sign_streak > 0 ? ' (' + T('lvl_streak', '已连续') + ' ' + d.sign_streak + T('lvl_days', '天') + ')' : '');
        }
    });
    loadLevelBoard();
    loadLevelHistory();
}

function fmtAttach(kb) {
    if (kb >= 1024 * 1024) return (kb / 1024 / 1024).toFixed(2) + ' GB';
    if (kb >= 1024) return Math.round(kb / 1024) + ' MB';
    return kb + ' KB';
}

async function doUpgrade() {
    try {
        var d = await fetch('../../api/level.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'action=upgrade'
        }).then(function(r) { return r.json(); });
        if (d.success) {
            if (d.payload) {
                MYLV = d.payload.level;
                MYEXP = d.payload.exp;
            }
            xalert(T('lvl_upgraded', '升级成功') + ' → Lv.' + (d.level || MYLV));
            // refresh settings page display too
            loadLevelPanel();
        } else {
            xalert(d.error || T('lvl_error', '操作失败'));
        }
    } catch (e) {
        xalert(T('lvl_error', '操作失败'));
    }
}

async function doSign() {
    try {
        var d = await fetch('../../api/level.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'action=sign'
        }).then(function(r) { return r.json(); });
        if (d.success) {
            xalert('+' + d.exp + ' EXP | ' + T('lvl_streak', '连续签到') + ': ' + d.streak + T('lvl_days', '天'));
            if (d.payload) {
                MYLV = d.payload.level;
                MYEXP = d.payload.exp;
            }
            loadLevelPanel();
        } else {
            xalert(d.error || T('lvl_already_signed', '今日已签到'));
        }
    } catch (e) {
        xalert(T('lvl_error', '签到失败'));
    }
}

function loadLevelBoard() {
    var board = document.getElementById('lvlBoard');
    if (!board) return;
    board.innerHTML = '<div class="es"><p>' + T('msg_loading', '加载中...') + '</p></div>';
    fetch('../../api/level.php?action=leaderboard').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) { board.innerHTML = ''; return; }
        var rankInfo = document.getElementById('lvlRankInfo');
        if (rankInfo) {
            fetch('../../api/level.php?action=rank').then(function(r2) { return r2.json(); }).then(function(d2) {
                if (d2.success && d2.total > 0) rankInfo.textContent = T('lvl_rank', '我的排名') + ': #' + d2.rank + ' / ' + d2.total;
            }).catch(function() {});
        }
        var h = '<table class="lvl-board-table"><thead><tr><th>#</th><th>' + T('lvl_user', '用户') + '</th><th>Lv</th><th>EXP</th></tr></thead><tbody>';
        for (var i = 0; i < d.list.length; i++) {
            var r = d.list[i];
            h += '<tr' + (r.username === U ? ' class="lvl-me"' : '') + '><td>' + r.rank + '</td><td>' + eh(r.display_name || r.username) + '</td><td>Lv.' + r.level + '</td><td>' + r.exp + '</td></tr>';
        }
        h += '</tbody></table>';
        board.innerHTML = h;
    }).catch(function() {
        board.innerHTML = '';
    });
}

function loadLevelHistory() {
    var hist = document.getElementById('lvlHistory');
    if (!hist) return;
    fetch('../../api/level.php?action=history').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) return;
        if (!d.items || d.items.length === 0) {
            hist.innerHTML = '<div class="es"><p>' + T('lvl_no_history', '暂无经验记录') + '</p></div>';
            return;
        }
        var h = '';
        for (var i = 0; i < d.items.length; i++) {
            var it = d.items[i];
            h += '<div class="lvl-hist-item"><span class="lvl-hist-label">' + eh(_lvlTypeLabel(it.type)) + '</span><span class="lvl-hist-detail">' + (it.detail ? eh(it.detail) : '') + '</span><span class="lvl-hist-exp">+' + it.exp + '</span><span class="lvl-hist-time">' + eh(it.created_at.replace('T', ' ').substring(5, 16)) + '</span></div>';
        }
        hist.innerHTML = h;
    }).catch(function() {
        hist.innerHTML = '';
    });
}

// ================= Flash transfer (temp upload) frontend =================
var _flashTarget = null;  // 'announcement' | 'dm'

function toggleFlashMenu(e, btn) {
    e.stopPropagation();
    e.preventDefault();
    var menu = document.getElementById('flashMenu');
    if (!menu) return;
    var wasOpen = menu.style.display === 'block';
    document.getElementById('flashMenu').style.display = 'none';
    if (wasOpen) return;
    var r = btn.getBoundingClientRect();
    menu.style.display = 'block';
    menu.style.position = 'fixed';
    menu.style.left = Math.max(4, r.left) + 'px';
    menu.style.top = (r.top - menu.offsetHeight - 4) + 'px';
    _flashTarget = btn.closest('.cia') && btn.closest('.cia').querySelector('#dmMessageInput') ? 'dm' : 'announcement';
}

function flashMenuUpload() {
    document.getElementById('flashMenu').style.display = 'none';
    if (_flashTarget === 'dm' && D) {
        document.getElementById('dmMediaFile').click();
    } else {
        document.getElementById('mediaFile').click();
    }
}

function flashMenuFlash() {
    document.getElementById('flashMenu').style.display = 'none';
    if (_flashTarget === 'dm') {
        document.getElementById('flashMediaFileDm').click();
    } else {
        document.getElementById('flashMediaFile').click();
    }
}

function flashMenuMy() {
    document.getElementById('flashMenu').style.display = 'none';
    loadFlashMy();
    document.getElementById('flashMyModal').classList.add('active');
}

function closeFlashMyModal() {
    document.getElementById('flashMyModal').classList.remove('active');
}

// ================= 9 点菜单（移动端输入区折叠按钮 → 弹框网格） =================
function toggleDmNineMenu(e, btn) {
    e.stopPropagation();
    e.preventDefault();
    var menu = document.getElementById('dmNineMenu');
    if (!menu) return;
    var wasOpen = menu.style.display === 'grid';
    menu.style.display = 'none';
    if (wasOpen) return;
    var r = btn.getBoundingClientRect();
    menu.style.display = 'grid';
    var mw = menu.offsetWidth, mh = menu.offsetHeight;
    var left = r.left + r.width - mw;
    if (left < 4) left = 4;
    if (left + mw > window.innerWidth - 4) left = window.innerWidth - mw - 4;
    if (r.top >= mh + 10) menu.style.top = (r.top - mh - 8) + 'px';
    else menu.style.top = (r.bottom + 8) + 'px';
    menu.style.left = left + 'px';
}
function closeDmNineMenu() {
    var m = document.getElementById('dmNineMenu');
    if (m) m.style.display = 'none';
}
// 手机端表情选择器停靠在输入栏下面（给 .main-content 加下内边距，把聊天/输入栏抬上去）
function emojiDockMobile() {
    var mc = document.querySelector('.main-content');
    if (mc && window.matchMedia && window.matchMedia('(max-width:768px)').matches) mc.classList.add('emoji-open');
}
function emojiUndockMobile() {
    var mc = document.querySelector('.main-content');
    if (mc) mc.classList.remove('emoji-open');
}
function nineEmoji() {
    closeDmNineMenu();
    var popup = document.getElementById('emojiPopup');
    var btn = document.getElementById('dmNineBtn');
    if (!popup || !btn) return;
    if (popup.style.display === 'flex') { popup.style.display = 'none'; emojiUndockMobile(); return; }
    _emojiTarget = 'dmMessageInput';
    var rect = btn.getBoundingClientRect();
    var popW = 360;
    var left = rect.left + (rect.width - popW) / 2;
    if (left < 4) left = 4;
    if (left + popW > window.innerWidth - 4) left = window.innerWidth - popW - 4;
    if (rect.top >= 160) popup.style.top = (rect.top - 158) + 'px';
    else popup.style.top = (rect.bottom + 6) + 'px';
    popup.style.left = left + 'px';
    popup.style.display = 'flex';
    emojiDockMobile();
    switchEmojiTab('builtin');
}
function nineFlash() {
    closeDmNineMenu();
    var i = document.getElementById('flashMediaFileDm');
    if (i) i.click();
}
function nineUpload() {
    closeDmNineMenu();
    var i = document.getElementById('dmMediaFile');
    if (i) i.click();
}
function ninePen() {
    closeDmNineMenu();
    var btn = document.getElementById('dmNineBtn');
    if (btn) togglePenMenu({ stopPropagation: function () {} }, btn);
}
function nineVoice() {
    closeDmNineMenu();
    toggleVoiceRec();
}
function nineMy() {
    closeDmNineMenu();
    loadFlashMy();
    var m = document.getElementById('flashMyModal');
    if (m) m.classList.add('active');
}
function nineShare() { closeDmNineMenu(); startStandaloneShare(); }
function nineVoiceCall() { closeDmNineMenu(); startVoiceCall(); }
function nineVideoCall() { closeDmNineMenu(); startVideoCall(); }
// 点击 9 点菜单/按钮外部时收起
document.addEventListener('click', function (e) {
    var menu = document.getElementById('dmNineMenu');
    if (!menu || menu.style.display !== 'grid') return;
    if (e.target.closest && e.target.closest('#dmNineMenu')) return;
    if (e.target.closest && e.target.closest('#dmNineBtn')) return;
    menu.style.display = 'none';
});

function flashFileChosen(input, target) {
    var files = input.files ? Array.prototype.slice.call(input.files) : [];
    input.value = '';
    if (!files.length) return;
    if (files.length > 1) {
        // 多文件：走批量预览 → 逐个普通发送
        openBatchPreview(files, target === 'dm' ? 'dm' : 'ann');
        return;
    }
    var f = files[0];
    if (f.size > 8 * 1024 * 1024 * 1024) {
        xalert(T('flash_too_large', '文件过大'));
        return;
    }
    if (f.size > 20 * 1024 * 1024) {
        // Large file - warn that server limits may apply
        xconfirm(T('flash_large_warn', '文件较大，可能超过服务器上传限制，继续？')).then(function(ok) {
            if (ok !== true) return;
            _doFlashUpload(f, target);
        });
        return;
    }
    _doFlashUpload(f, target);
}

// 点击闪传后立即出现的乐观卡片（不等 create/发送往返）：立刻显示“正在上传”+ 可见进度条
function _appendFlashOptimistic(file, target) {
    var area = (target === 'dm' && D) ? document.getElementById('dmMessagesArea') : document.getElementById('messagesArea');
    if (!area) return null;
    var es = area.querySelector('.es');
    if (es) es.remove();
    var tmpId = 'pending' + Date.now();
    var html = tempCardHtml({ temp_upload_id: tmpId, attachment_name: file.name, attachment_size: file.size, temp_status: 'uploading', username: U });
    var row = document.createElement('div');
    row.className = 'mr own flash-optimistic';
    row.setAttribute('data-msgid', '9999999999'); // 排序放最底，避免旧消息重排时被顶到最上
    row.setAttribute('data-msguser', U);
    row.setAttribute('data-raw', '');
    row.innerHTML = '<div class="mc"><div class="mb"><div class="mu">' + eh(_contactNotes[U] || U) + '</div><div class="mt"></div>' + html + '<div class="mti">' + fmtTime(Date.now() / 1000) + '</div></div></div>';
    var st = row.querySelector('.flash-state');
    if (st) {
        st.setAttribute('data-temp', tmpId);
        st.textContent = T('flash_uploading', '正在上传') + ': 0%';
    }
    var bar = row.querySelector('.flash-progress');
    if (bar) bar.style.display = 'block';
    area.appendChild(row);
    scrollChatToBottom(area);
    return { row: row, st: st };
}
function _removeFlashOpt(d) {
    if (d && d.optCard && d.optCard.parentNode) d.optCard.parentNode.removeChild(d.optCard);
}
// 真实卡片（loadDmMessages / WSS 推送）出现时，移除同 temp id 的乐观卡片，避免重复
function _removeFlashOptByTemp(tempId) {
    if (!tempId) return;
    var idStr = String(tempId);
    var els = document.querySelectorAll('.flash-optimistic');
    for (var i = 0; i < els.length; i++) {
        var st = els[i].querySelector('.flash-state');
        var t = st ? st.getAttribute('data-temp') : null;
        if (t === idStr) { els[i].remove(); }
    }
}
function _doFlashUpload(file, target) {
    // 0) 立即显示乐观卡片：点击后立刻出现 Flash transfer + “正在上传”（进度条可见）
    var opt = _appendFlashOptimistic(file, target);
    // 1) create：先占位（登记上传中记录，hash 暂空）
    var cf = new URLSearchParams();
    cf.append('action', 'create');
    cf.append('filename', file.name);
    cf.append('size', file.size);
    fetch('../../api/temp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: cf.toString()
    }).then(function(r) {
        return r.text().then(function(txt) {
            var d = null;
            try { d = JSON.parse(txt); } catch (e) { d = null; }
            if (d && d.success) return d;
            if (d && d.error) { var e = new Error(d.error); e.server = d; throw e; }
            var e2 = new Error('HTTP ' + r.status); e2.status = r.status; throw e2;
        });
    }).then(function(d) {
        var tempId = d.id;
        // 2) 把乐观卡片绑定到真实 temp id（进度/状态才能写入它），并启动轮询兜底
        if (opt && opt.st) {
            opt.st.setAttribute('data-temp', tempId);
            if (opt.row && opt.row.parentNode) startTempPoll(opt.row);
        }
        // 3) 发消息：双方都看到卡片（对方显示“对方正在上传中”）
        if (target === 'dm' && D) flashSendDm({ id: tempId, optCard: opt && opt.row });
        else flashSendAnnouncement({ id: tempId, optCard: opt && opt.row });
        // 4) XHR 传字节，进度实时写回本端卡片
        uploadFlashBytes(tempId, file);
    }).catch(function(err) {
        _removeFlashOpt({ optCard: opt && opt.row });
        if (err && err.server && err.server.error) {
            var s = err.server, extra = '';
            if (s.active_count != null && s.max_active) extra = '（已有 ' + s.active_count + ' 个，上限 ' + s.max_active + ' 个）';
            else if (s.max_size && s.error === 'File too large') extra = '（上限 ' + fmtSize(s.max_size) + '）';
            xalert(T('flash_fail', '闪传失败') + '：' + s.error + extra);
            return;
        }
        var status = err && err.status;
        if (status === 413) { xalert(T('flash_fail_413', '闪传失败：文件过大，超出服务器上传限制。')); return; }
        if (status >= 500) { xalert(T('flash_fail_5xx', '闪传失败：服务器错误 (HTTP ' + status + ')。')); return; }
        xalert(T('flash_fail_net', '闪传失败：网络错误（连接被中断）。若文件较大，可能是服务器/代理上传限制。'));
    });
}

// XHR 上传字节，进度实时写回本端闪传卡片
function uploadFlashBytes(tempId, file) {
    var fd = new FormData();
    fd.append('action', 'upload');
    fd.append('id', tempId);
    fd.append('file', file);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '../../api/temp.php');
    xhr.upload.onprogress = function(ev) {
        if (!ev.lengthComputable) return;
        var pct = Math.round(ev.loaded / ev.total * 100);
        var st = document.querySelector('.flash-state[data-temp="' + tempId + '"]');
        if (!st) return;
        var card = st.closest('.flash-card');
        var bar = card && card.querySelector('.flash-progress');
        var fill = bar && bar.querySelector('.flash-progress-fill');
        var pctEl = bar && bar.querySelector('.flash-progress-pct');
        if (bar) bar.style.display = 'block';
        if (fill) fill.style.width = pct + '%';
        if (pctEl) pctEl.textContent = pct + '%';
        st.textContent = T('flash_uploading', '正在上传') + ': ' + pct + '%';
    };
    xhr.onload = function() {
        var d = null;
        try { d = JSON.parse(xhr.responseText); } catch (e) {}
        var st = document.querySelector('.flash-state[data-temp="' + tempId + '"]');
        var card = st && st.closest('.flash-card');
        var bar = card && card.querySelector('.flash-progress');
        if (bar) bar.style.display = 'none';
        if (d && d.success) {
            if (st) st.textContent = T('flash_has_uploaded', '已上传');
            // 状态轮询会接手：ready 后恢复下载按钮
        } else {
            var msg = (d && d.error) ? d.error : ('HTTP ' + xhr.status);
            if (st) st.textContent = T('flash_upload_failed', '上传失败');
            xalert(T('flash_fail', '闪传失败') + '：' + msg);
        }
    };
    xhr.onerror = function() {
        var st = document.querySelector('.flash-state[data-temp="' + tempId + '"]');
        if (st) st.textContent = T('flash_upload_failed', '上传失败');
        xalert(T('flash_fail_net', '闪传失败：网络错误（连接被中断）。'));
    };
    xhr.send(fd);
}

function flashSendAnnouncement(d) {
    apiRequest('send', { message: '', temp_upload_id: d.id }).then(function(res) {
        if (res.success) {
            copyText(d.url || '');
            // 公告区没有本地重载：乐观卡片保留显示，交给轮询接管就绪/下载；WSS 推到真实公告卡时会被 _removeFlashOptByTemp 替换
            var st = document.querySelector('.flash-state[data-temp="' + d.id + '"]');
            var row = st && st.closest('.flash-optimistic');
            if (row && row.parentNode) startTempPoll(row);
            pm();
        } else {
            _removeFlashOpt(d);
            xalert(T('flash_send_fail', '闪传发送失败') + '：' + (res.error || T('flash_send_unknown', '未知错误')));
        }
    }).catch(function() {
        _removeFlashOpt(d);
        xalert(T('flash_send_fail_net', '闪传发送失败：网络错误，请重试。'));
    });
}

function flashSendDm(d) {
    if (!D) { _removeFlashOpt(d); return; }
    apiRequest('send', { message: '', recipient: D, temp_upload_id: d.id }).then(function(res) {
        if (res.success) {
            delete seenMsgIds['dm_' + res.message_id];
            loadDmMessages().then(function() {
                _removeFlashOpt(d);
            }).catch(function() {
                _removeFlashOpt(d);
            });
            var a = document.getElementById('dmMessagesArea');
            if (a) scrollChatToBottom(a);
        } else {
            _removeFlashOpt(d);
            xalert(T('flash_send_fail', '闪传发送失败') + '：' + (res.error || T('flash_send_unknown', '未知错误')));
        }
    }).catch(function() {
        _removeFlashOpt(d);
        xalert(T('flash_send_fail_net', '闪传发送失败：网络错误，请重试。'));
    });
}

function copyText(txt) {
    try {
        navigator.clipboard.writeText(txt).catch(function() {});
    } catch (e) {}
}

// ---- Flash card rendering (called from attachmentHtml for msg_type='temp') ----
function tempCardHtml(m) {
    var id = m.temp_upload_id;
    var name = eh(m.attachment_name || 'file');
    var size = m.attachment_size ? fmtSize(m.attachment_size) : '';
    var revoked = m.temp_revoked ? 1 : 0;
    var isOwner = (m.username === U);
    var uploading = (m.temp_status === 'uploading');
    var dlBtn;
    if (revoked || uploading) {
        dlBtn = '<span class="flash-dl flash-dl-dis">' + (uploading ? T('flash_uploading_btn', '上传中…') : T('btn_download', '下载')) + '</span>';
    } else {
        dlBtn = '<button class="flash-dl" onclick="event.stopPropagation();tempDownload(' + id + ')">' + T('btn_download', '下载') + '</button>';
    }
    var statusRow = '';
    if (revoked) {
        statusRow = '<div class="flash-status flash-revoked">' + T('flash_revoked_msg', '已被撤回并删除') + '</div>';
    } else if (uploading) {
        statusRow = '<div class="flash-status flash-state" data-temp="' + id + '" data-owner="' + (isOwner ? 1 : 0) + '">' + (isOwner ? T('flash_uploading', '正在上传') : T('flash_partner_uploading', '对方正在上传中')) + '</div>';
    } else {
        statusRow = '<div class="flash-status flash-state" data-temp="' + id + '" data-owner="' + (isOwner ? 1 : 0) + '">' + T('flash_checking', '检查状态...') + '</div>';
    }
    // 上传/下载进度条
    var progRow = '<div class="flash-progress" style="display:none"><div class="flash-progress-fill"></div><span class="flash-progress-pct">0%</span></div>';
    var expireRow = '<div class="flash-expire" data-expires="' + (m.temp_expires || '') + '">' + T('flash_expire', '过期时间') + ': --:--:--</div>';
    return '<div class="flash-card" data-fname="' + name + '" data-size="' + (m.attachment_size || 0) + '">'
        + '<div class="flash-title">' + T('flash_flash', '闪传（临时）') + '</div>'
        + '<div class="flash-file">' + name + (size ? ' (' + size + ')' : '') + '</div>'
        + dlBtn
        + statusRow
        + progRow
        + expireRow
        + '</div>';
}

function tempDownload(id) {
    // 找到对应闪传卡片（取文件名/大小/进度条）
    var st = document.querySelector('.flash-state[data-temp="' + id + '"]');
    var card = st ? st.closest('.flash-card') : null;
    var fname = card ? (card.getAttribute('data-fname') || ('flash-' + id)) : ('flash-' + id);
    var size = card ? (parseInt(card.getAttribute('data-size') || '0', 10) || 0) : 0;
    var bar = card ? card.querySelector('.flash-progress') : null;
    var fill = bar ? bar.querySelector('.flash-progress-fill') : null;
    var pctEl = bar ? bar.querySelector('.flash-progress-pct') : null;
    var url = '../../api/temp.php?action=download&id=' + id;
    // 超大文件（>512MB）仍走原生新标签下载，避免浏览器内存打爆
    if (size > 512 * 1024 * 1024) { openFlashNative(url); return; }
    if (bar) bar.style.display = 'block';
    fetch(url).then(function(res) {
        if (!res.ok || !res.body) throw new Error('http ' + res.status);
        var total = parseInt(res.headers.get('Content-Length') || '0', 10) || size || 0;
        var reader = res.body.getReader();
        var received = 0, chunks = [];
        function pump() {
            return reader.read().then(function(r) {
                if (r.done) {
                    var blob = new Blob(chunks, { type: 'application/octet-stream' });
                    if (bar) bar.style.display = 'none';
                    saveFlashBlob(blob, fname);
                    return;
                }
                chunks.push(r.value);
                received += r.value.length;
                if (fill && total) fill.style.width = Math.min(100, Math.round(received / total * 100)) + '%';
                if (pctEl && total) pctEl.textContent = Math.round(received / total * 100) + '%';
                return pump();
            });
        }
        return pump();
    }).catch(function() {
        if (bar) bar.style.display = 'none';
        // 失败（撤销/网络等）降级：原生下载兜底
        openFlashNative(url);
    });
}
// 原生方式打开闪传下载（新标签页，浏览器自行处理）
function openFlashNative(url) {
    var a = document.createElement('a');
    a.href = url;
    a.target = '_blank';
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
}
// 把流式下载到的 Blob 保存为文件
function saveFlashBlob(blob, name) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function() { URL.revokeObjectURL(url); }, 4000);
}

// ---- Flash card UI 更新（WSS 推送 temp_status 时调用；也供本地 HTTP 轮询复用） ----

// 下载速度跟踪器（按 temp id 存上一次采样点；WSS 推送与 HTTP 轮询共用）
var _flashDlSpeed = {};
function flashDlSpeedText(tempId, bytes) {
    var now = Date.now();
    var prev = _flashDlSpeed[tempId];
    if (!prev) {
        _flashDlSpeed[tempId] = { bytes: bytes, time: now };
        return null; // 第一次采样，无基线 → 暂不显示速度
    }
    var speed = 0;
    if (bytes >= prev.bytes && now > prev.time) {
        speed = (bytes - prev.bytes) / ((now - prev.time) / 1000);
    } else if (bytes < prev.bytes) {
        _flashDlSpeed[tempId] = { bytes: bytes, time: now };
        return null; // 新的下载开始（进度清零），重新取基线
    }
    _flashDlSpeed[tempId] = { bytes: bytes, time: now };
    return fmtSpeed(speed);
}

window.updateTempCardFromPush = function(state, item) {
    if (!state || !item) return;
    var bubble = state.closest('.flash-card');
    if (!bubble) return;
    var expireEl = bubble.querySelector('.flash-expire');

    // 更新过期时间
    if (expireEl && item.expires_at) {
        expireEl.setAttribute('data-expires', item.expires_at);
    }

    // 更新下载按钮状态（被撤销）
    if (item.revoked) {
        state.textContent = T('flash_revoked_msg', '已被撤回并删除');
        var btn = bubble.querySelector('.flash-dl');
        if (btn && btn.tagName === 'BUTTON') {
            var sp = document.createElement('span');
            sp.className = 'flash-dl flash-dl-dis';
            sp.textContent = T('btn_download', '下载');
            btn.parentNode.replaceChild(sp, btn);
        }
        return;
    }

    // 更新状态文本
    var isOwner = state.getAttribute('data-owner') === '1';
    if (item.status === 'complete') {
        state.textContent = isOwner
            ? (T('flash_complete', '对方已经下载完成') + ' ✓')
            : T('flash_has_downloaded', '已下载');
    } else if (item.status === 'in_progress' && isOwner && typeof item.downloaded_bytes === 'number' && item.size > 0) {
        var pct = Math.round(item.downloaded_bytes / item.size * 100);
        var spd = flashDlSpeedText(item.id, item.downloaded_bytes);
        state.textContent = T('flash_downloading', '对方正在下载') + ': ' + pct + '%' + (spd ? ' ' + spd : '');
    } else if (item.status === 'in_progress') {
        state.textContent = T('flash_has_downloaded', '已下载');
    } else if (item.status === 'revoked') {
        state.textContent = T('flash_revoked_msg', '已被撤回并删除');
        var btn2 = bubble.querySelector('.flash-dl');
        if (btn2 && btn2.tagName === 'BUTTON') {
            var sp2 = document.createElement('span');
            sp2.className = 'flash-dl flash-dl-dis';
            sp2.textContent = T('btn_download', '下载');
            btn2.parentNode.replaceChild(sp2, btn2);
        }
    } else {
        state.textContent = isOwner
            ? T('flash_not_started', '对方还没下载完成')
            : T('flash_not_downloaded', '是否已下载: 否');
    }
};

// ---- Flash status polling + countdown + speed ----
function startTempPoll(bubble) {
    var state = bubble.querySelector('.flash-state');
    var expireEl = bubble.querySelector('.flash-expire');
    if (!state || !expireEl) return;
    if (bubble.getAttribute('data-temp-poll')) return;
    bubble.setAttribute('data-temp-poll', '1');
    var id = parseInt(state.getAttribute('data-temp'), 10);
    var isOwner = state.getAttribute('data-owner') === '1';
    function tick() {
        if (!bubble.isConnected) return;
        // Countdown
        var ex = expireEl.getAttribute('data-expires');
        if (ex) {
            var t = new Date(ex.replace(' ', 'T') + 'Z').getTime() - Date.now();
            if (t <= 0) {
                expireEl.textContent = T('flash_expire', '过期时间') + ': 00:00:00';
                state.textContent = T('flash_expired', '已过期');
                return;
            }
            var sec = Math.floor(t / 1000);
            var hh = String(Math.floor(sec / 3600)).padStart(2, '0');
            var mm = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
            var ss = String(sec % 60).padStart(2, '0');
            expireEl.textContent = T('flash_expire', '过期时间') + ': ' + hh + ':' + mm + ':' + ss;
        }
        fetch('../../api/temp.php?action=status&id=' + id).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.success) { state.textContent = T('flash_expired', '已过期'); return; }
            if (d.revoked || d.status === 'revoked') {
                state.textContent = T('flash_revoked_msg', '已被撤回并删除');
                var btn = bubble.querySelector('.flash-dl');
                if (btn && btn.tagName === 'BUTTON') {
                    var sp = document.createElement('span');
                    sp.className = 'flash-dl flash-dl-dis';
                    sp.textContent = T('btn_download', '下载');
                    btn.parentNode.replaceChild(sp, btn);
                }
                return;
            }
            // 上传中：对方还没传完 → 禁用下载；发送端进度由 XHR 驱动（轮询不覆盖其百分比）
            if (d.upload_status === 'uploading') {
                var btnU = bubble.querySelector('.flash-dl');
                if (btnU && btnU.tagName === 'BUTTON') {
                    var spU = document.createElement('span');
                    spU.className = 'flash-dl flash-dl-dis';
                    spU.textContent = T('flash_uploading_btn', '上传中…');
                    btnU.parentNode.replaceChild(spU, btnU);
                }
                if (!isOwner) {
                    state.textContent = T('flash_partner_uploading', '对方正在上传中');
                }
                return;
            }
            // 已就绪：恢复下载按钮（若之前被"上传中"状态禁用）
            var btnR = bubble.querySelector('.flash-dl');
            if (btnR && btnR.tagName === 'SPAN' && btnR.classList.contains('flash-dl-dis')) {
                var bR = document.createElement('button');
                bR.className = 'flash-dl';
                bR.onclick = function(ev) { ev.stopPropagation(); tempDownload(id); };
                btnR.parentNode.replaceChild(bR, btnR);
            }
            if (isOwner && d.status !== 'not_started') {
                // Owner: show real-time download progress & speed
                if (d.status === 'complete') {
                    state.textContent = T('flash_complete', '对方已经下载完成') + ' ✓';
                    return;
                }
                if (d.status === 'in_progress' && typeof d.downloaded_bytes === 'number' && d.size > 0) {
                    var pct = Math.round(d.downloaded_bytes / d.size * 100);
                    var spd2 = flashDlSpeedText(id, d.downloaded_bytes);
                    state.textContent = T('flash_downloading', '对方正在下载') + ': ' + pct + '%' + (spd2 ? ' ' + spd2 : '');
                } else {
                    state.textContent = T('flash_not_started', '对方还没下载完成');
                }
            } else {
                // Receiver sees only whether download happened
                state.textContent = (d.status === 'complete' || d.status === 'in_progress')
                    ? T('flash_has_downloaded', '已下载')
                    : T('flash_not_downloaded', '是否已下载: 否');
            }
        }).catch(function() {});
    }
    tick();
    // WSS 在线时：状态由 temp_status 推送驱动，HTTP 降至 10s 低频兜底；
    // WSS 断线时：保持 2s 高频轮询确保实时性。
    function scheduleNext() {
        var interval = (typeof window.wssRequestAvailable === 'function' && window.wssRequestAvailable()) ? 10000 : 2000;
        setTimeout(function() {
            if (bubble.isConnected) {
                tick();
                scheduleNext();
            }
        }, interval);
    }
    scheduleNext();
}

// ---- Flash forward (share same temp file, new message) ----
function flashForward(el, tempId) {
    var bubble = el && el.closest ? el.closest('.mr') : null;
    if (!bubble) return;
    var list = document.getElementById('forwardTargetList');
    if (!list) return;
    list.innerHTML = '<div class="es"><p>' + T('msg_loading', '加载中...') + '</p></div>';
    document.getElementById('forwardModal').classList.add('active');
    fetch('../../api/contacts.php?action=list').then(function(r) { return r.json() }).then(function(d) {
        if (!d.success || !d.contacts || d.contacts.length === 0) {
            list.innerHTML = '<div class="es"><p>' + T('msg_no_contacts', '暂无联系人') + '</p></div>';
            return;
        }
        var h = '';
        for (var i = 0; i < d.contacts.length; i++) {
            var c = d.contacts[i];
            h += '<div class="fwd-item" onclick="flashForwardTo(' + tempId + ',\'' + c.username + '\')"><span class="fwd-name">' + eh(c.note || c.display_name || c.username) + '</span><span class="fwd-uid">@' + eh(c.username) + '</span></div>';
        }
        list.innerHTML = h;
    });
}

function flashForwardTo(tempId, username) {
    apiRequest('send', { message: '', recipient: username, temp_upload_id: tempId }).then(function(d) {
        if (d.success) closeForwardModal();
        else xalert(d.error || T('flash_fail', '闪传失败'));
    });
}

// ---- Flash interrupt (revoke file + mark message) ----
function flashInterrupt(el, tempId) {
    var bubble = el && el.closest ? el.closest('.mr') : null;
    xconfirm(T('flash_interrupt_confirm', '撤回并中断此闪传文件？对方将无法下载。')).then(function(ok) {
        if (ok !== true) return;
        var form = new URLSearchParams();
        form.append('action', 'revoke');
        form.append('id', tempId);
        fetch('../../api/temp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: form.toString()
        }).then(function(r) { return r.json() }).then(function(d) {
            if (d.success && bubble && bubble.isConnected) {
                // Re-render card as revoked
                var state = bubble.querySelector('.flash-state');
                if (state) {
                    state.textContent = T('flash_revoked_msg', '已被撤回并删除');
                    var btn = bubble.querySelector('.flash-dl');
                    if (btn && btn.tagName === 'BUTTON') {
                        var sp = document.createElement('span');
                        sp.className = 'flash-dl flash-dl-dis';
                        sp.textContent = T('btn_download', '下载');
                        btn.parentNode.replaceChild(sp, btn);
                    }
                }
                if (bubble.getAttribute('data-temp-poll')) {
                    bubble.removeAttribute('data-temp-poll');
                    startTempPoll(bubble);
                }
            }
        });
    });
}

// ---- My flash files modal ----
function loadFlashMy() {
    var list = document.getElementById('flashMyList');
    if (!list) return;
    list.innerHTML = '<div class="es"><p>' + T('msg_loading', '加载中...') + '</p></div>';
    fetch('../../api/temp.php?action=my').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) { list.innerHTML = ''; return; }
        if (!d.files.length) {
            list.innerHTML = '<div class="es"><p>' + T('flash_no_files', '暂无闪传文件') + '</p></div>';
            return;
        }
        var h = '';
        for (var i = 0; i < d.files.length; i++) {
            var f = d.files[i];
            var rel = '';
            var secs = Math.floor((new Date(f.expires_at.replace(' ', 'T') + 'Z').getTime() - Date.now()) / 1000);
            if (secs > 0) {
                var hh = String(Math.floor(secs / 3600)).padStart(2, '0');
                var mm = String(Math.floor((secs % 3600) / 60)).padStart(2, '0');
                var ss = String(secs % 60).padStart(2, '0');
                rel = hh + ':' + mm + ':' + ss;
            } else {
                rel = T('flash_expired', '已过期');
            }
            var stTxt = f.revoked
                ? '<span style="color:#e06060">' + T('flash_revoked_msg', '已撤回') + '</span>'
                : (f.download_complete ? '<span style="color:#60e060">' + T('flash_has_downloaded', '已下载') + '</span>' : '<span style="color:#999">' + T('flash_not_started', '未下载') + '</span>');
            var dlBtn = f.revoked
                ? '<span class="bsm flash-dl-dis" style="opacity:.4">' + T('btn_download', '下载') + '</span>'
                : '<button class="bsm" onclick="tempDownload(' + f.id + ')">' + T('btn_download', '下载') + '</button>';
            var revBtn = '<button class="bsm" style="color:#e06060" onclick="flashMyRevoke(' + f.id + ')">' + (f.revoked ? T('flash_unrevoke', '解除撤回') : T('flash_revoke_interrupt', '撤回并中断')) + '</button>';
            h += '<div class="flash-my-item" style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #333">'
                + '<div style="flex:1;min-width:0"><div style="color:#ddd;font-size:.82em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + eh(f.filename) + '</div>'
                + '<div style="color:#888;font-size:.68em">' + fmtSize(f.size) + ' · ' + stTxt + '</div></div>'
                + '<div style="color:#666;font-size:.68em">' + rel + '</div>'
                + revBtn
                + dlBtn
                + '</div>';
        }
        list.innerHTML = h;
    }).catch(function() { list.innerHTML = ''; });
}

function flashMyRevoke(id) {
    var form = new URLSearchParams();
    form.append('action', 'revoke');
    form.append('id', id);
    fetch('../../api/temp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: form.toString()
    }).then(function(r) { return r.json() }).then(function(d) {
        if (d.success) loadFlashMy();
    });
}

// ---- EXP toast notifications (log=1 gains, bottom-right) ----
var _expToastSeenId = 0;
function _expToastLabel(type) {
    var map = {
        'sign': T('lvl_type_sign', '每日签到'),
        'receive': T('lvl_type_receive', '收到消息'),
        'bug': T('lvl_type_bug', '提交 Bug'),
        'suggestion': T('lvl_type_suggestion', '提交建议'),
        'bug_resolved': T('lvl_type_bug_resolved', 'Bug 被修复'),
        'suggestion_resolved': T('lvl_type_suggestion_resolved', '建议被采纳'),
        'bonus_avatar': T('lvl_type_bonus_avatar', '首次设置头像'),
        'bonus_zh_egg': T('lvl_type_bonus_zh_egg', '使用彩蛋语言'),
        'bonus_wyw': T('lvl_type_bonus_wyw', '使用文言文'),
        'bonus_report': T('lvl_type_bonus_report', '首次举报'),
        'bonus_first_bug': T('lvl_type_bonus_first_bug', '首次提交 Bug')
    };
    return map[type] || type;
}

function showExpToast(item) {
    var container = document.getElementById('expToasts');
    if (!container) return;
    var el = document.createElement('div');
    el.className = 'exp-toast';
    el.innerHTML = '<div class="exp-toast-title"><span class="plus">+' + item.exp + '</span> EXP</div>'
        + '<div class="exp-toast-sub">' + eh(_expToastLabel(item.type)) + (item.detail ? ' (' + eh(item.detail) + ')' : '') + '</div>'
        + '<div class="exp-toast-sub">' + T('lvl_current_exp', '当前经验') + ': ' + (typeof MYEXP !== 'undefined' ? MYEXP : item.exp) + '</div>';
    el.onclick = function() { el.classList.add('fade'); setTimeout(function() { el.remove(); }, 250); };
    container.appendChild(el);
    requestAnimationFrame(function() { el.classList.add('show'); });
    setTimeout(function() {
        el.classList.add('fade');
        setTimeout(function() { el.remove(); }, 250);
    }, 4000);
}

function pollExpToasts() {
    fetch('../../api/level.php?action=history').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success || !d.items || !d.items.length) return;
        var maxId = 0;
        for (var i = 0; i < d.items.length; i++) {
            if (parseInt(d.items[i].id, 10) > maxId) maxId = parseInt(d.items[i].id, 10);
        }
        if (_expToastSeenId === 0) {
            _expToastSeenId = maxId;   // first run: baseline, no toasts
            exp_refresh();
            return;
        }
        // Newest first: iterate reversed so toasts appear in chronological order
        var newOnes = [];
        for (var i = d.items.length - 1; i >= 0; i--) {
            var it = d.items[i];
            if (parseInt(it.id, 10) > _expToastSeenId) newOnes.push(it);
        }
        if (newOnes.length) {
            for (var j = 0; j < newOnes.length; j++) showExpToast(newOnes[j]);
            _expToastSeenId = maxId;
            exp_refresh();
        }
    }).catch(function() {});
}

function exp_refresh() {
    fetch('../../api/level.php?action=info').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) return;
        if (typeof MYEXP !== 'undefined') MYEXP = d.exp;
        if (typeof MYLV !== 'undefined') MYLV = d.level;
        var badge = document.getElementById('lvlBadge');
        if (badge) badge.textContent = 'Lv.' + d.level;
    }).catch(function() {});
}

// Start polling after baseline load
setTimeout(pollExpToasts, 1500);
setInterval(pollExpToasts, 5000);

// ---- Level badge periodic refresh (not dependent on exp_log) ----
setTimeout(exp_refresh, 500);
setInterval(exp_refresh, 5000);


// ================= Encrypted local chat cache (IndexedDB + AES-256-GCM) =================
var LC_DB_PREFIX = 'chatapp_cache_';
var LC_DB = null;
var LC_READY = false;
var LC_KEY_CACHE = null; // CryptoKey derived from CACHE_KEY

function lcDbName() { return LC_DB_PREFIX + (U || 'user'); }

function lcOpen() {
    if (!window.indexedDB) return Promise.reject(new Error('No IndexedDB'));
    return new Promise(function(resolve, reject) {
        var req = indexedDB.open(lcDbName(), 1);
        req.onupgradeneeded = function(e) {
            var db = e.target.result;
            if (!db.objectStoreNames.contains('channels')) {
                db.createObjectStore('channels', { keyPath: 'key' });
            }
        };
        req.onsuccess = function(e) { LC_DB = e.target.result; resolve(e.target.result); };
        req.onerror = function() { reject(req.error); };
    });
}

function lcTxn(mode) {
    return LC_DB.transaction('channels', mode).objectStore('channels');
}

function lcDeriveKey() {
    if (LC_KEY_CACHE) return Promise.resolve(LC_KEY_CACHE);
    if (!CACHE_KEY || !window.crypto || !crypto.subtle) return Promise.reject(new Error('No key'));
    var enc = new TextEncoder();
    return crypto.subtle.importKey('raw', enc.encode(CACHE_KEY), 'PBKDF2', false, ['deriveKey'])
        .then(function(base) {
            return crypto.subtle.deriveKey(
                { name: 'PBKDF2', salt: enc.encode('chatapp-local-cache-v1'), iterations: 100000, hash: 'SHA-256' },
                base,
                { name: 'AES-GCM', length: 256 },
                false,
                ['encrypt', 'decrypt']
            );
        })
        .then(function(key) { LC_KEY_CACHE = key; return key; });
}

function lcEncrypt(obj) {
    return lcDeriveKey().then(function(key) {
        var iv = crypto.getRandomValues(new Uint8Array(12));
        var data = new TextEncoder().encode(JSON.stringify(obj));
        return crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, data).then(function(buf) {
            return { iv: Array.from(iv), data: Array.from(new Uint8Array(buf)) };
        });
    });
}

function lcDecrypt(store) {
    if (!store || !store.iv || !store.data) return Promise.reject(new Error('Bad store'));
    return lcDeriveKey().then(function(key) {
        var iv = new Uint8Array(store.iv);
        var data = new Uint8Array(store.data);
        return crypto.subtle.decrypt({ name: 'AES-GCM', iv: iv }, key, data).then(function(buf) {
            return JSON.parse(new TextDecoder().decode(buf));
        });
    });
}

function lcSaveChannel(key, messages) {
    if (!LC_READY) return Promise.resolve();
    return lcEncrypt(messages).then(function(ct) {
        return new Promise(function(resolve, reject) {
            var tx = lcTxn('readwrite');
            tx.put({ key: key, iv: ct.iv, data: ct.data });
            tx.oncomplete = resolve;
            tx.onerror = function() { reject(tx.error); };
        });
    }).catch(function() { /* never break chat */ });
}

function lcLoadChannel(key) {
    if (!LC_READY) return Promise.resolve(null);
    return new Promise(function(resolve) {
        try {
            var tx = lcTxn('readonly');
            var req = tx.get(key);
            req.onsuccess = function() {
                var row = req.result;
                if (!row) return resolve(null);
                lcDecrypt(row).then(function(msgs) {
                    // 按 id 排序后再返回，旧版脏缓存可能有乱序
                    if (Array.isArray(msgs)) msgs.sort(function(a, b) { return (a.id || 0) - (b.id || 0); });
                    resolve(msgs);
                }).catch(function() { resolve(null); });
            };
            req.onerror = function() { resolve(null); };
        } catch (e) { resolve(null); }
    });
}

function lcClearAll() {
    if (!LC_READY) return Promise.resolve();
    return new Promise(function(resolve) {
        try {
            var tx = lcTxn('readwrite');
            tx.clear();
            tx.oncomplete = resolve;
            tx.onerror = resolve;
        } catch (e) { resolve(); }
    });
}

function lcInit() {
    if (typeof LOCAL_CACHE === 'undefined' || !LOCAL_CACHE || typeof CACHE_KEY === 'undefined' || !CACHE_KEY) { LC_READY = false; return; }
    lcOpen().then(function() {
        LC_READY = true;
        // Render cached announcement history immediately
        lcLoadChannel('announcement').then(function(msgs) {
            if (msgs && msgs.length > 0) {
                var area = document.getElementById('messagesArea');
                if (area && !area.querySelector('[data-msgid]')) {
                    for (var i = 0; i < msgs.length; i++) {
                        var m = msgs[i];
                        if (m && m.id && !m.is_deleted) {
                            delete seenMsgIds['an_' + m.id];
                            addAnnouncement(m);
                        }
                    }
                    L = msgs[msgs.length - 1].id;
                    requestAnimationFrame(function() { scrollChatToBottom(document.getElementById('messagesArea')); });
                }
            }
        }).catch(function() {});
    }).catch(function() { LC_READY = false; });
}

function lcPersistMsg(key, m) {
    if (!LC_READY || !m || !m.id) return;
    lcLoadChannel(key).then(function(msgs) {
        msgs = msgs || [];
        if (msgs.some(function(x) { return x.id === m.id; })) return;
        msgs.push(m);
        msgs.sort(function(a, b) { return a.id - b.id; });   // 保持按 id 顺序，防止乱序渲染
        if (msgs.length > 2000) msgs = msgs.slice(-2000);
        lcSaveChannel(key, msgs);
    });
}

function lcPersistBatch(key, newMsgs) {
    if (!LC_READY || !newMsgs || newMsgs.length === 0) return;
    lcLoadChannel(key).then(function(msgs) {
        msgs = msgs || [];
        var have = {};
        for (var i = 0; i < msgs.length; i++) have[msgs[i].id] = true;
        for (var j = 0; j < newMsgs.length; j++) {
            if (newMsgs[j].id && !have[newMsgs[j].id]) {
                msgs.push(newMsgs[j]);
                have[newMsgs[j].id] = true;
            }
        }
        msgs.sort(function(a, b) { return a.id - b.id; });
        if (msgs.length > 2000) msgs = msgs.slice(-2000);
        lcSaveChannel(key, msgs);
    });
}

function lcMarkRevoked(key, id) {
    if (!LC_READY) return;
    lcLoadChannel(key).then(function(msgs) {
        if (!msgs) return;
        for (var i = 0; i < msgs.length; i++) {
            if (msgs[i].id === id) { msgs[i].is_deleted = true; break; }
        }
        lcSaveChannel(key, msgs);
    });
}

// Toggle in settings page
function toggleLocalCache() {
    var cb = document.getElementById('localCacheToggle');
    if (!cb) return;
    var on = cb.checked ? 1 : 0;
    var f = new URLSearchParams();
    f.append('action', 'toggle_local_cache');
    f.append('enabled', on);
    fetch('../../api/settings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            if (d.cache_key) CACHE_KEY = d.cache_key;
            LOCAL_CACHE = d.local_cache_enabled;
            if (on) { LC_READY = false; LC_KEY_CACHE = null; lcInit(); }
            sm('success', on ? 'Local cache enabled.' : 'Local cache disabled.');
        } else {
            cb.checked = !on;
            sm('error', 'Something went wrong.');
        }
    }).catch(function() { cb.checked = !on; });
}

function clearLocalCache() {
    if (!(confirm(T('btn_clear_local_cache', 'Clear local cache?')))) return;
    lcClearAll().then(function() { sm('success', T('msg_local_cache_cleared', 'Local cache cleared.')); });
}

// Wrap revoke functions to also update cache
var _origRevokeDm = (typeof revokeDmMessage === 'function') ? revokeDmMessage : null;
if (_origRevokeDm) {
    window.revokeDmMessage = function(id) {
        lcMarkRevoked('dm_' + (D || ''), id);
        _origRevokeDm(id);
    };
}
var _origRevokeAnn = (typeof revokeAnnouncement === 'function') ? revokeAnnouncement : null;
if (_origRevokeAnn) {
    window.revokeAnnouncement = function(id) {
        lcMarkRevoked('announcement', id);
        _origRevokeAnn(id);
    };
}

// ================= Wallpaper (custom background) =================
var BG_CACHE_KEY = 'chatapp_bg_v1';
function bgEnable(url, version) {
    var bg = document.getElementById('app-bg');
    if (!bg) return;
    if (!url) {
        bg.style.backgroundImage = '';
        return;
    }
    // version: used for cache-bust; null forces reload
    var src = url + (version ? '&v=' + version : '');
    bg.style.backgroundImage = 'url("' + src + '")';
}
function bgApply(blur, opacity) {
    var bg = document.getElementById('app-bg');
    var ov = document.getElementById('app-bg-overlay');
    var v = parseInt(opacity, 10);
    if (isNaN(v)) v = 100;
    v = Math.max(0, Math.min(v, 70)); // 背景透出上限 70%
    var alpha = Math.max(0.25, 1 - v / 100); // 界面不透明度
    var hasBg = !!(bg && bg.style.backgroundImage && bg.style.backgroundImage !== 'none');
    if (bg) {
        bg.style.filter = 'blur(' + (parseInt(blur,10)||0) + 'px)';
        bg.style.opacity = '1'; // 背景图始终全显（透明度由界面半透明控制）
    }
    if (ov) ov.style.opacity = '0'; // 覆盖遮罩恒为 0
    // 界面（sidebar/main/头/输入栏）半透明随滑块：滑块值 = 背景透出 %
    var a = hasBg ? alpha : 1; // 无背景时界面不透明
    var sb = document.querySelector('.sidebar');
    var mc = document.querySelector('.main-content');
    if (sb) sb.style.background = 'rgba(30,30,30,' + a + ')';
    if (mc) mc.style.background = 'rgba(34,34,34,' + a + ')';
    var i;
    var ch = document.querySelectorAll('.ch');
    for (i = 0; i < ch.length; i++) ch[i].style.background = 'rgba(42,42,42,' + a + ')';
    var cia = document.querySelectorAll('.cia');
    for (i = 0; i < cia.length; i++) cia[i].style.background = 'rgba(42,42,42,' + a + ')';
}
function bgSyncUI() {
    var c = {};
    try { c = JSON.parse(localStorage.getItem(BG_CACHE_KEY) || '{}'); } catch(e) {}
    var blurEl = document.getElementById('bgBlur');
    var opEl = document.getElementById('bgOpacity');
    var op = parseInt(c.opacity, 10);
    if (isNaN(op) || op < 0 || op >= 100) op = 30; // 首次登录/旧默认 100 → 30（界面保持可读）
    if (blurEl) blurEl.value = c.blur || 0;
    if (opEl) opEl.value = Math.min(op, 70);
    if (document.getElementById('bgBlurVal')) document.getElementById('bgBlurVal').textContent = (c.blur||0) + 'px';
    if (document.getElementById('bgOpacityVal')) document.getElementById('bgOpacityVal').textContent = Math.min(op, 70) + '%';
    bgApply(c.blur || 0, op);
}
function loadBg(skipCache) {
    fetch('../../api/settings.php?action=get_background').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) return;
        // Presets
        var presetsEl = document.getElementById('bgPresets');
        if (presetsEl && d.presets) {
            var h = '';
            for (var i = 0; i < d.presets.length; i++) {
                var p = d.presets[i];
                var isCur = d.url && d.url.indexOf(p.url) === 0;
                h += '<div class="bg-preset' + (isCur ? ' cur' : '') + '" title="' + eh(p.name) + '" onclick="setPresetBg(\'' + p.name + '\')"><img src="' + p.url + '" alt="' + eh(p.name) + '"><span>' + eh(p.name) + '</span></div>';
            }
            presetsEl.innerHTML = h;
        }
        // Load background: use localStorage cache unless version changed / force
        var cached = {};
        try { cached = JSON.parse(localStorage.getItem(BG_CACHE_KEY) || '{}'); } catch(e) {}
        if (!skipCache && d.url && cached.url === d.url && cached.version === d.version) {
            // cached — do nothing for bg (already shown or will be shown)
            bgEnable(d.url, cached.version);
        } else {
            // version mismatch or force or no server bg
            if (d.url) {
                bgEnable(d.url, d.version);
                cached.url = d.url;
                cached.version = d.version;
                try { localStorage.setItem(BG_CACHE_KEY, JSON.stringify(cached)); } catch(e) {}
            } else {
                bgEnable(null);
                localStorage.removeItem(BG_CACHE_KEY);
            }
        }
        // Preview
        var pv = document.getElementById('bgPreview');
        if (pv) {
            pv.innerHTML = d.url ? '<img src="' + d.url + '" alt="bg">' : '<span>' + T('label_no_bg', '无背景') + '</span>';
        }
        bgSyncUI();
    });
}
function uploadBg() {
    var f = document.getElementById('bgFile').files[0];
    if (!f) return;
    if (f.size > 32 * 1024 * 1024) { xalert(T('flash_too_large', '文件过大') + ' (32MB)'); return; }
    var reader = new FileReader();
    reader.onload = function(ev) {
        var frm = new URLSearchParams();
        frm.append('action', 'upload_background');
        frm.append('image', ev.target.result);
        fetch('../../api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: frm.toString()
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) {
                bgEnable(d.url, 'force-' + Date.now());
                var cached = { url: d.url, version: 'force-' + Date.now(), blur: 0, opacity: 30 };
                try { localStorage.setItem(BG_CACHE_KEY, JSON.stringify(cached)); } catch(e) {}
                document.getElementById('bgFile').value = '';
                loadBg(true);
                sm('success', 'Background updated.');
            } else {
                xalert(d.error || 'Upload failed.');
            }
        });
    };
    reader.readAsDataURL(f);
}
function removeBg() {
    var frm = new URLSearchParams();
    frm.append('action', 'remove_background');
    fetch('../../api/settings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: frm.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            bgEnable(null);
            localStorage.removeItem(BG_CACHE_KEY);
            var pv = document.getElementById('bgPreview');
            if (pv) pv.innerHTML = '<span>' + T('label_no_bg', '无背景') + '</span>';
            loadBg(true);
        }
    });
}
function forceRefreshBg() {
    loadBg(true);
    xalert('Background refreshed.');
}
function setPresetBg(name) {
    var frm = new URLSearchParams();
    frm.append('action', 'set_preset_background');
    frm.append('name', name);
    fetch('../../api/settings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: frm.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            var cached = { url: d.url, version: 'preset-' + Date.now(), blur: 0, opacity: 30 };
            try { localStorage.setItem(BG_CACHE_KEY, JSON.stringify(cached)); } catch(e) {}
            bgEnable(d.url, 'preset-' + Date.now());
            loadBg(true);
        } else xalert(d.error || 'Failed.');
    });
}
function onBgBlur(v) {
    if (document.getElementById('bgBlurVal')) document.getElementById('bgBlurVal').textContent = v + 'px';
    bgApply(v, document.getElementById('bgOpacity').value);
    saveBgPrefs();
}
function onBgOpacity(v) {
    if (document.getElementById('bgOpacityVal')) document.getElementById('bgOpacityVal').textContent = v + '%';
    bgApply(document.getElementById('bgBlur').value, v);
    saveBgPrefs();
}
function saveBgPrefs() {
    var c = {};
    try { c = JSON.parse(localStorage.getItem(BG_CACHE_KEY) || '{}'); } catch(e) {}
    c.blur = document.getElementById('bgBlur').value;
    c.opacity = document.getElementById('bgOpacity').value;
    try { localStorage.setItem(BG_CACHE_KEY, JSON.stringify(c)); } catch(e) {}
}
// ================= Duress password =================
function showDuressModal() {
    document.getElementById('duressCurrent').value = '';
    document.getElementById('duressNew').value = '';
    document.getElementById('duressNew2').value = '';
    document.getElementById('duressModal').classList.add('active');
}
function closeDuressModal() {
    document.getElementById('duressModal').classList.remove('active');
}
function saveDuress() {
    var cp = document.getElementById('duressCurrent').value;
    var np = document.getElementById('duressNew').value;
    var np2 = document.getElementById('duressNew2').value;
    if (!cp) { xalert(T('label_current_password','Current Password') + ': ' + T('msg_login_something_wrong','Something went wrong.')); return; }
    if (!np) { xalert(T('msg_duress_need_new','Please enter a duress password.')); return; }
    if (np !== np2) { xalert(T('msg_duress_mismatch','Duress passwords do not match.')); return; }
    var frm = new URLSearchParams();
    frm.append('action', 'setup_duress');
    frm.append('current_password', cp);
    frm.append('duress_password', np);
    fetch('../../api/settings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: frm.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            xalert(T('msg_duress_saved','Duress password saved.'));
            closeDuressModal();
        } else xalert(d.error || T('msg_login_something_wrong','Something went wrong.'));
    });
}
function clearDuress() {
    if (!confirm(T('msg_duress_clear_confirm','Are you sure you want to clear your duress password?'))) return;
    var cp = document.getElementById('duressCurrent').value;
    if (!cp) { xalert(T('label_current_password','Current Password') + ': ' + T('msg_login_something_wrong','Something went wrong.')); return; }
    var frm = new URLSearchParams();
    frm.append('action', 'setup_duress');
    frm.append('current_password', cp);
    frm.append('duress_password', '');
    fetch('../../api/settings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: frm.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            xalert(T('msg_duress_cleared','Duress password cleared.'));
            closeDuressModal();
        } else xalert(d.error || T('msg_login_something_wrong','Something went wrong.'));
    });
}

// ================= Database admin (root only) =================
// 判断当前访问来源属于哪种通讯模式（与 wss_client.js 的 wssTargetUrl 一致）
function wssDetectMode() {
    var h = String(location.hostname || '').toLowerCase();
    if (h === 'localhost' || h === '127.0.0.1' || h === '::1' || h === '[::1]') return 'local';
    if (/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/.test(h)) return 'private';
    return 'public';
}
function loadWssSettings() {
    fetch('../../api/admin.php?action=wss_get').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) return;
        var ids = { local: 'wssLocalInput', private: 'wssPrivateInput', public: 'wssPublicInput' };
        for (var k in ids) {
            var el = document.getElementById(ids[k]);
            if (el) el.value = d[k] || '';
        }
        var mode = wssDetectMode();
        var am = document.getElementById('wssActiveMode');
        if (am) am.textContent = '当前检测: ' + ({local:'🖥 本地', private:'🏠 私网', public:'🌐 公网'})[mode] + ' → ' + (d[mode] || '(未配置)');
    }).catch(function() {});
}
function saveWssSettings() {
    var ids = { local: 'wssLocalInput', private: 'wssPrivateInput', public: 'wssPublicInput' };
    var f = new URLSearchParams();
    f.append('action', 'wss_set');
    for (var k in ids) {
        var el = document.getElementById(ids[k]);
        f.append(k, (el ? el.value : '').trim());
    }
    fetch('../../api/admin.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
    .then(function(r) { return r.json(); }).then(function(d) {
        var st = document.getElementById('wssSaveStatus');
        if (!st) return;
        if (d.success) {
            st.textContent = '✓ Saved — 前端按来源自动选择 local/private/public';
            st.style.color = '#7ddb9a';
            var mode = wssDetectMode();
            var am = document.getElementById('wssActiveMode');
            if (am) am.textContent = '当前检测: ' + ({local:'🖥 本地', private:'🏠 私网', public:'🌐 公网'})[mode] + ' → ' + (d[mode] || '(未配置)');
        } else { st.textContent = d.error || 'Failed'; st.style.color = '#ff8a8a'; }
    }).catch(function() {});
}
// 重新运行 OOBE（root only，需验证当前管理员密码）
function rerunOobe() {
    var cur = prompt('请输入当前管理员密码以重新运行 OOBE：');
    if (cur === null || cur === '') { xalert('已取消。'); return; }
    var f = new URLSearchParams();
    f.append('action', 'oobe_rerun');
    f.append('password', cur);
    fetch('../../api/admin.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
    .then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) window.location.href = 'oobe.php';
        else xalert('无法重新运行 OOBE：' + (d.error || ''));
    }).catch(function() { xalert('无法重新运行 OOBE。'); });
}

function dbLoadTables() {
    var sel = document.getElementById('dbTableSelect');
    fetch('../../api/admin.php?action=db_tables').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) { sel.innerHTML = '<option value="">-- 加载失败 --</option>'; return; }
        var h = '<option value="">-- 选择表 --</option>';
        for (var i = 0; i < d.tables.length; i++) {
            h += '<option value="' + d.tables[i] + '">' + d.tables[i] + '</option>';
        }
        sel.innerHTML = h;
    }).catch(function() {
        sel.innerHTML = '<option value="">-- 加载失败 --</option>';
    });
}

function dbShowTable() {
    var table = document.getElementById('dbTableSelect').value;
    if (!table) return;
    var info = document.getElementById('dbTableInfo');
    var createEl = document.getElementById('dbCreateSQL');
    var colsEl = document.getElementById('dbColumns');
    var structDiv = document.getElementById('dbStructure');
    info.textContent = 'Loading...';
    structDiv.style.display = 'block';
    fetch('../../api/admin.php?action=db_structure&table=' + encodeURIComponent(table)).then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) { info.textContent = 'Error: ' + (d.error || 'unknown'); return; }
        info.textContent = '表名: ' + d.table + ' | 行数: ' + d.row_count;
        createEl.textContent = d.create_sql;
        var h = '';
        for (var i = 0; i < d.columns.length; i++) {
            var c = d.columns[i];
            h += '<tr><td style="padding:3px 8px;border-bottom:1px solid #333">' + eh(c.Field) + '</td>'
                + '<td style="padding:3px 8px;border-bottom:1px solid #333">' + eh(c.Type) + '</td>'
                + '<td style="padding:3px 8px;border-bottom:1px solid #333">' + eh(c.Null) + '</td>'
                + '<td style="padding:3px 8px;border-bottom:1px solid #333">' + eh(c.Key) + '</td>'
                + '<td style="padding:3px 8px;border-bottom:1px solid #333">' + eh(c.Default !== null ? c.Default : 'NULL') + '</td>'
                + '<td style="padding:3px 8px;border-bottom:1px solid #333">' + eh(c.Extra) + '</td></tr>';
        }
        colsEl.innerHTML = h;
    }).catch(function() {
        info.textContent = '加载失败';
    });
}

function dbExport() {
    var table = document.getElementById('dbTableSelect').value;
    if (!table) { xalert('请先选择表'); return; }
    // Navigate to download URL directly
    window.open('../../api/admin.php?action=db_export&table=' + encodeURIComponent(table) + '&csrf=' + encodeURIComponent(window.CSRF || ''), '_blank');
}

function dbRunQuery() {
    var sql = document.getElementById('dbQueryInput').value.trim();
    var statusEl = document.getElementById('dbQueryStatus');
    var table = document.getElementById('dbResultTable');
    var head = document.getElementById('dbResultHead');
    var body = document.getElementById('dbResultBody');
    if (!sql) { statusEl.textContent = '请输入 SQL'; return; }
    statusEl.textContent = '执行中...';
    table.style.display = 'none';
    var form = new URLSearchParams();
    form.append('action', 'db_query');
    form.append('sql', sql);
    form.append('csrf', window.CSRF || '');
    fetch('../../api/admin.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: form.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) {
            statusEl.textContent = '错误: ' + (d.error || 'unknown');
            return;
        }
        statusEl.textContent = '返回 ' + d.row_count + ' 行';
        var h = '<tr>';
        for (var i = 0; i < d.columns.length; i++) {
            h += '<th style="padding:4px 8px;text-align:left;border-bottom:1px solid #444;background:#252525">' + eh(d.columns[i]) + '</th>';
        }
        h += '</tr>';
        head.innerHTML = h;
        var b = '';
        for (var i = 0; i < d.rows.length; i++) {
            b += '<tr>';
            for (var j = 0; j < d.columns.length; j++) {
                var val = d.rows[i][d.columns[j]];
                b += '<td style="padding:3px 8px;border-bottom:1px solid #333;max-width:400px;overflow:hidden">' + dbFormatCell(val) + '</td>';
            }
            b += '</tr>';
        }
        body.innerHTML = b;
        table.style.display = 'table';
    }).catch(function() {
        statusEl.textContent = '请求失败';
    });
}

// Detect base64 image data (data:image/*;base64,...)
function dbIsBase64Image(val) {
    return typeof val === 'string' && /^data:image\/[a-zA-Z0-9.+-]+;base64,/.test(val);
}

// Format a cell value; collapse base64 image data by default
function dbFormatCell(val) {
    if (dbIsBase64Image(val)) {
        var prefix = val.substring(0, 80);
        var totalLen = val.length;
        return '<span class="db-b64" onclick="dbToggleB64(this)" title="点击展开/折叠" style="cursor:pointer;color:#e0a040">'
            + '<span class="db-b64-label" style="font-weight:bold">[图片数据]</span> '
            + '<span class="db-b64-preview" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px;display:inline-block;vertical-align:bottom">' + eh(prefix) + '...</span>'
            + '<span class="db-b64-full" style="display:none;word-break:break-all;white-space:pre-wrap">' + eh(val) + '</span>'
            + ' <span class="db-b64-meta" style="color:#888">(' + totalLen + ' chars)</span>'
            + '</span>';
    }
    return eh(val !== null ? val : 'NULL');
}

// Toggle collapsed base64 image data cell
function dbToggleB64(el) {
    var full = el.querySelector('.db-b64-full');
    var preview = el.querySelector('.db-b64-preview');
    if (full.style.display === 'none') {
        full.style.display = 'inline';
        preview.style.display = 'none';
    } else {
        full.style.display = 'none';
        preview.style.display = 'inline';
    }
}

// ================= Session heartbeat (every 10s) =================
function checkSession() {
    fetch('../../api/auth.php?action=check')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.upgrade_reload) { location.reload(); return; } // 在线升级：强制刷新 → 落维护页
            if (!d.success) { location.reload(); return; }
            // 会话期间账号突然被限制 → 本地强制会话刷新（锁定客户端 + 弹刷新警告）
            if (d.restricted && !RSTR) {
                RSTR = 1;
                if (typeof updateDndUI === 'function') updateDndUI();
                if (typeof lockClient === 'function') lockClient();
            }
        })
        .catch(function() {});
}
setInterval(checkSession, 10000);

// ================= Mobile sidebar drawer =================
function openMobileSidebar() {
  var side = document.querySelector('.sidebar');
  var ov = document.getElementById('sidebarOverlay');
  var tg = document.getElementById('sidebarToggleBtn');
  if (side) side.classList.add('open');
  if (ov) ov.classList.add('active');
  if (tg) tg.classList.add('hidden');
}
function closeMobileSidebar() {
  var side = document.querySelector('.sidebar');
  var ov = document.getElementById('sidebarOverlay');
  var tg = document.getElementById('sidebarToggleBtn');
  if (side) side.classList.remove('open');
  if (ov) ov.classList.remove('active');
  if (tg) tg.classList.remove('hidden');
}
function isMobileView() {
  return window.matchMedia && window.matchMedia('(max-width:768px)').matches;
}
function sidebarInit() {
  var side = document.querySelector('.sidebar');
  if (!isMobileView()) {
    // Desktop: keep natural sidebar; toggle/overlay stay hidden (CSS display:none also covers it)
    closeMobileSidebar();
    if (side) side.classList.remove('open');
    return;
  }
  // Mobile: 默认收起侧边栏，只在用户按下 #sidebarToggleBtn 时才展开（不再进入页面自动弹出）
  closeMobileSidebar();
}
document.addEventListener('click', function(e) {
  if (!isMobileView()) return;
  if (e.target.closest && e.target.closest('#sidebarOverlay')) return;
  if (e.target.closest && e.target.closest('#sidebarToggleBtn')) return;
  var hit = e.target.closest ? e.target.closest('.sidebar') : null;
  if (!hit) return;
  var clickable = e.target.closest('.ngh, .csi, .na, .sri, .pi, .support-row');
  if (!clickable) return;
  // 纯「展开/折叠分组」的 .ngh（带 .ngb 兄弟体）不应收起抽屉，否则一展开菜单抽屉就退回
  if (clickable.classList.contains('ngh')) {
    var ng = clickable.closest('.ng');
    if (ng && ng.querySelector('.ngb')) return;
  }
  setTimeout(closeMobileSidebar, 250);
});
document.addEventListener('DOMContentLoaded', sidebarInit);
window.addEventListener('resize', function() { sidebarInit(); });
sidebarInit();

// ================================================================
// Profile Drawer (right-side overlay, iframe renders test.html)
// ================================================================
function openMyProfile(username) {
    var src = '/modern/wp/profile.php';
    if (username) src += '?user=' + encodeURIComponent(username);
    var fr = document.getElementById('profileFrame');
    var sb = document.getElementById('userSidebar');
    var ov = document.getElementById('profileOverlay');
    if (!fr || !sb || !ov) return;   // 页面无 profile 抽屉时安全返回
    fr.src = src;
    sb.classList.add('active');
    ov.classList.add('active');
}

function openSettings() {
    var fr = document.getElementById('profileFrame');
    var sb = document.getElementById('userSidebar');
    var ov = document.getElementById('profileOverlay');
    if (!fr || !sb || !ov) return;
    fr.src = '/modern/wp/settings.php';
    sb.classList.add('active');
    ov.classList.add('active');
}

function closeMyProfile() {
    document.getElementById('userSidebar').classList.remove('active');
    document.getElementById('profileOverlay').classList.remove('active');
}

// ---- 右键菜单：sidebar 联系人（与聊天 ⋯ 选项一致） ----
var _userCtxEl = null,
    _ctxUser = null;
function ctxToggleE2ee() {
    var u = _ctxUser;
    if (!u) return;
    openDm(u);
    toggleDmE2ee(u);
}
function ensureUserCtxMenu() {
    if (_userCtxEl && document.body.contains(_userCtxEl)) return _userCtxEl;
    _userCtxEl = document.createElement('div');
    _userCtxEl.id = 'userCtxMenu';
    _userCtxEl.innerHTML =
        '<button onclick="closeUserCtxMenu();viewDmProfile(_ctxUser)">' + T('btn_view_profile') + '</button>' +
        '<button onclick="closeUserCtxMenu();ctxToggleE2ee()">' + T('opt_e2ee') + '</button>' +
        '<button onclick="closeUserCtxMenu();ctxOpenSafetyVerify()">' + T('opt_safety_verify') + '</button>' +
        '<button onclick="closeUserCtxMenu();startVoiceCall(_ctxUser)">' + T('opt_voice_call') + '</button>' +
        '<button onclick="closeUserCtxMenu();startVideoCall(_ctxUser)">' + T('opt_video_call') + '</button>' +
        '<button onclick="closeUserCtxMenu();startStandaloneShare(_ctxUser)">' + T('opt_share_screen') + '</button>' +
        '<button onclick="closeUserCtxMenu();reportDmUser(_ctxUser)">' + T('btn_report_user') + '</button>' +
        '<button onclick="closeUserCtxMenu();openDmSearch(_ctxUser)">' + T('d_search_history') + '</button>' +
        '<button id="ctxPinBtn" onclick="closeUserCtxMenu();togglePinContact(_ctxUser)">' + T('d_pin') + '</button>' +
        '<button onclick="closeUserCtxMenu();changeNickname(_ctxUser)">' + T('d_change_nickname') + '</button>' +
        (ADMIN ? '<button onclick="closeUserCtxMenu();reloadClient(_ctxUser)">' + T('opt_reload_client') + '</button>' : '') +
        '<button class="danger" onclick="closeUserCtxMenu();deleteDmContact(_ctxUser)">' + T('btn_delete_contact') + '</button>';
    document.body.appendChild(_userCtxEl);
    return _userCtxEl;
}
function openUserCtxMenu(e, username) {
    if (e) e.preventDefault();
    // 阻止 contextmenu 冒泡到 document 的监听器（否则菜单刚打开就被关掉，
    // 表现为「菜单被用户名挡住/闪一下就没了」）。右键别处仍会正常关闭菜单。
    if (e && e.stopPropagation) e.stopPropagation();
    var el = ensureUserCtxMenu();
    _ctxUser = username;
    var pinBtn = document.getElementById('ctxPinBtn');
    if (pinBtn) pinBtn.textContent = ((username === U) ? _pinnedSelf : _pinned[username]) ? T('d_unpin') : T('d_pin');
    el.classList.add('active');
    var x = e.clientX,
        y = e.clientY;
    var bw = el.offsetWidth || 80,
        bh = el.offsetHeight || 180;
    if (x + bw > window.innerWidth - 8) x = Math.max(8, window.innerWidth - bw - 8);
    if (y + bh > window.innerHeight - 8) y = Math.max(8, window.innerHeight - bh - 8);
    el.style.left = x + 'px';
    el.style.top = y + 'px';
}
function closeUserCtxMenu() {
    if (_userCtxEl) _userCtxEl.classList.remove('active');
}
document.addEventListener('click', function() { closeUserCtxMenu(); });
document.addEventListener('contextmenu', function(e) {
    if (!(e.target.closest && e.target.closest('#userCtxMenu'))) closeUserCtxMenu();
});
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { closeUserCtxMenu(); closeCodePreview(); } });
window.addEventListener('scroll', function() { closeUserCtxMenu(); }, true);
(function() {
    // Sidebar contact list: click on avatar (.ca) → open profile (stop propagation to avoid openDm)
    var fc = document.getElementById('friendContacts');
    if (fc) {
        fc.addEventListener('click', function(e) {
            var csi = e.target.closest('.csi');
            if (!csi) return;
            var username = csi.getAttribute('data-cuser');
            if (!username) return;
            // Only if clicking on the avatar area (.ca), not the name
            if (e.target.closest('.ca')) {
                e.stopPropagation();
                e.preventDefault();
                openMyProfile(username);
            }
        }, true); // use capture to beat the inline onclick

        // 右键联系人 → 上下文菜单（与 ⋯ 选项一致）
        fc.addEventListener('contextmenu', function(e) {
            var csi = e.target.closest('.csi');
            if (!csi) return;
            var username = csi.getAttribute('data-cuser');
            if (!username || username === U) return;
            e.preventDefault();
            e.stopPropagation();   // 防止冒泡到 document 级 handler 把菜单立即关掉
            openUserCtxMenu(e, username);
        });
    }

    // Chat message bubbles: click on avatar → open profile
    var dma = document.getElementById('dmMessagesArea');
    if (dma) {
        dma.addEventListener('click', function(e) {
            var avatar = e.target.closest('.msg-avatar');
            if (!avatar) return;
            var mr = avatar.closest('.mr');
            if (!mr) return;
            var username = mr.getAttribute('data-msguser');
            if (!username) return;
            e.stopPropagation();
            openMyProfile(username);
        });
    }

    var ma = document.getElementById('messagesArea');
    if (ma) {
        ma.addEventListener('click', function(e) {
            var avatar = e.target.closest('.msg-avatar');
            if (!avatar) return;
            var mr = avatar.closest('.mr');
            if (!mr) return;
            var username = mr.getAttribute('data-msguser');
            if (!username) return;
            e.stopPropagation();
            openMyProfile(username);
        });
    }
})();

/* ================================================================
   自訂滑鼠效果（移植自 apps/selfpage/exampl/src/utils/cursor.js）
   ================================================================ */
(function () {
    // 触屏 + 鼠标通用：建立跟随指针的白色小圆点。
    // 触屏（无 hover）下手指按下/拖动时，圆点从上一个触摸点快速「飞」到当前触摸点。
    var cursor = document.createElement("div");
    cursor.id = "cursor";
    document.body.appendChild(cursor);

    // 隐藏系統滑鼠游標，改成白色小圓點（文字輸入框保留 I 型游標）
    // hotspot 必须与 SVG 圆心的像素质点重合：SVG 尺寸(8px)=viewBox(8x8)，圆心在 4,4，
    // 故 `url(...) 4 4` 即精确居中；若尺寸/viewBox 不一致（如 10px 却仍写 4 4），
    // 热点会偏左上、圆点看起来偏右下。
    var styleEl = document.createElement("style");
    styleEl.innerHTML = '*:not(input):not(textarea):not([contenteditable="true"]) {cursor: url("data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 8 8\' width=\'8px\' height=\'8px\'><circle cx=\'4\' cy=\'4\' r=\'4\' fill=\'white\' /></svg>") 4 4, auto !important}';
    document.head.appendChild(styleEl);

    var pos = { curr: null, prev: null };
    var _fast = false; // 触屏模式：用更快的 lerp 系数，让圆点快速飞到触摸点
    var lerp = function (a, b, n) {
        if (Math.round(a) === b) return b;
        return (1 - n) * a + n * b;
    };
    var move = function (left, top) {
        cursor.style.left = left + "px";
        cursor.style.top = top + "px";
    };
    var render = function () {
        if (pos.prev) {
            var n = _fast ? 0.7 : 0.35;
            pos.prev.x = lerp(pos.prev.x, pos.curr.x, n);
            pos.prev.y = lerp(pos.prev.y, pos.curr.y, n);
            move(pos.prev.x, pos.prev.y);
        } else {
            pos.prev = pos.curr;
        }
        if (pos.curr && (pos.curr.x !== pos.prev.x || pos.curr.y !== pos.prev.y)) {
            requestAnimationFrame(render);
        }
    };

    document.addEventListener("mousemove", function (e) {
        _fast = false;
        if (pos.curr == null) move(e.clientX - 9, e.clientY - 9);
        pos.curr = { x: e.clientX - 9, y: e.clientY - 9 };
        cursor.classList.remove("hidden");
        render();
    });
    document.addEventListener("mouseenter", function () { cursor.classList.remove("hidden"); });
    document.addEventListener("mouseleave", function () { cursor.classList.add("hidden"); });
    document.addEventListener("mousedown", function () { cursor.classList.add("active"); });
    document.addEventListener("mouseup", function () { cursor.classList.remove("active"); });

    // 触屏：手指落下/拖动时，圆点从上一个触摸点快速飞到当前触摸点（passive 不挡滚动）
    document.addEventListener("touchstart", function (e) {
        var t = e.touches ? e.touches[0] : null;
        if (!t) return;
        _fast = true;
        pos.curr = { x: t.clientX - 9, y: t.clientY - 9 };
        cursor.classList.remove("hidden");
        cursor.classList.add("active");
        render();
    }, { passive: true });
    document.addEventListener("touchmove", function (e) {
        var t = e.touches ? e.touches[0] : null;
        if (!t) return;
        _fast = true;
        pos.curr = { x: t.clientX - 9, y: t.clientY - 9 };
        cursor.classList.remove("hidden");
        render();
    }, { passive: true });
    document.addEventListener("touchend", function () {
        cursor.classList.add("hidden");
        cursor.classList.remove("active");
        _fast = false;
    });
    document.addEventListener("touchcancel", function () {
        cursor.classList.add("hidden");
        cursor.classList.remove("active");
        _fast = false;
    });

    // ================================================================
    // iframe 光标桥接：apps 面板等 iframe 里的鼠标/触屏也驱动同一个白点。
    // 同源 iframe 可直接在其 contentDocument 挂监听，用 getBoundingClientRect
    // 把 iframe 内坐标换算到父页视口坐标，喂给同一个 pos/render。
    // 跨域 iframe 无法访问 contentDocument（抛异常）→ 跳过，保留系统光标。
    // ================================================================
    function _framePost(iframe, type, x, y, kind) {
        if (type === 'move') {
            var rect = iframe.getBoundingClientRect();
            _fast = (kind === 'touch');
            pos.curr = { x: rect.left + x - 9, y: rect.top + y - 9 };
            cursor.classList.remove('hidden');
            if (kind === 'touch') cursor.classList.add('active');
            else cursor.classList.remove('active');
            render();
        } else if (type === 'end') {
            cursor.classList.add('hidden');
            cursor.classList.remove('active');
            _fast = false;
        } else if (type === 'down') {
            cursor.classList.add('active');
        } else if (type === 'up') {
            cursor.classList.remove('active');
        }
    }
    function _bridgeFrame(iframe) {
        try {
            var w = iframe.contentWindow, d = iframe.contentDocument;
            if (!w || !d || !d.head) return; // 未加载完成
            if (w.__chatappCursorBridge) return; // 已桥接（iframe 导航后 window 换新 → 自动重桥接）
            w.__chatappCursorBridge = true;
            // 隐藏 iframe 内的系统光标（输入框保留 I 型），只留我们的白点
            var st = d.createElement('style');
            st.textContent = '*:not(input):not(textarea):not([contenteditable="true"]){cursor:url("data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 8 8\' width=\'8px\' height=\'8px\'><circle cx=\'4\' cy=\'4\' r=\'4\' fill=\'white\'/></svg>") 4 4,auto !important}';
            d.head.appendChild(st);
            d.addEventListener('mousemove', function (e) { _framePost(iframe, 'move', e.clientX, e.clientY, 'mouse'); }, { passive: true });
            d.addEventListener('touchstart', function (e) { var t = e.touches && e.touches[0]; if (t) _framePost(iframe, 'move', t.clientX, t.clientY, 'touch'); }, { passive: true });
            d.addEventListener('touchmove', function (e) { var t = e.touches && e.touches[0]; if (t) _framePost(iframe, 'move', t.clientX, t.clientY, 'touch'); }, { passive: true });
            d.addEventListener('touchend', function () { _framePost(iframe, 'end'); });
            d.addEventListener('touchcancel', function () { _framePost(iframe, 'end'); });
            d.addEventListener('mousedown', function () { _framePost(iframe, 'down'); });
            d.addEventListener('mouseup', function () { _framePost(iframe, 'up'); });
        } catch (err) { /* 跨域：无法访问，保留系统光标 */ }
    }
    // 先扫一遍已有 iframe，再定期重扫（覆盖懒加载 data-src 与 iframe 内部跳转）
    var _fs0 = document.querySelectorAll('iframe');
    for (var _i0 = 0; _i0 < _fs0.length; _i0++) _bridgeFrame(_fs0[_i0]);
    setInterval(function () {
        var fs = document.querySelectorAll('iframe');
        for (var i = 0; i < fs.length; i++) _bridgeFrame(fs[i]);
    }, 1500);
})();

/* ================================================================
   Doodle 涂鸦：画在聊天画面上（矢量笔迹 + 激光光效）
   ================================================================ */
var Doodle = (function () {
    var overlay = null, canvas = null, ctx = null;
    var strokes = [];
    var cur = null, drawing = false;
    var color = '#4dd8ff', size = 6, erasing = false;
    var W = 0, H = 0;
    var penOnly = false; // 检测到 Apple Pencil 后 → 仅笔模式，忽略手指
    var activeId = null; // 当前正在画的那支 pointer 的 id（防止手掌/另一支 pointer 干扰）

    function init() {
        overlay = document.getElementById('doodleOverlay');
        canvas = document.getElementById('doodleCanvas');
        if (!overlay || !canvas || ctx) return;
        ctx = canvas.getContext('2d');

        var sw = overlay.querySelectorAll('.dc');
        for (var i = 0; i < sw.length; i++) {
            sw[i].addEventListener('click', function () {
                setColor(this.getAttribute('data-c'), this);
            });
        }
        var sz = document.getElementById('doodleSize');
        if (sz) sz.addEventListener('input', function () { size = parseInt(this.value, 10) || 12; });

        canvas.addEventListener('pointerdown', onDown);
        canvas.addEventListener('pointermove', onMove);
        canvas.addEventListener('pointerup', onUp);
        canvas.addEventListener('pointercancel', onUp);

        // iPad 画图时防止误触发“全选”/长按菜单/手势缩放
        var block = function (e) { e.preventDefault(); };
        canvas.addEventListener('selectstart', block);
        canvas.addEventListener('dragstart', block);
        canvas.addEventListener('contextmenu', block);
        overlay.addEventListener('selectstart', block);
        overlay.addEventListener('contextmenu', block);
        overlay.addEventListener('gesturestart', block);
        overlay.addEventListener('gesturechange', block);
        overlay.addEventListener('gestureend', block);

        // Apple Pen 开关：可手动切换；Pencil 触碰时也会自动打开
        var psw = document.getElementById('doodlePenSwitch');
        if (psw) psw.addEventListener('change', function () {
            penOnly = !!psw.checked;
        });
    }

    function open() {
        init();
        if (!overlay) return;
        W = window.innerWidth; H = window.innerHeight;
        canvas.width = W; canvas.height = H;
        strokes = []; cur = null; drawing = false;
        penOnly = false; // 每次新开涂鸦都从“两者皆可”开始，直到检测到 Pencil
        syncPenSwitch();
        overlay.style.display = 'block';
        document.body.classList.add('doodle-lock');
        redraw();
    }

    function close() {
        if (!overlay) return;
        overlay.style.display = 'none';
        document.body.classList.remove('doodle-lock');
    }

    function setColor(c, btn) {
        color = c; erasing = false;
        var eb = document.getElementById('doodleEraserBtn');
        if (eb) eb.classList.remove('active');
        var sw = overlay.querySelectorAll('.dc');
        for (var i = 0; i < sw.length; i++) sw[i].classList.remove('active');
        if (btn) btn.classList.add('active');
    }

    function pt(e) {
        var r = canvas.getBoundingClientRect();
        return [e.clientX - r.left, e.clientY - r.top];
    }

    // 把 penOnly 状态同步到工具栏上的 Apple Pen 开关
    function syncPenSwitch() {
        var psw = document.getElementById('doodlePenSwitch');
        if (psw) psw.checked = penOnly;
    }

    // Doodle 调试用 verbose 日志（DevTools 控制台 → 级别选 Verbose/All 才能看到）
    function dvlog() {
        if (window.console && console.verbose) {
            var args = ['[doodle]'].concat(Array.prototype.slice.call(arguments));
            console.verbose.apply(console, args);
        }
    }

    function onDown(e) {
        var tp = e.pointerType || '?';
        dvlog('pointerdown', tp, 'id#' + e.pointerId, '@' + Math.round(e.clientX) + ',' + Math.round(e.clientY),
            'penOnly=' + penOnly, 'drawing=' + drawing, 'activeId=' + activeId);
        // Apple Pencil 检测：碰到一次 Pencil 就自动打开开关 → 仅笔模式，之后手指（含手掌误触）全部忽略
        if (e.pointerType === 'pen') { penOnly = true; syncPenSwitch(); }
        if (e.pointerType === 'touch' && penOnly) { dvlog('  ↳ TOUCH IGNORED (penOnly)'); return; }

        // 手掌/另一支 pointer 还压在屏幕上时，笔来了 → 丢掉那笔误触，让笔接管（修复“手放屏幕上笔就画不了”）
        if (drawing && cur && activeId !== null && activeId !== e.pointerId && e.pointerType === 'pen') {
            dvlog('  ↳ PEN TAKEOVER: discard palm stroke id#' + activeId);
            strokes.pop();
            drawing = false; cur = null; activeId = null;
        }

        // 关键：iOS Safari 上不要 preventDefault / 不要 setPointerCapture，否则 Apple Pencil
        // 会在手掌按压时收不到笔事件，或画到一半丢失 pointermove。滚动已由 touch-action:none 阻止。
        drawing = true;
        activeId = e.pointerId;
        cur = { tool: erasing ? 'eraser' : 'pen', color: color, size: size, points: [pt(e)] };
        strokes.push(cur);
        paintDot(ctx, cur.points[0], cur); // 落笔先画个点，增量绘制不整幅重绘
    }
    function onMove(e) {
        if (!drawing || !cur || activeId !== e.pointerId) return;
        var p = pt(e);
        var last = cur.points[cur.points.length - 1];
        // 按距离采样：移动不足 2px 不记录 → 线宽与画图速度无关（不会忽粗忽细）
        var dx = p[0] - last[0], dy = p[1] - last[1];
        if (dx * dx + dy * dy < 4) return;
        dvlog('pointermove', e.pointerType || '?', 'id#' + e.pointerId, '@' + Math.round(p[0]) + ',' + Math.round(p[1]), 'pts=' + cur.points.length);
        // 增量平滑绘制（不整幅重绘）→ 快，iPad 上画快也不丢事件/断线
        paintSmoothSeg(ctx, cur.points, p, cur);
        cur.points.push(p);
    }
    function onUp(e) {
        dvlog('pointerup/cancel', e.pointerType || '?', 'id#' + e.pointerId, 'activeId=' + activeId);
        if (activeId === e.pointerId) { drawing = false; cur = null; activeId = null; }
    }

    // 整段重绘（撤销/清空时）
    function redraw() {
        if (!ctx) return;
        doodlePaintAll(ctx, strokes, 1, 0, 0);
    }

    function undo() { strokes.pop(); redraw(); }
    function clearAll() { strokes = []; redraw(); }
    function toggleEraser() {
        erasing = !erasing;
        var eb = document.getElementById('doodleEraserBtn');
        if (eb) eb.classList.toggle('active', erasing);
    }
    function isEmpty() { return strokes.length === 0; }
    function data() { return JSON.stringify(strokes); }

    return { open: open, close: close, setColor: setColor, undo: undo, clear: clearAll, toggleEraser: toggleEraser, isEmpty: isEmpty, data: data };
})();

function openDoodle() { Doodle.open(); }
function closeDoodle() { Doodle.close(); }
function undoDoodle() { Doodle.undo(); }
function clearDoodle() { Doodle.clear(); }
function toggleDoodleEraser() { Doodle.toggleEraser(); }
// ---- 实时增量绘制（快，不整幅重绘 → iPad 上画快也不丢事件） ----
function smoothPath(ctx, pts, pNew) {
    var n = pts.length;
    ctx.beginPath();
    if (n === 1) {
        // 从首点画到新点的中点（保证与后续贝塞尔连续）
        ctx.moveTo(pts[0][0], pts[0][1]);
        ctx.lineTo((pts[0][0] + pNew[0]) / 2, (pts[0][1] + pNew[1]) / 2);
    } else {
        var p0 = pts[n - 2], p1 = pts[n - 1];
        ctx.moveTo((p0[0] + p1[0]) / 2, (p0[1] + p1[1]) / 2);
        ctx.quadraticCurveTo(p1[0], p1[1], (p1[0] + pNew[0]) / 2, (p1[1] + pNew[1]) / 2);
    }
    ctx.stroke();
}
function paintSmoothSeg(ctx, pts, pNew, s) {
    var w = Math.max(1, s.size);
    ctx.save();
    ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    if (s.tool === 'eraser') {
        ctx.globalCompositeOperation = 'destination-out';
        ctx.strokeStyle = '#000';
        ctx.lineWidth = w * 2.5;
        smoothPath(ctx, pts, pNew);
    } else {
        // 实色 + 光晕（source-over，不用叠加 → 线段接头处不会出亮点/点点）
        ctx.strokeStyle = s.color;
        ctx.lineWidth = w * 1.8;
        ctx.shadowColor = s.color; ctx.shadowBlur = 6;
        smoothPath(ctx, pts, pNew);
        ctx.shadowBlur = 0;
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = w * 0.7;
        smoothPath(ctx, pts, pNew);
    }
    ctx.restore();
}
function paintDot(ctx, p, s) {
    var r = Math.max(1, s.size * 0.5);
    ctx.save();
    if (s.tool === 'eraser') {
        ctx.globalCompositeOperation = 'destination-out';
        ctx.fillStyle = '#000';
        ctx.beginPath(); ctx.arc(p[0], p[1], r * 2.5, 0, Math.PI * 2); ctx.fill();
    } else {
        ctx.fillStyle = s.color;
        ctx.shadowColor = s.color; ctx.shadowBlur = 6;
        ctx.beginPath(); ctx.arc(p[0], p[1], r * 1.8, 0, Math.PI * 2); ctx.fill();
        ctx.shadowBlur = 0;
        ctx.fillStyle = '#fff';
        ctx.beginPath(); ctx.arc(p[0], p[1], r * 0.7, 0, Math.PI * 2); ctx.fill();
    }
    ctx.restore();
}

// ---- 涂鸦绘制原语（柔和霓虹 + 二次贝塞尔平滑，画快也不出折角/点） ----
function paintSmoothPath(ctx, t) {
    if (t.length === 1) {
        // 单点：画一个小圆点
        var r = Math.max(1, ctx.lineWidth * 0.5);
        ctx.beginPath();
        ctx.arc(t[0][0], t[0][1], r, 0, Math.PI * 2);
        ctx.fill();
        return;
    }
    // 二次贝塞尔过中点：点再稀疏也平滑，不会在转折处出现圆点/折角
    ctx.beginPath();
    ctx.moveTo((t[0][0] + t[1][0]) / 2, (t[0][1] + t[1][1]) / 2);
    for (var i = 1; i < t.length - 1; i++) {
        ctx.quadraticCurveTo(t[i][0], t[i][1], (t[i][0] + t[i + 1][0]) / 2, (t[i][1] + t[i + 1][1]) / 2);
    }
    ctx.lineTo(t[t.length - 1][0], t[t.length - 1][1]);
    ctx.stroke();
}
// 把整组笔迹画到某个 ctx 上（重绘 / 截图合成 / 消息卡片回放共用）
function doodlePaintAll(ctx, strokes, scale, ox, oy) {
    scale = scale || 1; ox = ox || 0; oy = oy || 0;
    ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
    for (var i = 0; i < strokes.length; i++) {
        var s = strokes[i], pts = s.points || [], t = [];
        for (var j = 0; j < pts.length; j++) {
            if (pts[j] && pts[j].length >= 2) t.push([pts[j][0] * scale + ox, pts[j][1] * scale + oy]);
        }
        if (t.length < 1) continue;
        ctx.save();
        ctx.lineCap = 'round'; ctx.lineJoin = 'round';
        if (s.tool === 'eraser') {
            ctx.globalCompositeOperation = 'destination-out';
            ctx.strokeStyle = '#000';
            ctx.lineWidth = Math.max(1, s.size * 2.5 * scale);
            paintSmoothPath(ctx, t);
        } else {
            ctx.strokeStyle = s.color;
            ctx.lineWidth = Math.max(1, s.size * 1.8 * scale);
            ctx.shadowColor = s.color;
            ctx.shadowBlur = 6;
            paintSmoothPath(ctx, t);
            ctx.shadowBlur = 0;
            ctx.strokeStyle = '#fff';
            ctx.lineWidth = Math.max(1, s.size * 0.7 * scale);
            paintSmoothPath(ctx, t);
        }
        ctx.restore();
    }
}

// ---- 发送：把涂鸦作为一条 doodle 消息发出去，对方在聊天里看到干净的发光涂鸦卡片 ----
async function sendDoodle() {
    if (!D || Doodle.isEmpty()) return;
    var strokes = JSON.parse(Doodle.data());
    var d = await apiRequest('send', { recipient: D, doodle: Doodle.data() }, { forceHttp: true });
    if (d && d.success) {
        Doodle.close();
        delete seenMsgIds['dm_' + d.message_id];
        await loadDmMessages();
        requestAnimationFrame(function () {
            var da = document.getElementById('dmMessagesArea');
            if (da) scrollChatToBottom(da);
        });
        playDoodle(strokes); // 发送后自己也整屏看到（历史批量加载已抑制自动回放，不会重复）
    } else {
        xalert((d && d.error) || '发送失败');
    }
}

/* ---- Doodle 消息：聊天里显示“✎ Doodle”徽章，点击/收到时在整屏回放 ---- */
var _doodleBulk = false; // 历史批量加载时禁止自动整屏回放
var _playTimer = null;

function doodleCardHtml(m) {
    var strokes = [];
    try { strokes = JSON.parse(m.attachment || '') || []; } catch (e) { strokes = []; }
    var esc = function (s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };
    return '<div class="msg-media doodle-msg"><button type="button" class="doodle-replay" data-strokes="' + esc(JSON.stringify(strokes)) + '" onclick="replayDoodleMsg(this)"><img src="../../data/res/cil/cil-pen.svg" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px">Doodle</button></div>';
}

function replayDoodleMsg(btn) {
    var strokes = [];
    try { strokes = JSON.parse(btn.getAttribute('data-strokes') || '[]') || []; } catch (e) { strokes = []; }
    playDoodle(strokes);
}

// 整屏回放：把涂鸦放大铺满整个聊天窗口（不是消息框里的小卡片）
function playDoodle(strokes) {
    var ov = document.getElementById('doodleOverlay');
    var cv = document.getElementById('doodleCanvas');
    if (!ov || !cv || !strokes || !strokes.length) return;
    var W = window.innerWidth, H = window.innerHeight;
    cv.width = W; cv.height = H;
    var ctx = cv.getContext('2d');
    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity, has = false;
    for (var i = 0; i < strokes.length; i++) {
        var pts = (strokes[i] && strokes[i].points) || [];
        for (var j = 0; j < pts.length; j++) {
            if (!pts[j] || pts[j].length < 2) continue;
            minX = Math.min(minX, pts[j][0]); minY = Math.min(minY, pts[j][1]);
            maxX = Math.max(maxX, pts[j][0]); maxY = Math.max(maxY, pts[j][1]);
            has = true;
        }
    }
    var scale = 1, ox = 0, oy = 0;
    if (has) {
        var bw = (maxX - minX) || 1, bh = (maxY - minY) || 1;
        var pad = 40;
        scale = Math.min((W - pad * 2) / bw, (H - pad * 2) / bh);
        ox = (W - bw * scale) / 2 - minX * scale;
        oy = (H - bh * scale) / 2 - minY * scale;
    }
    doodlePaintAll(ctx, strokes, scale, ox, oy);
    ov.classList.add('playing'); // 隐藏工具栏，只显示整屏涂鸦
    ov.style.display = 'block';
    ov.onclick = function () { if (ov.classList.contains('playing')) closeDoodlePlay(); };
    clearTimeout(_playTimer);
    _playTimer = setTimeout(closeDoodlePlay, 4000);
}
function closeDoodlePlay() {
    var ov = document.getElementById('doodleOverlay');
    if (ov) {
        ov.classList.remove('playing');
        ov.style.display = 'none';
        document.body.classList.remove('doodle-lock');
        ov.onclick = null;
    }
}
// 收到新的 doodle 消息时自动整屏回放（历史批量加载时不自动）
function maybeAutoPlayDoodle(m) {
    if (m.msg_type !== 'doodle' || _doodleBulk) return;
    var strokes = [];
    try { strokes = JSON.parse(m.attachment || '') || []; } catch (e) { strokes = []; }
    if (strokes.length) playDoodle(strokes);
}

/* ============================================================
   Live Draw：双人实时协作画板（透明覆盖在聊天画面上）
   - 真·实时：流式转发笔迹（stroke_start / stroke_points / stroke_end）
   - 发起者持有画板状态；wss 服务器纯转发
   - 流程：发起者设画板大小 → 邀请 → 对方接受 → 双方同画
   ============================================================ */
var LiveDraw = (function () {
    var overlay = null, canvas = null, ctx = null;
    var strokes = [];          // 双方已完成的笔迹（权威列表，用于快照）
    var pending = {};          // 进行中的笔迹 id -> stroke（双方）
    var cur = null, drawing = false;
    var color = '#4dd8ff', size = 6, erasing = false;
    var W = 0, H = 0;          // 画板像素尺寸（共享坐标系）
    var scale = 1;             // 显示缩放（fit 到屏幕）
    var peer = null;           // 对方用户名
    var sessionActive = false;
    var penOnly = false;
    var activeId = null;
    var pendingInvite = null;  // 收到的邀请 {from, board}
    var _waiting = null;       // 发起后等待对方接受 {recipient, w, h}
    var lastSent = 0;          // 节流
    var strokeSeq = 0;
    var _getSizeWaiter = null; // host 等待对方窗口尺寸的回调

    function byId(id) { return document.getElementById(id); }

    function init() {
        overlay = document.getElementById('ldOverlay');
        canvas = document.getElementById('ldCanvas');
        if (!overlay || !canvas || ctx) return;
        ctx = canvas.getContext('2d');

        var sw = overlay.querySelectorAll('.dc');
        for (var i = 0; i < sw.length; i++) {
            sw[i].addEventListener('click', function () { setColor(this.getAttribute('data-c'), this); });
        }
        var sz = document.getElementById('ldSize');
        if (sz) sz.addEventListener('input', function () { size = parseFloat(this.value) || 6; });

        if (byId('ldEraserBtn')) byId('ldEraserBtn').onclick = toggleEraser;
        if (byId('ldUndoBtn')) byId('ldUndoBtn').onclick = undo;
        if (byId('ldClearBtn')) byId('ldClearBtn').onclick = clearAll;
        if (byId('ldExitBtn')) byId('ldExitBtn').onclick = exit;

        canvas.addEventListener('pointerdown', onDown);
        canvas.addEventListener('pointermove', onMove);
        canvas.addEventListener('pointerup', onUp);
        canvas.addEventListener('pointercancel', onUp);
        var block = function (e) { e.preventDefault(); };
        ['selectstart', 'dragstart', 'contextmenu'].forEach(function (t) { canvas.addEventListener(t, block); });
        ['selectstart', 'contextmenu', 'gesturestart', 'gesturechange', 'gestureend'].forEach(function (t) { overlay.addEventListener(t, block); });
    }

    var _setupBound = false; // 防重复绑定（DOMContentLoaded + openSetup 兜底都可能触发）
    function initSetup() {
        if (_setupBound) return;
        _setupBound = true;
        var sizeBtns = document.querySelectorAll('#ldSizeOpts .ld-size-btn');
        for (var i = 0; i < sizeBtns.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () { selectSize(btn.getAttribute('data-size')); });
            })(sizeBtns[i]);
        }
        if (byId('ldSetupCancel')) byId('ldSetupCancel').onclick = function () { byId('ldSetupOverlay').classList.remove('active'); };
        if (byId('ldSetupStart')) byId('ldSetupStart').onclick = startSession;
        if (byId('ldWaitCancel')) byId('ldWaitCancel').onclick = cancelWait;
    }

    function setColor(c, btn) {
        color = c; erasing = false;
        var eb = byId('ldEraserBtn'); if (eb) eb.classList.remove('active');
        var sw = overlay.querySelectorAll('.dc');
        for (var i = 0; i < sw.length; i++) sw[i].classList.remove('active');
        if (btn) btn.classList.add('active');
    }
    function toggleEraser() {
        erasing = !erasing;
        var eb = byId('ldEraserBtn'); if (eb) eb.classList.toggle('active', erasing);
    }

    // 屏幕坐标 → 画板坐标（除以显示缩放，保证共享坐标系一致）
    function pt(e) {
        var r = canvas.getBoundingClientRect();
        return [(e.clientX - r.left) / scale, (e.clientY - r.top) / scale];
    }

    // 画布像素尺寸 = W×H，CSS 缩放铺满可用区域（保留顶部工具栏空间）
    function fitCanvas() {
        var vw = window.innerWidth, vh = window.innerHeight;
        var availW = vw - 40, availH = vh - 90;
        scale = Math.min(availW / W, availH / H);
        if (!(scale > 0)) scale = 1;
        canvas.width = W; canvas.height = H;
        canvas.style.width = Math.round(W * scale) + 'px';
        canvas.style.height = Math.round(H * scale) + 'px';
    }

    function allStrokes() {
        var list = strokes.slice();
        for (var k in pending) if (pending.hasOwnProperty(k)) list.push(pending[k]);
        return list;
    }
    function redraw() {
        if (!ctx) return;
        ctx.clearRect(0, 0, W, H);
        doodlePaintAll(ctx, allStrokes(), 1, 0, 0);
    }

    function openBoard(board, peerName, hostRole) {
        init();
        if (!overlay) return;
        W = Math.max(64, Math.round(board.w));
        H = Math.max(64, Math.round(board.h));
        peer = peerName;
        strokes = []; pending = {}; cur = null; drawing = false;
        erasing = false; penOnly = false; strokeSeq = 0;
        byId('ldPeerName').textContent = peer;
        fitCanvas();
        overlay.style.display = 'flex';
        document.body.classList.add('doodle-lock');
        sessionActive = true;
        redraw();
    }
    function teardown() {
        sessionActive = false;
        if (overlay) overlay.style.display = 'none';
        document.body.classList.remove('doodle-lock');
        peer = null;
        strokes = []; pending = {}; cur = null; drawing = false;
        var eb = byId('ldEraserBtn'); if (eb) eb.classList.remove('active');
    }
    function exit() {
        if (sessionActive && peer) send('close', {});
        teardown();
    }

    function send(event, data) {
        if (!peer || !window.wssSendLiveDraw) return;
        window.wssSendLiveDraw(peer, event, data || {});
    }

    // ---------- 本地绘制 ----------
    function onDown(e) {
        if (!sessionActive) return;
        if (e.pointerType === 'pen') penOnly = true;
        if (e.pointerType === 'touch' && penOnly) return;
        if (drawing && cur && activeId !== null && activeId !== e.pointerId && e.pointerType === 'pen') {
            if (cur.id && pending[cur.id]) delete pending[cur.id]; // 丢掉手掌误触（进行中笔迹在 pending，不在 strokes）
            drawing = false; cur = null; activeId = null;
        }
        drawing = true; activeId = e.pointerId;
        var s = { tool: erasing ? 'eraser' : 'pen', color: color, size: size, points: [pt(e)] };
        var id = 's' + (++strokeSeq);
        cur = { id: id };
        pending[id] = s;
        send('stroke_start', { id: id, stroke: { tool: s.tool, color: s.color, size: s.size, points: [s.points[0]] } });
        paintDot(ctx, s.points[0], s);
    }
    function onMove(e) {
        if (!drawing || !cur || activeId !== e.pointerId || !sessionActive) return;
        var p = pt(e);
        var s = pending[cur.id];
        if (!s) return;
        var last = s.points[s.points.length - 1];
        var dx = p[0] - last[0], dy = p[1] - last[1];
        if (dx * dx + dy * dy < 4) return;
        paintSmoothSeg(ctx, s.points, p, s);
        s.points.push(p);
        var now = Date.now();
        if (now - lastSent > 40) { // 节流 ~40ms，真·实时且不刷爆 wss
            lastSent = now;
            send('stroke_points', { id: cur.id, pts: [p] });
        }
    }
    function onUp(e) {
        if (!drawing || !cur || activeId !== e.pointerId) return;
        var s = pending[cur.id];
        if (s) {
            send('stroke_end', { id: cur.id, stroke: s });
            strokes.push(s);
            delete pending[cur.id];
        }
        drawing = false; cur = null; activeId = null;
    }

    // ---------- 接收对方笔迹 ----------
    function onStrokeStart(id, stroke) {
        if (!stroke) return;
        pending[id] = { tool: stroke.tool || 'pen', color: stroke.color || '#4dd8ff', size: stroke.size || 6, points: (stroke.points || []).slice() };
        var s = pending[id];
        if (s.points.length) paintDot(ctx, s.points[0], s);
    }
    function onStrokePoints(id, pts) {
        var s = pending[id];
        if (!s || !pts) return;
        for (var i = 0; i < pts.length; i++) {
            var p = pts[i];
            if (!p || p.length < 2) continue;
            s.points.push(p);
            if (s.points.length >= 2) paintSmoothSeg(ctx, s.points, p, s);
            else paintDot(ctx, p, s);
        }
    }
    function onStrokeEnd(id, stroke) {
        var s = pending[id];
        if (!s) return;
        if (stroke) {
            s.tool = stroke.tool || s.tool;
            s.color = stroke.color || s.color;
            s.size = stroke.size || s.size;
            s.points = (stroke.points || []).slice();
        }
        delete pending[id];
        strokes.push(s);
        redraw(); // 以权威终稿重绘，保证两端一致
    }

    // ---------- 清空 / 撤销 ----------
    function clearAll() {
        strokes = []; pending = {};
        redraw();
        send('clear', {});
    }
    function onClear() { strokes = []; pending = {}; redraw(); }

    function undo() {
        if (!strokes.length) return;
        strokes.pop();
        redraw();
        send('sync', { strokes: strokes }); // 撤销通过全量同步保证两端一致
    }
    function onSync(data) {
        strokes = (data && data.strokes) || [];
        pending = {};
        redraw();
    }

    // ---------- 发起流程 ----------
    var _selSize = 'mine';
    function openSetup() {
        if (!window.wssSendLiveDraw) { xalert('需要 WebSocket 连接才能发起 Live Draw'); return; }
        // 直接用当前正在对话的对象（D），不需要选人
        var invitee = (typeof D !== 'undefined' && D) ? D : '';
        initSetup(); // 兜底：确保按钮监听已绑（等 DOM 就绪后第一次打开时也会绑）
        byId('ldInvitee').textContent = invitee || '（未打开对话）';
        byId('ldInviteeNote').textContent = invitee ? '' : '请先打开一个私聊对话，再点 Live Draw';
        byId('ldSetupStart').disabled = !invitee;
        selectSize('mine');
        byId('ldSetupOverlay').classList.add('active');
    }
    function selectSize(kind) {
        _selSize = kind;
        var btns = document.querySelectorAll('#ldSizeOpts .ld-size-btn');
        for (var i = 0; i < btns.length; i++) btns[i].classList.toggle('active', btns[i].getAttribute('data-size') === kind);
        byId('ldCustomRow').style.display = (kind === 'custom') ? 'flex' : 'none';
        byId('ldSizeNote').textContent = (kind === 'mine') ? ('当前窗口 ' + window.innerWidth + ' × ' + window.innerHeight) : '';
    }
    function startSession() {
        var recipient = (typeof D !== 'undefined') ? D : '';
        if (!recipient) { xalert('请先打开一个私聊对话'); return; }
        if (!window.wssSendLiveDraw) { xalert('WebSocket 未连接，无法发起'); return; }

        var w, h;
        if (_selSize === 'mine') { w = window.innerWidth; h = window.innerHeight; }
        else if (_selSize === '1024x768') { w = 1024; h = 768; }
        else if (_selSize === '640x480') { w = 640; h = 480; }
        else if (_selSize === 'custom') {
            w = parseFloat(byId('ldCustomW').value);
            h = parseFloat(byId('ldCustomH').value);
            if (!w || !h || w < 64 || h < 64) { xalert('请输入有效的宽高（≥64）'); return; }
        } else if (_selSize === 'peer') {
            var btn = byId('ldSetupStart');
            btn.disabled = true; btn.textContent = '等待对方窗口大小…';
            requestPeerSize(recipient, function (pw, ph) {
                btn.disabled = false; btn.textContent = '发起';
                if (!pw || !ph) { xalert('获取对方窗口大小失败，请重试'); return; }
                doStart(recipient, pw, ph);
            });
            return;
        }
        doStart(recipient, w, h);
    }
    function doStart(recipient, w, h) {
        byId('ldSetupOverlay').classList.remove('active');
        var sent = window.wssSendLiveDraw(recipient, 'invite', { board: { w: w, h: h } });
        if (!sent) { xalert('WebSocket 未连接，无法发起'); return; }
        // 等对方同意后才进画板（不能发完邀请就直接进）
        _waiting = { recipient: recipient, w: w, h: h };
        showWaitOverlay(recipient);
    }
    // 发起方等待对方接受：模态框 + 取消邀请
    function showWaitOverlay(recipient) {
        var w = byId('ldWaitOverlay');
        if (!w) return;
        var n = byId('ldWaitName');
        if (n) n.textContent = recipient;
        w.classList.add('active');
    }
    function hideWaitOverlay() {
        var w = byId('ldWaitOverlay');
        if (w) w.classList.remove('active');
        _waiting = null;
    }
    function cancelWait() {
        if (!_waiting) return;
        var recipient = _waiting.recipient;
        hideWaitOverlay();
        if (window.wssSendLiveDraw) window.wssSendLiveDraw(recipient, 'cancel', {});
    }
    function requestPeerSize(recipient, cb) {
        var done = false;
        var timer = setTimeout(function () { if (!done) { done = true; cb(0, 0); } }, 5000);
        _getSizeWaiter = function (w, h) { if (!done) { done = true; clearTimeout(timer); cb(w, h); } };
        window.wssSendLiveDraw(recipient, 'get_size', {});
    }
    function sendSize(to) {
        window.wssSendLiveDraw(to, 'size', { w: window.innerWidth, h: window.innerHeight });
    }

    // ---------- 被邀请流程：内嵌卡片（像闪传一样出现在聊天里，不再居中弹窗） ----------
    function onInvite(from, data) {
        if (sessionActive || pendingInvite) {
            if (window.wssSendLiveDraw) window.wssSendLiveDraw(from, 'decline', { reason: 'busy' });
            return;
        }
        var board = data.board || { w: 1024, h: 768 };
        pendingInvite = { from: from, board: board, card: null };
        pendingInvite.card = renderInviteCard(from, board);
    }
    // 发起方取消了等待：关掉对应的邀请卡
    function onCancel(from) {
        if (pendingInvite && pendingInvite.from === from) {
            if (pendingInvite.card && pendingInvite.card.parentNode) pendingInvite.card.parentNode.removeChild(pendingInvite.card);
            pendingInvite = null;
        }
    }
    function renderInviteCard(from, board) {
        var area = document.getElementById('dmMessagesArea');
        if (!area) return;
        var card = document.createElement('div');
        card.className = 'ld-invite-card';

        var info = document.createElement('div');
        info.className = 'ld-invite-info';
        var b = document.createElement('b');
        b.textContent = from;
        var pen = document.createElement('img');
        pen.src = '../../data/res/cil/cil-pen.svg';
        pen.style.cssText = 'width:14px;height:14px;vertical-align:-2px;margin-right:4px';
        info.appendChild(pen);
        info.appendChild(b);
        info.appendChild(document.createTextNode(' 邀请你一起画板（' + Math.round(board.w) + ' × ' + Math.round(board.h) + '）'));

        var actions = document.createElement('div');
        actions.className = 'ld-invite-actions';
        var ok = document.createElement('button');
        ok.type = 'button'; ok.className = 'bsm ld-invite-ok'; ok.textContent = '同意';
        ok.style.background = '#2a4a2a'; ok.style.borderColor = '#3a6a3a';
        var no = document.createElement('button');
        no.type = 'button'; no.className = 'bsm ld-invite-no'; no.textContent = '拒绝';
        no.style.background = '#4a2020'; no.style.borderColor = '#5c2a2a';
        actions.appendChild(ok); actions.appendChild(no);

        card.appendChild(info); card.appendChild(actions);
        area.appendChild(card);
        if (typeof scrollChatToBottom === 'function') scrollChatToBottom(area);

        ok.onclick = function () { acceptInviteFromCard(card, from, board); };
        no.onclick = function () { declineInviteFromCard(card, from); };
        return card;
    }
    function dimInviteCard(card) {
        var btns = card.querySelectorAll('button');
        for (var i = 0; i < btns.length; i++) btns[i].disabled = true;
    }
    function acceptInviteFromCard(card, from, board) {
        if (!pendingInvite) return;
        pendingInvite = null;
        dimInviteCard(card);
        // 若正在等待别人接受，先取消自己的等待并通知对方（避免同时进两个画板）
        if (_waiting) {
            var wrecip = _waiting.recipient;
            hideWaitOverlay();
            if (window.wssSendLiveDraw) window.wssSendLiveDraw(wrecip, 'cancel', {});
        }
        window.wssSendLiveDraw(from, 'accept', {});
        openBoard(board, from, false);
    }
    function declineInviteFromCard(card, from) {
        if (!pendingInvite) return;
        pendingInvite = null;
        dimInviteCard(card);
        window.wssSendLiveDraw(from, 'decline', {});
    }
    function onAccept(from) {
        // 发起方：对方点了「同意」才真正进入画板
        if (_waiting && from === _waiting.recipient) {
            var wh = _waiting;
            hideWaitOverlay();
            openBoard({ w: wh.w, h: wh.h }, wh.recipient, true);
            // 把当前全部已完成笔迹快照发给对方
            window.wssSendLiveDraw(wh.recipient, 'snapshot', { strokes: strokes, board: { w: W, h: H } });
            return;
        }
        // 兜底：已在画板中收到 accept（如重连）→ 补发快照
        if (from === peer && sessionActive) {
            window.wssSendLiveDraw(peer, 'snapshot', { strokes: strokes, board: { w: W, h: H } });
        }
    }
    function onDecline(from) {
        if (!_waiting || from !== _waiting.recipient) return;
        var recipient = _waiting.recipient;
        hideWaitOverlay();
        xalert(recipient + ' 拒绝了邀请');
    }
    function onSnapshot(data) {
        strokes = (data && data.strokes) || [];
        pending = {};
        if (data && data.board) {
            W = Math.max(64, Math.round(data.board.w));
            H = Math.max(64, Math.round(data.board.h));
            fitCanvas();
        }
        redraw();
    }

    function showBanner(msg) {
        var b = byId('ldBanner');
        if (!b) return;
        b.textContent = msg; b.style.display = 'block';
    }
    function hideBanner() {
        var b = byId('ldBanner');
        if (b) b.style.display = 'none';
    }
    function onClose() {
        showBanner('对方已退出画板');
        teardown();
        setTimeout(hideBanner, 2200);
    }

    // ---------- wss 事件分发 ----------
    window.handleLiveDraw = function (d) {
        var event = d.event || '';
        var data = d.data || {};
        var from = d.from || '';
        switch (event) {
            case 'invite': if (from) onInvite(from, data); break;
            case 'accept': onAccept(from); break;
            case 'decline': onDecline(from); break;
            case 'cancel': if (from) onCancel(from); break;
            case 'get_size': sendSize(from); break;
            case 'size': if (_getSizeWaiter) { var wf = _getSizeWaiter; _getSizeWaiter = null; wf(data.w, data.h); } break;
            case 'snapshot': if (from === peer && sessionActive) onSnapshot(data); break;
            case 'stroke_start': if (from === peer && sessionActive) onStrokeStart(data.id, data.stroke); break;
            case 'stroke_points': if (from === peer && sessionActive) onStrokePoints(data.id, data.pts); break;
            case 'stroke_end': if (from === peer && sessionActive) onStrokeEnd(data.id, data.stroke); break;
            case 'clear': if (from === peer && sessionActive) onClear(); break;
            case 'sync': if (from === peer && sessionActive) onSync(data); break;
            case 'close': if (from === peer) onClose(); break;
        }
    };

    // 绑定设置弹窗按钮：等 DOM 就绪再绑（chat.js 在 body 底部弹窗 HTML 之前加载，
    // 模块加载时立即绑会找不到元素 → 按钮“按不进去”）。守卫保证只绑一次。
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSetup);
    } else {
        initSetup();
    }

    return { init: init, openSetup: openSetup };
})();

// ---------- Pen 菜单（Doodle / Live Draw） ----------
function togglePenMenu(ev, btn) {
    ev.stopPropagation();
    var menu = document.getElementById('penMenu');
    if (!menu) return;
    var isOpen = menu.style.display === 'block';
    hidePenMenu();
    if (!isOpen) {
        var r = btn.getBoundingClientRect();
        menu.style.display = 'block';
        var mh = menu.offsetHeight;
        // 向上弹出（按钮上方），避免超出底部边框看不见
        menu.style.left = Math.max(8, r.left) + 'px';
        menu.style.top = Math.max(4, (r.top - mh - 4)) + 'px';
        setTimeout(function () { document.addEventListener('click', penOutside); }, 0);
    }
}
function penOutside(ev) {
    var menu = document.getElementById('penMenu');
    if (menu && !menu.contains(ev.target)) hidePenMenu();
}
function hidePenMenu() {
    var menu = document.getElementById('penMenu');
    if (menu) menu.style.display = 'none';
    document.removeEventListener('click', penOutside);
}
function openLiveDrawSetup() {
    if (typeof LiveDraw !== 'undefined' && LiveDraw.openSetup) LiveDraw.openSetup();
}

/* ============================================================
   WebRTC 语音/视频通话（ChatCall）
   信令走 wss（type='call'，服务端纯转发）；媒体流点对点（STUN 打洞）。
   ============================================================ */
var ChatCall = (function () {
    var pc = null, localStream = null, remoteStream = null, peer = null, kind = 'audio';
    var audioCtx = null, waveRaf = null;
    var lAnL = null, lAnR = null, rAnL = null, rAnR = null; // 本地/远端 的 L/R 声道 analyser
    var renegotiating = false;
    var role = null;              // 'caller' | 'callee'
    var ringing = false;
    var pendingOffer = null;      // callee 收到的 offer（sdp + kind）
    var iceBuffer = [];
    var startTs = 0, timerInt = null, ringTimer = null;
    var statsInt = null, _statsPrevBytes = 0, _statsPrevTime = 0;
    var muted = false, minimized = false;

    function byId(id) { return document.getElementById(id); }

    /* ---------- 信令 ---------- */
    function send(event, data) {
        if (peer && window.wssSendCall) window.wssSendCall(peer, event, data || {});
    }

    /* ---------- 媒体 / PeerConnection ---------- */
    function getMedia(video) {
        return navigator.mediaDevices.getUserMedia({ audio: true, video: video });
    }
    function makePc() {
        if (typeof window.RTCPeerConnection === 'undefined') { xalert(T('call_no_webrtc')); return null; }
        var cfg = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };
        var p = new RTCPeerConnection(cfg);
        p.onicecandidate = function (e) { if (e.candidate) send('ice', { candidate: e.candidate }); };
        p.ontrack = function (e) {
            var stream = (e.streams && e.streams[0]) ? e.streams[0] : null;
            var track = e.track;
            if (track && track.kind === 'video') {
                var rv = byId('callRemoteVideo');
                if (rv) { rv.srcObject = stream; rv.style.display = kind === 'video' ? 'block' : 'none'; }
                return;
            }
            // 音频
            if (stream) { remoteStream = stream; wireRemoteWave(stream); }
            var rv2 = byId('callRemoteVideo');
            if (rv2) { rv2.srcObject = stream; rv2.style.display = kind === 'video' ? 'block' : 'none'; }
        };
        return p;
    }

    /* ---------- 声波轨道（对方 / 自己，双声道 DAW 样式） ---------- */
    function ensureAudio() {
        if (!audioCtx) {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return false;
            audioCtx = new AC();
        }
        if (audioCtx.state === 'suspended') { try { audioCtx.resume(); } catch (e) {} }
        return true;
    }
    // 每个轨道用 ChannelSplitter(2) 拆出 L/R 两路，各自接 analyser
    function wireWave(stream, isLocal) {
        if (!stream || !ensureAudio()) return;
        var anL = null, anR = null;
        try {
            var src = audioCtx.createMediaStreamSource(stream);
            var split = audioCtx.createChannelSplitter(2);
            src.connect(split);
            anL = audioCtx.createAnalyser(); anL.fftSize = 256;
            anR = audioCtx.createAnalyser(); anR.fftSize = 256;
            split.connect(anL, 0);
            split.connect(anR, 1);
        } catch (e) { anL = null; anR = null; }
        if (isLocal) { lAnL = anL; lAnR = anR; } else { rAnL = anL; rAnR = anR; }
    }
    function wireLocalWave(stream) { wireWave(stream, true); }
    function wireRemoteWave(stream) { wireWave(stream, false); }
    function traceLine(ctx, w, h, data, cy) {
        ctx.strokeStyle = '#4dd8ff';
        ctx.lineWidth = 2;
        ctx.beginPath();
        for (var i = 0; i < data.length; i++) {
            var v = (data[i] - 128) / 128;
            var x = (i / (data.length - 1)) * w;
            var y = cy + v * (h * 0.2);
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        }
        ctx.stroke();
    }
    // 双声道绘制：L 上半轨、R 下半轨；若 R 无信号（单声道）则把 L 镜像到下半轨，保持 DAW 立体声观感
    function drawStereo(canvas, anL, anR) {
        var ctx = canvas.getContext('2d');
        if (!ctx) return;
        var w = canvas.width, h = canvas.height;
        ctx.clearRect(0, 0, w, h);
        var dL = new Uint8Array(128), dR = new Uint8Array(128);
        if (anL) anL.getByteTimeDomainData(dL); else dL.fill(128);
        if (anR) anR.getByteTimeDomainData(dR); else dR.fill(128);
        var flat = true;
        for (var i = 0; i < 128; i++) if (dR[i] !== 128) { flat = false; break; }
        if (flat) for (var j = 0; j < 128; j++) dR[j] = 256 - dL[j];
        traceLine(ctx, w, h, dL, h * 0.25);
        traceLine(ctx, w, h, dR, h * 0.75);
    }
    function startWaves() {
        stopWaves();
        var wl = byId('callWaveLocal'), wr = byId('callWaveRemote');
        if (!wl && !wr) return;
        if (!ensureAudio()) return;
        var cw = byId('callOverlay') && byId('callOverlay').querySelector('.call-waves');
        if (cw) cw.classList.add('on'); // 关键：.on 加在容器上（CSS 用 .call-waves.on 控制显示），之前加在 canvas 上导致一直透明
        var dpr = window.devicePixelRatio || 1;
        [wl, wr].forEach(function (c) {
            if (!c) return;
            c.width = Math.max(2, Math.round(c.clientWidth * dpr));
            c.height = Math.max(2, Math.round(c.clientHeight * dpr));
        });
        var loop = function () {
            waveRaf = requestAnimationFrame(loop);
            if (wr && (rAnL || rAnR)) drawStereo(wr, rAnL, rAnR);
            if (wl && (lAnL || lAnR)) drawStereo(wl, lAnL, lAnR);
        };
        waveRaf = requestAnimationFrame(loop);
    }
    function stopWaves() {
        if (waveRaf) { cancelAnimationFrame(waveRaf); waveRaf = null; }
        var cw = byId('callOverlay') && byId('callOverlay').querySelector('.call-waves');
        if (cw) cw.classList.remove('on');
        var wl = byId('callWaveLocal'), wr = byId('callWaveRemote');
        [wl, wr].forEach(function (c) {
            if (!c) return;
            var ctx = c.getContext && c.getContext('2d');
            if (ctx) ctx.clearRect(0, 0, c.width, c.height);
        });
    }

    /* ---------- 通话状态 UI ---------- */
    function showCallUI(state) {
        var ov = byId('callOverlay');
        if (!ov) return;
        var st = byId('callStatus');
        if (st) st.textContent = state === 'ringing' ? T('call_calling') : T('call_active');
        var nm = byId('callPeerName');
        if (nm) nm.textContent = peer || '';
        var tn = byId('callTopName');
        if (tn) tn.textContent = peer || '';
        var vw = byId('callVideoWrap'), aw = byId('callAudioWrap');
        if (vw) vw.style.display = kind === 'video' ? 'block' : 'none';
        if (aw) aw.style.display = kind === 'video' ? 'none' : 'flex';
        if (state === 'active') { startTimer(); startWaves(); } else { stopTimer(); stopWaves(); }
        if (minimized) { updateCallChip(); return; } // 最小化中：保持悬浮条，不弹主窗口
        ov.style.display = 'flex';
        initCallDrag();
    }
    function hideCallUI() {
        var ov = byId('callOverlay');
        if (ov) ov.style.display = 'none';
        minimized = false;
        var cm = byId('callMinimized');
        if (cm) cm.style.display = 'none';
        stopTimer();
    }
    function showIncoming(from, k) {
        var ov = byId('callIncomingOverlay');
        if (!ov) return;
        var nm = byId('callIncomingName'); if (nm) nm.textContent = from;
        var ic = byId('callIncomingIcon'); if (ic) ic.innerHTML = k === 'video' ? '<img src="../../data/res/svg/video_24.svg" width="46" style="vertical-align:middle">' : '<img src="../../data/res/svg/phone_24.svg" width="46" style="vertical-align:middle">';
        ov.style.display = 'flex';
    }
    function hideIncoming() {
        var ov = byId('callIncomingOverlay');
        if (ov) ov.style.display = 'none';
    }
    function startTimer() {
        if (timerInt) return;
        startTs = Date.now();
        timerInt = setInterval(function () {
            var el = byId('callDur');
            if (!el) return;
            var t = Math.floor((Date.now() - startTs) / 1000);
            el.textContent = Math.floor(t / 60) + ':' + ('0' + (t % 60)).slice(-2);
            if (minimized) updateCallChip();
        }, 500);
        if (!statsInt) { statsInt = setInterval(collectStats, 2000); collectStats(); }
    }
    function stopTimer() {
        if (timerInt) { clearInterval(timerInt); timerInt = null; }
        if (statsInt) { clearInterval(statsInt); statsInt = null; _statsPrevBytes = 0; _statsPrevTime = 0; }
        var cs = byId('callStats');
        if (cs) { cs.style.display = 'none'; cs.textContent = ''; }
    }
    /* ---------- 通话窗口拖动 / 最小化 ---------- */
    function initCallDrag() {
        var top = document.querySelector('#callOverlay .call-top');
        if (!top || top.getAttribute('data-drag')) return;
        top.setAttribute('data-drag', '1');
        top.addEventListener('mousedown', function (e) {
            if (e.target.closest && e.target.closest('button')) return;
            var ov = byId('callOverlay');
            if (!ov || ov.style.display !== 'flex') return;
            var rect = ov.getBoundingClientRect();
            ov.style.left = rect.left + 'px';
            ov.style.top = rect.top + 'px';
            ov.style.transform = 'none';
            var sx = e.clientX, sy = e.clientY, l0 = rect.left, t0 = rect.top;
            function move(ev) {
                ov.style.left = Math.max(-120, Math.min(window.innerWidth - 60, l0 + (ev.clientX - sx))) + 'px';
                ov.style.top = Math.max(0, Math.min(window.innerHeight - 36, t0 + (ev.clientY - sy))) + 'px';
            }
            function up() {
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
            }
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
        });
    }
    function updateCallChip() {
        var t = byId('callMinTitle');
        if (!t) return;
        var dur = byId('callDur');
        var durText = dur && dur.textContent ? dur.textContent : '0:00';
        t.textContent = T('call_min_title').replace('%s', (peer || '') + ' · ' + durText);
    }
    function minimize() {
        if (!pc) return;
        minimized = true;
        var ov = byId('callOverlay');
        if (ov) ov.style.display = 'none';
        var cm = byId('callMinimized');
        if (cm) cm.style.display = 'flex';
        updateCallChip();
    }
    function restore() {
        minimized = false;
        var cm = byId('callMinimized');
        if (cm) cm.style.display = 'none';
        var ov = byId('callOverlay');
        if (ov) ov.style.display = 'flex';
    }
    // 通话实时网络状态：ping（ICE candidate-pair RTT）+ 对方接收网速（inbound-rtp bytes 差分）
    function collectStats() {
        if (!pc) return;
        pc.getStats(null).then(function (report) {
            var ping = null, bytes = 0;
            report.forEach(function (st) {
                if (st.type === 'candidate-pair' && st.state === 'succeeded' && typeof st.currentRoundTripTime === 'number' && st.currentRoundTripTime >= 0) {
                    ping = st.currentRoundTripTime * 1000;
                }
                if (st.type === 'inbound-rtp' && typeof st.bytesReceived === 'number') {
                    bytes += st.bytesReceived;
                }
            });
            var el = byId('callStats');
            if (!el) return;
            var now = Date.now(), speed = 0;
            if (_statsPrevTime > 0 && bytes >= _statsPrevBytes) {
                speed = (bytes - _statsPrevBytes) / Math.max(1, (now - _statsPrevTime) / 1000);
            }
            _statsPrevBytes = bytes;
            _statsPrevTime = now;
            var parts = [];
            if (ping !== null) parts.push('ping ' + Math.round(ping) + 'ms');
            if (speed > 0) parts.push('recv ' + fmtSpeed(speed));
            el.textContent = parts.join(' · ');
            el.style.display = parts.length ? 'block' : 'none';
        }).catch(function () {});
    }

    /* ---------- 发起 ---------- */
    function startCall(username, video) {
        if (pc) { xalert(T('call_in_call')); return; }
        if (!username) { xalert(T('call_no_dm')); return; }
        if (!window.wssSendCall) { xalert(T('call_need_wss')); return; }
        ensureAudio(); // 在点击手势内创建/恢复 AudioContext，避免被浏览器挂起
        kind = video ? 'video' : 'audio';
        peer = username; role = 'caller'; ringing = true;
        getMedia(video).then(function (stream) {
            localStream = stream;
            wireLocalWave(stream);
            pc = makePc();
            if (!pc) { cleanup(); return; }
            stream.getTracks().forEach(function (t) { pc.addTrack(t, stream); });
            var lv = byId('callLocalVideo');
            if (lv) { lv.srcObject = stream; lv.style.display = kind === 'video' ? 'block' : 'none'; }
            showCallUI('ringing');
            pc.createOffer().then(function (offer) {
                pc.setLocalDescription(offer);
                send('offer', { sdp: offer, kind: kind });
            });
            ringTimer = setTimeout(function () {
                if (ringing) { cleanup(); xalert(T('share_no_response')); }
            }, 30000);
        }).catch(function () { xalert(T('call_no_media')); });
    }

    /* ---------- 接听方 ---------- */
    function onOffer(from, data) {
        // 通话中的重协商（对方加/去屏幕共享轨道等）
        if (data && data.reneg && pc && from === peer) {
            pc.setRemoteDescription(new RTCSessionDescription(data.sdp)).then(function () {
                return pc.createAnswer();
            }).then(function (answer) {
                pc.setLocalDescription(answer);
                send('answer', { sdp: answer, reneg: true });
            }).catch(function () {});
            return;
        }
        if (pc) { send('busy', {}); return; }
        peer = from; role = 'callee';
        kind = (data && data.kind === 'video') ? 'video' : 'audio';
        pendingOffer = data;
        showIncoming(from, kind);
    }
    function accept() {
        if (!pendingOffer || !peer) return;
        ensureAudio(); // 接听按钮手势内创建/恢复 AudioContext
        var offer = pendingOffer; pendingOffer = null;
        hideIncoming();
        getMedia(kind === 'video').then(function (stream) {
            localStream = stream;
            wireLocalWave(stream);
            pc = makePc();
            if (!pc) { cleanup(); return; }
            stream.getTracks().forEach(function (t) { pc.addTrack(t, stream); });
            var lv = byId('callLocalVideo');
            if (lv) { lv.srcObject = stream; lv.style.display = kind === 'video' ? 'block' : 'none'; }
            pc.setRemoteDescription(new RTCSessionDescription(offer.sdp)).then(function () {
                return pc.createAnswer();
            }).then(function (answer) {
                pc.setLocalDescription(answer);
                send('answer', { sdp: answer });
                flushIce();
                showCallUI('active');
            }).catch(function () { xalert(T('call_accept_fail')); cleanup(); });
        }).catch(function () { xalert(T('call_no_media')); cleanup(); });
    }
    function reject() {
        send('busy', {});
        hideIncoming();
        peer = null; pendingOffer = null;
    }

    /* ---------- 发起方收到 answer ---------- */
    function onAnswer(from, data) {
        if (role !== 'caller' || from !== peer || !pc) return;
        if (data && data.reneg) {
            renegotiating = false;
            pc.setRemoteDescription(new RTCSessionDescription(data.sdp)).then(function () {
                flushIce();
            }).catch(function () {});
            return;
        }
        ringing = false;
        if (ringTimer) { clearTimeout(ringTimer); ringTimer = null; }
        pc.setRemoteDescription(new RTCSessionDescription(data.sdp)).then(function () {
            flushIce();
            showCallUI('active');
        }).catch(function () { xalert(T('share_connect_fail')); });
    }

    /* ---------- ICE ---------- */
    function onIce(from, data) {
        if (from !== peer || !data || !data.candidate) return;
        if (pc && pc.remoteDescription) pc.addIceCandidate(new RTCIceCandidate(data.candidate)).catch(function () {});
        else iceBuffer.push(data.candidate);
    }
    function flushIce() {
        if (!pc) return;
        iceBuffer.forEach(function (c) { pc.addIceCandidate(new RTCIceCandidate(c)).catch(function () {}); });
        iceBuffer = [];
    }

    function onBusy(from) {
        if (role === 'caller' && ringing && from === peer) {
            ringing = false;
            if (ringTimer) { clearTimeout(ringTimer); ringTimer = null; }
            cleanup();
            xalert(T('call_busy') + ': ' + from);
        }
    }

    /* ---------- 挂断 / 清理 ---------- */
    function hangup() {
        if (peer && window.wssSendCall) window.wssSendCall(peer, 'hangup', {});
        cleanup();
    }
    function onHangup(from) {
        if (from === peer) { cleanup(); xalert(T('call_peer_hangup')); }
    }
    function cleanup() {
        if (pc) { try { pc.close(); } catch (e) {} }
        pc = null;
        if (localStream) { localStream.getTracks().forEach(function (t) { t.stop(); }); }
        localStream = null;
        peer = null; role = null; kind = 'audio'; ringing = false; pendingOffer = null; iceBuffer = [];
        if (ringTimer) { clearTimeout(ringTimer); ringTimer = null; }
        var lv = byId('callLocalVideo'), rv = byId('callRemoteVideo');
        if (lv) lv.srcObject = null;
        if (rv) rv.srcObject = null;
        remoteStream = null;
        stopWaves();
        [lAnL, lAnR, rAnL, rAnR].forEach(function (a) { if (a) { try { a.disconnect(); } catch (e) {} } });
        lAnL = lAnR = rAnL = rAnR = null;
        if (audioCtx) { try { audioCtx.close(); } catch (e) {} } audioCtx = null;
        hideIncoming(); hideCallUI();
    }

    function toggleMute() {
        muted = !muted;
        if (localStream) localStream.getAudioTracks().forEach(function (t) { t.enabled = !muted; });
        var b = byId('callMuteBtn');
        if (b) { b.classList.toggle('muted', muted); b.innerHTML = muted ? '<img src="../../data/res/svg/microphone_off_24.svg" width="16" style="vertical-align:-3px"> ' + T('call_unmute') : '<img src="../../data/res/svg/microphone_on_24.svg" width="16" style="vertical-align:-3px"> ' + T('call_mute'); }
    }

    /* ---------- 重新协商（加/去轨道用） ---------- */
    function renegotiate() {
        if (!pc || renegotiating) return;
        renegotiating = true;
        pc.createOffer().then(function (offer) {
            return pc.setLocalDescription(offer).then(function () {
                send('offer', { sdp: offer, kind: kind, reneg: true });
            });
        }).catch(function () { renegotiating = false; });
    }

    /* ---------- wss 事件分发 ---------- */
    window.handleCall = function (d) {
        var event = d.event || '', data = d.data || {}, from = d.from || '';
        switch (event) {
            case 'offer': if (from) onOffer(from, data); break;
            case 'answer': if (from) onAnswer(from, data); break;
            case 'ice': if (from) onIce(from, data); break;
            case 'busy': if (from) onBusy(from); break;
            case 'hangup': if (from) onHangup(from); break;
        }
    };

    return { startCall: startCall, accept: accept, reject: reject, hangup: hangup, toggleMute: toggleMute, minimize: minimize, restore: restore, isInCall: function () { return !!pc; } };
})();
function startVoiceCall(u) { if (ChatCall) ChatCall.startCall(u || D, false); }
function startVideoCall(u) { if (ChatCall) ChatCall.startCall(u || D, true); }
function acceptCall() { if (ChatCall) ChatCall.accept(); }
function rejectCall() { if (ChatCall) ChatCall.reject(); }
function hangupCall() { if (ChatCall) ChatCall.hangup(); }
function toggleCallMute() { if (ChatCall) ChatCall.toggleMute(); }

/* ============================================================
   独立屏幕共享（ChatShare）：不走语音/视频通话，直接邀请对方查看你的屏幕
   信令复用 wss type='call'（服务端纯转发），事件前缀 share_
   ============================================================ */
var ChatShare = (function () {
    var pc = null, screenStream = null, peer = null, role = null; // 'sharer' | 'viewer'
    var ringing = false, ringTimer = null, iceBuffer = [], pendingOffer = null, viewerAccepted = false, minimized = false;
    var mySid = null, shareAccepted = false, pendingSid = null, inviteTimer = null; // 一次性 key：每次邀请唯一 sid
    var shareAudioTrack = null, shareAudioSender = null, shareReneg = false, remoteShareStream = null; // 系统声音共享
    var shareMuted = false; // 观看端本地静音
    function makeSid() {
        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12) + '-' + Math.random().toString(36).slice(2, 8);
    }

    function byId(id) { return document.getElementById(id); }

    function send(event, data) {
        if (peer && window.wssSendCall) window.wssSendCall(peer, event, data || {});
    }

    function makePc(isViewer) {
        if (typeof window.RTCPeerConnection === 'undefined') { xalert(T('call_no_webrtc')); return null; }
        var cfg = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };
        var p = new RTCPeerConnection(cfg);
        p.onicecandidate = function (e) { if (e.candidate) send('share_ice', { candidate: e.candidate }); };
        p.ontrack = function (e) {
            // 查看方：对方屏幕流（视频+可选系统声音）累积到同一个 stream 再显示
            if (!isViewer || !e.streams || !e.streams[0]) return;
            if (!remoteShareStream) {
                remoteShareStream = e.streams[0];
            } else {
                e.streams[0].getTracks().forEach(function (nt) {
                    var dup = false;
                    remoteShareStream.getTracks().forEach(function (ot) { if (ot.id === nt.id) dup = true; });
                    if (!dup) remoteShareStream.addTrack(nt);
                });
            }
            var sv = byId('shareVideo');
            if (sv) { sv.srcObject = remoteShareStream; sv.play(); }
            showOverlay(true);
        };
        return p;
    }

    function showOverlay(viewerMode) {
        var ov = byId('shareOverlay');
        if (!ov) return;
        ov.style.display = 'flex';
        var wm = byId('shareWaitMsg');
        if (wm) wm.style.display = 'none';
        var vv = byId('shareVideo');
        if (vv) vv.style.display = 'block';
        var st = byId('shareStopBtn'), cl = byId('shareCloseBtn'), t = byId('shareTitle');
        if (st) st.style.display = viewerMode ? 'none' : 'inline-block';
        if (cl) cl.style.display = viewerMode ? 'inline-block' : 'none';
        if (t) t.textContent = viewerMode ? T('share_viewing').replace('%s', peer || '') : T('share_sharing');
        updateShareAudioBtn();
        updateShareMuteBtn();
        initDrag();
    }
    // 等待窗口（邀请发出等接受 / 已接受等屏幕流），非全屏小窗
    function showWaiting(msg, viewerMode) {
        var ov = byId('shareOverlay');
        if (!ov) return;
        ov.style.display = 'flex';
        var wm = byId('shareWaitMsg');
        if (wm) { wm.style.display = 'flex'; wm.textContent = msg || ''; }
        var vv = byId('shareVideo');
        if (vv) vv.style.display = 'none';
        var st = byId('shareStopBtn'), cl = byId('shareCloseBtn'), t = byId('shareTitle');
        if (st) st.style.display = viewerMode ? 'none' : 'inline-block';
        if (cl) cl.style.display = viewerMode ? 'inline-block' : 'none';
        if (t) t.textContent = viewerMode ? T('share_connecting') : T('share_waiting');
        updateShareAudioBtn();
        updateShareMuteBtn();
        initDrag();
    }
    // 窗口拖动：按住标题栏可移动
    function initDrag() {
        var top = document.querySelector('#shareOverlay .share-top');
        if (!top || top.getAttribute('data-drag')) return;
        top.setAttribute('data-drag', '1');
        top.addEventListener('mousedown', function (e) {
            if (e.target.closest && e.target.closest('button')) return;
            var ov = byId('shareOverlay');
            if (!ov || ov.style.display !== 'flex') return;
            var rect = ov.getBoundingClientRect();
            ov.style.left = rect.left + 'px';
            ov.style.top = rect.top + 'px';
            ov.style.transform = 'none';
            var sx = e.clientX, sy = e.clientY, l0 = rect.left, t0 = rect.top;
            function move(ev) {
                ov.style.left = Math.max(-120, Math.min(window.innerWidth - 60, l0 + (ev.clientX - sx))) + 'px';
                ov.style.top = Math.max(0, Math.min(window.innerHeight - 36, t0 + (ev.clientY - sy))) + 'px';
            }
            function up() {
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
            }
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
        });
    }
    function minimize() {
        var ov = byId('shareOverlay');
        if (!ov) return;
        minimized = true;
        ov.style.display = 'none';
        var chip = byId('shareMinimized');
        if (chip) chip.style.display = 'flex';
        var t = byId('shareMinTitle');
        if (t) t.textContent = (role === 'viewer') ? T('share_viewing').replace('%s', peer || '') : T('share_sharing');
    }
    function restore() {
        minimized = false;
        var chip = byId('shareMinimized');
        if (chip) chip.style.display = 'none';
        if (role === 'viewer') showOverlay(true);
        else if (pc) showSharingState();
        else showWaiting(T('share_waiting'), false);
    }
    // 共享方：不显示自己的共享输出，只显示状态文字 + 停止按钮
    function showSharingState() {
        var ov = byId('shareOverlay');
        if (!ov) return;
        ov.style.display = 'flex';
        var wm = byId('shareWaitMsg');
        if (wm) { wm.style.display = 'flex'; wm.textContent = T('share_sharing_msg'); }
        var vv = byId('shareVideo');
        if (vv) vv.style.display = 'none';
        var st = byId('shareStopBtn'), cl = byId('shareCloseBtn'), t = byId('shareTitle');
        if (st) st.style.display = 'inline-block';
        if (cl) cl.style.display = 'none';
        if (t) t.textContent = T('share_sharing');
        updateShareAudioBtn();
        updateShareMuteBtn();
        initDrag();
    }
    function hideOverlay() {
        var ov = byId('shareOverlay');
        if (ov) ov.style.display = 'none';
        minimized = false;
        var chip = byId('shareMinimized');
        if (chip) chip.style.display = 'none';
        var wm = byId('shareWaitMsg');
        if (wm) wm.style.display = 'none';
        var sv = byId('shareVideo');
        if (sv) { sv.style.display = 'none'; sv.srcObject = null; sv.muted = false; }
        shareMuted = false;
        var muteBtn = byId('shareMuteBtn');
        if (muteBtn) { muteBtn.style.display = 'none'; muteBtn.innerHTML = '🔊'; }
    }

    /* ---------- 发起（共享方）：先邀请，对方接受后才采集屏幕 ---------- */
    function startShare(username) {
        if (pc || role) { xalert(T('share_active')); return; }
        if (!username) { xalert(T('share_no_dm')); return; }
        if (!window.wssSendCall) { xalert(T('share_need_wss')); return; }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) { xalert(T('share_unsupported')); return; }
        peer = username; role = 'sharer'; ringing = true; viewerAccepted = false;
        mySid = makeSid(); shareAccepted = false; // 一次性邀请 key
        send('share_offer', { invite: true, sid: mySid });
        showWaiting(T('share_waiting'), false);
        ringTimer = setTimeout(function () {
            if (ringing) { stopShare(); xalert(T('share_no_response')); }
        }, 30000);
    }
    // 对方接受后：现在才采集屏幕 + 建连 + 发真实 offer
    // 系统声音：audio:true 采集（仅"共享标签页"时浏览器会提供系统音频；整屏/窗口大多没有）
    function captureAndOffer() {
        navigator.mediaDevices.getDisplayMedia({ video: true, audio: true }).then(function (stream) {
            var t = stream.getVideoTracks()[0];
            if (!t) { stream.getTracks().forEach(function (x) { x.stop(); }); stopShare(); return; }
            screenStream = stream;
            t.onended = function () { stopShare(); }; // 浏览器「停止共享」栏
            pc = makePc(false);
            if (!pc) { cleanup(); return; }
            pc.addTrack(t, stream);
            shareAudioTrack = stream.getAudioTracks()[0] || null; // 系统声音轨道（可能没有）
            if (shareAudioTrack) shareAudioSender = pc.addTrack(shareAudioTrack, stream); // 默认共享声音
            showSharingState(); // 共享方不看自己的输出，只显示状态 + 停止按钮
            pc.createOffer().then(function (offer) {
                pc.setLocalDescription(offer);
                send('share_offer', { sdp: offer, sid: mySid });
            });
        }).catch(function () { stopShare(); }); // 用户取消授权
    }

    /* ---------- 接收方 ---------- */
    function onOffer(from, data) {
        // 共享中的重协商（共享方开关系统声音 → 加/去音频轨道）
        if (data && data.reneg && pc && from === peer) {
            if (data.sid && pendingSid && data.sid !== pendingSid) return;
            pc.setRemoteDescription(new RTCSessionDescription(data.sdp)).then(function () {
                return pc.createAnswer();
            }).then(function (answer) {
                pc.setLocalDescription(answer);
                send('share_answer', { sdp: answer, sid: pendingSid, reneg: true });
            }).catch(function () {});
            return;
        }
        if (pc) { send('share_busy', { sid: data && data.sid }); return; }
        if (data && data.invite) {
            // 阶段1：邀请（还没有 SDP/媒体）——记住一次性 key
            peer = from; role = 'viewer'; ringing = false; viewerAccepted = false; pendingOffer = null;
            pendingSid = data.sid || null;
            if (inviteTimer) { clearTimeout(inviteTimer); inviteTimer = null; }
            inviteTimer = setTimeout(function () {
                // 邀请超时兜底：30s 无人应答自动清理，避免多端残留
                if (role === 'viewer' && !viewerAccepted && !pc) {
                    cleanup(); hideOverlay(); xalert(T('share_no_response'));
                }
            }, 30000);
            var nm = byId('shareIncomingName');
            if (nm) nm.textContent = from;
            var ov = byId('shareIncomingOverlay');
            if (ov) ov.style.display = 'flex';
            return;
        }
        // 阶段2：已接受后对方发来的真实 SDP offer（校验一次性 key）
        if (role !== 'viewer' || !viewerAccepted) { send('share_busy', { sid: data && data.sid }); return; }
        if (pendingSid && data.sid && data.sid !== pendingSid) { send('share_busy', { sid: data.sid }); return; }
        pc = makePc(true);
        if (!pc) { cleanup(); return; }
        pc.setRemoteDescription(new RTCSessionDescription(data.sdp)).then(function () {
            return pc.createAnswer();
        }).then(function (answer) {
            pc.setLocalDescription(answer);
            send('share_answer', { sdp: answer, sid: pendingSid });
            flushIce();
            showOverlay(true);
        }).catch(function () { xalert(T('share_connect_fail')); cleanup(); });
    }
    function accept() {
        var ov = byId('shareIncomingOverlay');
        if (ov) ov.style.display = 'none';
        if (!peer || role !== 'viewer') return;
        if (inviteTimer) { clearTimeout(inviteTimer); inviteTimer = null; }
        viewerAccepted = true;
        send('share_answer', { accept: true, sid: pendingSid }); // 带回一次性 key
        showWaiting(T('share_connecting'), true); // 查看方等待对方开始共享
    }
    function reject() {
        send('share_busy', { sid: pendingSid });
        var ov = byId('shareIncomingOverlay');
        if (ov) ov.style.display = 'none';
        if (inviteTimer) { clearTimeout(inviteTimer); inviteTimer = null; }
        peer = null; role = null; viewerAccepted = false; pendingOffer = null; pendingSid = null;
    }

    /* ---------- 共享方收到 answer ---------- */
    function onAnswer(from, data) {
        if (role !== 'sharer' || from !== peer) return;
        if (data && data.accept) {
            // 一次性 key 校验：只接受当前邀请，且只能被接受一次（防多端重放/劫持）
            if (!data.sid || data.sid !== mySid) return;
            if (shareAccepted) { send('share_busy', { sid: data.sid }); return; }
            shareAccepted = true;
            ringing = false;
            if (ringTimer) { clearTimeout(ringTimer); ringTimer = null; }
            captureAndOffer();
            return;
        }
        if (data && data.reneg) { // 重协商应答（声音开关）
            if (data.sid && mySid && data.sid !== mySid) return;
            shareReneg = false;
            if (pc) pc.setRemoteDescription(new RTCSessionDescription(data.sdp)).then(function () {
                flushIce();
            }).catch(function () {});
            return;
        }
        if (data.sid && mySid && data.sid !== mySid) return; // answer 阶段同样校验
        if (!pc) return;
        pc.setRemoteDescription(new RTCSessionDescription(data.sdp)).then(function () {
            flushIce();
        }).catch(function () { xalert(T('share_connect_fail')); });
    }

    /* ---------- 系统声音：共享中可随时开关 ---------- */
    function updateShareAudioBtn() {
        var b = byId('shareAudioBtn');
        if (!b) return;
        if (role !== 'sharer' || !shareAudioTrack) { b.style.display = 'none'; return; }
        b.style.display = 'inline-block';
        b.classList.toggle('off', !shareAudioSender);
        b.innerHTML = shareAudioSender ? '🔊 ' + T('share_audio_on') : '🔇 ' + T('share_audio_off');
        b.title = T(shareAudioSender ? 'share_audio_on' : 'share_audio_off');
    }
    // 切换是否把系统声音发给查看方（加/去音频轨道 + 重协商）
    function toggleAudio() {
        if (!pc || !shareAudioTrack) return;
        if (shareAudioSender) {
            try { pc.removeTrack(shareAudioSender); } catch (e) {}
            shareAudioSender = null;
        } else {
            shareAudioSender = pc.addTrack(shareAudioTrack, screenStream);
        }
        updateShareAudioBtn();
        renegotiateShare();
    }
    // 观看端：本地静音/恢复对方屏幕声音（不影响共享方发送）
    function updateShareMuteBtn() {
        var b = byId('shareMuteBtn');
        if (!b) return;
        if (role !== 'viewer') { b.style.display = 'none'; return; }
        b.style.display = 'inline-block';
        b.innerHTML = shareMuted ? '🔇' : '🔊';
        b.title = shareMuted ? T('share_unmute', '取消静音') : T('share_mute', '静音');
    }
    function toggleMute() {
        shareMuted = !shareMuted;
        var sv = byId('shareVideo');
        if (sv) sv.muted = shareMuted;
        updateShareMuteBtn();
    }
    function renegotiateShare() {
        if (!pc || shareReneg) return;
        shareReneg = true;
        pc.createOffer().then(function (offer) {
            return pc.setLocalDescription(offer).then(function () {
                send('share_offer', { sdp: offer, sid: mySid, reneg: true });
                // 兜底：对端未回 reneg answer 时 3s 后解除阻塞，防止无法再次切换声音
                setTimeout(function () { shareReneg = false; }, 3000);
            });
        }).catch(function () { shareReneg = false; });
    }

    /* ---------- ICE ---------- */
    function onIce(from, data) {
        if (from !== peer || !data || !data.candidate) return;
        if (pc && pc.remoteDescription) pc.addIceCandidate(new RTCIceCandidate(data.candidate)).catch(function () {});
        else iceBuffer.push(data.candidate);
    }
    function flushIce() {
        if (!pc) return;
        iceBuffer.forEach(function (c) { pc.addIceCandidate(new RTCIceCandidate(c)).catch(function () {}); });
        iceBuffer = [];
    }

    function onBusy(from, data) {
        // sharer 端：等待响应时对方忙
        if (role === 'sharer' && ringing && from === peer) {
            ringing = false;
            if (ringTimer) { clearTimeout(ringTimer); ringTimer = null; }
            stopShare();
            xalert(T('share_busy') + ': ' + from);
            return;
        }
        // viewer 端：邀请被对方拒绝 / 重复接受被拒（一次性 key 不匹配或已消费）
        // 只清理「还没接受」的邀请弹窗，不影响已接受正在观看的连接
        if (role === 'viewer' && !pc && !viewerAccepted && from === peer
            && (!data.sid || data.sid === pendingSid)) {
            cleanup();
            hideOverlay();
            xalert(T('share_busy'));
        }
    }

    /* ---------- 结束 ---------- */
    function stopShare() {
        if (peer) send('share_stop', {});
        cleanup();
        hideOverlay();
    }
    function closeViewer() {
        if (peer) send('share_stop', {});
        cleanup();
        hideOverlay();
    }
    function onStop(from) {
        if (from === peer) {
            var wasSharer = (role === 'sharer');
            cleanup();
            hideOverlay();
            if (!wasSharer) xalert(T('share_stopped'));
        }
    }

    function cleanup() {
        if (pc) { try { pc.close(); } catch (e) {} }
        pc = null;
        if (screenStream) { screenStream.getTracks().forEach(function (t) { t.stop(); }); }
        screenStream = null;
        peer = null; role = null; ringing = false; viewerAccepted = false; iceBuffer = []; pendingOffer = null;
        mySid = null; shareAccepted = false; pendingSid = null;
        shareAudioTrack = null; shareAudioSender = null; shareReneg = false; remoteShareStream = null;
        if (ringTimer) { clearTimeout(ringTimer); ringTimer = null; }
        if (inviteTimer) { clearTimeout(inviteTimer); inviteTimer = null; }
        var io = byId('shareIncomingOverlay');
        if (io) io.style.display = 'none';
    }

    /* ---------- wss 事件分发（只处理 share_*） ---------- */
    window.handleShare = function (d) {
        var event = d.event || '', data = d.data || {}, from = d.from || '';
        switch (event) {
            case 'share_offer': if (from) onOffer(from, data); break;
            case 'share_answer': if (from) onAnswer(from, data); break;
            case 'share_ice': if (from) onIce(from, data); break;
            case 'share_busy': if (from) onBusy(from, data); break;
            case 'share_stop': if (from) onStop(from); break;
        }
    };

    return { startShare: startShare, accept: accept, reject: reject, stopShare: stopShare, closeViewer: closeViewer, minimize: minimize, restore: restore, toggleAudio: toggleAudio, toggleMute: toggleMute, isActive: function () { return !!pc; } };
})();
function startStandaloneShare(u) { if (ChatShare) ChatShare.startShare(u || D); }
function acceptShare() { if (ChatShare) ChatShare.accept(); }
function rejectShare() { if (ChatShare) ChatShare.reject(); }

/* ============================================================
   强制 Reload：wss 下发（客户端过时）/ admin 指定 / root 全部
   发送时显示状态窗口：等待目标确认（10s 无响应则提示）
   ============================================================ */
var _reloadStatusTimer = null;
var _reloadStatusOk = null;
var _reloadPendingTo = null;

function showReloadStatusDialog(toLabel, ackTarget) {
    _reloadPendingTo = ackTarget;
    var dlg = document.getElementById('customDialog');
    if (!dlg) return;
    document.getElementById('cdTitle').textContent = '客户端版本过时';
    var msg = document.getElementById('cdMsg');
    msg.textContent = '正在发送Reload命令...\n\n' + toLabel;
    msg.style.whiteSpace = 'pre-line';
    var inp = document.getElementById('cdInput'),
        ok = document.getElementById('cdOk'),
        cancel = document.getElementById('cdCancel');
    inp.style.display = 'none';
    cancel.style.display = 'none';
    ok.style.display = 'block';
    ok.disabled = true;
    ok.textContent = '确认';
    ok.onclick = function() { _reloadPendingTo = null; ok.disabled = true; dlg.classList.remove('active'); };
    _reloadStatusOk = ok;
    dlg.classList.add('active');
    // 10s 无响应 → 提示对方太旧/网络不稳定
    _reloadStatusTimer = setTimeout(function() {
        _reloadStatusTimer = null;
        if (_reloadPendingTo !== null) {
            _reloadPendingTo = null;
            if (dlg.classList.contains('active')) {
                msg.textContent = '无响应，对方客户端可能太旧或网络极其不稳定。';
                msg.style.whiteSpace = 'normal';
                ok.disabled = false;
            }
        }
    }, 10000);
}

function _reloadAckReceived() {
    if (_reloadStatusTimer) { clearTimeout(_reloadStatusTimer); _reloadStatusTimer = null; }
    _reloadPendingTo = null;
    var dlg = document.getElementById('customDialog');
    if (dlg && dlg.classList.contains('active')) {
        var msg = document.getElementById('cdMsg');
        msg.textContent = '已发送Reload命令！';
        msg.style.whiteSpace = 'normal';
        if (_reloadStatusOk) _reloadStatusOk.disabled = false;
    }
}

// 收到目标客户端 reload_ack（由 wss_client 转发调用）
window.handleReloadAck = function(d) {
    if (_reloadPendingTo === null) return;
    // 指定用户：仅接受匹配来源；全部（*）：任意来源都算
    if (_reloadPendingTo !== '*' && d.from !== _reloadPendingTo) return;
    _reloadAckReceived();
};

function reloadClient(username) {
    if (typeof ADMIN === 'undefined' || !ADMIN) return;
    if (!window.wssSendReload || !window.wssSendReload(username)) {
        xalert('WebSocket 未连接，无法发送 Reload');
        return;
    }
    showReloadStatusDialog('To: ' + username, username);
}
function reloadDmClient() {
    if (!D) return;
    reloadClient(D);
}
function reloadAllClients() {
    if (typeof IS_ROOT === 'undefined' || !IS_ROOT) return;
    if (!window.wssSendReload || !window.wssSendReload('*')) {
        xalert('WebSocket 未连接，无法发送 Reload');
        return;
    }
    showReloadStatusDialog('To: 所有在线客户端', '*');
}
// ================================================================
// 客户端过时全局锁定：收到 wss reload 后禁用一切功能，只能刷新页面。
// 用 document 级捕获拦截 + 全局标志，不依赖可被删除的 DOM（删掉对话框/任何 div 也照样锁死）。
// ================================================================
var CLIENT_LOCKED = false;

function _lockBlock(e) {
    if (!CLIENT_LOCKED) return;
    // 放行「客户端版本过时」红色对话框本身：只能点 Reload 刷新页面
    var dlg = document.getElementById('customDialog');
    if (dlg && dlg.classList.contains('cd-danger') && dlg.classList.contains('active') && dlg.contains(e.target)) return;
    e.stopPropagation();
    if (e.cancelable) e.preventDefault();
}

// 收到 reload 后调用：置锁定标志 + 挂捕获拦截 + 收起当前输入/录音状态 + 弹红色对话框
function lockClient() {
    if (CLIENT_LOCKED) return;
    CLIENT_LOCKED = true;
    // 收到强制刷新：自动结束通话与屏幕共享（含等待/邀请中状态），避免残留窗口盖住警告
    try { if (window.ChatCall) ChatCall.hangup(); } catch (e) {}
    try { if (window.ChatShare) ChatShare.stopShare(); } catch (e) {}
    var evts = ['mousedown', 'mouseup', 'click', 'dblclick', 'pointerdown', 'pointerup', 'contextmenu', 'touchstart', 'touchend'];
    for (var i = 0; i < evts.length; i++) document.addEventListener(evts[i], _lockBlock, true);
    document.addEventListener('wheel', _lockBlock, { capture: true, passive: false });
    document.addEventListener('keydown', _lockBlock, true);
    document.addEventListener('keyup', _lockBlock, true);
    var foc = document.activeElement;
    if (foc && foc.blur) { try { foc.blur(); } catch (err) {} }
    if (typeof window.stopVoiceRec === 'function') { try { stopVoiceRec(); } catch (err) {} }
    if (typeof window.cancelVoiceRec === 'function') { try { cancelVoiceRec(); } catch (err) {} }
    showClientReloadDialog();
}

/* ================= 全局键盘快捷键（Enter 确认发送 / ESC·Backspace 退出/取消） ================= */
// 关闭最上层弹层/菜单/弹窗；返回 true 表示已处理（从上到下：瞬态菜单→对话框→弹窗→兜底隐藏）
function _escClosers() {
    if (typeof closeAllMsgMenus === 'function') closeAllMsgMenus();
    if (typeof closeUserCtxMenu === 'function') closeUserCtxMenu();
    var cd = document.getElementById('customDialog');
    if (cd && cd.classList.contains('active') && !cd.classList.contains('cd-danger')) {
        closeCustomDialog(false);
        return true;
    }
    var ep = document.getElementById('emojiPopup');
    if (ep && ep.style.display === 'flex') {
        ep.style.display = 'none';
        if (typeof emojiUndockMobile === 'function') emojiUndockMobile();
        _emojiTarget = null;
        return true;
    }
    var dmOpt = document.getElementById('dmOptionsMenu');
    if (dmOpt && dmOpt.classList.contains('active')) { dmOpt.classList.remove('active'); return true; }
    var nine = document.getElementById('dmNineMenu');
    if (nine && nine.style.display === 'block') { if (typeof closeDmNineMenu === 'function') closeDmNineMenu(); return true; }
    var fm = document.getElementById('flashMenu');
    if (fm && fm.style.display === 'block') { fm.style.display = 'none'; return true; }
    var pm = document.getElementById('penMenu');
    if (pm && pm.style.display === 'block') { pm.style.display = 'none'; return true; }
    // 已知 .active 弹窗 → 专用 close（保证内部状态复位）
    var closes = {
        attachModal: cancelAttachment, reportModal: closeReportModal,
        forwardModal: closeForwardModal, flashMyModal: closeFlashMyModal,
        friendReqModal: closeFriendReqModal, noteModal: closeNoteModal,
        addUserModal: closeAddUserModal, addPlaceholderModal: closeAddPlaceholderModal,
        changeStatusModal: closeChangeStatusModal, addDonModal: closeAddDonModal,
        createTicketModal: closeCreateTicket, codePreviewModal: closeCodePreview,
        safetyVerifyModal: closeSafetyVerify, duressModal: closeDuressModal,
        batchModal: cancelBatch, deleteModal: hideDeleteModal
    };
    for (var id in closes) {
        if (!Object.prototype.hasOwnProperty.call(closes, id)) continue;
        var el = document.getElementById(id);
        if (el && el.classList.contains('active')) { closes[id](); return true; }
    }
    // 兜底：隐藏任何可见 overlay/modal（没专门 close 的也能被 ESC 收掉）
    var ovs = document.querySelectorAll('.modal-overlay, .doodle-overlay, .crop-overlay, .profile-overlay, .sidebar-overlay, .bg-overlay');
    for (var i = 0; i < ovs.length; i++) {
        var o = ovs[i];
        var vis = o.classList.contains('active') || (o.style.display && o.style.display !== 'none');
        if (vis && o.id !== 'customDialog') { o.classList.remove('active'); o.style.display = 'none'; return true; }
    }
    return false;
}

function _activePanelId() {
    var p = document.querySelector('.panel.active');
    return p ? (p.id || '').replace('panel-', '') : '';
}

document.addEventListener('keydown', function(e) {
    if (typeof CLIENT_LOCKED !== 'undefined' && CLIENT_LOCKED) return;
    if (e.isComposing || e.keyCode === 229) return;   // 输入法组词中不劫持
    if (e.ctrlKey || e.metaKey || e.altKey) return;    // 不抢系统组合键
    var key = e.key;
    if (key !== 'Escape' && key !== 'Backspace') return;
    var el = e.target;
    var tag = (el && el.tagName) ? el.tagName.toLowerCase() : '';
    var editable = tag === 'input' || tag === 'textarea' || (el && el.isContentEditable);
    var composer = document.getElementById('dmMessageInput');

    if (key === 'Escape') {
        if (_escClosers()) { e.preventDefault(); return; }
        // 会话内搜索 → 退回聊天
        if (_activePanelId() === 'dm-search') { backToDm(); e.preventDefault(); return; }
        // 输入框里有草稿：先清空（abort 草稿），再按一次才退出会话
        if (composer && e.target === composer && composer.value.trim()) {
            composer.value = '';
            if (typeof autoResize === 'function') autoResize(composer);
            e.preventDefault();
            return;
        }
        if (D || G) { closeDm(); e.preventDefault(); }
        return;
    }
    // Backspace：仅非输入框时作为「返回/退出」（输入框内保持默认删字，绝不劫持）
    if (key === 'Backspace' && !editable) {
        if (_escClosers()) { e.preventDefault(); return; }
        if (_activePanelId() === 'dm-search') { backToDm(); e.preventDefault(); return; }
        if (D || G) { closeDm(); e.preventDefault(); }
    }
});

// 收到 wss reload 推送：Win8.1 风格「客户端版本过时」窗口（复用 customDialog 的 DOM），点 Reload 刷新页面
function showClientReloadDialog() {
    var dlg = document.getElementById('customDialog');
    if (!dlg) { window.location.reload(); return; }
    dlg.classList.add('cd-danger'); // 过时窗口：红色主题、无关闭符号、只能 Reload
    document.getElementById('cdTitle').textContent = '客户端版本过时';
    var msg = document.getElementById('cdMsg');
    msg.textContent = '你正在使用的客户端已经过时，请重新加载页面以获取最新客户端。';
    msg.style.whiteSpace = 'normal';
    var inp = document.getElementById('cdInput'),
        ok = document.getElementById('cdOk'),
        cancel = document.getElementById('cdCancel');
    inp.style.display = 'none';
    cancel.style.display = 'none';
    ok.style.display = 'block';
    ok.disabled = false;
    ok.textContent = 'Reload';
    ok.onclick = function() { window.location.reload(); };
    dlg.classList.add('active');
}
