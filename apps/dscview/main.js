/* dscview frontend — 本地解析上传的 DeepSeek 聊天记录 JSON */
'use strict';

const state = {
  list: [],          // 轻量列表项（不含消息正文）
  byId: new Map(),   // id -> messages[]
  filtered: [],      // 当前过滤后的列表
  q: '',
  page: 1,
  per: 30,
  currentId: null,
};

const $ = (sel) => document.querySelector(sel);

const dropzone = $('#dropzone');
const fileinput = $('#fileinput');
const progressEl = $('#progress');
const barFill = $('#bar-fill');
const progressText = $('#progress-text');
const app = $('#app');
const chatlist = $('#chatlist');
const searchbox = $('#searchbox');
const loadmore = $('#loadmore');
const loadmoreBtn = $('#loadmore-btn');
const chatview = $('#chatview');
const emptyEl = $('#empty');

/* ---------------- utils ---------------- */

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

function fmtDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d)) return String(iso).slice(0, 10);
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

function debounce(fn, ms) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

/* ---------------- markdown ---------------- */

// LaTeX 渲染（KaTeX）。失败时退回原文。
function renderTex(src, displayMode) {
  if (typeof katex !== 'undefined') {
    try {
      return katex.renderToString(src, { displayMode, throwOnError: false, strict: false });
    } catch (e) { /* fall through */ }
  }
  return `<span class="katex-error" style="color:#cc0000">${escapeHtml(src)}</span>`;
}

// 行内 LaTeX：$...$（避开 $$、以及被反引号包住的部分）
function renderInlineLatex(s) {
  return s.replace(/(^|[^$])\$([^$\n]+?)\$(?![$\d])/g, (m, pre, tex) => pre + renderTex(tex.trim(), false));
}

