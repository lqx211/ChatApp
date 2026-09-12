/* ============================================================================
 * ChatApp · DeepSeek 应用的「聊天外壳」（apps/deepseek/ui.js）
 * 抄 chat.php 正常用户的那套：透明壁纸背景、表情面板、9 点菜单、涂鸦、图片附件。
 * 不包含任何权限逻辑，只做 UI / 图像处理。
 * ==========================================================================*/
(function (global) {
  'use strict';

  var BG_CACHE_KEY = 'chatapp_bg_v1';
  var state = { attachment: null, emojiCache: null, customEmoji: null, imgCache: {} };

  function $(id) { return document.getElementById(id); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ====================== 壁纸（与 chat.js 同一套缓存/参数） ====================== */
  function bgApply(blur, opacity, hasBg) {
    var bg = $('app-bg');
    if (bg) {
      bg.style.filter = 'blur(' + (parseInt(blur, 10) || 0) + 'px)';
      bg.style.opacity = '1';
    }
    var v = parseInt(opacity, 10);
    if (isNaN(v)) v = 30;
    v = Math.max(0, Math.min(v, 70));
    var alpha = Math.max(0.25, 1 - v / 100);
    var a = hasBg ? alpha : 1;
    var el = document.querySelectorAll('#app-bg-overlay, .ds-wrap .ch, .ds-wrap .cia, .ds-wrap .ma');
    for (var i = 0; i < el.length; i++) {
      if (el[i].id === 'app-bg-overlay') { el[i].style.opacity = '0'; continue; }
      var base = el[i].classList.contains('ma') ? 'rgba(26,26,26,' : (el[i].classList.contains('cia') ? 'rgba(42,42,42,' : 'rgba(42,42,42,');
      el[i].style.background = base + a + ')';
    }
  }
  function initWallpaper() {
    var c = {};
    try { c = JSON.parse(localStorage.getItem(BG_CACHE_KEY) || '{}'); } catch (e) {}
    bgApply(c.blur || 0, c.opacity == null ? 30 : c.opacity, !!c.url);
    fetch('../../api/settings.php?action=get_background').then(function (r) { return r.json(); }).then(function (d) {
      var bg = $('app-bg');
      if (!d || !d.success || !d.url) { if (bg) bg.style.backgroundImage = ''; bgApply(c.blur || 0, c.opacity == null ? 30 : c.opacity, false); return; }
      if (bg) bg.style.backgroundImage = 'url("' + d.url + (d.version ? '&v=' + d.version : '') + '")';
      bgApply(c.blur || 0, c.opacity == null ? 30 : c.opacity, true);
    }).catch(function () {});
  }

  /* ==================================================================
     表情选择器 + 9 点菜单 —— 完全照抄 chat.php / chat.js
       · 同一个 #emojiPopup（markup id/class 与聊天页一致，CSS 直接复用 chat.css）
       · switchEmojiTab 与 EMOJI_PANEL 决定「动态 APNG / 悬停变动态 / 静态 PNG」
       · 内置 + 自定义两个标签，支持上传与删除自定义表情
     ================================================================== */
  var _emojiBuiltin = Array.isArray(global.EMOJI_BUILTIN) ? global.EMOJI_BUILTIN : [], _emojiTarget = null;
  var EMOJI_PANEL = (global.EMOJI_PANEL || 'dynamic');
  var EMOJI_CHAT = (global.EMOJI_CHAT || 'dynamic');

  /* 表情库是异步拉的：拉完通知页面重渲染一遍，否则历史消息会先按纯文本显示 */
  var _emojiListCbs = [];
  function emojiListReady() {
    var cbs = _emojiListCbs;
    _emojiListCbs = [];
    for (var i = 0; i < cbs.length; i++) { try { cbs[i](); } catch (err) {} }
  }
  function onEmojiListReady(cb) {
    if (Array.isArray(_emojiBuiltin) && _emojiBuiltin.length) { try { cb(); } catch (e) {} return; }
    _emojiListCbs.push(cb);
  }

  // 页面已经服务端注入内置表情表（未登录也有）；没注入才回头去拉接口
  (function loadBuiltinEmoji() {
    if (Array.isArray(_emojiBuiltin) && _emojiBuiltin.length) { emojiListReady(); return; }
    fetch('../../api/emoji.php?v=p1&action=list').then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.success && Array.isArray(d.emojis) && d.emojis.length) _emojiBuiltin = d.emojis;
      emojiListReady();
    }).catch(function () { emojiListReady(); });
  })();

  function toggleEmojiPicker(e, targetId) {
    if (e && e.stopPropagation) { e.stopPropagation(); e.preventDefault(); }
    _emojiTarget = targetId;
    var popup = document.getElementById('emojiPopup');
    if (!popup) return;
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
    // 桌面端：自定义表情一排 6 个；内置表情仍一排 8 个
    if (grid) grid.classList.toggle('emoji-custom', tab === 'custom');
    if (tab === 'builtin') {
      if (!Array.isArray(_emojiBuiltin) || _emojiBuiltin.length === 0) {
        fetch('../../api/emoji.php?v=p1&action=list').then(function (r) {
          return r.json();
        }).then(function (d) {
          // 只有拿到东西才递归重画，否则未登录时会变成无休止的请求
          if (d && d.success && Array.isArray(d.emojis) && d.emojis.length) {
            _emojiBuiltin = d.emojis;
            emojiListReady();
            switchEmojiTab('builtin');
          }
        }).catch(function () {});
        return;
      }
      for (var i = 0; i < _emojiBuiltin.length; i++) {
        var e = _emojiBuiltin[i];
        if (e.type === 4) {
          h += '<span class="emoji-item unicode" onclick="insertEmoji(\'' + e.code + '\')" title="' + esc(e.code) + '">' + e.id + '</span>';
        } else if (e.img) {
          var ep = (global.EMOJI_PANEL || 'dynamic');
          var dyn = e.img_dyn ? '../../' + _emojiBuiltin[i].img_dyn : '';
          if (ep === 'dynamic' && dyn) {
            h += '<img src="' + dyn + '" class="emoji-item" onclick="insertEmoji(\'' + e.code + '\')" title="' + esc(e.code) + '">';
          } else if (dyn && ep !== 'static') {
            h += '<img src="../../' + e.img + '" data-dyn="' + dyn + '" class="emoji-item" onclick="insertEmoji(\'' + e.code + '\')" title="' + esc(e.code) + '" onmouseover="if(this.dataset.dyn)this.src=this.dataset.dyn" onmouseout="this.src=\'../../' + e.img + '\'">';
          } else {
            h += '<img src="../../' + e.img + '" class="emoji-item" onclick="insertEmoji(\'' + e.code + '\')" title="' + esc(e.code) + '">';
          }
        }
      }
    } else {
      fetch('../../api/emoji.php?action=my_custom').then(function (r) {
        return r.json();
      }).then(function (d) {
        if (!d.success || !d.custom.length) {
          grid.innerHTML = '<div style="color:#888;font-size:.72em;text-align:center;padding:20px;grid-column:1/-1">还没有自定义表情。<br><button class="bsm" onclick="document.getElementById(\'customEmojiFile\').click()" style="margin-top:8px">+ 上传</button></div>';
          return;
        }
        var h2 = '';
        for (var i = 0; i < d.custom.length; i++) {
          var c = d.custom[i];
          h2 += '<div class="emoji-item-wrap"><img src="../../' + c.img + '" class="emoji-item" onclick="insertEmoji(\'[emoji:' + c.hash + ']\')"><span class="emoji-del" onclick="deleteCustomEmoji(\'' + c.hash + '\')">&times;</span></div>';
        }
        h2 += '<div style="grid-column:1/-1;text-align:center;padding:6px"><button class="bsm" onclick="document.getElementById(\'customEmojiFile\').click()">+ 上传</button></div>';
        grid.innerHTML = h2;
      });
      return;
    }
    grid.innerHTML = h;
  }

  function insertEmoji(code) {
    if (!_emojiTarget) return;
    var el = document.getElementById(_emojiTarget);
    if (!el) return;
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
      reader.onload = function (e) {
        var b64 = e.target.result;
        fetch('../../api/emoji.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=upload&image=' + encodeURIComponent(b64)
        }).then(function (r) { return r.json(); }).then(function () {
          done++;
          if (done >= total) switchEmojiTab('custom');
        }).catch(function () { done++; if (done >= total) switchEmojiTab('custom'); });
      };
      reader.readAsDataURL(f);
    }
    for (var i = 0; i < filesArr.length; i++) uploadOne(filesArr[i]);
  }

  async function deleteCustomEmoji(hash) {
    if (!confirm('删除这个表情？')) return;
    var f = new URLSearchParams();
    f.append('action', 'delete');
    f.append('hash', hash);
    var d = await fetch('../../api/emoji.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: f.toString()
    }).then(function (r) { return r.json(); }).catch(function () { return {}; });
    if (d.success) switchEmojiTab('custom');
    else alert('删除失败');
  }

  // 手机端表情选择器停靠在输入栏下面（给 .ds-wrap 加下内边距，把消息区抬上去）
  function emojiDockMobile() {
    var mc = document.querySelector('.ds-wrap');
    if (mc && window.matchMedia && window.matchMedia('(max-width:768px)').matches) mc.classList.add('emoji-open');
  }
  function emojiUndockMobile() {
    var mc = document.querySelector('.ds-wrap');
    if (mc) mc.classList.remove('emoji-open');
  }

  /* =========================== 9 点菜单（照抄 chat.js） =========================== */
  function toggleDmNineMenu(e, btn) {
    if (e && e.stopPropagation) { e.stopPropagation(); e.preventDefault(); }
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
  function nineEmoji() {
    closeDmNineMenu();
    var popup = document.getElementById('emojiPopup');
    var btn = document.getElementById('dmNineBtn');
    if (!popup || !btn) return;
    if (popup.style.display === 'flex') { popup.style.display = 'none'; emojiUndockMobile(); return; }
    _emojiTarget = 'aiInput';
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
  function nineUpload() {
    closeDmNineMenu();
    var i = document.getElementById('dmMediaFile');
    if (i) i.click();
  }
  function ninePen() {
    closeDmNineMenu();
    openDoodle();
  }

  /* ========================== 图片附件 ========================== */
  function shrinkImage(dataUrl, maxSide, cb) {
    var img = new Image();
    img.onload = function () {
      var w = img.width, h = img.height;
      var scale = 1;
      if (maxSide && Math.max(w, h) > maxSide) scale = maxSide / Math.max(w, h);
      if (scale < 1 || !/^data:image\/(png|webp)/i.test(dataUrl)) {
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(w * scale));
        c.height = Math.max(1, Math.round(h * scale));
        var ctx = c.getContext('2d');
        ctx.drawImage(img, 0, 0, c.width, c.height);
        var out = /^data:image\/(png|webp)/i.test(dataUrl) && scale >= 1 ? dataUrl : c.toDataURL('image/jpeg', 0.86);
        // PNG 且没有缩放 → 保留原图（线条清晰）
        if (/^data:image\/png/i.test(dataUrl) && scale >= 1) out = dataUrl;
        cb(out, c.width, c.height);
      } else cb(dataUrl, w, h);
    };
    img.onerror = function () { cb(null, 0, 0); };
    img.src = dataUrl;
  }
  function setAttachment(dataUrl, name) {
    state.attachment = dataUrl ? { url: dataUrl, name: name || 'image' } : null;
    var chip = $('dsAttachChip');
    if (!chip) return;
    if (!state.attachment) { chip.style.display = 'none'; chip.innerHTML = ''; return; }
    chip.style.display = 'flex';
    chip.innerHTML = '<img src="' + esc(state.attachment.url) + '" alt=""><span>待发送图片（AI 能看图）</span>' +
      '<b class="ds-att-x" title="移除">×</b>';
    chip.querySelector('.ds-att-x').addEventListener('click', function () { setAttachment(null); });
  }
  function getAttachment() { return state.attachment; }

  function pickFile() {
    var f = $('dmMediaFile');
    if (!f) return;
    f.value = '';
    f.click();
  }
  function onFilePicked(ev) {
    var file = ev.target.files && ev.target.files[0];
    if (!file) return;
    if (!/^image\//.test(file.type)) { alert('只能发图片（截图 / 照片 / 表情）'); return; }
    if (file.size > 8 * 1024 * 1024) { alert('图片太大了（最多 8MB）'); return; }
    var fr = new FileReader();
    fr.onload = function () { shrinkImage(String(fr.result), 1280, function (url) { if (url) setAttachment(url, file.name); else alert('图片读取失败'); }); };
    fr.readAsDataURL(file);
  }

  /* ============================ 涂鸦 ============================ */
  var doodle = { strokes: [], cur: null, color: '#ff4d4d', size: 6, eraser: false, undoStack: [] };
  function openDoodle() {
    closeDmNineMenu();
    var ov = $('dsDoodle');
    if (!ov) return;
    ov.style.display = 'block';
    var cv = $('dsDoodleCanvas');
    cv.width = window.innerWidth;
    cv.height = window.innerHeight;
    doodle.strokes = []; doodle.undoStack = []; drawDoodle();
  }
  function closeDoodle() { var ov = $('dsDoodle'); if (ov) ov.style.display = 'none'; }
  function doodleCtx() { var cv = $('dsDoodleCanvas'); return cv ? cv.getContext('2d') : null; }
  function drawDoodle() {
    var ctx = doodleCtx();
    if (!ctx) return;
    var cv = $('dsDoodleCanvas');
    ctx.clearRect(0, 0, cv.width, cv.height);
    ctx.lineCap = ctx.lineJoin = 'round';
    doodle.strokes.forEach(function (s) {
      if (!s.pts.length) return;
      ctx.strokeStyle = s.eraser ? 'rgba(0,0,0,1)' : s.color;
      ctx.globalCompositeOperation = s.eraser ? 'destination-out' : 'source-over';
      ctx.lineWidth = s.eraser ? s.size * 2.5 : s.size;
      ctx.beginPath();
      ctx.moveTo(s.pts[0].x, s.pts[0].y);
      for (var i = 1; i < s.pts.length; i++) ctx.lineTo(s.pts[i].x, s.pts[i].y);
      ctx.stroke();
    });
    ctx.globalCompositeOperation = 'source-over';
  }
  function doodlePoint(ev) {
    var cv = $('dsDoodleCanvas');
    var r = cv.getBoundingClientRect();
    return { x: (ev.clientX - r.left) * (cv.width / r.width), y: (ev.clientY - r.top) * (cv.height / r.height) };
  }
  function bindDoodle() {
    var cv = $('dsDoodleCanvas');
    if (!cv) return;
    cv.addEventListener('pointerdown', function (ev) {
      try { cv.setPointerCapture(ev.pointerId); } catch (e) { /* 合成事件/部分浏览器不支持：忽略即可 */ }
      doodle.cur = { color: doodle.color, size: doodle.size, eraser: doodle.eraser, pts: [doodlePoint(ev)] };
      doodle.strokes.push(doodle.cur);
      drawDoodle();
    });
    cv.addEventListener('pointermove', function (ev) {
      if (!doodle.cur) return;
      doodle.cur.pts.push(doodlePoint(ev));
      drawDoodle();
    });
    var end = function () { if (doodle.cur) { doodle.cur = null; } };
    cv.addEventListener('pointerup', end);
    cv.addEventListener('pointercancel', end);
    cv.addEventListener('pointerleave', end);
    var colors = $('dsDoodleColors');
    if (colors) colors.addEventListener('click', function (ev) {
      var b = ev.target.closest('button');
      if (!b) return;
      doodle.color = b.getAttribute('data-color');
      doodle.eraser = false;
      colors.querySelectorAll('button').forEach(function (x) { x.classList.toggle('active', x === b); });
    });
    var size = $('dsDoodleSize');
    if (size) size.addEventListener('input', function () { doodle.size = parseInt(size.value, 10) || 6; });
  }
  function doodleUndo() { doodle.strokes.pop(); drawDoodle(); }
  function doodleClear() { doodle.strokes = []; drawDoodle(); }
  function doodleExport(cb) {
    var cv = $('dsDoodleCanvas');
    if (!cv || !doodle.strokes.length) { cb(null); return; }
    // 裁掉四周空白：找非透明像素的包围盒
    var ctx = cv.getContext('2d');
    var data = ctx.getImageData(0, 0, cv.width, cv.height).data;
    var minX = cv.width, minY = cv.height, maxX = 0, maxY = 0, found = false;
    for (var y = 0; y < cv.height; y += 2) {
      for (var x = 0; x < cv.width; x += 2) {
        if (data[(y * cv.width + x) * 4 + 3] > 8) {
          found = true;
          if (x < minX) minX = x; if (x > maxX) maxX = x;
          if (y < minY) minY = y; if (y > maxY) maxY = y;
        }
      }
    }
    if (!found) { cb(null); return; }
    var pad = 20;
    minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad);
    maxX = Math.min(cv.width - 1, maxX + pad); maxY = Math.min(cv.height - 1, maxY + pad);
    var out = document.createElement('canvas');
    out.width = maxX - minX + 1; out.height = maxY - minY + 1;
    var octx = out.getContext('2d');
    octx.fillStyle = '#ffffff';                       // 白底，模型看得更清楚
    octx.fillRect(0, 0, out.width, out.height);
    octx.drawImage(cv, minX, minY, out.width, out.height, 0, 0, out.width, out.height);
    setAttachment(out.toDataURL('image/png'), 'doodle.png');
    closeDoodle();
    cb(out.toDataURL('image/png'));
  }

  /* ============ 表情渲染 / 自定义表情转图片（给模型看） ============ */
  /* 模型有时不写代码而直接吐 <img ... data-emoji-code="/斜眼笑">：把这种标签还原成代码，
     再走正常渲染（这样才会按 settings 用 APNG / PNG 渲染） */
  function normalizeEmojiHtml(text) {
    var s = String(text == null ? '' : text);
    if (s.indexOf('<img') < 0) return s;
    return s.replace(/<img\b[^>]*>/gi, function (tag) {
      var m = /data-emoji-code\s*=\s*"([^"]*)"/i.exec(tag);
      if (m) return m[1];
      var m2 = /(?:^|\/)emoji\/s?(\d+)\.(?:png|gif|webp)/i.exec(tag);
      if (m2) {
        for (var i = 0; i < _emojiBuiltin.length; i++) {
          if (String(_emojiBuiltin[i].id) === m2[1]) return _emojiBuiltin[i].code || '';
        }
      }
      return '';   // 认不出来的图片标签直接丢掉，不要以文本形式露出来
    });
  }

  /* 纯文本 → 带表情图的 HTML（用户气泡 / 普通文本用） */
  /* 内置表情代码 → 一次性编译好的正则（长代码优先），列表变了才重建 */
  var _emoReCache = { list: null, len: -1, re: null, map: null };
  function builtinEmojiRegex() {
    if (_emoReCache.list === _emojiBuiltin && _emoReCache.len === (_emojiBuiltin || []).length) return _emoReCache;
    var map = {}, codes = [];
    for (var i = 0; i < _emojiBuiltin.length; i++) {
      var it = _emojiBuiltin[i];
      if (!it || !it.code || !it.img || map[it.code]) continue;
      map[it.code] = it;
      codes.push(it.code);
    }
    codes.sort(function (a, b) { return b.length - a.length; });
    _emoReCache = {
      list: _emojiBuiltin,
      len: _emojiBuiltin.length,
      map: map,
      re: codes.length ? new RegExp(codes.map(function (c) {
        return String(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      }).join('|'), 'g') : null
    };
    return _emoReCache;
  }

  function renderEmoji(text) {
    var out = String(text == null ? '' : text);
    var useDyn = ((global.EMOJI_CHAT || EMOJI_CHAT) === 'dynamic');
    if (Array.isArray(_emojiBuiltin) && _emojiBuiltin.length) {
      // 一次正则扫描完成替换：
      // 不能逐个 split/join —— 后面的 /笑 会钻进前面 /笑哭 生成的 data-emoji-code="/笑哭" 里，把标签拧坏
      var c = builtinEmojiRegex();
      if (c.re) {
        out = out.replace(c.re, function (m) {
          var e2 = c.map[m];
          var src = useDyn && e2.img_dyn ? e2.img_dyn : e2.img;
          return '<img src="../../' + src + '" class="chat-emoji chat-emoji-builtin" data-emoji-code="' + esc(m) + '" alt="' + esc(m) + '">';
        });
      }
    }
    out = out.replace(/\[emoji:([a-f0-9]{32})\]/g, function (m, h) {
      return '<img src="../../api/emoji.php?action=img&hash=' + h + '" class="chat-emoji chat-emoji-custom" data-emoji-code="[emoji:' + h + ']" alt="">';
    });
    return out;
  }

  /* 已渲染好的 HTML → 只对「文本节点」做表情替换（不碰标签属性里的内容）
     renderMd 会先转义 HTML，所以表情必须在这之后再上屏（否则 <img> 会被当成文本） */
  function renderEmojiHtml(html) {
    return String(html == null ? '' : html).replace(/(<[^>]*>)|([^<]+)/g, function (m, tag, text) {
      return tag ? tag : renderEmoji(text);
    });
  }

  /* 把渲染结果贴到元素上：流式输出时每个 chunk 都会重画一遍整段 HTML，
     如果直接把 <img> 重建，动态表情（APNG）会不停重头播 / 闪一下。
     这里把「位置和 src 都对得上」的旧 <img> 节点原样搬过去，图片不重新解码、动画不重开。 */
  function paintHtml(el, html) {
    if (!el) return;
    var old = [].slice.call(el.querySelectorAll('img.chat-emoji'));
    el.innerHTML = html;
    if (!old.length) return;
    var fresh = el.querySelectorAll('img.chat-emoji');
    for (var i = 0; i < fresh.length && i < old.length; i++) {
      if (fresh[i].getAttribute('src') === old[i].getAttribute('src') &&
          fresh[i].getAttribute('data-emoji-code') === old[i].getAttribute('data-emoji-code')) {
        fresh[i].parentNode.replaceChild(old[i], fresh[i]);
      }
    }
  }
  function emojiImages(text) {
    var hash = [], m, re = /\[emoji:([a-f0-9]{32})\]/g;
    while ((m = re.exec(String(text || '')))) if (hash.indexOf(m[1]) < 0) hash.push(m[1]);
    if (!hash.length) return Promise.resolve([]);
    hash = hash.slice(0, 4);
    return Promise.all(hash.map(function (h) {
      if (state.imgCache[h]) return Promise.resolve(state.imgCache[h]);
      return fetch('../../api/emoji.php?action=img&hash=' + h).then(function (r) { return r.blob(); }).then(function (b) {
        return new Promise(function (res) {
          var fr = new FileReader();
          fr.onload = function () { state.imgCache[h] = String(fr.result); res(state.imgCache[h]); };
          fr.onerror = function () { res(null); };
          fr.readAsDataURL(b);
        });
      }).catch(function () { return null; });
    })).then(function (list) { return list.filter(Boolean); });
  }

  function init() {
    if ($('dmMediaFile')) $('dmMediaFile').addEventListener('change', onFilePicked);
    if ($('customEmojiFile')) $('customEmojiFile').addEventListener('change', uploadCustomEmoji);
    if ($('dsDoodleCancel')) $('dsDoodleCancel').addEventListener('click', closeDoodle);
    if ($('dsDoodleUndo')) $('dsDoodleUndo').addEventListener('click', doodleUndo);
    if ($('dsDoodleClear')) $('dsDoodleClear').addEventListener('click', doodleClear);
    if ($('dsDoodleSend')) $('dsDoodleSend').addEventListener('click', function () { doodleExport(function () {}); });
    bindDoodle();
    // 点外面关掉表情面板 / 9 点菜单（与聊天页一致的观感）
    document.addEventListener('click', function (ev) {
      var p = $('emojiPopup'), m = $('dmNineMenu');
      var onEditSave = ev.target.closest && (ev.target.closest('#dmEmojiBtn') || ev.target.closest('#nineEmoji') || ev.target.closest('#emojiPopup'));
      if (p && p.style.display === 'flex' && !onEditSave) { p.style.display = 'none'; emojiUndockMobile(); }
      if (m && m.style.display === 'grid' && !(ev.target.closest && (ev.target.closest('#dmNineBtn') || ev.target.closest('#dmNineMenu')))) m.style.display = 'none';
    });
    document.addEventListener('keydown', function (ev) {
      if (ev.key !== 'Escape') return;
      var p = $('emojiPopup');
      if (p && p.style.display === 'flex') { p.style.display = 'none'; emojiUndockMobile(); _emojiTarget = null; return; }
      closeDmNineMenu();
    });
    initWallpaper();
  }

  /* ============ 暴露成全局函数（markup 里的 onclick 与 chat.php 一致） ============ */
  global.toggleEmojiPicker = toggleEmojiPicker;
  global.switchEmojiTab = switchEmojiTab;
  global.insertEmoji = insertEmoji;
  global.uploadCustomEmoji = uploadCustomEmoji;
  global.deleteCustomEmoji = deleteCustomEmoji;
  global.emojiDockMobile = emojiDockMobile;
  global.emojiUndockMobile = emojiUndockMobile;
  global.toggleDmNineMenu = toggleDmNineMenu;
  global.closeDmNineMenu = closeDmNineMenu;
  global.nineEmoji = nineEmoji;
  global.nineUpload = nineUpload;
  global.ninePen = ninePen;

  global.DSUI = {
    init: init,
    renderEmoji: renderEmoji,
    renderEmojiHtml: renderEmojiHtml,
    paintHtml: paintHtml,
    normalizeEmojiHtml: normalizeEmojiHtml,
    onEmojiListReady: onEmojiListReady,
    emojiImages: emojiImages,
    getAttachment: getAttachment,
    setAttachment: setAttachment,
    shrinkImage: shrinkImage,
    pickFile: pickFile,
    openDoodle: openDoodle
  };
})(window);
