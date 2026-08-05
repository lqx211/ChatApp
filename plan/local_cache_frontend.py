#!/usr/bin/env python3
"""Frontend: chat.php JS vars + settings UI; chat.js cache module."""

# ---- 1. chat.php: inject CACHE_KEY/LOCAL_CACHE + settings UI ----
p = '/Volumes/Server/ChatApp/modern/chat.php'
s = open(p, encoding='utf-8').read()

# a) Inject JS vars before MYUID
old_js = "var MYUID=<?php echo (int)($currentUser['user_id'] ?? 0);?>;"
new_js = """var CACHE_KEY='<?php echo htmlspecialchars($currentUser['cache_key'] ?? '', ENT_QUOTES);?>';
var LOCAL_CACHE=<?php echo (int)($currentUser['local_cache_enabled'] ?? 0);?>;
var MYUID=<?php echo (int)($currentUser['user_id'] ?? 0);?>;"""
assert old_js in s, "MYUID anchor not found"
s = s.replace(old_js, new_js, 1)

# b) Settings UI: add local cache section right after data-saver section
old_ui = """    <div class="ss"><h3><?php echo t('title_data_saver');?></h3><div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="dataSaver" <?php echo ($currentUser['data_saver']??0)?'checked':'';?> onchange="toggleDataSaver()" style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_data_saver_label');?></label></div></div>"""
new_ui = """    <div class="ss"><h3><?php echo t('title_data_saver');?></h3><div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="dataSaver" <?php echo ($currentUser['data_saver']??0)?'checked':'';?> onchange="toggleDataSaver()" style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_data_saver_label');?></label></div></div>
    <div class="ss"><h3><?php echo t('title_local_cache');?></h3>
     <div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="localCacheToggle" <?php echo ($currentUser['local_cache_enabled']??0)?'checked':'';?> onchange="toggleLocalCache()" style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_local_cache_label');?></label></div>
     <div class="fg"><button class="bsm" onclick="clearLocalCache()" style="color:#e0a040"><?php echo t('btn_clear_local_cache');?></button></div>
    </div>"""
assert old_ui in s, "data-saver UI not found"
s = s.replace(old_ui, new_ui, 1)

open(p, 'w', encoding='utf-8').write(s)
print('OK chat.php')

# ---- 2. chat.js: cache module + integration ----
p = '/Volumes/Server/ChatApp/modern/chat.js'
s = open(p, encoding='utf-8').read()

# Add cache module before "// ================= Wallpaper" section
cache_module = """
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
        var tx = lcTxn('readonly');
        var req = tx.get(key);
        req.onsuccess = function() {
            var row = req.result;
            if (!row) return resolve(null);
            lcDecrypt(row).then(resolve).catch(function() { resolve(null); });
        };
        req.onerror = function() { resolve(null); };
    });
}

function lcDeleteChannel(key) {
    if (!LC_READY) return Promise.resolve();
    return new Promise(function(resolve) {
        try {
            var tx = lcTxn('readwrite');
            tx.delete(key);
            tx.oncomplete = resolve;
            tx.onerror = resolve;
        } catch (e) { resolve(); }
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
    if (!LOCAL_CACHE || !CACHE_KEY) { LC_READY = false; return; }
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
            if (!have[newMsgs[j].id] && newMsgs[j].id) {
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

// Hook: revoke messages also update cache
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

/*
 * Integration points (invoked from existing code below):
 *  - lcInit()              -> called at startup (after DOM ready)
 *  - lcPersistMsg(key,m)   -> called in pm() / addDmMessage new-poll path
 *  - lcPersistBatch(key,newMsgs)
 *  - lcLoadChannel(key)    -> DM history loader uses this for instant open
 */
"""
anchor = "// ================= Wallpaper (custom background) ================="
assert anchor in s, "wallpaper anchor not found"
s = s.replace(anchor, cache_module + anchor, 1)

# Hook lcInit() after window load (initialLoad at bottom already runs; call after contacts load)
old_boot = "initialLoad();"
assert old_boot in s, "boot anchor not found"
s = s.replace(old_boot, old_boot + "\nsetTimeout(lcInit, 500);", 1)

# pm(): persist new announcements + DMs
old_pm = """            for (var i = 0; i < d.messages.length; i++) {
                var m = d.messages[i];
                if (!m.recipient) addAnnouncement(m);
                if (D && m.recipient && ((m.username === U && m.recipient === D) || (m.username === D && m.recipient === U))) addDmMessage(m);"""