function renderInline(s) {
  let t = escapeHtml(s);
  // inline code (before other rules)
  t = t.replace(/`([^`\n]+)`/g, '<code>$1</code>');
  // LaTeX inline（在链接/加粗之前，避免 $ 干扰）
  t = renderInlineLatex(t);
  // links
  t = t.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g,
    '<a href="$2" target="_blank" rel="noopener">$1</a>');
  // bold
  t = t.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
  // italic
  t = t.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
  return t;
}

function renderMarkdown(src) {
  const lines = String(src).replace(/\r\n?/g, '\n').split('\n');
  const out = [];
  let i = 0;

  const splitCells = (line) =>
    line.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|')
      .map((c) => renderInline(c.trim()));

  while (i < lines.length) {
    const line = lines[i];

    // fenced code block
    const fence = line.match(/^```(\w*)\s*$/);
    if (fence) {
      const lang = fence[1];
      const code = [];
      i++;
      while (i < lines.length && !/^```\s*$/.test(lines[i])) { code.push(lines[i]); i++; }
      i++;
      const langLabel = lang ? escapeHtml(lang) : 'code';
      out.push(`<div class="md-code-block md-code-block-light">
        <div class="md-code-block-banner-wrap"><div class="md-code-block-banner">
          <span class="md-code-block-banner-lite">${langLabel}</span>
          <span class="md-code-block-action"><button type="button" class="md-code-block-copy">复制</button></span>
        </div></div>
        <pre class="language-${escapeHtml(lang)}"><code class="language-${escapeHtml(lang)}">${escapeHtml(code.join('\n'))}</code></pre>
      </div>`);
      continue;
    }

    // block LaTeX: $$...$$ (inline on one line, or multi-line block)
    const texBlock = line.match(/^\$\$([\s\S]*?)\$\$\s*$/);
    if (texBlock) {
      out.push(renderTex(texBlock[1].trim(), true));
      i++;
      continue;
    }
    if (/^\$\$\s*$/.test(line)) {
      const tex = [];
      i++;
      while (i < lines.length && !/^\$\$\s*$/.test(lines[i])) { tex.push(lines[i]); i++; }
      i++;
      out.push(renderTex(tex.join('\n').trim(), true));
      continue;
    }

    // horizontal rule: --- / *** / ___
    if (/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
      out.push('<hr>');
      i++;
      continue;
    }

    // table
    if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\s*\|[\s:|-]+\|\s*$/.test(lines[i + 1])) {
      const header = line;
      i += 2;
      const rows = [];
      while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) { rows.push(lines[i]); i++; }
      let html = '<div class="markdown-table-wrapper"><table><thead><tr>' + splitCells(header).map((c) => `<th>${c}</th>`).join('') + '</tr></thead><tbody>';
      html += rows.map((r) => '<tr>' + splitCells(r).map((c) => `<td>${c}</td>`).join('') + '</tr>').join('');
      html += '</tbody></table></div>';
      out.push(html);
      continue;
    }

    // heading
    const h = line.match(/^(#{1,6})\s+(.*)$/);
    if (h) {
      const lvl = h[1].length;
      out.push(`<h${lvl}>${renderInline(h[2])}</h${lvl}>`);
      i++;
      continue;
    }

    // blockquote
    if (/^>\s?/.test(line)) {
      const q = [];
      while (i < lines.length && /^>\s?/.test(lines[i])) { q.push(lines[i].replace(/^>\s?/, '')); i++; }
      out.push(`<blockquote>${renderMarkdown(q.join('\n'))}</blockquote>`);
      continue;
    }

    // unordered list
    if (/^[-*+]\s+/.test(line)) {
      const items = [];
      while (i < lines.length && /^[-*+]\s+/.test(lines[i])) {
        items.push(`<li>${renderInline(lines[i].replace(/^[-*+]\s+/, ''))}</li>`);
        i++;
      }
      out.push(`<ul>${items.join('')}</ul>`);
      continue;
    }

    // ordered list
    if (/^\d+[.)]\s+/.test(line)) {
      const items = [];
      while (i < lines.length && /^\d+[.)]\s+/.test(lines[i])) {
        items.push(`<li>${renderInline(lines[i].replace(/^\d+[.)]\s+/, ''))}</li>`);
        i++;
      }
      out.push(`<ol>${items.join('')}</ol>`);
      continue;
    }

    // blank
    if (/^\s*$/.test(line)) { i++; continue; }

    // paragraph
    const para = [];
    while (i < lines.length && !/^\s*$/.test(lines[i]) &&
           !/^(#{1,6}\s|>\s?|[-*+]\s+|\d+[.)]\s+|```|\s*\|)/.test(lines[i])) {
      para.push(lines[i]); i++;
    }
    if (para.length) out.push(`<p class="ds-markdown-paragraph">${renderInline(para.join('<br>'))}</p>`);
  }
  return out.join('\n');
}

/* ---------------- flatten ---------------- */

function flattenChat(chat) {
  const mapping = chat && chat.mapping ? chat.mapping : {};
  const messages = [];
  const walk = (nodeId) => {
    const node = mapping[nodeId];
    if (!node) return;
    const msg = node.message;
    if (msg && Array.isArray(msg.fragments)) {
      for (const f of msg.fragments) {
        messages.push({
          t: (f && f.type) || 'RESPONSE',
          c: (f && f.content) || '',
          m: msg.model || '',
          d: msg.inserted_at || '',
        });
      }
    }
    (node.children || []).forEach((c) => walk(String(c)));
  };
  walk('root');

  let nReq = 0, nResp = 0, chars = 0, preview = '';
  for (const m of messages) {
    if (m.t === 'REQUEST') nReq++;
    else if (m.t === 'RESPONSE') nResp++;
    chars += m.c.length;
    if (!preview && m.t === 'REQUEST' && m.c) preview = m.c;
  }
  return {
    id: chat.id || '',
    title: (chat.title && String(chat.title).trim()) ? chat.title : '未命名对话',
    inserted_at: chat.inserted_at || '',
    updated_at: chat.updated_at || '',
    nReq, nResp, chars, preview,
    messages,
  };
}

/* ---------------- streaming parse ---------------- */

