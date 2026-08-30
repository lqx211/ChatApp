/* =====================================================================
   ChatApp Mobile UI — QQ 风格手机端逻辑
   v1: 会话列表 / 联系人 / 动态(公告) / 我的 + 聊天窗口（HTTP 轮询实时）
   ===================================================================== */
(function () {
    'use strict';

    var LANG = window.LANG || {};
    var ME = window.M_USER || {};
    function t(k, d) { return LANG[k] || d || k; }
    function $(id) { return document.getElementById(id); }

    /* ---------------- 通用 ---------------- */
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function apiGet(path) {
        return fetch(path, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { success: false }; });
    }
    function letterAvatar(name) {
        return esc((name || '?').charAt(0).toUpperCase());
    }
    function listAvatarHTML(u) {
        if (u && u.avatar) return '<img class="li-avatar" src="' + esc(u.avatar) + '" alt="">';
        return '<div class="li-avatar">' + letterAvatar(u ? (u.display_name || u.username) : '') + '</div>';
    }
    // 解析消息时间（新版 time=UNIX 秒；旧版 'Y/m/d H:i:s' 或 'Y-m-d H:i:s'）→ 友好显示
    function fmtTime(dt) {
        if (!dt && dt !== 0) return '';
        var d;
        if (typeof dt === 'number' || /^\d{9,11}$/.test(String(dt).trim())) {
            d = new Date(Number(dt) * 1000);
        } else {
            var m = String(dt).match(/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})[ T](\d{1,2}):(\d{2})/);
            if (!m) return String(dt);
            d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
        }
        var now = new Date();
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var that = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        var diffDays = Math.round((today - that) / 86400000);
        if (diffDays === 0) return p(d.getHours()) + ':' + p(d.getMinutes());
        if (diffDays === 1) return '昨天';
        if (d.getFullYear() === now.getFullYear()) return (d.getMonth() + 1) + '/' + d.getDate();
        return d.getFullYear() + '/' + (d.getMonth() + 1) + '/' + d.getDate();
    }

    /* ---------------- Tab 切换 ---------------- */
    var SCREENS = { msg: 'screenMsg', contacts: 'screenContacts', discover: 'screenDiscover', me: 'screenMe' };
    var tabs = document.querySelectorAll('#tabbar .tab');

    function toast(msg) {
        var el = $('mToast');
        el.textContent = msg;
        el.classList.add('show');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { el.classList.remove('show'); }, 2200);
    }
    function showSheet(id) {
        var s = $(id);
        if (s) s.style.display = 'block';
    }
    function hideSheet(id) {
        var s = $(id);
        if (s) s.style.display = 'none';
    }
    function closeSheets() {
        hideSheet('chatMenuSheet');
        hideSheet('quickSheet');
    }

    /* ---------------- 表情渲染 ---------------- */
    var _emojiBuiltin = [];
    function loadEmojiList() {
        apiGet('../../api/emoji.php?action=list').then(function (d) {
            if (d && d.success && d.emojis) {
                _emojiBuiltin = d.emojis;
                // 列表到达后重渲染可见内容（首屏可能先于接口渲染）
                if (allConvs && allConvs.length) renderConvs();
                if (currentPartner) loadChatHistory();
                if (emojiPanelOpen && !$('emojiGridBuiltin').children.length) renderBuiltinEmojis();
            }
        });
    }
    loadEmojiList();
    function renderEmoji(text) {
        // 自定义表情 [emoji:hash] 不依赖内置列表，始终解析
        text = text.replace(/\[emoji:([a-f0-9]{32})\]/g, function (m, h) {
            return '<img src="../../api/emoji.php?action=img&hash=' + h + '" class="chat-emoji chat-emoji-custom" alt="">';
        });
        if (!_emojiBuiltin.length) return text;
        for (var i = 0; i < _emojiBuiltin.length; i++) {
            var e = _emojiBuiltin[i];
            if (e.img && e.code && text.indexOf(e.code) >= 0) {
                text = text.split(e.code).join('<img src="../../' + e.img + '" class="chat-emoji chat-emoji-builtin" alt="">');
            }
        }
        return text;
    }

    /* ---------------- 全屏 iframe 页面（资料/设置等） ---------------- */
    function openFrame(url, title) {
        $('frameContent').src = url;
        $('frameTitle').textContent = title || '';
        $('frameOverlay').style.display = 'flex';
    }
    function closeFrame() {
        $('frameOverlay').style.display = 'none';
        $('frameContent').src = 'about:blank';
    }
    $('frameBackBtn').addEventListener('click', closeFrame);
    window.MOB = { openFrame: openFrame, closeFrame: closeFrame, showTab: showTab };
    // profile.php 等页面内部调用 parent.closeMyProfile() 返回 → 移动端即关闭全屏框
    window.closeMyProfile = function () { closeFrame(); };

    /* ---------------- 表情选择器 ---------------- */
    var emojiPanelOpen = false;
    function openEmojiPanel() {
        emojiPanelOpen = true;
        $('emojiPanel').style.display = 'flex';
        if (!_emojiBuiltin.length) loadEmojiList();
        if (!$('emojiGridBuiltin').children.length && _emojiBuiltin.length) renderBuiltinEmojis();
        if (!$('emojiGridCustom').children.length) loadCustomEmojis();
    }
    function closeEmojiPanel() {
        emojiPanelOpen = false;
        $('emojiPanel').style.display = 'none';
    }
    function renderBuiltinEmojis() {
        var box = $('emojiGridBuiltin');
        var html = '';
        for (var i = 0; i < _emojiBuiltin.length; i++) {
            var e = _emojiBuiltin[i];
            if (!e.img || !e.code) continue;
            html += '<img src="../../' + e.img + '" data-code="' + esc(e.code) + '" alt="">';
        }
        box.innerHTML = html;
        [].forEach.call(box.querySelectorAll('img'), function (im) {
            im.addEventListener('click', function () { insertEmoji(this.getAttribute('data-code')); });
        });
    }
    function loadCustomEmojis() {
        apiGet('../../api/emoji.php?action=my').then(function (d) {
            var list = (d && d.custom) || [];
            var box = $('emojiGridCustom');
            if (!list.length) { box.innerHTML = '<div class="empty" style="grid-column:1/-1">' + t('m_no_emoji', 'No custom emojis') + '</div>'; return; }
            var html = '';
            for (var i = 0; i < list.length; i++) {
                var c = list[i];
                html += '<img src="../../api/emoji.php?action=img&hash=' + c.hash + '" data-code="[emoji:' + c.hash + ']" alt="">';
            }
            box.innerHTML = html;
            [].forEach.call(box.querySelectorAll('img'), function (im) {
                im.addEventListener('click', function () { insertEmoji(this.getAttribute('data-code')); });
            });
        });
    }
    function insertEmoji(code) {
        var inp = $('chatInput');
        inp.value += code;
        inp.focus();
    }
    $('chatEmojiBtn').addEventListener('click', function () {
        if (emojiPanelOpen) closeEmojiPanel(); else openEmojiPanel();
    });
    $('emojiTabBuiltin').addEventListener('click', function () {
        this.classList.add('active'); $('emojiTabCustom').classList.remove('active');
        $('emojiGridBuiltin').style.display = 'grid'; $('emojiGridCustom').style.display = 'none';
    });
    $('emojiTabCustom').addEventListener('click', function () {
        this.classList.add('active'); $('emojiTabBuiltin').classList.remove('active');
        $('emojiGridBuiltin').style.display = 'none'; $('emojiGridCustom').style.display = 'grid';
        if (!$('emojiGridCustom').children.length) loadCustomEmojis();
    });

    function showTab(name) {
        for (var k in SCREENS) $(SCREENS[k]).style.display = (k === name) ? 'flex' : 'none';
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-tab') === name);
        }
        if (name === 'msg') loadConversations();
        if (name === 'contacts') { loadContacts(); loadGroups(); }
        if (name === 'discover') loadDiscover();
    }
    [].forEach.call(tabs, function (btn) {
        btn.addEventListener('click', function () { showTab(btn.getAttribute('data-tab')); });
    });

    /* ---------------- 会话列表 ---------------- */
    var allConvs = [];
    var convFilter = '';
    function convLastText(c) {
        if (c.last_type === 'file') return '[' + (c.attachment_name || t('m_file')) + ']';
        if (c.last_type === 'temp') return '[' + t('m_flash') + ']';
        if (c.last_type === 'image') return '[图片]';
        return c.last_message || '';
    }
    function loadConversations() {
        apiGet('../../api/chat.php?action=conversations').then(function (d) {
            allConvs = (d && d.conversations) || [];
            renderConvs();
        });
    }
    function renderConvs() {
        var box = $('conversationList');
        var q = convFilter.trim().toLowerCase();
        var list = allConvs.filter(function (c) {
            if (!q) return true;
            return (c.display_name || '').toLowerCase().indexOf(q) >= 0 || (c.username || '').toLowerCase().indexOf(q) >= 0;
        });
        if (!list.length) {
            box.innerHTML = '<div class="empty">' + (allConvs.length ? t('m_no_match', 'No matching conversations') : t('m_no_conversations', 'No conversations yet')) + '</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < list.length; i++) {
            var c = list[i];
            var un = c.unread ? '<span class="li-badge">' + (c.unread > 99 ? '99+' : c.unread) + '</span>' : '';
            html += '<div class="li" data-u="' + esc(c.username) + '" data-name="' + esc(c.display_name) + '">'
                + listAvatarHTML(c)
                + '<div class="li-main">'
                + '<div class="li-row"><span class="li-name">' + esc(c.display_name) + '</span><span class="li-time">' + esc(fmtTime(c.last_datetime)) + '</span></div>'
                + '<div class="li-msg">' + renderEmoji(esc(convLastText(c))) + '</div>'
                + '</div>' + un + '</div>';
        }
        box.innerHTML = html;
        bindListClicks(box);
    }

    /* ---------------- 消息页头部交互 ---------------- */
    var hdrAvatarBtn = $('hdrAvatarBtn');
    // 点头像 → 打开自己的个人主页（随处可点头像进自己主页）
    function openSelfProfile() {
        openFrame('profile.php?user=' + encodeURIComponent(ME.username), ME.display_name || ME.username);
    }
    if (hdrAvatarBtn) hdrAvatarBtn.addEventListener('click', openSelfProfile);
    var hdrPlusBtn = $('hdrPlusBtn');
    if (hdrPlusBtn) hdrPlusBtn.addEventListener('click', function () { showSheet('quickSheet'); });
    var msgSearchInput = $('msgSearchInput');
    if (msgSearchInput) msgSearchInput.addEventListener('input', function () {
        convFilter = this.value;
        renderConvs();
    });

    /* ---------------- 「+」快捷入口 ---------------- */
    $('quickCancel').addEventListener('click', function () { hideSheet('quickSheet'); });
    $('quickNewGroup').addEventListener('click', function () { closeSheets(); createGroup(); });
    /* App 内输入弹窗（移动端不支持原生 prompt()） */
    var _inputCb = null;
    function openInputModal(title, placeholder, cb) {
        $('inputTitle').textContent = title;
        $('inputField').placeholder = placeholder || '';
        $('inputField').value = '';
        _inputCb = cb;
        $('inputOverlay').style.display = 'flex';
        setTimeout(function () { $('inputField').focus(); }, 120);
    }
    $('inputCancel').addEventListener('click', function () { $('inputOverlay').style.display = 'none'; _inputCb = null; });
    $('inputOk').addEventListener('click', function () {
        var v = $('inputField').value.trim();
        var cb = _inputCb;
        _inputCb = null;
        $('inputOverlay').style.display = 'none';
        if (cb && v) cb(v);
    });
    $('inputField').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); $('inputOk').click(); }
    });
    function createGroup() {
        openInputModal(t('m_group_name', 'Group name'), t('m_group_name', 'Group name'), function (name) {
            var f = new URLSearchParams();
            f.append('action', 'create');
            f.append('name', name);
            fetch('../../api/group.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.success) { toast(t('m_group_created', 'Group created')); showTab('contacts'); loadGroups(); }
                    else toast((d && d.error) || t('m_send_fail', 'Failed'));
                })
                .catch(function () { toast(t('m_send_fail', 'Failed')); });
        });
    }
    $('quickScan').addEventListener('click', function () { closeSheets(); toast(t('m_coming_soon', '敬请期待')); });
    $('quickQr').addEventListener('click', function () { closeSheets(); toast(t('m_coming_soon', '敬请期待')); });
    $('quickAddFriend').addEventListener('click', openAddFriend);

    /* ---------------- 添加好友 ---------------- */
    function openAddFriend() {
        closeSheets();
        $('afSearchInput').value = '';
        $('afSearchResult').innerHTML = '';
        $('afOverlay').style.display = 'flex';
        setTimeout(function () { $('afSearchInput').focus(); }, 120);
    }
    $('afClose').addEventListener('click', function () { $('afOverlay').style.display = 'none'; });
    $('afSearchInput').addEventListener('input', searchAF);
    function searchAF() {
        var q = $('afSearchInput').value.trim();
        var box = $('afSearchResult');
        if (!q) { box.innerHTML = ''; return; }
        apiGet('../../api/contacts.php?action=search&q=' + encodeURIComponent(q)).then(function (d) {
            var users = (d && d.users) || [];
            if (!users.length) { box.innerHTML = '<div class="af-empty">' + t('m_no_user', '未找到用户') + '</div>'; return; }
            var html = '';
            users.forEach(function (u) {
                var rel = u.relation;
                var btnLabel, done = false;
                if (rel === 'accepted') { btnLabel = t('m_already_friends', '已是好友'); done = true; }
                else if (rel === 'pending') { btnLabel = t('m_request_pending', '已发送请求'); done = true; }
                else { btnLabel = t('m_add', '添加'); }
                html += '<div class="af-user"><span class="af-av">' + letterAvatar(u.username)
                    + '<img src="../../api/avatar.php?u=' + esc(u.username) + '" onerror="this.remove()">'
                    + '</span><div class="af-info"><div class="af-name">' + esc(u.username) + '</div><div class="af-sub">' + u.user_id + '</div></div>'
                    + '<button class="af-btn' + (done ? ' done' : '') + '" data-u="' + esc(u.username) + '"' + (done ? ' disabled' : '') + '>' + btnLabel + '</button></div>';
            });
            box.innerHTML = html;
            [].forEach.call(box.querySelectorAll('.af-btn:not(.done)'), function (b) {
                b.addEventListener('click', function () { sendAF(b); });
            });
        });
    }
    function sendAF(btn) {
        var u = btn.getAttribute('data-u');
        var f = new URLSearchParams();
        f.append('action', 'send_request');
        f.append('username', u);
        f.append('msg', '');
        fetch('../../api/contacts.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success) {
                    btn.classList.add('done'); btn.textContent = t('m_request_pending', '已发送请求');
                    toast(t('m_sent', '已发送好友请求'));
                } else {
                    toast((d && d.error) || t('m_send_fail', '发送失败'));
                }
            });
    }

    /* ---------------- 群组 ---------------- */
    var myGroups = [];
    function loadGroups() {
        apiGet('../../api/group.php?action=list_my').then(function (d) {
            myGroups = (d && d.groups) || [];
            renderGroups();
        });
    }
    function renderGroups() {
        var box = $('groupList');
        if (!box) return;
        if (!myGroups.length) { box.innerHTML = '<div class="empty">' + t('m_no_groups', 'No groups yet') + '</div>'; return; }
        var html = '';
        for (var i = 0; i < myGroups.length; i++) {
            var g = myGroups[i];
            var av = g.avatar_url
                ? '<img class="li-avatar" src="' + esc(g.avatar_url) + '" alt="">'
                : '<div class="li-avatar li-group-av">' + letterAvatar(g.name) + '</div>';
            var roleTxt = g.role === 'owner' ? ' <span style="color:#c0a020;font-size:.7em">[群主]</span>' : (g.role === 'admin' ? ' <span style="color:#6fb3e8;font-size:.7em">[管理员]</span>' : '');
            html += '<div class="li" data-gid="' + g.group_id + '" data-name="' + esc(g.name) + '">'
                + av
                + '<div class="li-main"><div class="li-name">' + esc(g.name) + roleTxt + '</div></div>'
                + '<span style="color:var(--m-sub);font-size:14px">›</span></div>';
        }
        box.innerHTML = html;
        [].forEach.call(box.querySelectorAll('.li[data-gid]'), function (el) {
            el.addEventListener('click', function () {
                openGroupChat(parseInt(el.getAttribute('data-gid'), 10), el.getAttribute('data-name'));
            });
        });
    }

    /* ---------------- 联系人 ---------------- */
    function loadContacts() {
        apiGet('../../api/contacts.php?action=list').then(function (d) {
            var list = (d && d.contacts) || [];
            var box = $('contactList');
            if (!list.length) { box.innerHTML = '<div class="empty">' + t('m_no_contacts', 'No contacts yet') + '</div>'; return; }
            var html = '';
            for (var i = 0; i < list.length; i++) {
                var c = list[i];
                var name = c.note || c.display_name || c.username;
                html += '<div class="li" data-u="' + esc(c.username) + '" data-name="' + esc(name) + '">'
                    + listAvatarHTML(c)
                    + '<div class="li-main"><div class="li-name">' + esc(name) + '</div></div>'
                    + '<span style="color:var(--m-sub);font-size:14px">›</span></div>';
            }
            box.innerHTML = html;
            // 联系人点击 → 直接打开私聊（与桌面 chat.php 一致）；聊天头部点头像可进其主页
            [].forEach.call(box.querySelectorAll('.li[data-u]'), function (el) {
                el.addEventListener('click', function () {
                    openChat(el.getAttribute('data-u'), el.getAttribute('data-name') || el.getAttribute('data-u'));
                });
            });
        });
    }

    /* ---------------- 动态（公告） ---------------- */
    function loadDiscover() {
        apiGet('../../api/chat.php?action=all&limit=30').then(function (d) {
            var msgs = (d && d.messages) || [];
            var box = $('discoverList');
            var ann = [];
            for (var i = 0; i < msgs.length; i++) {
                var m = msgs[i];
                if (!m.is_deleted && m.recipient === null && !m.group_id) ann.push(m);
            }
            if (!ann.length) { box.innerHTML = '<div class="empty">' + t('m_no_announcements', 'Nothing here yet') + '</div>'; return; }
            var html = '';
            for (var j = 0; j < ann.length; j++) {
                var a = ann[j];
                html += '<div class="li"><div class="li-main">'
                    + '<div class="li-row"><span class="li-name">' + esc(a.display_name || a.username || 'System') + '</span><span class="li-time">' + esc(fmtTime(a.time)) + '</span></div>'
                    + '<div class="li-msg" style="white-space:normal;padding-right:0">' + (a.is_markdown ? renderMd(a.message) : esc(a.message)) + '</div>'
                    + '</div></div>';
            }
            box.innerHTML = html;
        });
    }

    /* ---------------- 列表点击 → 聊天 ---------------- */
    function bindListClicks(box) {
        var items = box.querySelectorAll('.li[data-u]');
        [].forEach.call(items, function (el) {
            el.addEventListener('click', function () {
                openChat(el.getAttribute('data-u'), el.getAttribute('data-name') || el.getAttribute('data-u'));
            });
        });
    }

    /* ---------------- 聊天窗口 ---------------- */
    var currentPartner = null;
    var currentGroupId = 0;   // >0 = 群聊模式
    var lastMsgId = 0;
    var beforeId = 0;
    var hasMore = true;
    var loadingMore = false;
    var sending = false;
    var pollTimer = null;
    var convTimer = null;
    var lastRenderedTime = 0;   // 用于消息时间分隔（unix 秒）
    var seenRendered = {};      // 已渲染的消息 id（去重，防并发拉取重复）

    function openChat(username, displayName, avatar) {
        currentPartner = { username: username, display_name: displayName, avatar: avatar || '' };
        currentGroupId = 0;
        showChatScreen(displayName || username, currentPartner.avatar || ('../../api/avatar.php?u=' + encodeURIComponent(username)));
        loadChatHistory();
        markRead(username);
        startPolling();
    }
    function openGroupChat(gid, name) {
        currentGroupId = gid;
        currentPartner = { username: '', display_name: name, avatar: '' };
        showChatScreen(name, '');
        loadChatHistory();
        startPolling();
    }
    function showChatScreen(title, avatarUrl) {
        $('chatTitle').textContent = title;
        $('chatHdrStatus').textContent = t('msg_online_status');
        var av = $('chatHdrAvatar');
        av.onerror = function () { av.style.display = 'none'; };
        if (avatarUrl) { av.src = avatarUrl; av.style.display = 'block'; }
        else { av.style.display = 'none'; }
        closeEmojiPanel();
        $('chatBody').innerHTML = '<div class="empty">' + t('msg_loading') + '</div>';
        $('chatScreen').style.display = 'flex';
        lastMsgId = 0; beforeId = 0; hasMore = true; lastRenderedTime = 0;
    }
    function closeChat() {
        stopPolling();
        closeEmojiPanel();
        currentPartner = null;
        currentGroupId = 0;
        $('chatScreen').style.display = 'none';
        loadConversations();
    }
    $('chatBackBtn').addEventListener('click', closeChat);
    // 点头像/聊天气泡头像 → 查看资料（全屏 iframe）
    $('chatHdrAvatar').addEventListener('click', function () {
        if (!currentPartner || currentGroupId) return;
        openFrame('profile.php?user=' + encodeURIComponent(currentPartner.username), currentPartner.display_name || currentPartner.username);
    });
    $('chatBody').addEventListener('click', function (e) {
        var av = e.target.closest('.msg .av');
        if (av && av.getAttribute('data-u')) {
            openFrame('profile.php?user=' + encodeURIComponent(av.getAttribute('data-u')), '');
        }
    });
    // 聊天更多菜单
    $('chatMoreBtn').addEventListener('click', function () { showSheet('chatMenuSheet'); });
    $('menuSheetCancel').addEventListener('click', function () { hideSheet('chatMenuSheet'); });
    $('menuViewProfile').addEventListener('click', function () {
        if (!currentPartner || currentGroupId) return;
        hideSheet('chatMenuSheet');
        openFrame('profile.php?user=' + encodeURIComponent(currentPartner.username), currentPartner.display_name || currentPartner.username);
    });
    $('menuClearHistory').addEventListener('click', function () {
        hideSheet('chatMenuSheet');
        toast(t('m_coming_soon', '敬请期待'));
    });

    function chatQuery() {
        if (currentGroupId) return 'group_id=' + currentGroupId;
        return 'dm=' + encodeURIComponent(currentPartner.username);
    }
    function loadChatHistory() {
        if (!currentPartner) return;
        var url = currentGroupId
            ? '../../api/group.php?action=history&' + chatQuery() + '&limit=50'
            : '../../api/chat.php?action=all&' + chatQuery() + '&limit=50';
        apiGet(url).then(function (d) {
            var msgs = (d && d.messages) || [];
            $('chatBody').innerHTML = '';
            if (d && d.has_more) {
                beforeId = d.oldest_id || 0;
                hasMore = true;
            } else { hasMore = false; }
            renderMessages(msgs, 'replace');
            if (msgs.length) lastMsgId = msgs[msgs.length - 1].id;
            scrollChatBottom();
        });
    }
    function loadMore() {
        if (!currentPartner || loadingMore || !hasMore || !beforeId) return;
        loadingMore = true;
        var url = currentGroupId
            ? '../../api/group.php?action=history&' + chatQuery() + '&before=' + beforeId + '&limit=50'
            : '../../api/chat.php?action=all&' + chatQuery() + '&before=' + beforeId + '&limit=50';
        apiGet(url).then(function (d) {
            loadingMore = false;
            var msgs = (d && d.messages) || [];
            if (!msgs.length) { hasMore = false; return; }
            var wrap = $('chatBody');
            var prevH = wrap.scrollHeight;
            // 删除"加载更多"按钮，插到最前
            var moreBtn = wrap.querySelector('.chat-loadmore');
            if (moreBtn) moreBtn.remove();
            beforeId = msgs[0].id;
            hasMore = !!(d && d.has_more);
            renderMessages(msgs, 'prepend');
            wrap.scrollTop = wrap.scrollHeight - prevH;
        });
    }

    function renderMessages(msgs, mode) {
        // mode: 'replace'（全量重建）| 'append'（新消息追加底部）| 'prepend'（更早消息插顶部）
        var wrap = $('chatBody');
        var chunks = [];
        var prev = (mode === 'prepend') ? 0 : lastRenderedTime;
        for (var i = 0; i < msgs.length; i++) {
            var m = msgs[i];
            if (mode !== 'replace' && m.id && seenRendered[m.id]) continue;
            if (mode !== 'replace' && m.id) seenRendered[m.id] = 1;
            var tSec = m.time ? (typeof m.time === 'number' || /^\d{9,11}$/.test(String(m.time).trim()) ? Number(m.time) : (Date.parse(String(m.time).replace(/\//g, '-').replace(' ', 'T')) / 1000)) : 0;
            if (isNaN(tSec)) tSec = 0;
            if (!prev || Math.abs(tSec - prev) > 300) {
                chunks.push('<div class="msg-time">' + esc(fmtTime(m.time)) + '</div>');
            }
            if (tSec) prev = tSec;
            var mine = (m.sender_id === ME.uid) || (m.username === ME.username);
            var gSender = (currentGroupId && !mine) ? (m.display_name || m.username || '') : '';
            chunks.push('<div class="msg ' + (mine ? 'out' : 'in') + '">'
                + (mine
                    ? '<img class="av" data-u="' + esc(ME.username) + '" src="' + (ME.avatar ? esc(ME.avatar) : '') + '" alt="">'
                    : (m.avatar ? '<img class="av" data-u="' + esc(m.username) + '" src="' + esc(m.avatar) + '" alt="">' : '<div class="av" data-u="' + esc(m.username) + '" style="display:flex;align-items:center;justify-content:center;color:#4aa9d8">' + letterAvatar(m.display_name || m.username) + '</div>'))
                + bubbleHTML(m, gSender)
                + '</div>');
        }
        var html = chunks.join('');
        if (mode === 'replace') { wrap.innerHTML = html; }
        else if (mode === 'append') { wrap.insertAdjacentHTML('beforeend', html); }
        else { wrap.insertAdjacentHTML('afterbegin', html); }
        if (mode === 'replace' || mode === 'append') lastRenderedTime = prev;
        if (mode !== 'append' && hasMore && beforeId) {
            var btn = wrap.querySelector('.chat-loadmore');
            if (!btn) {
                var d = document.createElement('div');
                d.className = 'chat-loadmore';
                d.innerHTML = '<button>' + t('m_load_more', 'Load earlier messages') + '</button>';
                d.querySelector('button').addEventListener('click', loadMore);
                wrap.insertBefore(d, wrap.firstChild);
            }
        }
    }

    function bubbleHTML(m, groupSender) {
        if (m.is_deleted) return '<div class="bubble revoked">' + t('m_revoked') + '</div>';
        var body = '';
        if (groupSender) body += '<div class="msg-sender">' + esc(groupSender) + '</div>';
        if (m.attachment_url && m.msg_type !== 'file' && /\.(png|jpe?g|gif|webp)(\?|$)/i.test(m.attachment_url)) {
            body += '<img src="' + esc(m.attachment_url) + '" style="max-width:210px;max-height:260px;border-radius:6px;display:block">';
        } else if (m.msg_type === 'file' || m.attachment_url) {
            body += '<a class="file" href="' + esc(m.attachment_url || '#') + '" target="_blank" rel="noopener"><img src="../../data/res/cil/cil-paperclip.svg" style="width:12px;height:12px;vertical-align:-1px;margin-right:3px;filter:brightness(0) invert(1)" alt=""> ' + esc(m.attachment_name || t('m_file', 'File')) + '</a>';
        } else {
            body += m.is_markdown ? renderMd(m.message) : renderEmoji(esc(m.message || ''));
        }
        return '<div class="bubble">' + body + '</div>';
    }

    function scrollChatBottom() {
        var wrap = $('chatBody');
        wrap.scrollTop = wrap.scrollHeight;
    }

    /* ---------------- 发送（支持附件/多文件队列） ---------------- */
    var pendingAttach = null;      // 当前待发送附件 { data: dataURL, name: filename }
    var attachQueue = [];          // 多文件队列
    function sendMessage() {
        var input = $('chatInput');
        var text = input.value.trim();
        if ((!text && !pendingAttach) || !currentPartner || sending) return;
        sending = true;
        var f = new URLSearchParams();
        var url;
        if (currentGroupId) {
            url = '../../api/group.php';
            f.append('action', 'send');
            f.append('group_id', currentGroupId);
        } else {
            url = '../../api/chat.php';
            f.append('action', 'send');
            f.append('recipient', currentPartner.username);
            f.append('msg_type', '');
        }
        f.append('message', text);
        if (pendingAttach) {
            f.append('attachment', pendingAttach.data);
            if (pendingAttach.name) f.append('filename', pendingAttach.name);
            pendingAttach = null;
        }
        fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                sending = false;
                if (d && d.success) {
                    input.value = '';
                    // 拉取刚发的消息后再发队列中的下一个文件（串行，避免并发乱序）
                    pullNew().catch(function () {}).then(flushAttachQueue);
                } else {
                    input.placeholder = (d && d.error) ? d.error : t('m_send_fail', 'Send failed');
                    flushAttachQueue();
                }
            })
            .catch(function () { sending = false; flushAttachQueue(); });
    }
    $('chatSendBtn').addEventListener('click', sendMessage);
    $('chatInput').addEventListener('keydown', function (e) { if (e.key === 'Enter') sendMessage(); });

    /* 附件：点按钮选文件 → 逐个发送 */
    var chatAttachBtn = $('chatAttachBtn');
    var chatMediaFile = $('chatMediaFile');
    if (chatAttachBtn && chatMediaFile) {
        chatAttachBtn.addEventListener('click', function () {
            if (!currentPartner) { toast(t('m_choose_chat', 'Choose a chat first')); return; }
            chatMediaFile.click();
        });
        chatMediaFile.addEventListener('change', function () {
            var files = Array.prototype.slice.call(chatMediaFile.files || []);
            chatMediaFile.value = '';
            if (!files.length || !currentPartner) return;
            toast(t('m_sending', 'Sending') + ' ' + files.length + ' file(s)');
            files.forEach(function (file) {
                var reader = new FileReader();
                reader.onload = function (ev) {
                    attachQueue.push({ data: ev.target.result, name: file.name });
                    flushAttachQueue();
                };
                reader.readAsDataURL(file);
            });
        });
    }
    function flushAttachQueue() {
        if (sending || !attachQueue.length || !currentPartner) return;
        pendingAttach = attachQueue.shift();
        sendMessage();
    }

    /* ---------------- 实时（HTTP 轮询） ---------------- */
    function pullNew() {
        if (!currentPartner) return Promise.resolve();
        var url = currentGroupId
            ? '../../api/group.php?action=fetch&' + chatQuery() + '&after=' + lastMsgId
            : '../../api/chat.php?action=fetch&after=' + lastMsgId + '&' + chatQuery();
        return apiGet(url).then(function (d) {
            var msgs = (d && d.messages) || [];
            if (!msgs.length) return;
            renderMessages(msgs, 'append');
            if (d.latest_id) lastMsgId = d.latest_id;
            else if (msgs.length) lastMsgId = msgs[msgs.length - 1].id;
            scrollChatBottom();
        });
    }
    function startPolling() {
        stopPolling();
        pollTimer = setInterval(pullNew, 4000);
        convTimer = setInterval(function () { loadConversations(); }, 15000);
    }
    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        if (convTimer) { clearInterval(convTimer); convTimer = null; }
    }

    /* ---------------- 已读 ---------------- */
    function markRead(username) {
        var f = new URLSearchParams();
        f.append('action', 'mark_read');
        f.append('from', username);
        fetch('../../api/chat.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() }).catch(function () {});
    }

    /* ---------------- 退出 ---------------- */
    $('meLogoutBtn').addEventListener('click', function () {
        if (!confirm(t('m_logout_confirm', 'Log out?'))) return;
        var f = new URLSearchParams();
        f.append('action', 'logout');
        fetch('../../api/auth.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
            .then(function () { window.location.href = 'login.php'; });
    });
    // 我的页 → 设置/编辑资料（iframe 全屏，避免整页跳转导致返回黑屏）
    $('meSettingsBtn').addEventListener('click', function () { openFrame('settings.php', t('title_settings')); });
    $('meEditProfileBtn').addEventListener('click', function () { openFrame('editinfo.php', t('set_edit_profile')); });
    // 我的页头像/名字 → 打开自己的个人主页
    var meAvatarEl = document.querySelector('.me-avatar');
    if (meAvatarEl) meAvatarEl.addEventListener('click', openSelfProfile);
    var meNameEl = document.querySelector('.me-name');
    if (meNameEl) meNameEl.addEventListener('click', openSelfProfile);

    /* ---------------- 启动 ---------------- */
    showTab('msg');
})();
