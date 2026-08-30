/* ============================================================================
 * ChatApp · 共享 Markdown 渲染器（桌面 chat.js 与移动端 m.js 共用）
 *
 * 纯函数、零依赖（不引用 chat.js 的 T/eh/_emojiBuiltin 等全局）。
 * 桌面：chat.php 在 chat.js 之后加载本文件 → 覆盖 chat.js 内同名实现（相同代码）。
 * 移动：m.php 在 m.js 之前加载本文件 → m.js 直接使用 renderMd。
 *
 * 使用：
 *   renderMd(rawText)            —— 完整 markdown → HTML（内部先做 HTML 转义，安全）
 *   renderEmoji(text)            —— 仅 emoji 码 → 图片（移动端自带的留用；桌面仍在 chat.js）
 * ============================================================================ */

/** URL 白名单：仅 http(s) 与 data:image 允许，其余一律 #（防 javascript: 等注入）。 */
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