function makeReader(file) {
  if (typeof file.stream === 'function') return file.stream().getReader();
  // fallback for browsers without File.stream()
  return file.arrayBuffer().then((buf) => {
    let done = false;
    return {
      read: async () => done
        ? { done: true, value: undefined }
        : (done = true, { done: false, value: new Uint8Array(buf) }),
    };
  });
}

async function parseFile(file, onProgress) {
  const total = file.size || 1;
  const reader = await makeReader(file);
  const decoder = new TextDecoder('utf-8');

  let depth = 0, inString = false, escaped = false, capturing = false, raw = '';
  const list = [];
  const byId = new Map();
  let processed = 0, lastUi = 0;

  const process = (s) => {
    for (let i = 0; i < s.length; i++) {
      const c = s[i];
      if (inString) {
        if (capturing) raw += c;
        if (escaped) escaped = false;
        else if (c === '\\') escaped = true;
        else if (c === '"') inString = false;
        continue;
      }
      if (c === '"') { inString = true; if (capturing) raw += c; continue; }
      if (c === '{') {
        if (depth === 1 && !capturing) { capturing = true; raw = '{'; }
        else if (capturing) raw += c;
        depth++;
        continue;
      }
      if (c === '}') {
        if (capturing) raw += c;
        depth--;
        if (depth === 1 && capturing) {
          capturing = false;
          let chat = null;
          try { chat = JSON.parse(raw); } catch (e) { /* skip malformed */ }
          raw = '';
          if (chat && chat.id) {
            const flat = flattenChat(chat);
            list.push({
              id: flat.id, title: flat.title,
              inserted_at: flat.inserted_at, updated_at: flat.updated_at,
              nReq: flat.nReq, nResp: flat.nResp, chars: flat.chars, preview: flat.preview,
            });
            byId.set(flat.id, flat.messages);
          }
        }
        continue;
      }
      if (c === '[') { if (capturing) raw += c; depth++; continue; }
      if (c === ']') { if (capturing) raw += c; depth--; continue; }
      if (capturing) raw += c;
    }
  };

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    processed += value.byteLength;
    process(decoder.decode(value, { stream: true }));
    const now = Date.now();
    if (now - lastUi > 60) {
      lastUi = now;
      onProgress(processed / total);
      await new Promise((r) => setTimeout(r, 0));
    }
  }
  process(decoder.decode());
  onProgress(1);

  list.sort((a, b) => String(b.updated_at).localeCompare(String(a.updated_at)));
  return { list, byId };
}

/* ---------------- upload flow ---------------- */

function show(which) {
  dropzone.classList.toggle('hidden', which !== 'dropzone');
  progressEl.classList.toggle('hidden', which !== 'progress');
  app.classList.toggle('hidden', which !== 'chat');
}

async function handleFile(file) {
  if (!file) return;
  state.list = []; state.byId = new Map(); state.filtered = [];
  state.page = 1; state.currentId = null; state.q = '';
  searchbox.value = '';
  chatlist.innerHTML = '';
  emptyEl.classList.remove('hidden');
  chatview.classList.add('hidden');
  app.classList.remove('viewing');

  show('progress');
  barFill.style.width = '0%';
  progressText.textContent = '0%';

  try {
    const res = await parseFile(file, (p) => {
      barFill.style.width = (p * 100).toFixed(1) + '%';
      progressText.textContent = (p * 100).toFixed(1) + '%';
    });
    state.list = res.list;
    state.byId = res.byId;
    state.filtered = res.list;
    show('chat');
    renderPage();
  } catch (e) {
    alert('解析失败：' + (e && e.message ? e.message : e));
    show('dropzone');
  }
}

/* ---------------- list ---------------- */

function applyFilter() {
  const q = state.q.trim().toLowerCase();
  state.filtered = q
    ? state.list.filter((it) =>
        String(it.title || '').toLowerCase().includes(q) ||
        String(it.preview || '').toLowerCase().includes(q))
    : state.list;
  state.page = 1;
  renderPage();
}

