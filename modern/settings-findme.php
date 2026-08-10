<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$u = chatapp_get_user();
$searchable = (int)($u['searchable'] ?? 1);
$searchableByUid = (int)($u['searchable_by_uid'] ?? 1);
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title>找到我的方式</title>
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title">找到我的方式</span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint">选择其他人可以通过哪些方式在 ChatApp 里找到你。</p>

  <div class="set-group">查找方式</div>
  <div class="set-row" style="cursor:default">
    <span class="row-label">允许通过用户名搜索到</span>
    <label class="set-switch">
      <input type="checkbox" id="searchableSw" <?php echo $searchable ? 'checked' : '';?> onchange="save()">
      <span class="track"></span>
    </label>
  </div>
  <div class="set-row" style="cursor:default">
    <span class="row-label">允许通过 UID 搜索到</span>
    <label class="set-switch">
      <input type="checkbox" id="searchableUidSw" <?php echo $searchableByUid ? 'checked' : '';?> onchange="save()">
      <span class="track"></span>
    </label>
  </div>
  <p class="set-note">关闭后，其他用户将无法通过「发现用户 / 搜索」找到你，也不会出现在推荐中。</p>

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
    var f = new URLSearchParams();
    f.append('action', 'save_privacy');
    f.append('searchable', document.getElementById('searchableSw').checked ? 1 : 0);
    f.append('searchable_by_uid', document.getElementById('searchableUidSw').checked ? 1 : 0);
    fetch('../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) showToast();
    });
}
</script>

</body>
</html>
