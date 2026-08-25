<?php
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('btn_change_password');?></title>
<link rel="stylesheet" href="../../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="../style/settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('btn_change_password', 'Change Password');?></span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint"><?php echo t('set_cpasswd_hint', 'Changing your password regularly helps keep your account secure.');?></p>

  <div class="set-group"><?php echo t('btn_change_password', 'Change Password');?></div>
  <div class="set-block">
    <div class="set-field">
      <label><?php echo t('label_current_password', 'Current Password');?></label>
      <input type="password" id="pwCurrent" autocomplete="current-password">
    </div>
    <div class="set-field">
      <label><?php echo t('label_new_password', 'New Password');?></label>
      <input type="password" id="pwNew" autocomplete="new-password" placeholder="<?php echo t('admin_min_password', '8+ characters, letters & numbers');?>">
    </div>
    <div class="set-field">
      <label><?php echo t('label_confirm_password', 'Confirm Password');?></label>
      <input type="password" id="pwNew2" autocomplete="new-password">
    </div>
    <button class="set-btn" onclick="changePw()"><?php echo t('btn_change_password', 'Change Password');?></button>
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

function changePw() {
    var cur = document.getElementById('pwCurrent').value;
    var np = document.getElementById('pwNew').value;
    var np2 = document.getElementById('pwNew2').value;
    if (!cur || !np) { showErr('<?php echo t('set_pw_fill_all', 'Please fill in all fields.');?>'); return; }
    if (np !== np2) { showErr('<?php echo t('set_pw_mismatch', 'The two new passwords do not match.');?>'); return; }
    api('change_password', { current_password: cur, new_password: np }).then(function(d) {
        if (d.success) {
            document.getElementById('pwCurrent').value = '';
            document.getElementById('pwNew').value = '';
            document.getElementById('pwNew2').value = '';
            showToast();
        } else showErr(d.error || '<?php echo t('set_pw_fail', 'Change failed.');?>');
    });
}
</script>

</body>
</html>