new_pm = """            for (var i = 0; i < d.messages.length; i++) {
                var m = d.messages[i];
                if (!m.recipient) { addAnnouncement(m); lcPersistMsg('announcement', m); }
                if (D && m.recipient && ((m.username === U && m.recipient === D) || (m.username === D && m.recipient === U))) { addDmMessage(m); lcPersistMsg('dm_' + D, m); }"""
if old_pm not in s:
    print('WARN: pm() hook not found (may already be modified)')
else:
    s = s.replace(old_pm, new_pm, 1)

# loadDmMessages(): render cache instantly before network
old_ldm = """async function loadDmMessages(before) {
    if (!D) return;
    try {
        var url = '../api/chat.php?action=all&limit=50&dm=' + encodeURIComponent(D);"""
new_ldm = """async function loadDmMessages(before) {
    if (!D) return;
    if (!before) {
        lcLoadChannel('dm_' + D).then(function(msgs) {
            if (msgs && msgs.length > 0) {
                var area = document.getElementById('dmMessagesArea');
                if (area && !area.querySelector('[data-msgid]')) {
                    for (var i = 0; i < msgs.length; i++) {
                        var m = msgs[i];
                        if (m && m.id && !m.is_deleted) {
                            delete seenMsgIds['dm_' + m.id];
                            addDmMessage(m);
                        }
                    }
                    var a = document.getElementById('dmMessagesArea');
                    if (a) scrollChatToBottom(a);
                }
            }
        }).catch(function() {});
    }
    try {
        var url = '../api/chat.php?action=all&limit=50&dm=' + encodeURIComponent(D);"""
if old_ldm not in s:
    print('WARN: loadDmMessages() hook not found')
else:
    s = s.replace(old_ldm, new_ldm, 1)

# loadDmMessages(): persist fetched messages
old_dml = """            for (var i = 0; i < d.messages.length; i++) {
                var m = d.messages[i];
                if (m.id > maxId) maxId = m.id;
                if (m.recipient && ((m.username === U && m.recipient === D) || (m.username === D && m.recipient === U))) {
                    delete seenMsgIds['dm_' + m.id];
                    addDmMessage(m, !!before)
                }
            }"""
new_dml = """            for (var i = 0; i < d.messages.length; i++) {
                var m = d.messages[i];
                if (m.id > maxId) maxId = m.id;
                if (m.recipient && ((m.username === U && m.recipient === D) || (m.username === D && m.recipient === U))) {
                    delete seenMsgIds['dm_' + m.id];
                    addDmMessage(m, !!before)
                }
            }
            lcPersistBatch('dm_' + D, d.messages);"""
if old_dml not in s:
    print('WARN: loadDmMessages() persist hook not found')
else:
    s = s.replace(old_dml, new_dml, 1)

# loadGroupMessages(): persist group messages
old_grp = """    if (d.messages.length > 0) {
        for (var i = 0; i < d.messages.length; i++) {
            var m = d.messages[i];
            if (m.id > _glast) _glast = m.id;
            delete seenMsgIds['dm_' + m.id];
            addDmMessage(m);
        }
        var a = document.getElementById('dmMessagesArea');
        if (a) scrollChatToBottom(a);
    }"""
new_grp = """    if (d.messages.length > 0) {
        for (var i = 0; i < d.messages.length; i++) {
            var m = d.messages[i];
            if (m.id > _glast) _glast = m.id;
            delete seenMsgIds['dm_' + m.id];
            addDmMessage(m);
            lcPersistMsg('group_' + gid, m);
        }
        var a = document.getElementById('dmMessagesArea');
        if (a) scrollChatToBottom(a);
    }"""
if old_grp not in s:
    print('WARN: loadGroupMessages() persist hook not found')
else:
    s = s.replace(old_grp, new_grp, 1)

# openGroupChat(): load cache before network
old_ogc = """function openGroupChat(gid, gname) {
    fetch('../api/group.php?action=history&group_id=' + gid).then(function(r) {
        return r.json()
    }).then(function(d) {
        if (!d.success) return;"""
new_ogc = """function openGroupChat(gid, gname) {
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
        if (!d.success) return;"""
if old_ogc not in s:
    print('WARN: openGroupChat() cache hook not found')
else:
    s = s.replace(old_ogc, new_ogc, 1)

open(p, 'w', encoding='utf-8').write(s)
print('OK chat.js')
print('ALL DONE')