/**
 * ChatApp — WebSocket 客户端（配合 wss/wss_server.php）
 *
 * 功能:
 *   1. 从 api/ws_token.php 获取一次性 token
 *   2. 建立 wss 连接（WSS_URL 由 chat.php 注入）
 *   3. 60s 心跳 + 游标上报（l/glast/groups）
 *   4. 接收服务端推送:
 *      - msg       → 公告/私聊消息渲染
 *      - group_msg → 群消息渲染（当前打开的群）
 *      - presence  → 好友上线/下线实时更新
 *      - typing    → 打字指示器
 *   5. request/response：POST 移植 WSS（send/revoke/mark_read/unread_counts）
 *   6. 断线指数退避自动重连（断线时清空所有 pending 请求，由 chat.js 降级 HTTP）
 *
 * 依赖 chat.js 全局变量: L, _glast, G, D, U, unreadCounts, updateUnreads,
 *   addAnnouncement, addDmMessage（均已在 chat.js 定义）
 */
(function() {
    'use strict';

    var WS_STATE = 'idle';   // idle | connecting | open | closed
    var ws = null;
    var heartbeatTimer = null;
    var reconnectTimer = null;
    var reconnectDelay = 1500;   // 指数退避基础
    var TOKEN = '';
    var myGroupsCache = [];
    var typingHideTimer = null;

    // ---- request/response 通信（POST 移植 WSS） ----
    var _reqId = 0;
    var _pendingReqs = {};   // id => {resolve, reject, timer}

    function _reqTimeout(id) {
        var p = _pendingReqs[id];
        if (!p) return;
        clearTimeout(p.timer);
        delete _pendingReqs[id];
        p.reject(new Error('WSS_REQ_TIMEOUT'));
    }

    function _reqRejectAll(err) {
        for (var id in _pendingReqs) {
            if (Object.prototype.hasOwnProperty.call(_pendingReqs, id)) {
                var p = _pendingReqs[id];
                clearTimeout(p.timer);
                p.reject(err);
            }
        }
        _pendingReqs = {};
    }

    /* ---------------- token ---------------- */
    function fetchToken() {
        return fetch('../api/ws_token.php?action=issue', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success && d.token) {
                    TOKEN = d.token;
                    return TOKEN;
                }
                throw new Error('token issue failed');
            });
    }

    /* ---------------- 群组列表（心跳上报） ---------------- */
    function fetchMyGroups() {
        return fetch('../api/group.php?action=list_my', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                myGroupsCache = (d.success && d.groups) ? d.groups.map(function(g) { return g.group_id; }) : [];
            })
            .catch(function() { myGroupsCache = []; });
    }

    /* ---------------- 心跳 ---------------- */
    function sendHeartbeat() {
        if (!ws || ws.readyState !== WebSocket.OPEN) return;
        var payload = {
            type: 'ping',
            l: (typeof L !== 'undefined' && L) ? L : 0,
            glast: (typeof _glast !== 'undefined' && _glast) ? _glast : 0,
            groups: myGroupsCache
        };
        try { ws.send(JSON.stringify(payload)); } catch (e) {}
    }

    /* ---------------- 在线状态 UI ---------------- */
    function setOnlineStatus(username, cls) {
        var items = document.querySelectorAll('.csi[data-cuser]');
        for (var i = 0; i < items.length; i++) {
            if (items[i].getAttribute('data-cuser') === username) {
                var cn = items[i].querySelector('.cn');
                if (cn) {
                    cn.classList.remove('on', 'dnd', 'rstr', 'off');
                    if (cls) cn.classList.add(cls);
                }
            }
        }
    }

    /* ---------------- 消息处理 ---------------- */
    function handleMessage(ev) {
        var d;
        try { d = JSON.parse(ev.data); } catch (e) { return; }
        if (!d || !d.type) return;

        switch (d.type) {

            case 'pong':
                // 心跳确认（不做事）
                break;

            case 'response':
                // request/response：POST 操作经 WSS 的回执
                var rid = d.id;
                if (rid !== undefined && _pendingReqs[rid]) {
                    var p = _pendingReqs[rid];
                    clearTimeout(p.timer);
                    delete _pendingReqs[rid];
                    p.resolve(d);
                }
                break;

            case 'msg':
                // 公告 + 私聊新消息
                if (d.messages && d.messages.length) {
                    for (var i = 0; i < d.messages.length; i++) {
                        var m = d.messages[i];
                        if (!m || m.username === U) continue;
                        if (!m.recipient) {
                            // 公告
                            if (typeof addAnnouncement === 'function') addAnnouncement(m);
                            if (typeof window.notifyNewMessage === 'function') window.notifyNewMessage(m);
                        } else if (D && (m.username === D || m.recipient === D)) {
                            // 当前打开的私聊
                            if (typeof addDmMessage === 'function') addDmMessage(m);
                        } else {
                            // 其他私聊：未读数 + 提醒
                            if (!unreadCounts[m.username]) unreadCounts[m.username] = 0;
                            unreadCounts[m.username]++;
                            if (typeof window.notifyNewMessage === 'function') window.notifyNewMessage(m);
                        }
                    }
                    if (typeof updateUnreads === 'function') updateUnreads();
                }
                if (d.latest_id && d.latest_id > L) L = d.latest_id;
                break;

            case 'group_msg':
                // 群消息（当前打开的群才渲染；未读群暂不强制）
                if (d.messages && d.messages.length) {
                    for (var j = 0; j < d.messages.length; j++) {
                        var gm = d.messages[j];
                        if (!gm || gm.username === U) continue;
                        if (G && gm.group_id && gm.group_id === G && typeof addDmMessage === 'function') {
                            addDmMessage(gm);
                        }
                    }
                }
                if (d.glast && d.glast > _glast) _glast = d.glast;
                break;

            case 'presence':
                // 好友上下线实时更新
                if (d.online) {
                    for (var un in d.online) {
                        if (Object.prototype.hasOwnProperty.call(d.online, un)) setOnlineStatus(un, 'on');
                    }
                }
                if (d.offline) {
                    for (var k = 0; k < d.offline.length; k++) setOnlineStatus(d.offline[k], 'off');
                }
                break;

            case 'typing':
                // 打字指示器（仅当前对话对象）
                if (d.from && D === d.from) {
                    var ti = document.getElementById('typingIndicator');
                    if (ti) {
                        ti.style.display = 'block';
                        ti.textContent = d.from + ' is typing...';
                        if (typingHideTimer) clearTimeout(typingHideTimer);
                        typingHideTimer = setTimeout(function() {
                            ti.style.display = 'none';
                        }, 3000);
                    }
                }
                break;

            case 'temp_status':
                // 闪传状态推送（替代前端每 2s HTTP 轮询 of api/temp.php?action=status）
                // 由 chat.js 暴露的 window.updateTempCardFromPush(stateEl, item) 负责更新 UI
                if (d.items && d.items.length && typeof window.updateTempCardFromPush === 'function') {
                    for (var ti2 = 0; ti2 < d.items.length; ti2++) {
                        var item = d.items[ti2];
                        var cards = document.querySelectorAll('.flash-status.flash-state[data-temp="' + item.id + '"]');
                        for (var c = 0; c < cards.length; c++) {
                            window.updateTempCardFromPush(cards[c], item);
                        }
                    }
                }
                break;
        }
    }

    /* ---------------- 连接管理 ---------------- */
    function scheduleReconnect() {
        if (reconnectTimer) return;
        reconnectTimer = setTimeout(function() {
            reconnectTimer = null;
            reconnectDelay = Math.min(reconnectDelay * 2, 30000);
            connect();
        }, reconnectDelay);
    }

    function connect() {
        if (WS_STATE === 'connecting' || WS_STATE === 'open') return;
        if (!window.WebSocket) return;
        if (!window.WSS_URL) return;
        WS_STATE = 'connecting';

        fetchToken().then(function() {
            var url = WSS_URL + '/?token=' + TOKEN;
            try {
                ws = new WebSocket(url);
            } catch (e) {
                WS_STATE = 'closed';
                scheduleReconnect();
                return;
            }

            ws.onopen = function() {
                WS_STATE = 'open';
                reconnectDelay = 1500;
                // 连接建立后立即同步游标 + 心跳
                fetchMyGroups().then(function() {
                    sendHeartbeat();
                });
                sendHeartbeat();
                if (heartbeatTimer) clearInterval(heartbeatTimer);
                heartbeatTimer = setInterval(sendHeartbeat, 60000);
                if (window.console) console.log('[WSS] 已连接 ' + WSS_URL);
            };

            ws.onmessage = handleMessage;

            ws.onclose = function() {
                WS_STATE = 'closed';
                if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null; }
                _reqRejectAll(new Error('WSS_CLOSED'));
                if (window.console) console.log('[WSS] 连接断开，准备重连');
                scheduleReconnect();
            };

            ws.onerror = function() {
                // onclose 会触发重连
            };
        }).catch(function() {
            WS_STATE = 'closed';
            scheduleReconnect();
        });
    }

    /* ---------------- 对外入口 ---------------- */
    window.wssInit = function() {
        if (!window.WebSocket || !window.WSS_URL) return;
        // 等 initialLoad 完成（L 游标就绪）再连接，3s 兜底
        setTimeout(function() {
            connect();
        }, 3000);
    };

    // 创建/加入群后调用刷新群列表（下次心跳上报）
    window.wssRefreshGroups = function() {
        fetchMyGroups();
    };

    // 发送打字指示（chat.js onDmInput 调用；WS 不可用时由 HTTP 兜底）
    window.wssSendTyping = function(to) {
        // 「我的输入状态可见」关闭时不发送
        if (typeof window.TYPING_VIS !== 'undefined' && !window.TYPING_VIS) return false;
        if (WS_STATE !== 'open' || !ws || ws.readyState !== WebSocket.OPEN) return false;
        try {
            ws.send(JSON.stringify({ type: 'typing', to: to }));
            return true;
        } catch (e) { return false; }
    };

    /**
     * 经 WSS 发起一次请求（POST 移植核心接口）。
     * 返回 Promise，resolve 服务端 response JSON，reject 表示 WSS 不可用/超时/断线。
     * chat.js 的 apiRequest() 据此决定是否降级 HTTP。
     *
     * @param {string} action  action 名（send/revoke/mark_read/unread_counts）
     * @param {Object} params  参数对象（与 HTTP POST body 同字段）
     * @param {number} [timeoutMs=3000] 超时毫秒
     */
    window.wssRequest = function(action, params, timeoutMs) {
        timeoutMs = timeoutMs || 3000;
        if (WS_STATE !== 'open' || !ws || ws.readyState !== WebSocket.OPEN) {
            return Promise.reject(new Error('WSS_NOT_OPEN'));
        }
        _reqId++;
        var id = _reqId;
        return new Promise(function(resolve, reject) {
            var payload = { type: 'request', id: id, action: action, params: params || {} };
            try {
                ws.send(JSON.stringify(payload));
            } catch (e) {
                reject(new Error('WSS_SEND_FAIL'));
                return;
            }
            _pendingReqs[id] = {
                resolve: resolve,
                reject: reject,
                timer: setTimeout(function() { _reqTimeout(id); }, timeoutMs)
            };
        });
    };

    /** WSS request 通道是否可用 */
    window.wssRequestAvailable = function() {
        return WS_STATE === 'open' && !!ws && ws.readyState === WebSocket.OPEN;
    };

    // 调试
    window.wssStatus = function() { return WS_STATE; };
})();