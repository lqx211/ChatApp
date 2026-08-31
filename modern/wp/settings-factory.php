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
<title><?php echo t('fr_title', 'Factory Reset'); ?></title>
<link rel="stylesheet" href="/plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="/modern/style/settings.css?v=20260828">
<style>
  @font-face{font-family:'Roboto';src:url('../../css/fonts/Roboto-Regular.ttf') format('truetype');font-weight:400;font-style:normal}
  @font-face{font-family:'Chinese';src:url('../../css/fonts/chinese.otf') format('opentype');font-weight:400;font-style:normal}
  body{font-family:'Roboto','Chinese',-apple-system,BlinkMacSystemFont,'Segoe UI','Helvetica Neue',sans-serif}
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
  /* 内嵌 iframe 流程层（替代新开窗口） */
  #frFrameWrap{position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:10000;display:none;align-items:center;justify-content:center;padding:16px}
  #frFrameWrap.active{display:flex}
  #frFrame{width:90vw;height:90vh;border:1px solid #444;border-radius:10px;background:#1a1a1a;box-shadow:0 12px 40px rgba(0,0,0,0.6)}
  #frFrameClose{position:fixed;top:14px;right:18px;background:rgba(60,60,60,0.9);color:#ddd;border:1px solid #555;border-radius:50%;width:34px;height:34px;font-size:16px;line-height:1;cursor:pointer;z-index:10001}
  #frFrameClose:hover{background:#7c3434;color:#fff}
</style>
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title" style="color:#ff6b6b"><?php echo t('fr_title', 'Factory Reset'); ?></span>
    <span style="width:28px"></span>
  </div>

  <p class="fr-desc"><?php echo t('fr_desc1', 'This will <b>reset the database</b>, delete <b>all user data</b> and go back to the factory setup.'); ?></p>
  <p class="fr-desc"><?php echo t('fr_desc2', 'Please enter the current ChatApp git version in uppercase, the current password of Administrator %s (10000) and credentials of Maintenance Portal to perform this operation.', $__adminLabel); ?></p>

  <div class="set-block">
    <div class="set-field">
      <label><?php echo t('fr_lbl_pwd', 'Password'); ?></label>
      <input type="password" id="frPwd" autocomplete="current-password" placeholder="Administrator (10000)">
    </div>
    <div class="set-field">
      <label><?php echo t('fr_lbl_maint_user', 'Maintenance Mode Account'); ?></label>
      <input type="text" id="frMUser" autocomplete="off">
    </div>
    <div class="set-field">
      <label><?php echo t('fr_lbl_maint_secret', 'Maintenance Mode Passphrase'); ?></label>
      <input type="password" id="frMSecret" autocomplete="off">
    </div>
    <div class="set-field">
      <label><?php echo t('fr_lbl_hash1', 'Enter current git hash'); ?></label>
      <input type="text" id="frHash1" autocomplete="off" spellcheck="false" placeholder="git log -1 --format=%H">
    </div>
    <div class="set-field">
      <label><?php echo t('fr_lbl_hash2', 'Re-enter current git hash'); ?></label>
      <input type="text" id="frHash2" autocomplete="off" spellcheck="false" placeholder="git log -1 --format=%H">
    </div>
    <label class="set-check-row"><input type="checkbox" id="frConfirm"> <span style="color:#ff8a8a"><?php echo t('fr_chk_confirm', 'Confirm deletion'); ?></span></label>
    <button class="set-btn danger" onclick="frRun()"><?php echo t('fr_btn_reset', 'Confirm deletion'); ?></button>
    <button class="set-btn" style="margin-top:8px" onclick="goBack()"><?php echo t('fr_btn_abort', 'Abort'); ?></button>
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

<!-- 内嵌工厂重置流程（iframe，替代新开窗口） -->
<div id="frFrameWrap">
  <button id="frFrameClose" onclick="closeFactoryReset()" title="关闭"><?php echo svg_ic('close', 16);?></button>
  <iframe id="frFrame" src="about:blank"></iframe>
</div>

