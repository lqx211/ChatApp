<?php
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
$u = chatapp_get_user();
$notifSystem = (int)($u['notif_system'] ?? 1);
$notifBanner = (int)($u['notif_banner'] ?? 1);
$dnd         = (int)($u['dnd'] ?? 0);
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('set_notifications');?></title>
<link rel="stylesheet" href="../../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="../style/settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('set_notifications', 'Notifications');?></span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint"><?php echo t('set_notif_hint', 'Choose how you want to receive message notifications.');?></p>

  <div class="set-group"><?php echo t('set_notif_closed', 'When the app is closed');?></div>
  <div class="set-row" style="cursor:default">
    <span class="row-label"><?php echo t('set_notif_system', 'System notifications');?></span>
    <label class="set-switch">
      <input type="checkbox" id="notifSysSw" <?php echo $notifSystem ? 'checked' : '';?> onchange="toggleCol('notif_system', 'notifSysSw', 'NOTIF_SYS', this)">
      <span class="track"></span>
    </label>
  </div>
  <div class="set-row" style="cursor:default">
    <span class="row-label"><?php echo t('set_notif_preview', 'Show message preview in notifications');?></span>
    <span class="row-value" style="color:#5a6270;font-size:12px"><?php echo t('set_notif_follow_system', 'Follows the system notification setting');?></span>
    <span class="row-arrow" style="visibility:hidden">›</span>
  </div>

  <div class="set-group"><?php echo t('set_notif_open', 'When the app is open');?></div>
  <div class="set-row" style="cursor:default">
    <span class="row-label"><?php echo t('set_notif_banner', 'In-app message banner');?></span>
    <label class="set-switch">
      <input type="checkbox" id="notifBannerSw" <?php echo $notifBanner ? 'checked' : '';?> onchange="toggleCol('notif_banner', 'notifBannerSw', 'NOTIF_BANNER', this)">
      <span class="track"></span>
    </label>
  </div>

  <div class="set-group"><?php echo t('set_notif_remind', 'Reminder method');?></div>
  <div class="set-row set-row-2line" style="cursor:default">
    <div class="row-2line">
      <span class="row-label"><?php echo t('set_dnd', 'Do Not Disturb');?></span>
      <span class="row-desc"><?php echo t('set_dnd_desc', 'When on, no message pushes will be received for a set time');?></span>
    </div>
    <label class="set-switch">
      <input type="checkbox" id="dndSw" <?php echo $dnd ? 'checked' : '';?> onchange="toggleDnd(this)">
      <span class="track"></span>
    </label>
  </div>

</div>

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
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
function api(action, data) {
    var f = new URLSearchParams();
    f.append('action', action);
    for (var k in (data || {})) f.append(k, data[k]);
    return fetch('../../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); });
}

/* 通用开关：调 toggle_<col> API 并同步父页面变量 */
function toggleCol(col, swId, parentVar, el) {
    api('toggle_' + col).then(function(d) {
        el.checked = !!d[col];
        if (d.success) {
            if (window.parent && parentVar) window.parent[parentVar] = d[col];
            showToast();
        }
    });
}

/* 勿扰模式：调 toggle_dnd 并同步父页面 DND 变量 + 侧边栏状态（父 toggleDnd 会再调一次 API，不能复用） */
function toggleDnd(el) {
    api('toggle_dnd').then(function(d) {
        el.checked = !!d.dnd;
        if (d.success) {
            if (window.parent) {
                window.parent.DND = d.dnd;
                if (typeof window.parent.updateDndUI === 'function') window.parent.updateDndUI();
            }
            showToast();
        }
    });
}
</script>

</body>
</html>
