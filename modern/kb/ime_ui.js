/* ============================================================
   ChatApp 拼音输入法 UI
   候选条（Win8.1 风格深色）+ 中/EN 切换 + 输入框组合管理
   组合键在捕获阶段拦截，优先于 Enter 发送，避免冲突。
   ============================================================ */
var ImePinyinUI = (function () {
    var _on = false;
    var _input = null;
    var _py = '';
    var _cands = [];
    var _sel = 0;
    var _bar = null;
    var _tog = null;

    // 平台检测（mac / win / cros / linux / other），用于系统快捷键判定
    var _os = (function () {
        var ua = navigator.userAgent || '';
        if (/Macintosh|Mac OS X|iPhone|iPad|iPod/i.test(ua)) return 'mac';
        if (/CrOS|Chromium OS/i.test(ua)) return 'cros';
        if (/Windows/i.test(ua)) return 'win';
        if (/Linux/i.test(ua)) return 'linux';
        return 'other';
    })();

    // 中文标点转换表（拼音模式开启时，按键自动转全角标点）
    var PUNCT = {
        ',': '，', '.': '。', ';': '；', ':': '：', '?': '？',
        '/': '、', '\\': '、', '`': '·',
        '<': '《', '>': '》',
        '{': '「', '}': '」',        // Shift+[ / Shift+]
        '_': '——'                  // Shift+-
    };
    function chinesePunct(k) {
        return Object.prototype.hasOwnProperty.call(PUNCT, k) ? PUNCT[k] : null;
    }

    function attach(input, toggleId) {
        if (!input) return;
        _input = input;

        // 候选条
        _bar = document.createElement('div');
        _bar.id = 'imeCandBar';
        _bar.style.display = 'none';
        document.body.appendChild(_bar);

        // 切换按钮
        _tog = toggleId ? document.getElementById(toggleId) : null;
        if (_tog) {
            _tog.onclick = function () { toggle(); };
            _tog.textContent = 'EN';
        }

        // 捕获阶段 keydown（优先于 Enter 发送）
        input.addEventListener('keydown', onKeydown, true);
        input.addEventListener('blur', function () { endCompose(); });
        input.addEventListener('scroll', positionBar);
        window.addEventListener('scroll', positionBar, true);
        window.addEventListener('resize', positionBar);
    }

    function toggle() {
        _on = !_on;
        if (!_on) endCompose();
        if (_tog) {
            _tog.textContent = _on ? '中' : 'EN';
            _tog.classList.toggle('ime-active', _on);
        }
        _input.focus();
    }
    function isOn() { return _on; }

    function onKeydown(e) {
        if (!_on) return;
        var k = e.key || '';

        // 系统快捷键放行：Cmd(Mac)/Ctrl(Win/CrOS/Linux) 组合 = 复制/粘贴/全选/撤销等，
        // 不拦截、不转拼音，交给浏览器/系统处理（如 Cmd+C 复制、Ctrl+V 粘贴、Cmd+A 全选）
        if (e.metaKey || e.ctrlKey) {
            return;
        }

        if (_py.length > 0) {
            // 字母 → 继续拼音
            if (/^[a-z]$/i.test(k)) {
                e.preventDefault(); e.stopImmediatePropagation();
                _py += k.toLowerCase();
                refresh();
                return;
            }
            // 撇号 ' → 音节分隔符（xi'an、w'q），参与组合，不打断
            if (k === "'") {
                e.preventDefault(); e.stopImmediatePropagation();
                if (_py.charAt(_py.length - 1) !== "'") _py += "'";
                refresh();
                return;
            }
            // 数字 1-9 → 选候选
            if (/^[1-9]$/.test(k)) {
                e.preventDefault(); e.stopImmediatePropagation();
                var i = parseInt(k, 10) - 1;
                if (_cands[i]) commit(_cands[i].word);
                return;
            }
            // 空格 → 上屏首选
            if (k === ' ') {
                e.preventDefault(); e.stopImmediatePropagation();
                commit(_cands[0] ? _cands[0].word : _py.replace(/'/g, ''));
                return;
            }
            // Enter → 直接输入拼音原文（无 '），不是选候选
            if (k === 'Enter') {
                e.preventDefault(); e.stopImmediatePropagation();
                commit(_py.replace(/'/g, ''));
                return;
            }
            // Backspace → 删拼音
            if (k === 'Backspace') {
                e.preventDefault(); e.stopImmediatePropagation();
                _py = _py.slice(0, -1);
                if (_py.length === 0) endCompose(); else refresh();
                return;
            }
            // Esc → 取消组合
            if (k === 'Escape') {
                e.preventDefault(); e.stopImmediatePropagation();
                endCompose();
                return;
            }
            // 其它可见字符（标点）：先上屏候选，再插入（中文标点转换）
            if (k.length === 1) {
                e.preventDefault(); e.stopImmediatePropagation();
                var w = _cands[_sel] ? _cands[_sel].word : _py.replace(/'/g, '');
                _py = ''; _sel = 0; hideBar();
                insertText(w);
                insertText(chinesePunct(k) || k);
                return;
            }
        } else if (_on) {
            // 未组合时：字母开始组合；标点转中文
            if (/^[a-z]$/i.test(k)) {
                e.preventDefault(); e.stopImmediatePropagation();
                _py = k.toLowerCase();
                refresh();
            } else if (k.length === 1) {
                var p = chinesePunct(k);
                if (p) {
                    e.preventDefault(); e.stopImmediatePropagation();
                    insertText(p);
                }
            }
        }
    }

    /* ---------- 候选 ---------- */
    function refresh() {
        if (!_on || !_py) { hideBar(); return; }
        loadLearning(); // 首次使用时加载用户习惯
        if (!ImePinyin.isReady()) {
            // 首次使用懒加载 8MB 词典
            ImePinyin.load(function (ok) { if (ok && _py) doRefresh(); });
            return;
        }
        doRefresh();
    }
    function doRefresh() {
        _cands = ImePinyin.decode(_py, 9);
        _sel = 0;
        // 无论是否有候选都显示候选框：没有候选就只显示拼音预览（不显示 1-9）
        renderBar();
        positionBar();
    }
    function renderBar() {
        // 顶部拼音预览（音节用 ' 分隔，如 wo'xian'zai'hen'kai'xin）
        var preview = ImePinyin.segment(_py).join("'") || _py;
        var html = '<div class="ime-bar-preview">' + esc(preview) + '</div>';
        // 有候选才显示编号候选行
        if (_cands.length) {
            html += '<div class="ime-bar-cands">';
            for (var i = 0; i < _cands.length; i++) {
                html += '<div class="ime-cand' + (i === _sel ? ' sel' : '') + '" data-i="' + i + '">'
                    + '<span class="ime-cand-num">' + (i + 1) + '</span>'
                    + esc(_cands[i].word)
                    + '</div>';
            }
            html += '</div>';
        }
        _bar.innerHTML = html;
        _bar.style.display = 'block';
        var items = _bar.querySelectorAll('.ime-cand');
        for (var j = 0; j < items.length; j++) {
            (function (el, idx) {
                el.onclick = function () { commit(_cands[idx].word); };
            })(items[j], j);
        }
    }
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function positionBar() {
        if (!_bar || _bar.style.display === 'none' || !_input) return;
        var r = _input.getBoundingClientRect();
        var bw = _bar.offsetWidth;
        var left = r.left + Math.min(0, (window.innerWidth - bw - 8 - r.left));
        _bar.style.left = Math.max(8, left) + 'px';
        _bar.style.top = (r.top - _bar.offsetHeight - 6) + 'px';
    }

    /* ---------- 上屏 ---------- */
    function commit(word) {
        var py = _py;
        _py = ''; _cands = []; _sel = 0;
        hideBar();
        insertText(word);
        recordHabit(word, py); // 记录用户习惯
        _input.focus();
    }

    /* ---------- 用户习惯：加载（首次） + 记录（提交时） ---------- */
    var _learnLoaded = false;
    function loadLearning() {
        if (_learnLoaded) return;
        _learnLoaded = true;
        try {
            fetch('../../api/ime.php?action=learned').then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.success && d.items && ImePinyin.setLearning) ImePinyin.setLearning(d.items);
            }).catch(function () {});
        } catch (e) {}
    }
    function recordHabit(word, pinyin) {
        if (!word || !ImePinyin || !ImePinyin.bumpLearning) return;
        ImePinyin.bumpLearning(word, pinyin); // 本会话即时生效
        try {
            fetch('../../api/ime.php?action=record', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'word=' + encodeURIComponent(word) + '&pinyin=' + encodeURIComponent(pinyin || '')
            }).catch(function () {});
        } catch (e) {}
    }
    function insertText(text) {
        var t = _input;
        var s = t.selectionStart === null ? t.value.length : t.selectionStart;
        var e = t.selectionEnd === null ? t.value.length : t.selectionEnd;
        var nv = t.value.slice(0, s) + text + t.value.slice(e);
        if (t.maxLength > 0 && nv.length > t.maxLength) nv = nv.slice(0, t.maxLength);
        t.value = nv;
        var pos = s + text.length;
        if (t.setSelectionRange) t.setSelectionRange(pos, pos);
        // 触发既有输入处理（自适应高度 + 输入回调 + md 预览）
        if (typeof autoResize === 'function') autoResize(t);
        var id = t.id;
        if (id === 'dmMessageInput' && typeof onDmInput === 'function') onDmInput();
        if (id === 'messageInput' && typeof onAnnInput === 'function') onAnnInput();
        if (typeof onMdInput === 'function') {
            var mdId = id === 'dmMessageInput' ? 'mdCheckDm' : 'mdCheckAnn';
            var prevId = id === 'dmMessageInput' ? 'mdPreviewDm' : 'mdPreviewAnn';
            var ck = document.getElementById(mdId);
            if (ck && ck.checked) onMdInput(prevId, id, mdId);
        }
    }
    function endCompose() {
        _py = ''; _cands = []; _sel = 0;
        hideBar();
    }
    function hideBar() {
        if (_bar) _bar.style.display = 'none';
    }

    return { attach: attach, toggle: toggle, isOn: isOn, os: _os };
})();
