<?php
require_once __DIR__ . '/../../api/config.php';
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
<title><?php echo t('set_findme');?></title>
<link rel="stylesheet" href="../../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="../style/settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('set_findme', 'Ways to find me');?></span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint"><?php echo t('set_findme_hint', 'Choose how others can find you in ChatApp.');?></p>

  <div class="set-group"><?php echo t('set_search_methods', 'Search methods');?></div>
  <div class="set-row" style="cursor:default">
    <span class="row-label"><?php echo t('set_searchable_name', 'Searchable by username');?></span>
    <label class="set-switch">
      <input type="checkbox" id="searchableSw" <?php echo $searchable ? 'checked' : '';?> onchange="save()">
      <span class="track"></span>
    </label>
  </div>
  <div class="set-row" style="cursor:default">
    <span class="row-label"><?php echo t('set_searchable_uid', 'Searchable by UID');?></span>
    <label class="set-switch">
      <input type="checkbox" id="searchableUidSw" <?php echo $searchableByUid ? 'checked' : '';?> onchange="save()">
      <span class="track"></span>
    </label>
  </div>
  <p class="set-note"><?php echo t('set_findme_note', 'When off, other users cannot find you via "Discover Users / Search", and you will not appear in recommendations.');?></p>

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
    fetch('../../api/settings.php', {
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
