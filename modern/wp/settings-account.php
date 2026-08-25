<?php
/**
 * ChatApp · 账号与安全（accountSafety）
 * 行：修改密码 → cpasswd.php / 胁迫密码 → settings-duress.php / 注销账号 → byeaccount.php
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('set_account_safety');?></title>
<link rel="stylesheet" href="../../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="../style/settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('set_account_safety', 'Account & Safety');?></span>
    <span style="width:28px"></span>
  </div>

  <div class="set-group"><?php echo t('set_account_safety', 'Account & Safety');?></div>
  <div class="set-row" onclick="navTo('cpasswd.php')">
    <span class="row-label"><?php echo t('btn_change_password', 'Change Password');?></span>
    <span class="row-arrow">›</span>
  </div>
  <div class="set-row" onclick="navTo('settings-duress.php')">
    <span class="row-label"><?php echo t('title_duress', 'Duress Password');?></span>
    <span class="row-arrow">›</span>
  </div>
  <div class="set-row" onclick="navTo('byeaccount.php')">
    <span class="row-label set-danger-text"><?php echo t('set_deactivate', 'Deactivate Account');?></span>
    <span class="row-arrow">›</span>
  </div>

</div>

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
</script>

</body>
</html>
