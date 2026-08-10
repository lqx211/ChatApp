<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$u = chatapp_get_user();
$strangerInvite = (int)($u['stranger_invite_group'] ?? 1);
$strangerLike   = (int)($u['stranger_like'] ?? 1);
$typingVisible  = (int)($u['typing_visible'] ?? 1);
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title>隐私设置</title>
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title">隐私设置</span>
    <span style="width:28px"></span>
  </div>

  <!-- ============ 陌生人 ============ -->
  <div class="set-group">陌生人</div>
  <div class="set-row" onclick="navTo('settings-findme.php')">
    <span class="row-label">找到我的方式</span>
    <span class="row-arrow">›</span>
  </div>
  <div class="set-row" onclick="navTo('settings-addme.php')">
    <span class="row-label">加我为好友的方式</span>
    <span class="row-arrow">›</span>
  </div>
  <div class="set-row" style="cursor:default">
    <span class="row-label">允许陌生人邀请我加入群聊</span>
    <label class="set-switch">
      <input type="checkbox" id="inviteSw" <?php echo $strangerInvite ? 'checked' : '';?> onchange="toggleCol('stranger_invite_group','inviteSw',this)">
      <span class="track"></span>
    </label>
  </div>
  <div class="set-row" style="cursor:default">
    <span class="row-label">允许陌生人赞我</span>
    <label class="set-switch">
      <input type="checkbox" id="likeSw" <?php echo $strangerLike ? 'checked' : '';?> onchange="toggleCol('stranger_like','likeSw',this)">
      <span class="track"></span>
    </label>
  </div>

  <!-- ============ 好友 ============ -->
  <div class="set-group">好友</div>
  <div class="set-row" onclick="navTo('settings-blacklist.php')">
    <span class="row-label">黑名单管理</span>
    <span class="row-arrow">›</span>
  </div>
  <div class="set-row" onclick="navTo('settings-oneway.php')">
    <span class="row-label">单向好友管理</span>
    <span class="row-arrow">›</span>
  </div>

  <!-- ============ 好友权限 ============ -->
  <div class="set-group">好友权限</div>
  <div class="set-row" onclick="navTo('editbgprivacy.php?from=settings')">
    <span class="row-label">背景图查看权</span>
    <span class="row-arrow">›</span>
  </div>
  <div class="set-row" style="cursor:default">
    <span class="row-label">我的输入状态可见</span>
    <label class="set-switch">
      <input type="checkbox" id="typingSw" <?php echo $typingVisible ? 'checked' : '';?> onchange="toggleCol('typing_visible','typingSw',this)">
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
function navTo(src) {
    var card = document.querySelector('.card');
    if (!card) { if (window.parent) window.parent.document.getElementById('profileFrame').src = src; return; }
    card.classList.add('slide-out-left');
    setTimeout(function() {
        if (window.parent && window.parent.document.getElementById('profileFrame')) {
            window.parent.document.getElementById('profileFrame').src = src;
        } else { location.href = src; }
    }, 250);
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
    return fetch('../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); });
}

function toggleCol(col, swId, el) {
    api('toggle_' + col).then(function(d) {
        el.checked = !!d[col];
        if (d.success) {
            // 输入状态可见性需同步父页面 TYPING_VIS
            if (col === 'typing_visible' && window.parent) window.parent.TYPING_VIS = d[col];
            showToast();
        }
    });
}
</script>

</body>
</html>
