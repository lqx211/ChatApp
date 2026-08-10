<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title>胁迫密码</title>
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title">胁迫密码</span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint">当你在输入密码的地方输入胁迫密码时，账号会进入「自毁」流程，例如删除账号。请勿与他人共用，且不能与普通密码相同。</p>

  <div class="set-group">设置胁迫密码</div>
  <div class="set-block">
    <div class="set-field">
      <label>当前密码（验证身份）</label>
      <input type="password" id="duCurrent" autocomplete="current-password">
    </div>
    <div class="set-field">
      <label>新胁迫密码</label>
      <input type="password" id="duNew" placeholder="留空则清除胁迫密码">
    </div>
    <div class="set-field">
      <label>确认胁迫密码</label>
      <input type="password" id="duNew2">
    </div>
    <button class="set-btn" onclick="saveDuress()">保存胁迫密码</button>
    <button class="set-btn ghost" onclick="clearDuress()" style="margin-top:10px">清除胁迫密码</button>
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

function saveDuress() {
    var cur = document.getElementById('duCurrent').value;
    var np = document.getElementById('duNew').value;
    var np2 = document.getElementById('duNew2').value;
    if (!cur) { showErr('请输入当前密码验证身份'); return; }
    if (np && np !== np2) { showErr('两次输入的胁迫密码不一致'); return; }
    api('setup_duress', { current_password: cur, duress_password: np }).then(function(d) {
        if (d.success) {
            document.getElementById('duCurrent').value = '';
            document.getElementById('duNew').value = '';
            document.getElementById('duNew2').value = '';
            showToast();
        } else showErr(d.error || '保存失败');
    });
}
function clearDuress() {
    var cur = document.getElementById('duCurrent').value;
    if (!cur) { showErr('请输入当前密码验证身份'); return; }
    if (!confirm('确定要清除胁迫密码吗？')) return;
    api('setup_duress', { current_password: cur, duress_password: '' }).then(function(d) {
        if (d.success) {
            document.getElementById('duCurrent').value = '';
            showToast();
        } else showErr(d.error || '清除失败（请输入当前密码验证）');
    });
}
</script>

</body>
</html>
