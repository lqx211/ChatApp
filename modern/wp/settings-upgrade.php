<?php
/**
 * ChatApp · Upgrade System
 * 检测 github.com/lqx211/ChatApp 更新并拉取覆盖代码。
 * 验证与 Factory Reset 一致（admin 密码 + 维护门户凭据 + git hash），
 * 执行时保留 config/ data/ maintenance/，覆盖其余代码，需自行承担风险。
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
<title>Upgrade System</title>
<link rel="stylesheet" href="/plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="/modern/style/settings.css?v=20260828">
<style>
  .up-title{color:#6fa8dc;font-size:1.05em;font-weight:700;margin:14px 0 6px;text-align:center}
  .up-status{background:#1e1e1e;border:1px solid #333;border-radius:10px;padding:12px;margin:10px 4px;font-size:.78em}
  .up-status .row{display:flex;justify-content:space-between;padding:4px 0;border-bottom:1px solid #222}
  .up-status .row:last-child{border-bottom:none}
  .up-status .k{color:#888}
  .up-status .v{color:#e0e0e0;font-family:Menlo,Consolas,monospace;font-size:.9em;word-break:break-all;text-align:right;max-width:60%}
  .up-btn{display:block;width:100%;margin:10px 0;padding:11px;border-radius:9px;font-size:.9em;border:1px solid #3a3a3a;background:#262626;color:#ccc}
  .up-btn:hover{background:#303030}
  .up-warn{background:#2a1f1f;border:1px solid #5c2a2a;color:#ffb3b3;border-radius:10px;padding:12px;margin:10px 4px;font-size:.75em;line-height:1.7}
  .up-warn b{color:#ff6b6b}
  .up-badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:.72em;font-weight:700}
  .up-badge.latest{background:#1f3a2a;color:#7ddb9a;border:1px solid #2f5a3f}
  .up-badge.outdated{background:#3a2a1f;color:#ffcf7d;border:1px solid #5c4a2f}
</style>
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title" style="color:#6fa8dc">Upgrade System</span>
    <span style="width:28px"></span>
  </div>

  <p style="color:#bbb;font-size:.76em;margin:0 4px 6px">Check &amp; pull updates from <b style="color:#6fa8dc">github.com/lqx211/ChatApp</b>. Config, user data and maintenance credentials are preserved.</p>

  <!-- 状态卡片 -->
  <div class="up-status" id="upStatus">
    <div class="row"><span class="k">Branch</span><span class="v" id="upBranch">…</span></div>
    <div class="row"><span class="k">Current version</span><span class="v" id="upLocal">…</span></div>
    <div class="row"><span class="k">Remote latest</span><span class="v" id="upRemote">…</span></div>
    <div class="row"><span class="k">Uncommitted changes</span><span class="v" id="upDirty">…</span></div>
  </div>

  <button class="up-btn" id="upCheckBtn" onclick="checkUpgrade()">Check for updates</button>

  <!-- 升级区块（有更新时显示） -->
  <div id="upPanel" style="display:none">
    <div class="up-warn">
      <b>⚠ Upgrade at your own risk</b><br>
      · Pulls from <b>github.com/lqx211/ChatApp</b> and <b>overwrites code</b>.<br>
      · <b>config/</b>, <b>data/</b> (user data) and <b>maintenance/</b> are <b>kept</b>.<br>
      · Any <b>uncommitted local changes</b> will be <b>overwritten</b>. Commit them first or accept the loss.<br>
      · Verify with Administrator password, Maintenance Portal credentials and current git hash.
    </div>
    <div class="set-block">
      <div class="set-field"><label>Password</label><input type="password" id="upPwd" autocomplete="current-password" placeholder="Administrator <?php echo htmlspecialchars($__adminLabel); ?> (10000)"></div>
      <div class="set-field"><label>Maintenance Mode Account</label><input type="text" id="upMUser" autocomplete="off"></div>
      <div class="set-field"><label>Maintenance Mode Passphrase</label><input type="password" id="upMSecret" autocomplete="off"></div>
      <div class="set-field"><label>Enter current git hash</label><input type="text" id="upHash1" autocomplete="off" spellcheck="false" placeholder="git log -1 --format=%H"></div>
      <div class="set-field"><label>Re-enter current git hash</label><input type="text" id="upHash2" autocomplete="off" spellcheck="false" placeholder="git log -1 --format=%H"></div>
      <label class="set-check-row"><input type="checkbox" id="upConfirm"> <span style="color:#ff8a8a">I understand and accept the risk</span></label>
      <button class="set-btn danger" onclick="runUpgrade()">Upgrade now</button>
    </div>
  </div>

  <!-- 升级进度 -->
  <div id="upProgress" style="display:none">
    <div style="text-align:center;color:#6fa8dc;font-weight:700;margin:16px 0 6px">UPGRADING…</div>
    <div style="text-align:center;color:#ccc;font-size:.8em;min-height:1.2em" id="upStep">Starting…</div>
    <div style="width:80%;max-width:340px;height:16px;border:1px solid #3a6a8a;margin:12px auto;border-radius:8px;overflow:hidden">
      <div id="upBar" style="height:100%;width:0%;background:#4a9dd8;transition:width .4s"></div>
    </div>
    <div style="text-align:center;color:#888;font-size:.75em" id="upPct">0%</div>
    <div style="text-align:center;color:#666;font-size:.68em;margin-top:10px">All other users are in maintenance mode until this completes.</div>
  </div>

</div>

<div class="save-toast" id="saveToast">✓</div>

<script>
function goBack(){
  if (window.parent && window.parent.document.getElementById('profileFrame')) window.parent.document.getElementById('profileFrame').src = 'settings.php';
  else history.back();
}
function showMsg(msg, ok){
  var t = document.getElementById('saveToast');
  t.textContent = msg;
  if (ok) { t.style.background = '#2a4a2a'; t.style.borderColor = '#3a6a3a'; t.style.color = '#e0e0e0'; }
  else { t.style.background = '#4a2020'; t.style.borderColor = '#5c2a2a'; t.style.color = '#ffb3b3'; }
  t.classList.add('show');
  setTimeout(function(){ t.classList.remove('show'); }, 4000);
}
function api(action, extra){
  var f = new URLSearchParams();
  f.append('action', action);
  for (var k in (extra || {})) f.append(k, extra[k]);
  return fetch('/api/upgrade.php', {
    method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: f.toString()
  }).then(function(r){ return r.json(); });
}

function checkUpgrade(){
  var btn = document.getElementById('upCheckBtn');
  btn.disabled = true; btn.textContent = 'Checking…';
  api('check').then(function(d){
    btn.disabled = false; btn.textContent = 'Check for updates';
    if (!d.success) { showMsg(d.error || 'Check failed', false); return; }
    document.getElementById('upBranch').textContent = d.branch || 'main';
    document.getElementById('upLocal').textContent = (d.local || '').slice(0, 12);
    document.getElementById('upRemote').textContent = d.remote ? d.remote.slice(0, 12) : '?';
    document.getElementById('upDirty').textContent = d.dirty_count;
    var panel = document.getElementById('upPanel');
    if (d.has_update) {
      panel.style.display = 'block';
      showMsg('Update available → ' + (d.remote || '').slice(0, 12), true);
    } else {
      panel.style.display = 'none';
      showMsg('Already up to date ✓', true);
    }
  }).catch(function(){ btn.disabled = false; btn.textContent = 'Check for updates'; showMsg('Network error', false); });
}

function runUpgrade(){
  var pwd = document.getElementById('upPwd').value;
  var mu  = document.getElementById('upMUser').value.trim();
  var ms  = document.getElementById('upMSecret').value;
  var h1  = document.getElementById('upHash1').value.trim().toUpperCase();
  var h2  = document.getElementById('upHash2').value.trim().toUpperCase();
  var conf = document.getElementById('upConfirm').checked;
  if (!pwd || !mu || !ms || !h1 || !h2) { showMsg('All fields are required', false); return; }
  if (h1 !== h2) { showMsg('Git hash mismatch', false); return; }
  if (!conf) { showMsg('Please accept the risk', false); return; }
  api('perform', { password: pwd, maint_user: mu, maint_secret: ms, git_hash: h1, git_hash2: h2 }).then(function(d){
    if (d.success) {
      // 进入后台升级：隐藏表单，显示进度
      document.getElementById('upPanel').style.display = 'none';
      document.getElementById('upCheckBtn').style.display = 'none';
      var pg = document.getElementById('upProgress');
      pg.style.display = 'block';
      showMsg('Upgrade started — maintenance mode armed', true);
      pollProgress();
    } else {
      showMsg(d.error || 'Upgrade failed', false);
    }
  }).catch(function(){ showMsg('Network error', false); });
}

function pollProgress(){
  api('progress').then(function(d){
    if (!d.success) { setTimeout(pollProgress, 1500); return; }
    var step = document.getElementById('upStep');
    var bar  = document.getElementById('upBar');
    var pct  = document.getElementById('upPct');
    if (d.step) step.textContent = d.step;
    if (typeof d.pct === 'number') { bar.style.width = d.pct + '%'; pct.textContent = d.pct + '%'; }
    if (d.status === 'done') {
      step.textContent = 'Upgrade complete ✓';
      bar.style.width = '100%'; pct.textContent = '100%';
      showMsg('Upgrade complete — service restored', true);
      document.getElementById('upCheckBtn').style.display = 'block';
      setTimeout(function(){ location.reload(); }, 2500);
      return;
    }
    if (d.status === 'error') {
      step.textContent = 'Upgrade failed';
      showMsg('Upgrade failed — maintenance released', false);
      document.getElementById('upCheckBtn').style.display = 'block';
      return;
    }
    setTimeout(pollProgress, 1000);
  }).catch(function(){ setTimeout(pollProgress, 2000); });
}

// 进入页面自动检查一次
checkUpgrade();
</script>
</body>
</html>