function renderPage() {
  const end = state.page * state.per;
  const slice = state.filtered.slice(0, end);
  if (slice.length === 0) {
    chatlist.innerHTML = '<div class="empty-list">没有找到匹配的对话</div>';
  } else {
    // 按日期分组：今天 / 昨天 / 7 天内 / 更早（对齐官方 Today / Yesterday / 7 Days）
    const groups = [];
    const buckets = [['今天', []], ['昨天', []], ['7 天内', []], ['更早', []]];
    const now = new Date();
    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const DAY = 86400000;
    const groupIdx = (ts) => {
      const t = new Date(ts).getTime();
      if (!isNaN(t) && t >= startToday) return 0;
      if (!isNaN(t) && t >= startToday - DAY) return 1;
      if (!isNaN(t) && t >= startToday - 7 * DAY) return 2;
      return 3;
    };
    for (const it of slice) buckets[groupIdx(it.updated_at)][1].push(it);
    for (const [label, items] of buckets) {
      if (!items.length) continue;
      let html = `<div class="sessionGroup"><div class="groupLabel">${label}</div>`;
      html += items.map((it) => `
        <div class="sessionRow${it.id === state.currentId ? ' selected' : ''}" data-id="${escapeHtml(it.id)}">
          <span class="title">${escapeHtml(it.title)}</span>
          <span class="time">${fmtDate(it.updated_at)}</span>
        </div>`).join('');
      html += '</div>';
      groups.push(html);
    }
    chatlist.innerHTML = groups.join('');
  }
  loadmore.classList.toggle('hidden', end >= state.filtered.length);
  loadmoreBtn.textContent = `加载更多（${Math.min(end, state.filtered.length)}/${state.filtered.length}）`;
}

/* ---------------- chat rendering ---------------- */

const FRAG_LABEL = {
  SEARCH: '🔍 搜索',
  THINK: '💭 思考过程',
  TOOL_OPEN: '🛠 工具调用',
  TOOL_SEARCH: '🛠 工具搜索',
  FILE: '📎 文件',
};

function openChat(id) {
  state.currentId = id;
  document.querySelectorAll('.sessionRow').forEach((el) =>
    el.classList.toggle('selected', el.dataset.id === id));
  const item = state.list.find((i) => i.id === id);
  const messages = state.byId.get(id) || [];
  // 打开新对话时默认回到「对话」面板
  switchPanel('chat');
  renderChat(item, messages);
  emptyEl.classList.add('hidden');
  chatview.classList.remove('hidden');
  app.classList.add('viewing');
}

function renderChat(chat, messages) {
  const title = chat ? chat.title : '对话';
  const sub = [
    chat && chat.updated_at ? `更新 ${fmtDate(chat.updated_at)}` : '',
    chat && chat.inserted_at ? `创建 ${fmtDate(chat.inserted_at)}` : '',
    `${messages.length} 条消息`,
  ].filter(Boolean).join(' · ');

  $('#chatTitle').textContent = title;
  $('#headerMeta').textContent = sub;

  // 性能优化：超长对话分批插入（requestAnimationFrame 分片），避免一次 innerHTML 卡死
  const target = $('#messages');
  target.innerHTML = '';
  const CHUNK = 400;
  let cursor = 0;

  const build = (m) => {
    if (m.t === 'REQUEST') {
      return `<div class="userRow"><div class="userStack"><div class="bubble">${escapeHtml(m.c)}</div></div></div>`;
    }
    if (m.t === 'RESPONSE') {
      return `<div class="ds-markdown">${renderMarkdown(m.c)}</div>`;
    }
    const label = FRAG_LABEL[m.t] || m.t;
    const body = (m.t === 'FILE' && m.c) ? escapeHtml(m.c) : renderMarkdown(m.c || '（空）');
    return `<details class="thinkRow">
      <summary class="thinkSummary"><span class="thinkTitle">${label}${m.d ? ` · ${fmtDate(m.d)}` : ''}</span></summary>
      <div class="thinkBody">${body}</div>
    </details>`;
  };

  const pump = () => {
    const end = Math.min(cursor + CHUNK, messages.length);
    const html = [];
    for (let k = cursor; k < end; k++) html.push(build(messages[k]));
    target.insertAdjacentHTML('beforeend', html.join(''));
    cursor = end;
    if (cursor < messages.length) {
      requestAnimationFrame(pump);
    }
  };
  pump();
  $('#chatScroll').scrollTop = 0;
}

