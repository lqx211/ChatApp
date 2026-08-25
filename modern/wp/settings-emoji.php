<?php
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
$u = chatapp_get_user();
$panelMode = $u['emoji_panel_mode'] ?? 'dynamic';
$chatMode  = $u['emoji_chat_mode'] ?? 'dynamic';
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('set_emoji_settings');?></title>
<link rel="stylesheet" href="../../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="../style/settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('set_emoji_settings', 'Emoji Settings');?></span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint"><?php echo t('set_emoji_hint', 'Choose how emoji are displayed in the panel and in chat. Animated emoji are more lively but use more traffic.');?></p>

  <div class="set-group"><?php echo t('set_emoji_panel', 'Emoji panel');?></div>
  <div class="set-opt-group">
    <div class="set-opt" data-panel="dynamic" onclick="setPanel(this)"><span><?php echo t('set_emoji_panel_dynamic', 'Always animated');?></span><span class="checked" id="panel-dynamic"></span></div>
    <div class="set-opt" data-panel="hover" onclick="setPanel(this)"><span><?php echo t('set_emoji_panel_hover', 'Animated on hover');?></span><span class="checked" id="panel-hover"></span></div>
    <div class="set-opt" data-panel="static" onclick="setPanel(this)"><span><?php echo t('set_emoji_panel_static', 'Static only');?></span><span class="checked" id="panel-static"></span></div>
  </div>

  <div class="set-group"><?php echo t('set_emoji_chat', 'Emoji in chat');?></div>
  <div class="set-opt-group">
    <div class="set-opt" data-chat="dynamic" onclick="setChat(this)"><span><?php echo t('set_emoji_chat_dynamic', 'Animated');?></span><span class="checked" id="chat-dynamic"></span></div>
    <div class="set-opt" data-chat="static" onclick="setChat(this)"><span><?php echo t('set_emoji_chat_static', 'Static only');?></span><span class="checked" id="chat-static"></span></div>
  </div>

</div>

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
var panelMode = <?php echo json_encode($panelMode);?>;
var chatMode  = <?php echo json_encode($chatMode);?>;

function goBack() {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
        window.parent.document.getElementById('profileFrame').src = 'settings.php';
    } else { history.back(); }
}
function showToast() {
    var t = document.getElementById('saveToast');
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
function render() {
    ['dynamic','hover','static'].forEach(function(m) {
        document.getElementById('panel-' + m).textContent = (panelMode === m) ? '✓' : '';
    });
    ['dynamic','static'].forEach(function(m) {
        document.getElementById('chat-' + m).textContent = (chatMode === m) ? '✓' : '';
    });
}
function save() {
    var f = new URLSearchParams();
    f.append('action', 'save_emoji_settings');
    f.append('panel_mode', panelMode);
    f.append('chat_mode', chatMode);
    fetch('../../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) { render(); showToast(); }
    });
}
function setPanel(el) { panelMode = el.getAttribute('data-panel'); save(); }
function setChat(el) { chatMode = el.getAttribute('data-chat'); save(); }
render();
</script>

</body>
</html>
