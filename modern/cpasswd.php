<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title>修改密码</title>
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title">修改密码</span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint">定期修改密码可以更好地保护你的账号安全。</p>

  <div class="set-group">修改密码</div>
  <div class="set-block">
    <div class="set-field">
      <label>当前密码</label>
      <input type="password" id="pwCurrent" autocomplete="current-password">
    </div>
    <div class="set-field">
      <label>新密码</label>
      <input type="password" id="pwNew" autocomplete="new-password" placeholder="至少 8 位，含字母和数字">
    </div>
    <div class="set-field">
      <label>确认新密码</label>
      <input type="password" id="pwNew2" autocomplete="new-password">
    </div>
    <button class="set-btn" onclick="changePw()">修改密码</button>
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
    t.textContent = '✗ ' + msg;
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
    return fetch('../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); });
}

function changePw() {
    var cur = document.getElementById('pwCurrent').value;
    var np = document.getElementById('pwNew').value;
    var np2 = document.getElementById('pwNew2').value;
    if (!cur || !np) { showErr('请填写完整'); return; }
    if (np !== np2) { showErr('两次输入的新密码不一致'); return; }
    api('change_password', { current_password: cur, new_password: np }).then(function(d) {
        if (d.success) {
            document.getElementById('pwCurrent').value = '';
            document.getElementById('pwNew').value = '';
            document.getElementById('pwNew2').value = '';
            showToast();
        } else showErr(d.error || '修改失败');
    });
}
</script>

</body>
</html>
