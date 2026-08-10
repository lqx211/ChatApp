<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$u = chatapp_get_user();
$anyone = (int)($u['anyone_add_friend'] ?? 1);
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('set_addme');?></title>
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('set_addme', 'Ways to add me as a friend');?></span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint"><?php echo t('set_addme_hint', 'Control whether others can actively add you as a friend.');?></p>

  <div class="set-group"><?php echo t('set_friend_req', 'Friend requests');?></div>
  <div class="set-row" style="cursor:default">
    <span class="row-label"><?php echo t('set_anyone_add', 'Allow anyone to add me as a friend');?></span>
    <label class="set-switch">
      <input type="checkbox" id="anyoneSw" <?php echo $anyone ? 'checked' : '';?> onchange="save()">
      <span class="track"></span>
    </label>
  </div>
  <p class="set-note"><?php echo t('set_addme_note', 'When off, other users cannot send you friend requests.');?></p>

</div>

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
function goBack() {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
        window.parent.document.getElementById('profileFrame').src = 'settings-privacy.php';
    } else { history.back(); }
}
function showToast() {
    var t = document.getElementById('saveToast');
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
function save() {
    api('toggle_anyone_add_friend').then(function(d) {
        document.getElementById('anyoneSw').checked = !!d.anyone_add_friend;
        if (d.success) showToast();
    });
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
</script>

</body>
</html>
