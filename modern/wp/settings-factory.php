<?php
/**
 * ChatApp · Factory Reset（模拟模式）
 * 完整验证流程（管理员密码 / 维护门户凭据 / git hash），
 * 验证全过后仅播放模拟重置动画 —— 不执行任何真实删除。
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();

// 动态读取管理员（uid 10000）显示名，不写死
$__adminLabel = 'Administrator';
try {
    $__s = db()->prepare('SELECT display_name, username FROM users WHERE user_id = 10000');
    $__s->execute();
    $__adm = $__s->fetch();
    if ($__adm) {
        $__n = trim((string)($__adm['display_name'] ?? ''));
        if ($__n === '') $__n = trim((string)$__adm['username']);
        $__adminLabel = $__n;
    }
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title>Factory Reset</title>
<link rel="stylesheet" href="/plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="/modern/style/settings.css?v=20260810">
<style>
  .fr-title{color:#ff6b6b;font-size:1.05em;font-weight:700;margin:14px 0 6px;text-align:center}
  .fr-desc{color:#bbb;font-size:.76em;line-height:1.6;margin:0 4px 8px;word-break:break-word}
  .fr-desc b{color:#ff8a8a}
  .fr-note{color:#ff8a8a;font-size:.7em;margin:8px 4px 0}
  /* 全屏模拟动画 */
  #frOverlay{position:fixed;inset:0;background:#000;z-index:9999;display:none;flex-direction:column;align-items:center;justify-content:center;color:#ff3b3b;font-family:Menlo,Consolas,monospace;text-align:center}
  #frOverlay.active{display:flex}
  #frOverlayTitle{font-size:1.5em;font-weight:800;letter-spacing:3px}
  #frOverlayStep{margin-top:18px;font-size:.85em;color:#ff8a8a;min-height:1.2em}
  #frOverlayBarWrap{width:70%;max-width:320px;height:14px;border:1px solid #ff3b3b;margin-top:14px;border-radius:7px;overflow:hidden}
  #frOverlayBar{height:100%;width:0%;background:#ff3b3b;transition:width .22s}
  #frOverlayPct{margin-top:8px;font-size:.75em;color:#aaa}
</style>
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title" style="color:#ff6b6b">Factory Reset</span>
    <span style="width:28px"></span>
  </div>

  <p class="fr-desc">This will <b>reset the database</b>, delete <b>all user data</b> and go back to the factory setup.</p>
  <p class="fr-desc">Please enter the current ChatApp git version in uppercase, the current password of Administrator <?php echo htmlspecialchars($__adminLabel); ?> (10000) and credentials of Maintenance Portal to perform this operation.</p>

  <div class="set-block">
    <div class="set-field">
      <label>Password</label>
      <input type="password" id="frPwd" autocomplete="current-password" placeholder="Administrator (10000)">
    </div>
    <div class="set-field">
      <label>Maintenance Mode Account</label>
      <input type="text" id="frMUser" autocomplete="off">
    </div>
    <div class="set-field">
      <label>Maintenance Mode Passphrase</label>
      <input type="password" id="frMSecret" autocomplete="off">
    </div>
    <div class="set-field">
      <label>Enter current git hash</label>
      <input type="text" id="frHash1" autocomplete="off" spellcheck="false" placeholder="git log -1 --format=%H">
    </div>
    <div class="set-field">
      <label>Re-enter current git hash</label>
      <input type="text" id="frHash2" autocomplete="off" spellcheck="false" placeholder="git log -1 --format=%H">
    </div>
    <label class="set-check-row"><input type="checkbox" id="frConfirm"> <span style="color:#ff8a8a">Confirm deletion</span></label>
    <button class="set-btn danger" onclick="frRun()">Confirm deletion</button>
    <button class="set-btn" style="margin-top:8px" onclick="goBack()">Abort</button>
  </div>

</div>

<div class="save-toast" id="saveToast">✓</div>

