<?php
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('title_duress');?></title>
<link rel="stylesheet" href="../../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="../style/settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('title_duress', 'Duress Password');?></span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint"><?php echo t('set_duress_hint', 'When you type the duress password where a password is required, your account will enter a "self-destruct" flow, such as deleting the account. Do not share it with others, and it cannot be the same as your normal password.');?></p>

  <div class="set-group"><?php echo t('set_duress_password', 'Duress Password');?></div>
  <div class="set-block">
    <div class="set-field">
      <label><?php echo t('set_duress_current', 'Current Password (verify identity)');?></label>
      <input type="password" id="duCurrent" autocomplete="current-password">
    </div>
    <div class="set-field">
      <label><?php echo t('label_duress_new', 'New Duress Password');?></label>
      <input type="password" id="duNew" placeholder="<?php echo t('set_duress_clear', 'Clear Duress Password');?>">
    </div>
    <div class="set-field">
      <label><?php echo t('label_duress_confirm', 'Confirm Duress Password');?></label>
      <input type="password" id="duNew2">
    </div>
    <button class="set-btn" onclick="saveDuress()" style="background:#e8b730;color:#14161d"><?php echo t('btn_save_duress', 'Save Duress Password');?></button>
    <button class="set-btn ghost" onclick="clearDuress()" style="margin-top:10px"><?php echo t('btn_clear_duress', 'Clear Duress Password');?></button>
  </div>

</div>

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
function goBack() {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
        window.parent.document.getElementById('profileFrame').src = 'settings-account.php';
    } else { history.back(); }
}
function showToast() {
    var t = document.getElementById('saveToast');
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
function showErr(msg) {
    var t = document.getElementById('saveToast');
    t.textContent = ' ' + msg;
    t.style.background = '#4a2020';
    t.style.borderColor = '#5c2a2a';
    t.style.color = '#ffb3b3';
    t.classList.add('show');
    setTimeout(function() {
        t.classList.remove('show');
        t.textContent = '✓ 已保存';
        t.style.background = '#2a4a2a';
        t.style.borderColor = '#3a6a3a';
        t.style.color = '#e0e0e0';
    }, 2600);
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

function saveDuress() {
    var cur = document.getElementById('duCurrent').value;
    var np = document.getElementById('duNew').value;
    var np2 = document.getElementById('duNew2').value;
    if (!cur) { showErr('<?php echo t('set_duress_need_current', 'Please enter your current password to verify identity.');?>'); return; }
    if (np && np !== np2) { showErr('<?php echo t('msg_duress_mismatch', 'Duress passwords do not match.');?>'); return; }
    api('setup_duress', { current_password: cur, duress_password: np }).then(function(d) {
        if (d.success) {
            document.getElementById('duCurrent').value = '';
            document.getElementById('duNew').value = '';
            document.getElementById('duNew2').value = '';
            showToast();
        } else showErr(d.error || '<?php echo t('set_save_fail', 'Save failed.');?>');
    });
}
function clearDuress() {
    var cur = document.getElementById('duCurrent').value;
    if (!cur) { showErr('<?php echo t('set_duress_need_current', 'Please enter your current password to verify identity.');?>'); return; }
    if (!confirm('<?php echo t('set_duress_clear_confirm', 'Are you sure you want to clear the duress password?');?>')) return;
    api('setup_duress', { current_password: cur, duress_password: '' }).then(function(d) {
        if (d.success) {
            document.getElementById('duCurrent').value = '';
            showToast();
        } else showErr(d.error || '<?php echo t('set_clear_fail', 'Clear failed (please enter current password to verify).');?>');
    });
}
</script>

</body>
</html>
