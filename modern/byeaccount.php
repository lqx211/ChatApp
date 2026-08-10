<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title>注销账号</title>
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title">注销账号</span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint">注销后账号将无法恢复。也可以选择只清除聊天记录、保留账号。</p>

  <div class="set-group" style="color:#ff8a8a">注销账号</div>
  <div class="set-block">
    <label class="set-check-row"><input type="radio" name="delMode" value="delete" checked> 直接删除账号</label>
    <label class="set-check-row"><input type="radio" name="delMode" value="revoke"> 清除全部聊天记录（账号保留）</label>
    <div class="set-field">
      <label>输入密码确认</label>
      <input type="password" id="delPwd" autocomplete="current-password">
    </div>
    <label class="set-check-row"><input type="checkbox" id="delConfirm"> 我已知晓此操作不可恢复</label>
    <button class="set-btn danger" onclick="deleteAccount()">注销账号</button>
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

function deleteAccount() {
    if (!document.getElementById('delConfirm').checked) { showErr('请勾选确认框'); return; }
    var pwd = document.getElementById('delPwd').value;
    if (!pwd) { showErr('请输入密码确认'); return; }
    if (!confirm('确定要执行此操作吗？此操作不可恢复！')) return;
    var mode = document.querySelector('input[name="delMode"]:checked').value;
    api('delete_account', { password: pwd, mode: mode }).then(function(d) {
        if (d.success) {
            if (mode === 'revoke') {
                showToast();
                setTimeout(function() { goBack(); }, 900);
            } else {
                // 账号已删除：让父页面跳到登录页（不能只改 iframe 内部）
                if (window.parent && window.parent.location) window.parent.location.href = 'login.php';
                else window.location.href = 'login.php';
            }
        } else showErr('密码错误或操作失败');
    });
}
</script>

</body>
</html>
