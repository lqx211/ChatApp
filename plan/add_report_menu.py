#!/usr/bin/env python3
# Add "Report" (举报) menu item below "Add emoji" in message context menu
p = '/Volumes/Server/ChatApp/modern/chat.js'
s = open(p, encoding='utf-8').read()

# 1) Add reportMenuItem definition after each emojiMenuItem definition
old_def = """    var emojiMenuItem = emojiCode ? '<div class="msg-emoji-add" data-emoji-code="' + eh(emojiCode) + '">' + T('menu_add_emoji') + '</div>' : '';
"""
new_def = """    var emojiMenuItem = emojiCode ? '<div class="msg-emoji-add" data-emoji-code="' + eh(emojiCode) + '">' + T('menu_add_emoji') + '</div>' : '';
    var reportMenuItem = '<div class="msg-report" onclick="reportMsgFromMenu(this,\\'' + m.username + '\\');closeAllMsgMenus()">' + T('menu_report') + '</div>';
"""
if old_def not in s:
    print('ERROR: emojiMenuItem definition not found')
    raise SystemExit(1)
s = s.replace(old_def, new_def)

# 2) Insert reportMenuItem into non-flash context menus (DM + Announcement, own & other)
#    These come right before the revoke item, i.e. BELOW "add emoji".
s = s.replace("+ emojiMenuItem + '<div onclick=\"revokeDmMessage", "+ emojiMenuItem + reportMenuItem + '<div onclick=\"revokeDmMessage")
s = s.replace("+ emojiMenuItem + '<div style=\"color:#555;cursor:not-allowed\">' + T('menu_revoke') + '</div></div>';",
              "+ emojiMenuItem + reportMenuItem + '<div style=\"color:#555;cursor:not-allowed\">' + T('menu_revoke') + '</div></div>';")
s = s.replace("+ emojiMenuItem + '<div onclick=\"revokeAnnouncement", "+ emojiMenuItem + reportMenuItem + '<div onclick=\"revokeAnnouncement")

# 3) Flash (temp) message context menus: add report at end (after revoke item)
s = s.replace("+ revokeItem + '</div>';", "+ revokeItem + reportMenuItem + '</div>';")

# 4) Add reportMsgFromMenu() function before reportDmUser()
func = """
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

function reportDmUser() {"""
if 'function reportDmUser() {' not in s:
    print('ERROR: reportDmUser not found')
    raise SystemExit(1)
s = s.replace('function reportDmUser() {', func, 1)

open(p, 'w', encoding='utf-8').write(s)
print('OK chat.js updated')

# 5) CSS: yellow report item below "add emoji" style
c = '/Volumes/Server/ChatApp/modern/chat.css'
css = open(c, encoding='utf-8').read()
old_css = ".msg-menu .msg-emoji-add { color: #7bdcff; }"
new_css = """.msg-menu .msg-emoji-add { color: #7bdcff; }
.msg-menu .msg-report { color: #e0a040; }
.msg-menu .msg-report:hover { background: #3a3a3a; }"""
if old_css not in css:
    print('ERROR: CSS anchor not found')
    raise SystemExit(1)
css = css.replace(old_css, new_css, 1)
open(c, 'w', encoding='utf-8').write(css)
print('OK chat.css updated')