<!-- 全屏模拟重置动画 -->
<div id="frOverlay">
  <div id="frOverlayTitle">FACTORY RESET</div>
  <div id="frOverlayStep">INITIATING…</div>
  <div id="frOverlayBarWrap"><div id="frOverlayBar"></div></div>
  <div id="frOverlayPct">0%</div>
</div>

<script>
function goBack(){
  if (window.parent && window.parent.document.getElementById('profileFrame')) window.parent.document.getElementById('profileFrame').src = 'settings.php';
  else history.back();
}
function showErr(msg){
  var t = document.getElementById('saveToast');
  t.textContent = msg;
  t.style.background = '#4a2020'; t.style.borderColor = '#5c2a2a'; t.style.color = '#ffb3b3';
  t.classList.add('show');
  setTimeout(function(){
    t.classList.remove('show'); t.textContent = '✓';
    t.style.background = '#2a4a2a'; t.style.borderColor = '#3a6a3a'; t.style.color = '#e0e0e0';
  }, 3000);
}
function frRun(){
  var pwd = document.getElementById('frPwd').value;
  var mu  = document.getElementById('frMUser').value.trim();
  var ms  = document.getElementById('frMSecret').value;
  var h1  = document.getElementById('frHash1').value.trim().toUpperCase();
  var h2  = document.getElementById('frHash2').value.trim().toUpperCase();
  var conf = document.getElementById('frConfirm').checked;

  if (!pwd || !mu || !ms || !h1 || !h2) { showErr('All fields are required'); return; }
  if (h1 !== h2) { showErr('Git hash mismatch'); return; }
  if (!conf) { showErr('Please confirm deletion'); return; }

  var f = new URLSearchParams();
  f.append('action', 'start');
  f.append('password', pwd);
  f.append('maint_user', mu);
  f.append('maint_secret', ms);
  f.append('git_hash', h1);
  f.append('git_hash2', h2);
  fetch('/api/factory_reset.php', {
    method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: f.toString()
  }).then(function(r){ return r.json(); })
    .then(function(d){
      if (d.success) {
        // 打开独立流程窗口（Win8.1 风格，二次确认 + 多阶段重置）
        window.open('factory-reset-flow.php', '_blank', 'width=620,height=520');
      } else {
        showErr(d.error || 'Verification failed');
      }
    })
    .catch(function(){ showErr('Network error'); });
}

var FR_STEPS = ['Deleting user accounts…','Deleting messages…','Deleting uploads…','Deleting database…','Restoring factory state…'];
function frSimulate(){
  var ov = document.getElementById('frOverlay'); ov.classList.add('active');
  var bar = document.getElementById('frOverlayBar');
  var step = document.getElementById('frOverlayStep');
  var pct = document.getElementById('frOverlayPct');
  var i = 0;
  bar.style.width = '0%'; pct.textContent = '0%';
  step.textContent = 'INITIATING…';
  var t = setInterval(function(){
    i++;
    if (i <= 100) {
      bar.style.width = i + '%';
      pct.textContent = i + '%';
      step.textContent = FR_STEPS[Math.min(FR_STEPS.length - 1, Math.floor(i / 25))];
    } else {
      clearInterval(t);
      step.textContent = '';
      ov.innerHTML =
        '<div style="text-align:center;padding:20px">' +
          '<div style="font-size:1.15em;color:#ff6b6b;font-weight:800;letter-spacing:1px">JUST KIDDING 😎</div>' +
          '<div style="margin-top:10px;color:#bbb;font-size:.82em;line-height:1.6">This was a <b style="color:#ff8a8a">simulation</b>.<br>Your data is 100% safe.</div>' +
          '<button onclick="location.reload()" style="margin-top:20px;padding:11px 30px;background:#2a2a2a;border:1px solid #ff6b6b;color:#ff6b6b;border-radius:9px;font-size:.9em">OK</button>' +
        '</div>';
    }
  }, 60);
}
</script>
</body>
</html>