/* ---------------- events ---------------- */

fileinput.addEventListener('change', () => handleFile(fileinput.files[0]));

dropzone.addEventListener('dragenter', (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('dragover'); });
dropzone.addEventListener('dragleave', (e) => { e.preventDefault(); dropzone.classList.remove('dragover'); });
dropzone.addEventListener('drop', (e) => {
  e.preventDefault();
  dropzone.classList.remove('dragover');
  const file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
  handleFile(file);
});

chatlist.addEventListener('click', (e) => {
  const item = e.target.closest('.sessionRow');
  if (item) openChat(item.dataset.id);
});

searchbox.addEventListener('input', debounce(() => {
  state.q = searchbox.value;
  applyFilter();
}, 200));

loadmoreBtn.addEventListener('click', () => { state.page++; renderPage(); });

$('#reuploadBtn').addEventListener('click', () => {
  fileinput.value = '';
  show('dropzone');
});
$('#newSession').addEventListener('click', () => {
  fileinput.value = '';
  show('dropzone');
});

/* ---------------- 对话 / 统计 面板切换 ---------------- */
function switchPanel(name) {
  const chat = $('#chatScroll');
  const stats = $('#statsView');
  const isStats = name === 'stats';
  chat.classList.toggle('hidden', isStats);
  stats.classList.toggle('hidden', !isStats);
  document.querySelectorAll('#tabs .tab').forEach((x) => {
    const on = (x.dataset.panel || 'chat') === name;
    x.classList.toggle('tabActive', on);
  });
  if (isStats) renderStats();
}

document.querySelectorAll('#tabs .tab').forEach((tb) => {
  tb.addEventListener('click', () => switchPanel(tb.dataset.panel || 'chat'));
});

/* ---------------- 统计渲染 ---------------- */
const STAT_LABELS = {
  REQUEST: '用户提问', RESPONSE: 'AI 回复', THINK: '思考过程',
  SEARCH: '搜索', TOOL_OPEN: '工具调用', TOOL_SEARCH: '工具搜索', FILE: '文件',
};
const STAT_ICONS = {
  REQUEST: '✍️', RESPONSE: '🤖', THINK: '💭', SEARCH: '🔍',
  TOOL_OPEN: '🛠', TOOL_SEARCH: '🔎', FILE: '📎',
};

