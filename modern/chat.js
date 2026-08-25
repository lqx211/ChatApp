var U, TZ, DND, RSTR, DS, L = 0,
    P = null,
    S = false,
    D = null,
    typingTimer = null;
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
        return fetch('../api/chat.php', {
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
        out.push('<pre><code class="language-' + (codeLang || 'text') + '">' + hlCode(codeBuf.join('\n')) + '</code></pre>');
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
        pH = '<div style="color:#aaa;padding:20px;text-align:center">📄 ' + f.name + '<br><span style="color:#888;font-size:.8em">' + fmtSize(f.size) + ' · Large file (preview skipped)</span></div>';
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
    if (n === 'music') {}
    if (n === 'donations') loadDonations(1);
    if (n === 'profile-mgmt') loadPm();
    if (n === 'logs') loadAdminLogs(1);
    if (n === 'support') loadSupportTickets('open');
    if (n === 'level') loadLevelPanel();
    if (n === 'dbadmin') dbLoadTables();
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
    fetch('../api/admin.php?action=' + action + '&q=' + encodeURIComponent(q) + '&page=' + _logPage).then(function(r) {
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

// 服务器数据库里存储的本地时区（chat.php 注入 SERVER_TZ）。
// messages.time / datetime 由 PHP date('Y-m-d H:i:s')（Asia/Hong_Kong）写入，
// 并非 UTC —— 解析时必须显式按此偏移换算，绝不能追加 'Z' 当 UTC 用。
var SERVER_TZ = (typeof SERVER_TZ !== 'undefined') ? SERVER_TZ : '+08:00';

// 把一个 "YYYY-MM-DD HH:MM:SS" 字符串（服务器本地钟面时间）精确换算成时间戳。
// 直接 Date.UTC 构造 + 减去服务器时区偏移，杜绝 getHours() 受浏览器本地时区影响。
function timeToTs(s) {
    if (!s) return null;
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
    fetch('../api/status.php', {
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
    var r = await fetch('../api/settings.php', {
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
            fetch('../api/status.php', {
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
    var r = await fetch('../api/settings.php', {
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
    var r = await fetch('../api/settings.php', {
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
    fetch('../api/status.php?action=check&users=' + encodeURIComponent(users.join(','))).then(function(r) {
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
    fetch('../api/incident.php?action=count').then(function(r) { return r.json(); }).then(function(d) {
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
    fetch('../api/contacts.php?action=list').then(r => r.json()).then(function(d) {
        var e = document.getElementById('friendContacts');
        if (typeof d.pin_self !== 'undefined') { _pinnedSelf = d.pin_self ? 1 : 0; renderSelfPin(); }
        if (d.success && d.contacts.length > 0) {
            var h = '';
            var sorted = d.contacts.slice().sort(function(a, b) { return ((b.pinned ? 1 : 0) - (a.pinned ? 1 : 0)); });
            for (var i = 0; i < sorted.length; i++) {
                var c = sorted[i],
                    a = c.avatar ? '<img src="' + c.avatar + '">' : '<img src="../data/profile_empty.png">';
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
    fetch('../api/contacts.php?action=pending').then(r => r.json()).then(function(d) {
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
    var d = await fetch('../api/contacts.php', {
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
        fetch('../api/contacts.php?action=search&q=' + encodeURIComponent(q)).then(r => r.json()).then(function(d) {
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
        fetch('../api/contacts.php', {
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
    fetch('../api/settings.php?action=discover&q=' + encodeURIComponent(q) + '&page=' + dsPage).then(r => r.json()).then(function(d) {
        var t = document.getElementById('discoverTable'),
            h = '';
        if (!d.success || d.users.length === 0) h = '<tr><td colspan="5" style="text-align:center;color:#555">' + T('msg_no_users_found') + '</td></tr>';
        else
            for (var i = 0; i < d.users.length; i++) {
                var u = d.users[i],
                    av = u.avatar ? '<span class="srch-avatar"><img src="' + u.avatar + '" alt=""></span>' : '<span class="srch-avatar"></span>';
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
    fetch('../api/contacts.php', {
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
    fetch('../api/contacts.php?action=pending').then(r => r.json()).then(function(d) {
        var a = document.getElementById('reqArea');
        if (!d.success || d.pending.length === 0) {
            a.innerHTML = '<div class="es"><p>' + T('msg_no_pending') + '</p></div>';
            return;
        }
        var h = '';
        for (var i = 0; i < d.pending.length; i++) {
            var p = d.pending[i],
                av = p.avatar ? '<span class="req-av"><img src="' + p.avatar + '" alt=""></span>' : '<span class="req-av"></span>';
            h += '<div class="req-item">' + av + '<div class="req-info"><div class="req-name">' + eh(p.display_name || p.username) + '</div><div class="req-time">' + eh(p.created_at || '') + '</div><div class="req-msg">' + (p.msg ? eh(p.msg) : '') + '</div></div><div class="req-actions"><button class="ac" onclick="showNoteModal(\'' + p.username + '\')">Accept</button><button class="rj" onclick="respondRequest(\'' + p.username + '\',\'reject\')">Reject</button></div></div>';
        }
        a.innerHTML = h;
    });
}

function loadReports() {
    fetch('../api/report.php?action=list').then(r => r.json()).then(function(d) {
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
    fetch('../api/report.php?action=count').then(r => r.json()).then(function(d) {
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
    var r = await fetch('../api/report.php', {
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
    var r = await fetch('../api/report.php', {
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
    var r = await fetch('../api/report.php', {
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
    var r = await fetch('../api/admin.php', {
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
    var r = await fetch('../api/admin.php', {
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
    fetch('../api/admin.php?action=list&search=' + encodeURIComponent(q) + '&regex=' + re + '&deleted=' + de + '&page=' + admPage + '&sort=' + encodeURIComponent(_admSort) + '&dir=' + encodeURIComponent(_admDir)).then(r => r.json()).then(function(d) {
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
    var d = await fetch('../api/admin.php?action=user_detail&username=' + encodeURIComponent(username)).then(r => r.json());
    if (!d.success) return;
    var u = d.user;
    var stLabel = u.status_label;
    var dndLabel = u.dnd ? 'DND' : 'Online';
    var prof = document.getElementById('sidebarProfile');
    prof.querySelector('.sa').innerHTML = (u.avatar ? ('<img src="' + u.avatar + '" alt="">') : '');
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
        'DND: ' + (u.dnd ? 'Yes' : 'No') + '</div>';
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
        var r = await fetch('../api/admin.php', {
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
        var r = await fetch('../api/admin.php', {
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
        var r = await fetch('../api/admin.php', {
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
        var r = await fetch('../api/admin.php', {
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
        var r = await fetch('../api/admin.php', {
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
    if (type === 'us_login_as') {
        if ((await xconfirm('Login as ' + u + '?')) !== true) return;
        var f = new URLSearchParams();
        f.append('action', 'login_as');
        f.append('username', u);
        var r = await fetch('../api/admin.php', {
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
        var r = await fetch('../api/contacts.php', {
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
        var r = await fetch('../api/admin.php', {
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
        var r = await fetch('../api/contacts.php', {
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
        var r = await fetch('../api/admin.php', {
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
            var dd = await fetch('../api/admin.php?action=user_detail&username=' + encodeURIComponent(u)).then(r => r.json());
            if (dd.success) curReason = dd.user.restricted_reason || '';
        } catch (e) {}
        var rr = await xprompt('Set restrict reason for ' + u + ':', curReason);
        if (rr === null || rr === false) return;
        var f = new URLSearchParams();
        f.append('action', 'set_restrict_reason');
        f.append('username', u);
        f.append('reason', rr);
        var r = await fetch('../api/admin.php', {
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
        var r = await fetch('../api/admin.php', {
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
        var r = await fetch('../api/admin.php', {
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
        var r = await fetch('../api/admin.php', {
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
        var r = await fetch('../api/admin.php', {
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
        var r = await fetch('../api/contacts.php', {
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
    var r = await fetch('../api/admin.php', {
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

function openDm(u) {
    G = null;
    D = u;
    document.getElementById('dmTitle').textContent = T('title_chat') + ': ' + (_contactNotes[u] || u) + ' (' + u + ')';
    switchPanel('dm');
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
        fetch('../api/status.php', {
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
    fr.src = '/modern/groupinfo.php?gid=' + G;
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
    // 置顶按钮文案随当前会话置顶状态切换
    var dmPin = document.getElementById('dmPinBtn');
    if (dmPin) {
        var isSelfDm = !!(D && U && D === U);
        dmPin.textContent = (isSelfDm ? _pinnedSelf : _pinned[D]) ? T('d_unpin') : T('d_pin');
    }
    var grpPin = document.getElementById('grpPinBtn');
    if (grpPin) grpPin.textContent = _pinnedGroup[G] ? T('d_unpin') : T('d_pin');
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
    fetch('../api/contacts.php', {
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
    fetch('../api/group.php', {
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
    fetch('../api/contacts.php', {
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
        fetch('../api/contacts.php', {
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
        fetch('../api/contacts.php', {
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
    return (bps / 1000).toFixed(1) + ' kB/s';
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
        return '<div class="msg-media"><audio src="' + attUrl + '" controls preload="none" style="max-width:100%;max-height:40px"></audio></div>';
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
    'dockerfile':'dockerfile','makefile':'makefile','cmake':'cmake','gradle':'groovy','groovy':'groovy'
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
    if (m.avatar) av = '<div class="msg-avatar" onclick="event.stopPropagation();openMyProfile(\'' + m.username + '\')"><img src="' + m.avatar + '" alt=""></div>';
    var md;
    if (m.msg_type === 'temp' && m.temp_upload_id) md = tempCardHtml(m);
    else if (m.msg_type === 'doodle') md = doodleCardHtml(m);
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
            tempMenu = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu">' + dlItem + fwdItem + '<div onclick="replyDmMessage(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + revokeItem + reportMenuItem + '</div>';
        }
        rh = (own && !dl) ? tempMenu : ((!dl) ? tempMenu : '');
    } else if (own && !dl) rh = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu"><div class="msg-fwd" onclick="openForwardModal(this);closeAllMsgMenus()">' + T('menu_forward') + '</div><div onclick="replyDmMessage(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + emojiMenuItem + reportMenuItem + '<div onclick="revokeDmMessage(' + m.id + ');closeAllMsgMenus()">' + T('menu_revoke') + '</div></div>';
    else if (!dl) rh = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu"><div class="msg-fwd" onclick="openForwardModal(this);closeAllMsgMenus()">' + T('menu_forward') + '</div><div onclick="replyDmMessage(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + emojiMenuItem + reportMenuItem + '<div style="color:#555;cursor:not-allowed">' + T('menu_revoke') + '</div></div>';
    d.innerHTML = av + '<div class="mc"><div class="mb"><div class="mu">' + eh(_contactNotes[m.username] || m.display_name || m.username) + '</div>' + rq + '<div class="mt' + dc + '">' + msgContent + '</div>' + md + '<div class="mti">' + fmtTime(m.time) + '</div></div>' + rh + '</div>';
    // Start status polling for flash cards
    if (m.msg_type === 'temp' && m.temp_upload_id) {
        startTempPoll(d);
    }
    if (prepend) a.insertBefore(d, a.firstChild);
    else a.appendChild(d);
    startImagesIn(d);
    maybeAutoPlayDoodle(m);
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
        var url = '../api/chat.php?action=all&limit=50&dm=' + encodeURIComponent(D);
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
    fetch('../api/status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'action=typing&to=' + encodeURIComponent(D)
    }).catch(function() {});
    typingTimer = setTimeout(function() {
        fetch('../api/status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'action=clear_typing'
        }).catch(function() {});
    }, 3000);
}
async function sendDmMessage() {
    if (!D || S || RSTR) return;
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
    if (!G || S || RSTR) return;
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
        var r = await fetch('../api/group.php', {
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
    var url = '../api/group.php?action=fetch&group_id=' + gid + '&after=' + _glast;
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
    if (m.avatar) av = '<div class="msg-avatar" onclick="event.stopPropagation();openMyProfile(\'' + m.username + '\')"><img src="' + m.avatar + '" alt=""></div>';
    var md = (m.msg_type === 'temp' && m.temp_upload_id)
        ? tempCardHtml(m)
        : attachmentHtml.call({ attName: m.attachment_name || '', attSize: m.attachment_size || null }, m.attachment_url, m.msg_type);
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
            rh = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu">' + dlItem + fwdItem + '<div onclick="replyAnnouncement(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + revokeItem + reportMenuItem + '</div>';
        }
    } else if (own && !dl) rh = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu"><div class="msg-fwd" onclick="openForwardModal(this);closeAllMsgMenus()">' + T('menu_forward') + '</div><div onclick="replyAnnouncement(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + emojiMenuItem + reportMenuItem + '<div onclick="revokeAnnouncement(' + m.id + ');closeAllMsgMenus()">' + T('menu_revoke') + '</div></div>';
    else if (!dl) rh = '<button class="msg-more-btn" onclick="toggleMsgMenu(event,this)"><img src="../data/res/svg/channel_more_16.svg" width="14"></button><div class="msg-menu"><div class="msg-fwd" onclick="openForwardModal(this);closeAllMsgMenus()">' + T('menu_forward') + '</div><div onclick="replyAnnouncement(' + m.id + ');closeAllMsgMenus()">' + T('menu_reply') + '</div>' + emojiMenuItem + reportMenuItem + '<div style="color:#555;cursor:not-allowed">' + T('menu_revoke') + '</div></div>';
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
        var r = await fetch('../api/chat.php?action=all&before=' + oldest + '&limit=50'),
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
        var d2 = await fetch('../api/group.php?action=history&group_id=' + G + '&before=' + before).then(function(r) { return r.json(); });
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
        var r = await fetch('../api/chat.php?action=fetch&after=' + L),
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
                var rf = await fetch('../api/chat.php?action=fetch&after=' + L);
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
        var r = await fetch('../api/chat.php?action=all'),
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
    var d = await fetch('../api/settings.php', {
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
    var d = await fetch('../api/settings.php', {
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
        fetch('../api/settings.php', {
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
    var d = await fetch('../api/settings.php', {
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
    var d = await fetch('../api/settings.php', {
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
    var d = await fetch('../api/settings.php', {
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
    var d = await fetch('../api/settings.php', {
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
    var d = await fetch('../api/settings.php', {
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
    var d = await fetch('../api/settings.php', {
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
    var r = await fetch('../api/auth.php', {
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
    var d = await fetch('../api/incident.php', {
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
    var u = '../api/incident.php?action=list&status=' + statusParam + '&page=' + supPage + '&per_page=' + supPerPage;
    if (q) u += '&search=' + encodeURIComponent(q);
    var btns = document.querySelectorAll('.support-tabs button');
    for (var i = 0; i < btns.length; i++) {
        btns[i].classList.remove('active');
        if (btns[i].textContent.toLowerCase().indexOf(supTab) >= 0) btns[i].classList.add('active');
    }
    var d = await fetch(u).then(r => r.json());
    var a = document.getElementById('supportList');
    if (!d.success || d.incidents.length === 0) {
        a.innerHTML = '<div class="es"><p>No tickets found.</p></div>';
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
        info = 'Showing ' + ((supPage - 1) * supPerPage + 1) + '-' + Math.min(supPage * supPerPage, d.total) + ' of ' + d.total;
    pg += '<button class="bsm" ' + (supPage > 1 ? 'onclick="loadSupportTickets(supTab,' + (supPage - 1) + ')"' : 'disabled') + '>Prev</button> ';
    pg += '<button class="bsm" ' + (supPage < tp ? 'onclick="loadSupportTickets(supTab,' + (supPage + 1) + ')"' : 'disabled') + '>Next</button>';
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
async function toggleSupportDetail(id) {
    var el = document.getElementById('supDtl' + id);
    if (el.classList.contains('active')) {
        el.classList.remove('active');
        return;
    }
    if (el.getAttribute('data-loaded')) {
        el.classList.add('active');
        return;
    }
    var d = await fetch('../api/incident.php?action=detail&id=' + id).then(r => r.json());
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
    h += '<div class="support-reply-box"><textarea id="supReply' + id + '" placeholder="Reply..."></textarea><button class="bsm" onclick="doSupportReply(' + id + ')">Reply</button>';
    if (ADMIN) {
        h += '<select id="supStatus' + id + '" class="bsm" style="background:#1e1e1e;border:1px solid #444;color:#ccc"><option value="">Status...</option><option value="open">Open</option><option value="in_progress">In Progress</option><option value="resolved">Resolved</option><option value="closed">Closed</option></select><button class="bsm" onclick="doSupportUpdateStatus(' + id + ')">Update</button>';
    }
    h += '</div></div>';
    el.innerHTML = h;
    el.setAttribute('data-loaded', '1');
    el.classList.add('active');
}
async function doSupportReply(id) {
    var m = document.getElementById('supReply' + id).value.trim();
    if (!m) return;
    var f = new URLSearchParams();
    f.append('action', 'respond');
    f.append('id', id);
    f.append('message', m);
    var d = await fetch('../api/incident.php', {
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
    var d = await fetch('../api/incident.php', {
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
    var url = '../api/chat.php?action=search_messages&q=' + encodeURIComponent(q) + '&page=' + _msgSearchPage;
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
    var url = '../api/chat.php?action=search_messages&q=' + encodeURIComponent(q) + '&page=' + _dmSearchPage;
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
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            sendAnnouncement();
        }
    });
}
var _dmInput = document.getElementById("dmMessageInput");
if (_dmInput) {
    _dmInput.addEventListener("keydown", function(e) {
        if (e.key === "Enter" && !e.shiftKey) {
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
    fetch('../api/admin.php?action=role_list').then(function(r) {
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
    fetch('../api/admin.php?action=role_list').then(function(r) {
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
            fetch('../api/admin.php', {
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
        fetch('../api/admin.php', {
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
    var d = await fetch('../api/admin.php', {
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
    fetch('../api/donation.php?action=list&page=' + donPage).then(function(r) {
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
    fetch('../api/chat.php?action=my_content&type=' + encodeURIComponent(_pmType) + '&limit=200').then(function(r) {
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
        thumb = '<span class="pmg-thumb pmg-file">' + T('pmg_file_icon', '📄') + '</span>';
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
    fetch('../api/chat.php', {
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
        fetch('../api/donation.php?action=search_users&q=' + encodeURIComponent(q)).then(function(r) {
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
    var d = await fetch('../api/donation.php', {
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
    var d = await fetch('../api/donation.php', {
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
        fetch('../api/group.php', {
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
        fetch('../api/group.php', {
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
    fetch('../api/group.php?action=list_my').then(function(r) {
        return r.json()
    }).then(function(d) {
        if (!d.success) return;
        var h = '';
        var sorted = d.groups.slice().sort(function(a, b) { return ((b.pinned ? 1 : 0) - (a.pinned ? 1 : 0)); });
        for (var i = 0; i < sorted.length; i++) {
            var g = sorted[i];
            _pinnedGroup[g.group_id] = g.pinned ? 1 : 0;
            var gav = g.avatar_url ? '<img src="' + g.avatar_url + '" alt="">' : '';
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
    fetch('../api/group.php?action=history&group_id=' + gid).then(function(r) {
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
fetch('../api/emoji.php?action=list').then(function(r) {
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
            text = text.split(e.code).join('<img src="../' + src + '" class="chat-emoji chat-emoji-builtin" data-emoji-code="' + eh(e.code) + '" alt="' + eh(e.code) + '">');
        }
    }
    text = text.replace(/\[emoji:([a-f0-9]{32})\]/g, function(m, h) {
        return '<img src="../api/emoji.php?action=img&hash=' + h + '" class="chat-emoji chat-emoji-custom" data-emoji-code="[emoji:' + h + ']" alt="">';
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
    switchEmojiTab('builtin');
}

function switchEmojiTab(tab) {
    document.getElementById('emojiTabBuiltin').classList.toggle('active', tab === 'builtin');
    document.getElementById('emojiTabCustom').classList.toggle('active', tab === 'custom');
    var grid = document.getElementById('emojiGrid'),
        h = '';
    if (tab === 'builtin') {
        if (!Array.isArray(_emojiBuiltin) || _emojiBuiltin.length === 0) {
            fetch('../api/emoji.php?action=list').then(function(r) {
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
                    var dyn = e.img_dyn ? '../' + _emojiBuiltin[i].img_dyn : '';
                    if (ep === 'dynamic' && dyn) {
                        h += '<img src="' + dyn + '" class="emoji-item" onclick="insertEmoji(\'' + e.code + '\')" title="' + eh(e.code) + '">';
                    } else if (dyn && ep !== 'static') {
                        h += '<img src="../' + e.img + '" data-dyn="' + dyn + '" class="emoji-item" onclick="insertEmoji(\'' + e.code + '\')" title="' + eh(e.code) + '" onmouseover="if(this.dataset.dyn)this.src=this.dataset.dyn" onmouseout="this.src=\'../' + e.img + '\'">';
                    } else {
                        h += '<img src="../' + e.img + '" class="emoji-item" onclick="insertEmoji(\'' + e.code + '\')" title="' + eh(e.code) + '">';
                    }
                }
            }
        }
    } else {
        fetch('../api/emoji.php?action=my_custom').then(function(r) {
            return r.json()
        }).then(function(d) {
            if (!d.success || !d.custom.length) {
                grid.innerHTML = '<div style="color:#888;font-size:.72em;text-align:center;padding:20px">No custom emoji.<br><button class="bsm" onclick="document.getElementById(\'customEmojiFile\').click()" style="margin-top:8px">+ Upload</button></div>';
                return;
            }
            var h2 = '';
            for (var i = 0; i < d.custom.length; i++) {
                var c = d.custom[i];
                h2 += '<div class="emoji-item-wrap"><img src="../' + c.img + '" class="emoji-item" onclick="insertEmoji(\'[emoji:' + c.hash + ']\')"><span class="emoji-del" onclick="deleteCustomEmoji(\'' + c.hash + '\')">&times;</span></div>';
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
            fetch('../api/emoji.php', {
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
    var d = await fetch('../api/emoji.php', {
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
    fetch('../api/settings.php', {
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
    fetch('../api/emoji.php?action=public_list').then(function(r) {
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
            h += '<img src="../' + e.img + '" onclick="toggleSelectedPublicEmoji(this)" data-code="[emoji:' + e.hash + ']" data-hash="' + e.hash + '" data-img="../' + e.img + '" alt="">';
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
            fetch('../api/emoji.php', {
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
    fetch('../api/emoji.php', {
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
        fetch('../api/emoji.php', {
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
    var rawMsg = bubble.getAttribute('data-raw') || '';
    _fwdRaw = rawMsg;
    var list = document.getElementById('forwardTargetList');
    if (!list) return;
    list.innerHTML = '<div class="es"><p>' + T('msg_loading', '加载中...') + '</p></div>';
    document.getElementById('forwardModal').classList.add('active');
    fetch('../api/contacts.php?action=list').then(function(r) { return r.json() }).then(function(d) {
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
function forwardTo(username) {
    if (!_fwdRaw || !username) return;
    apiRequest('send', { message: _fwdRaw, recipient: username }).then(function(d) {
        if (d.success) {
            closeForwardModal();
        } else xalert(d.error || 'Failed.');
    });
}
function closeForwardModal() {
    document.getElementById('forwardModal').classList.remove('active');
    _fwdRaw = '';
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
        fetch('../api/emoji.php', {
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
    var popup = document.getElementById('emojiPopup');
    if (popup && popup.style.display === 'flex' && !e.target.closest('#emojiPopup') && !e.target.closest('button[title=Emoji]')) popup.style.display = 'none';
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
    fetch('../api/level.php?action=info').then(function(r) { return r.json(); }).then(function(d) {
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
        var d = await fetch('../api/level.php', {
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
            xalert('🎉 ' + T('lvl_upgraded', '升级成功') + ' → Lv.' + (d.level || MYLV));
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
        var d = await fetch('../api/level.php', {
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
    fetch('../api/level.php?action=leaderboard').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) { board.innerHTML = ''; return; }
        var rankInfo = document.getElementById('lvlRankInfo');
        if (rankInfo) {
            fetch('../api/level.php?action=rank').then(function(r2) { return r2.json(); }).then(function(d2) {
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
    fetch('../api/level.php?action=history').then(function(r) { return r.json(); }).then(function(d) {
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

function flashFileChosen(input, target) {
    var f = input.files[0];
    input.value = '';
    if (!f) return;
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

function _doFlashUpload(file, target) {
    var reader = new FileReader();
    reader.onload = function(ev) {
        var b64 = ev.target.result;
        var form = new URLSearchParams();
        form.append('action', 'upload');
        form.append('filename', file.name);
        form.append('file', b64);
        fetch('../api/temp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: form.toString()
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.success) {
                xalert(d.error || T('flash_fail', '闪传失败'));
                return;
            }
            // Auto-send to current chat (announcement or DM)
            if (target === 'dm') {
                flashSendDm(d);
            } else {
                flashSendAnnouncement(d);
            }
        }).catch(function() {
            xalert(T('flash_fail', '闪传失败'));
        });
    };
    reader.readAsDataURL(file);
}

function flashSendAnnouncement(d) {
    apiRequest('send', { message: '', temp_upload_id: d.id }).then(function(res) {
        if (res.success) {
            copyText(d.url || '');
            pm();
        } else {
            xalert(res.error || T('flash_fail', '闪传失败'));
        }
    });
}

function flashSendDm(d) {
    if (!D) return;
    apiRequest('send', { message: '', recipient: D, temp_upload_id: d.id }).then(function(res) {
        if (res.success) {
            delete seenMsgIds['dm_' + res.message_id];
            loadDmMessages();
            var a = document.getElementById('dmMessagesArea');
            if (a) scrollChatToBottom(a);
        } else {
            xalert(res.error || T('flash_fail', '闪传失败'));
        }
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
    var dlBtn = revoked
        ? '<span class="flash-dl flash-dl-dis">' + T('btn_download', '下载') + '</span>'
        : '<button class="flash-dl" onclick="event.stopPropagation();tempDownload(' + id + ')">' + T('btn_download', '下载') + '</button>';
    var statusRow = '';
    if (revoked) {
        statusRow = '<div class="flash-status flash-revoked">' + T('flash_revoked_msg', '已被撤回并删除') + '</div>';
    } else {
        statusRow = '<div class="flash-status flash-state" data-temp="' + id + '" data-owner="' + (isOwner ? 1 : 0) + '">' + T('flash_checking', '检查状态...') + '</div>';
    }
    var expireRow = '<div class="flash-expire" data-expires="' + (m.temp_expires || '') + '">' + T('flash_expire', '过期时间') + ': --:--:--</div>';
    return '<div class="flash-card">'
        + '<div class="flash-title">' + T('flash_flash', '闪传（临时）') + '</div>'
        + '<div class="flash-file">' + name + (size ? ' (' + size + ')' : '') + '</div>'
        + dlBtn
        + statusRow
        + expireRow
        + '</div>';
}

function tempDownload(id) {
    var a = document.createElement('a');
    a.href = '../api/temp.php?action=download&id=' + id;
    a.target = '_blank';
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
}

// ---- Flash card UI 更新（WSS 推送 temp_status 时调用；也供本地 HTTP 轮询复用） ----
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
        state.textContent = T('flash_downloading', '对方正在下载') + ': ' + pct + '%';
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
    var prevBytes = 0, prevTime = 0;
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
        fetch('../api/temp.php?action=status&id=' + id).then(function(r) { return r.json(); }).then(function(d) {
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
            if (isOwner && d.status !== 'not_started') {
                // Owner: show real-time download progress & speed
                if (d.status === 'complete') {
                    state.textContent = T('flash_complete', '对方已经下载完成') + ' ✓';
                    return;
                }
                if (d.status === 'in_progress' && typeof d.downloaded_bytes === 'number' && d.size > 0) {
                    var now = Date.now();
                    var speed = 0;
                    if (prevTime > 0) {
                        speed = (d.downloaded_bytes - prevBytes) / Math.max(1, (now - prevTime) / 1000);
                    }
                    prevBytes = d.downloaded_bytes;
                    prevTime = now;
                    var pct = Math.round(d.downloaded_bytes / d.size * 100);
                    var speedTxt = fmtSpeed(speed);
                    state.textContent = T('flash_downloading', '对方正在下载') + ': ' + pct + '% ' + speedTxt + ' ' + T('flash_speed_unit', '/s');
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
    fetch('../api/contacts.php?action=list').then(function(r) { return r.json() }).then(function(d) {
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
        fetch('../api/temp.php', {
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
    fetch('../api/temp.php?action=my').then(function(r) { return r.json(); }).then(function(d) {
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
    fetch('../api/temp.php', {
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
    fetch('../api/level.php?action=history').then(function(r) { return r.json(); }).then(function(d) {
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
    fetch('../api/level.php?action=info').then(function(r) { return r.json(); }).then(function(d) {
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
    fetch('../api/settings.php', {
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
    if (bg) {
        bg.style.filter = 'blur(' + (parseInt(blur,10)||0) + 'px)';
        bg.style.opacity = (parseInt(opacity,10)||100)/100;
    }
    if (ov) {
        ov.style.opacity = (100 - (parseInt(opacity,10)||100))/100 * 0.5;
    }
}
function bgSyncUI() {
    var c = {};
    try { c = JSON.parse(localStorage.getItem(BG_CACHE_KEY) || '{}'); } catch(e) {}
    var blurEl = document.getElementById('bgBlur');
    var opEl = document.getElementById('bgOpacity');
    if (blurEl) blurEl.value = c.blur || 0;
    if (opEl) opEl.value = c.opacity || 100;
    if (document.getElementById('bgBlurVal')) document.getElementById('bgBlurVal').textContent = (c.blur||0) + 'px';
    if (document.getElementById('bgOpacityVal')) document.getElementById('bgOpacityVal').textContent = (c.opacity||100) + '%';
    bgApply(c.blur || 0, c.opacity || 100);
}
function loadBg(skipCache) {
    fetch('../api/settings.php?action=get_background').then(function(r) { return r.json(); }).then(function(d) {
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
        fetch('../api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: frm.toString()
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) {
                bgEnable(d.url, 'force-' + Date.now());
                var cached = { url: d.url, version: 'force-' + Date.now(), blur: 0, opacity: 100 };
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
    fetch('../api/settings.php', {
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
    fetch('../api/settings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: frm.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            var cached = { url: d.url, version: 'preset-' + Date.now(), blur: 0, opacity: 100 };
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
    fetch('../api/settings.php', {
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
    fetch('../api/settings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: frm.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            xalert(T('msg_duress_cleared','Duress password cleared.'));
            closeDuressModal();
        } else xalert(d.error || T('msg_login_something_wrong','Something went wrong.'));
    });
}

// ================= Database admin (root only) =================
function dbLoadTables() {
    var sel = document.getElementById('dbTableSelect');
    fetch('../api/admin.php?action=db_tables').then(function(r) { return r.json(); }).then(function(d) {
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
    fetch('../api/admin.php?action=db_structure&table=' + encodeURIComponent(table)).then(function(r) { return r.json(); }).then(function(d) {
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
    window.open('../api/admin.php?action=db_export&table=' + encodeURIComponent(table) + '&csrf=' + encodeURIComponent(window.CSRF || ''), '_blank');
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
    fetch('../api/admin.php', {
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
    fetch('../api/auth.php?action=check')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) location.reload();
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
  var tg = document.getElementById('sidebarToggleBtn');
  var ov = document.getElementById('sidebarOverlay');
  if (!isMobileView()) {
    // Desktop: keep natural sidebar; toggle/overlay stay hidden (CSS display:none also covers it)
    closeMobileSidebar();
    var side = document.querySelector('.sidebar');
    if (side) side.classList.remove('open');
    return;
  }
  // Mobile: auto-open the sidebar on entry so visitors see the nav.
  // Tapping a nav item (handled below) auto-collapses it again.
  openMobileSidebar();
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
    var src = '/modern/profile.php';
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
    fr.src = '/modern/settings.php';
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
function ensureUserCtxMenu() {
    if (_userCtxEl && document.body.contains(_userCtxEl)) return _userCtxEl;
    _userCtxEl = document.createElement('div');
    _userCtxEl.id = 'userCtxMenu';
    _userCtxEl.innerHTML =
        '<button onclick="closeUserCtxMenu();viewDmProfile(_ctxUser)">' + T('btn_view_profile') + '</button>' +
        '<button onclick="closeUserCtxMenu();reportDmUser(_ctxUser)">' + T('btn_report_user') + '</button>' +
        '<button onclick="closeUserCtxMenu();openDmSearch(_ctxUser)">' + T('d_search_history') + '</button>' +
        '<button id="ctxPinBtn" onclick="closeUserCtxMenu();togglePinContact(_ctxUser)">' + T('d_pin') + '</button>' +
        '<button onclick="closeUserCtxMenu();changeNickname(_ctxUser)">' + T('d_change_nickname') + '</button>' +
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
    // 觸控裝置不啟用
    if (window.matchMedia && window.matchMedia("(hover: none)").matches) return;

    // 建立跟隨滑鼠的白色小圓點
    var cursor = document.createElement("div");
    cursor.id = "cursor";
    document.body.appendChild(cursor);

    // 隱藏系統滑鼠游標，改成白色小圓點（文字輸入框保留 I 型游標）
    var styleEl = document.createElement("style");
    styleEl.innerHTML = '*:not(input):not(textarea):not([contenteditable="true"]) {cursor: url("data:image/svg+xml,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 8 8\' width=\'10px\' height=\'10px\'><circle cx=\'4\' cy=\'4\' r=\'4\' fill=\'white\' /></svg>") 4 4, auto !important}';
    document.head.appendChild(styleEl);

    var pos = { curr: null, prev: null };
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
            pos.prev.x = lerp(pos.prev.x, pos.curr.x, 0.35);
            pos.prev.y = lerp(pos.prev.y, pos.curr.y, 0.35);
            move(pos.prev.x, pos.prev.y);
        } else {
            pos.prev = pos.curr;
        }
        if (pos.curr && (pos.curr.x !== pos.prev.x || pos.curr.y !== pos.prev.y)) {
            requestAnimationFrame(render);
        }
    };

    document.addEventListener("mousemove", function (e) {
        if (pos.curr == null) move(e.clientX - 9, e.clientY - 9);
        pos.curr = { x: e.clientX - 9, y: e.clientY - 9 };
        cursor.classList.remove("hidden");
        render();
    });
    document.addEventListener("mouseenter", function () { cursor.classList.remove("hidden"); });
    document.addEventListener("mouseleave", function () { cursor.classList.add("hidden"); });
    document.addEventListener("mousedown", function () { cursor.classList.add("active"); });
    document.addEventListener("mouseup", function () { cursor.classList.remove("active"); });
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
    return '<div class="msg-media doodle-msg"><button type="button" class="doodle-replay" data-strokes="' + esc(JSON.stringify(strokes)) + '" onclick="replayDoodleMsg(this)">✎ Doodle</button></div>';
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