<script>
var FR_L = {
  req: <?php echo json_encode(t('fr_err_req', 'All fields are required')); ?>,
  hash: <?php echo json_encode(t('fr_err_hash', 'Git hash mismatch')); ?>,
  conf: <?php echo json_encode(t('fr_err_conf', 'Please confirm deletion')); ?>,
  verify: <?php echo json_encode(t('fr_err_verify', 'Verification failed')); ?>,
  net: <?php echo json_encode(t('fr_err_net', 'Network error')); ?>
};
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
/* 工厂重置流程：iframe 显示在顶层窗口（chat.php）全屏，而不是嵌在 settings 内层 */
function frAbortApi(){
  try {
    var f = new URLSearchParams();
    f.append('action', 'abort');
    fetch('/api/factory_reset.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:f.toString() });
  } catch (e) {}
}
function frCloseTop(){
  var topWin = window.top || window;
  var wrap = topWin.document.getElementById('frFrameWrap');
  if (wrap) {
    if (wrap.classList) wrap.classList.remove('active'); // 本页静态版本
    wrap.style.display = 'none';                          // 顶层动态版本
  }
  var f = topWin.document.getElementById('frFrame');
  if (f) f.src = 'about:blank'; // 清空，停止 iframe 内动画/计时器
  frAbortApi(); // 中途关闭 → 释放 upgrade.lock（幂等）
}
/* 在顶层窗口注入全屏覆盖层（settings 内嵌时，套在 profileFrame 外面） */
function ensureFrOverlay(){
  var topWin = window.top || window;
  if (topWin !== window) {
    var wrap = topWin.document.getElementById('frFrameWrap');
    if (wrap) { topWin.closeFactoryReset = frCloseTop; return wrap; }
    wrap = topWin.document.createElement('div');
    wrap.id = 'frFrameWrap';
    wrap.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:2147483000;display:none;align-items:center;justify-content:center;padding:16px;';
    var close = topWin.document.createElement('button');
    close.id = 'frFrameClose';
    close.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    close.title = 'Close';
    close.style.cssText = 'position:fixed;top:14px;right:18px;background:rgba(60,60,60,0.9);color:#ddd;border:1px solid #555;border-radius:50%;width:34px;height:34px;font-size:16px;line-height:1;cursor:pointer;z-index:2147483001;';
    close.onclick = frCloseTop;
    var frame = topWin.document.createElement('iframe');
    frame.id = 'frFrame';
    frame.src = 'about:blank';
    frame.style.cssText = 'width:90vw;height:90vh;border:1px solid #444;border-radius:10px;background:#1a1a1a;box-shadow:0 12px 40px rgba(0,0,0,0.6);';
    wrap.appendChild(close);
    wrap.appendChild(frame);
    topWin.document.body.appendChild(wrap);
    topWin.closeFactoryReset = frCloseTop; // 让流程页的 frClose() 能回调
    return wrap;
  }
  // 独立打开（顶层即本页）：用本页静态覆盖层
  return document.getElementById('frFrameWrap');
}
function showFactoryResetFlow(){
  var topWin = window.top || window;
  var wrap = ensureFrOverlay();
  if (!wrap) return;
  var frame = topWin.document.getElementById('frFrame');
  if (frame) frame.src = '/modern/wp/factory-reset-flow.php';
  if (topWin !== window) { wrap.style.display = 'flex'; }
  else if (wrap.classList) { wrap.classList.add('active'); }
}
function closeFactoryReset(){ frCloseTop(); }
function frRun(){
  var pwd = document.getElementById('frPwd').value;
  var mu  = document.getElementById('frMUser').value.trim();
  var ms  = document.getElementById('frMSecret').value;
  var h1  = document.getElementById('frHash1').value.trim().toUpperCase();
  var h2  = document.getElementById('frHash2').value.trim().toUpperCase();
  var conf = document.getElementById('frConfirm').checked;

  if (!pwd || !mu || !ms || !h1 || !h2) { showErr(FR_L.req); return; }
  if (h1 !== h2) { showErr(FR_L.hash); return; }
  if (!conf) { showErr(FR_L.conf); return; }

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
        // 在当前页面内嵌 iframe 显示流程（二次确认 + 多阶段重置）
        showFactoryResetFlow();
      } else {
        showErr(d.error || FR_L.verify);
      }
    })
    .catch(function(){ showErr(FR_L.net); });
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
          '<div style="font-size:1.15em;color:#ff6b6b;font-weight:800;letter-spacing:1px">JUST KIDDING</div>' +
          '<div style="margin-top:10px;color:#bbb;font-size:.82em;line-height:1.6">This was a <b style="color:#ff8a8a">simulation</b>.<br>Your data is 100% safe.</div>' +
          '<button onclick="location.reload()" style="margin-top:20px;padding:11px 30px;background:#2a2a2a;border:1px solid #ff6b6b;color:#ff6b6b;border-radius:9px;font-size:.9em">OK</button>' +
        '</div>';
    }
  }, 60);
}
</script>
</body>
</html>