function renderStats() {
  const id = state.currentId;
  const messages = state.byId.get(id) || [];
  const item = state.list.find((i) => i.id === id);

  const esc = escapeHtml;
  const fmt = (n) => n.toLocaleString('zh-CN');

  // 分类统计
  const byType = {};
  const byModel = {};
  let userChars = 0, aiChars = 0, thinkChars = 0, totalChars = 0;
  let firstTs = null, lastTs = null;
  const dayBuckets = {};   // 按日期统计消息数（用于简单柱状图）
  const hourBuckets = Array(24).fill(0);

  for (const m of messages) {
    const t = m.t || 'RESPONSE';
    byType[t] = (byType[t] || 0) + 1;
    if (m.m) byModel[m.m] = (byModel[m.m] || 0) + 1;
    const len = (m.c || '').length;
    totalChars += len;
    if (t === 'REQUEST') userChars += len;
    else if (t === 'RESPONSE') aiChars += len;
    else if (t === 'THINK') thinkChars += len;

    if (m.d) {
      const dt = new Date(m.d);
      if (!isNaN(dt.getTime())) {
        if (!firstTs || dt.getTime() < firstTs) firstTs = dt.getTime();
        if (!lastTs || dt.getTime() > lastTs) lastTs = dt.getTime();
        const day = dt.toISOString().slice(0, 10);
        dayBuckets[day] = (dayBuckets[day] || 0) + 1;
        hourBuckets[dt.getHours()] = (hourBuckets[dt.getHours()] || 0) + 1;
      }
    }
  }

  const typeOrder = ['REQUEST', 'RESPONSE', 'THINK', 'SEARCH', 'TOOL_OPEN', 'TOOL_SEARCH', 'FILE'];
  const typeRows = typeOrder
    .filter((t) => byType[t])
    .map((t) => `
      <div class="statRow">
        <span class="statLabel">${STAT_ICONS[t]} ${STAT_LABELS[t]}</span>
        <span class="statBarWrap"><span class="statBar" style="width:${Math.min(100, Math.round((byType[t] / messages.length) * 100))}%"></span></span>
        <span class="statNum">${fmt(byType[t])}</span>
      </div>`)
    .join('');

  const modelRows = Object.entries(byModel)
    .sort((a, b) => b[1] - a[1])
    .map(([k, v]) => `<div class="statRow"><span class="statLabel">${esc(k)}</span><span class="statBarWrap"><span class="statBar" style="width:${Math.min(100, Math.round((v / messages.length) * 100))}%"></span></span><span class="statNum">${fmt(v)}</span></div>`)
    .join('');

  // 最近 14 天柱状图（简单条形，无第三方库）
  const days = Object.keys(dayBuckets).sort();
  const recentDays = days.slice(-14);
  const maxDay = Math.max(1, ...recentDays.map((d) => dayBuckets[d]));
  const barChart = recentDays.length
    ? `<div class="chartBars">${recentDays.map((d) => {
        const h = Math.max(4, Math.round((dayBuckets[d] / maxDay) * 60));
        return `<div class="chartCol" title="${esc(d)} · ${dayBuckets[d]} 条">
          <div class="chartBar" style="height:${h}px"></div>
          <div class="chartDay">${esc(d.slice(5))}</div>
        </div>`;
      }).join('')}</div>`
    : '<p class="statEmpty">暂无时间数据</p>';

  // 时段分布（0-23 点，取主要活跃时段）
  const peakHour = hourBuckets.reduce((a, b, i) => (hourBuckets[i] > hourBuckets[a] ? i : a), 0);
  const activeHours = hourBuckets
    .map((c, h) => ({ h, c }))
    .filter((x) => x.c > 0)
    .sort((a, b) => b.c - a.c)
    .slice(0, 3)
    .map((x) => `${x.h} 点`)
    .join('、');

  const duration = (firstTs && lastTs) ? Math.round((lastTs - firstTs) / 60000) : 0;
  const durationText = firstTs && lastTs
    ? (duration < 60 ? `${duration} 分钟` : `${Math.floor(duration / 60)} 小时 ${duration % 60} 分钟`)
    : '—';

  const html = `
    <div class="statsWrap">
      <h3 class="statsTitle">📊 对话统计</h3>

      <div class="statCards">
        <div class="statCard"><div class="statCardNum">${fmt(messages.length)}</div><div class="statCardLabel">消息总数</div></div>
        <div class="statCard"><div class="statCardNum">${fmt(userChars)}</div><div class="statCardLabel">用户字符</div></div>
        <div class="statCard"><div class="statCardNum">${fmt(aiChars)}</div><div class="statCardLabel">AI 字符</div></div>
        <div class="statCard"><div class="statCardNum">${fmt(totalChars)}</div><div class="statCardLabel">总字符</div></div>
      </div>

      <div class="statSection">
        <div class="statSectionTitle">消息构成</div>
        ${typeRows || '<p class="statEmpty">无消息</p>'}
      </div>

      ${modelRows ? `<div class="statSection">
        <div class="statSectionTitle">模型</div>
        ${modelRows}
      </div>` : ''}

      <div class="statSection">
        <div class="statSectionTitle">近 14 天活跃度</div>
        ${barChart}
      </div>

      <div class="statSection">
        <div class="statSectionTitle">其他信息</div>
        <div class="statRow"><span class="statLabel">⏱ 对话跨度</span><span class="statNum">${durationText}</span></div>
        <div class="statRow"><span class="statLabel">🌙 最活跃时段</span><span class="statNum">${activeHours || '—'}</span></div>
        <div class="statRow"><span class="statLabel">🧠 思考字符</span><span class="statNum">${fmt(thinkChars)}</span></div>
        <div class="statRow"><span class="statLabel">📅 创建</span><span class="statNum">${item && item.inserted_at ? esc(fmtDate(item.inserted_at)) : '—'}</span></div>
        <div class="statRow"><span class="statLabel">🔄 更新</span><span class="statNum">${item && item.updated_at ? esc(fmtDate(item.updated_at)) : '—'}</span></div>
      </div>
    </div>`;

  $('#statsContent').innerHTML = html;
}

/* 代码块复制 */
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.md-code-block-copy');
  if (!btn) return;
  const pre = btn.closest('.md-code-block')?.querySelector('pre');
  if (!pre) return;
  navigator.clipboard?.writeText(pre.textContent || '')
    .then(() => {
      const old = btn.textContent;
      btn.textContent = '已复制';
      setTimeout(() => { btn.textContent = old; }, 1500);
    })
    .catch(() => {});
});

/* 移动端：点击标题回到列表 */
$('#brand').addEventListener('click', () => app.classList.remove('viewing'));

/* ---------------- 主题（官方系统设置弹窗内的三段选择器） ---------------- */
const settingsBtn = $('#settingsBtn');
const settingsOverlay = $('#settingsOverlay');
const settingsRoot = $('#settingsRoot');
const themeBtns = settingsRoot ? [...settingsRoot.querySelectorAll('._6c1fed6')] : [];

function themeModeOf(btn) {
  const t = ((btn.querySelector('.ds-button__content') || {}).textContent || '').trim();
  if (t === '浅色') return 'light';
  if (t === '深色') return 'dark';
  return 'system';
}

function prefersDark() {
  return !!window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function applyTheme(mode) {
  const dark = mode === 'dark' || (mode === 'system' && prefersDark());
  document.body.toggleAttribute('data-ds-dark-theme', dark);
}

function setThemeMode(mode) {
  localStorage.setItem('dscview-theme', mode);
  applyTheme(mode);
  themeBtns.forEach((b) => {
    const active = themeModeOf(b) === mode;
    b.classList.toggle('_16a7dbe', active);
    b.style.setProperty('--dsl-button-color', active ? 'var(--dsw-specific-selector)' : '');
  });
}

const savedMode = localStorage.getItem('dscview-theme');
setThemeMode(['light', 'dark', 'system'].includes(savedMode) ? savedMode : 'system');

themeBtns.forEach((b) => b.addEventListener('click', () => setThemeMode(themeModeOf(b))));

if (window.matchMedia) {
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    const mode = localStorage.getItem('dscview-theme') || 'system';
    if (mode === 'system') applyTheme('system');
  });
}

/* ---------------- 设置弹窗（官方系统设置） ---------------- */
function openSettings() {
  settingsOverlay.classList.remove('hidden');
  settingsRoot.classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}
function closeSettings() {
  settingsOverlay.classList.add('hidden');
  settingsRoot.classList.add('hidden');
  document.body.style.overflow = '';
}

if (settingsBtn) settingsBtn.addEventListener('click', openSettings);
if (settingsOverlay) settingsOverlay.addEventListener('click', closeSettings);
if (settingsRoot) {
  const closeBtn = settingsRoot.querySelector('.ds-modal-content__close');
  if (closeBtn) closeBtn.addEventListener('click', closeSettings);
  // 左侧导航 tab 切换
  settingsRoot.querySelectorAll('.b40079d7 ._266abb8').forEach((nb) => {
    nb.addEventListener('click', () => {
      settingsRoot.querySelectorAll('.b40079d7 ._266abb8').forEach((x) => x.classList.remove('_699d482'));
      nb.classList.add('_699d482');
    });
  });
}

/* ---------------- init ---------------- */
show('dropzone');
