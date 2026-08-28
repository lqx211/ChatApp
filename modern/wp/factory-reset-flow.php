<?php
/**
 * ChatApp · Factory Reset 流程（login.php 毛玻璃风格）
 * 由 settings-factory.php 验证通过后以 iframe 内嵌打开（也可独立窗口打开）。
 * 流程：二次确认 → 过期会话 → 断开客户端 → 新凭据 → DROP+重建 → 完成。
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();

// 🔒 工厂重置页仅开放给 root(uid 10000) 且已从 settings-factory.php 通过三重验证（armed）的用户；
// 直接 URL 访问（绕过 settings-factory.php）→ 403。
$__frSt = db()->prepare('SELECT user_id FROM users WHERE username = ?');
$__frSt->execute([$_SESSION['username']]);
$__frUid = (int)($__frSt->fetchColumn() ?: 0);
if ($__frUid !== 10000 || empty($_SESSION['fr_flow_armed'])) {
    http_response_code(403);
    require __DIR__ . '/../../errors/403.php';
    exit;
}
// 与 login.php 共用同一壁纸（会话内保持一致）
if (empty($_SESSION['wallpaper']) || (int)$_SESSION['wallpaper'] < 1 || (int)$_SESSION['wallpaper'] > 10) {
    $_SESSION['wallpaper'] = rand(1, 10);
}
$bgWallpaper = (int)$_SESSION['wallpaper'];
?>
<!DOCTYPE html>
<html lang="zh-Hans">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Factory Reset</title>
<style>
  @font-face{font-family:'Roboto';src:url('../../css/fonts/Roboto-Regular.ttf') format('truetype');font-weight:400;font-style:normal}
  @font-face{font-family:'Chinese';src:url('../../css/fonts/chinese.otf') format('opentype');font-weight:400;font-style:normal}
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { height:100%; }
  body {
    font-family:'Roboto','Chinese',-apple-system,BlinkMacSystemFont,'Segoe UI','Helvetica Neue',sans-serif;
    color:#e0e0e0;
    display:flex; justify-content:center; align-items:center;
    min-height:100vh;
    background-color:#1a1a1a;
    background-image:
      radial-gradient(rgba(0,0,0,0) 0%, rgba(0,0,0,0.5) 100%),
      radial-gradient(rgba(0,0,0,0) 33%, rgba(0,0,0,0.3) 166%),
      url('../bg/background<?php echo $bgWallpaper; ?>.jpg');
    background-size:cover; background-position:center; background-repeat:no-repeat; background-attachment:fixed;
  }
  .card {
    width:420px; max-width:calc(100vw - 32px);
    background:rgba(42,42,42,0.9);
    -webkit-backdrop-filter:blur(10px); backdrop-filter:blur(10px);
    border:1px solid rgba(90,90,90,0.5);
    box-shadow:0 8px 32px rgba(0,0,0,0.5);
    padding:26px 30px;
  }
  .card h1 { text-align:center; font-size:1.5em; color:#e8a0a0; margin-bottom:4px; font-weight:600; }
  .card .sub { text-align:center; color:#888; font-size:.8em; margin-bottom:18px; }
  .warn { color:#e8826a; font-size:.85em; line-height:1.55; margin-bottom:6px; }
  .hint { color:#999; font-size:.78em; line-height:1.6; margin:10px 0; }
  .bar { height:8px; border-radius:4px; background:#2a2a2a; overflow:hidden; margin:12px 0; }
  .bar div { height:100%; background:#4a9dd8; border-radius:4px; transition:width .3s; }
  .fg { margin-bottom:14px; }
  .fg label { display:block; margin-bottom:6px; color:#aaa; font-size:.82em; }
  .fg input {
    width:100%; padding:10px 12px; background:#1e1e1e; border:1px solid #444;
    color:#e0e0e0; font-size:.92em; font-family:inherit; outline:none; transition:border-color .2s;
  }
  .fg input:focus { border-color:#888; }
  .check { display:flex; align-items:flex-start; gap:8px; margin:12px 0; font-size:.78em; color:#c88; line-height:1.45; }
  .check input { margin-top:2px; }
  .actions { display:flex; gap:10px; margin-top:20px; }
  .btn {
    flex:1; padding:11px; background:#4a4a4a; border:1px solid #555; color:#e0e0e0;
    font-size:.92em; font-weight:600; cursor:pointer; font-family:inherit; transition:background .2s;
  }
  .btn:hover { background:#5a5a5a; }
  .btn.danger { background:#6a2a2a; border-color:#7c3434; }
  .btn.danger:hover { background:#7c3434; }
  .btn.primary { background:#4a6a9a; border-color:#3a5a8a; }
  .btn.primary:hover { background:#5a7aaa; }
  .mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:.9em; background:#1e1e1e; border:1px solid #333; padding:10px 12px; margin:8px 0; line-height:1.6; word-break:break-all; }
  .step-title { font-weight:600; font-size:.95em; color:#ddd; margin-bottom:10px; }
  .ok { color:#7ddb9a; }
</style>
</head>
<body>
<div class="card">
  <h1><?php echo t('frf_h1', 'Factory Reset'); ?></h1>
  <p class="sub" id="sub"><?php echo t('frf_sub0', 'Erase database and rebuild'); ?></p>
  <div id="body"><?php echo t('frf_loading', 'Loading…'); ?></div>
</div>

<script>
var FRF_L = {
  sub0: <?php echo json_encode(t('frf_sub0', 'Erase database and rebuild')); ?>,
  confirmWarn: <?php echo json_encode(t('frf_confirm_warn', 'Are you sure you want to factory reset? This will delete <b>all</b> user data, logs and configuration. <b>Irreversible</b>.')); ?>,
  confirmChk: <?php echo json_encode(t('frf_confirm_chk', 'I have confirmed: delete everything including all user data, logs and configuration, and understand this cannot be undone.')); ?>,
  btnAbort: <?php echo json_encode(t('frf_btn_abort', 'Abort')); ?>,
  btnContinue: <?php echo json_encode(t('frf_btn_continue', 'Continue')); ?>,
  alertConfirm: <?php echo json_encode(t('frf_alert_confirm', 'Please tick the confirmation first.')); ?>,
  s1Title: <?php echo json_encode(t('frf_s1_title', 'Preparing')); ?>,
  s1Step: <?php echo json_encode(t('frf_s1_step', 'Configuring deletion request')); ?>,
  s1Contacting: <?php echo json_encode(t('frf_s1_contacting', 'Contacting server…')); ?>,
  s1Ready: <?php echo json_encode(t('frf_s1_ready', 'Server ready, preparing to delete…')); ?>,
  s1Unreachable: <?php echo json_encode(t('frf_s1_unreachable', 'Server unreachable.')); ?>,
  s2Title: <?php echo json_encode(t('frf_s2_title', 'Expiring all sessions')); ?>,
  s2Step: <?php echo json_encode(t('frf_s2_step', 'Expiring all other user sessions')); ?>,
  s2Tokens: <?php echo json_encode(t('frf_s2_tokens', '(0/0 valid tokens) …')); ?>,
  s2Failed: <?php echo json_encode(t('frf_s2_failed', 'Failed')); ?>,
  s2Back: <?php echo json_encode(t('frf_s2_back', 'Back')); ?>,
  net: <?php echo json_encode(t('frf_net', 'Network error.')); ?>,
  s3Title: <?php echo json_encode(t('frf_s3_title', 'Disconnecting clients')); ?>,
  s3Step: <?php echo json_encode(t('frf_s3_step', 'Disconnecting other online clients')); ?>,
  s3Note: <?php echo json_encode(t('frf_s3_note', '(0/10 online browsers) — forcing clients to refresh…')); ?>,
  s4Title: <?php echo json_encode(t('frf_s4_title', 'Set new administrator')); ?>,
  s4Step: <?php echo json_encode(t('frf_s4_step', 'Enter the new administrator credentials')); ?>,
  s4User: <?php echo json_encode(t('frf_s4_user', 'Username')); ?>,
  s4Pass: <?php echo json_encode(t('frf_s4_pass', 'Password')); ?>,
  s4MaintHdr: <?php echo json_encode(t('frf_s4_maint_hdr', 'Maintenance Portal (leave blank = keep current)')); ?>,
  s4MaintUser: <?php echo json_encode(t('frf_s4_maint_user', 'Maintenance username')); ?>,
  s4MaintPass: <?php echo json_encode(t('frf_s4_maint_pass', 'Maintenance password')); ?>,
  s4Blank: <?php echo json_encode(t('frf_s4_blank', 'leave blank to keep current')); ?>,
  s4SkipDump: <?php echo json_encode(t('frf_s4_skip_dump', 'Skip database backup (dangerous)')); ?>,
  alertCreds: <?php echo json_encode(t('frf_alert_creds', 'Please enter administrator username and password.')); ?>,
  alertMaint: <?php echo json_encode(t('frf_alert_maint', 'Maintenance username and password must both be set (or both empty).')); ?>,
  s5Title: <?php echo json_encode(t('frf_s5_title', 'Rebuilding database')); ?>,
  s5Step: <?php echo json_encode(t('frf_s5_step', 'Deleting and rebuilding the database, please wait.')); ?>,
  s5Prep: <?php echo json_encode(t('frf_s5_prep', 'Preparing to delete…')); ?>,
  s5Backup: <?php echo json_encode(t('frf_s5_backup', 'Backing up database…')); ?>,
  s5Del: <?php echo json_encode(t('frf_s5_del', '> Deleting database…')); ?>,
  s5Schema: <?php echo json_encode(t('frf_s5_schema', '> Rebuilding from schema.sql…')); ?>,
  s5Fail: <?php echo json_encode(t('frf_s5_fail', 'Rebuild failed: ')); ?>,
  s5Unknown: <?php echo json_encode(t('frf_s5_unknown', 'Unknown error')); ?>,
  s5Close: <?php echo json_encode(t('frf_s5_close', 'Close')); ?>,
  s6Title: <?php echo json_encode(t('frf_s6_title', 'Reset complete')); ?>,
  s6Ok: <?php echo json_encode(t('frf_s6_ok', '✓ Factory reset successful')); ?>,
  s6Login: <?php echo json_encode(t('frf_s6_login', 'Please log in again with the new credentials:')); ?>,
  s6AdminUser: <?php echo json_encode(t('frf_s6_admin_user', 'Administrator username: ')); ?>,
  s6Pass: <?php echo json_encode(t('frf_s6_pass', 'Password: ')); ?>,
  s6MaintUser: <?php echo json_encode(t('frf_s6_maint_user', 'Maintenance username: ')); ?>,
  s6MaintPass: <?php echo json_encode(t('frf_s6_maint_pass', 'Maintenance password: ')); ?>,
  s6Backup: <?php echo json_encode(t('frf_s6_backup', 'Backup file: ')); ?>,
  s6Relogin: <?php echo json_encode(t('frf_s6_relogin', 'Log in again')) ?>
};
function frApi(action, data){
  var f = new URLSearchParams();
  f.append('action', action);
  for (var k in (data||{})) f.append(k, data[k]);
  return fetch('/api/factory_reset.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:f.toString() }).then(function(r){ return r.json(); });
}
function show(html){ document.getElementById('body').innerHTML = html; }
function actions(html){ return '<div class="actions">' + html + '</div>'; }

/* ---- Step 0: 二次确认 ---- */
function stepConfirm(){
  document.getElementById('sub').textContent = FRF_L.sub0;
  show(
    '<div class="warn">' + FRF_L.confirmWarn + '</div>'+
    '<label class="check"><input type="checkbox" id="frConfirm2"> ' + FRF_L.confirmChk + '</label>'+
    actions('<button class="btn danger" onclick="frAbort()">' + FRF_L.btnAbort + '</button><button class="btn primary" onclick="frContinue()">' + FRF_L.btnContinue + '</button>')
  );
}
function frContinue(){
  if (!document.getElementById('frConfirm2').checked){ alert(FRF_L.alertConfirm); return; }
  step1();
}
/* 嵌入 iframe 时告知父页面关闭；独立窗口则 window.close() */
function frClose(){
  try {
    if (window.parent && window.parent !== window && typeof window.parent.closeFactoryReset === 'function') {
      window.parent.closeFactoryReset();
      return;
    }
  } catch (e) {}
  window.close();
}
function frAbort(){
  frApi('abort').then(frClose).catch(frClose);
}

/* ---- Step 1: 准备删除 ---- */
function step1(){
  document.getElementById('sub').textContent = FRF_L.s1Title;
  show('<div class="step-title">' + FRF_L.s1Step + '</div><p class="hint" id="frStatus">' + FRF_L.s1Contacting + '</p>');
  frApi('status').then(function(){
    var s = document.getElementById('frStatus');
    if (s) s.textContent = FRF_L.s1Ready;
    setTimeout(step2, 1200);
  }).catch(function(){
    var s = document.getElementById('frStatus');
    if (s) s.textContent = FRF_L.s1Unreachable;
  });
}

/* ---- Step 2: 过期所有会话 ---- */
function step2(){
  document.getElementById('sub').textContent = FRF_L.s2Title;
  show('<div class="step-title">' + FRF_L.s2Step + '</div>'+
       '<div class="bar"><div id="frBar" style="width:0%"></div></div>'+
       '<p class="hint" id="frSub">' + FRF_L.s2Tokens + '</p>');
  frApi('expire_tokens').then(function(d){
    if (!d.success){ show('<div class="warn">'+(d.error||FRF_L.s2Failed)+'</div>'+actions('<button class="btn" onclick="stepConfirm()">' + FRF_L.s2Back + '</button>')); return; }
    var pct = d.total ? Math.round(d.expired/d.total*100) : 100;
    document.getElementById('frBar').style.width = pct+'%';
    document.getElementById('frSub').textContent = '('+d.expired+'/'+d.total+' valid tokens)';
    setTimeout(step3, 6000);
  }).catch(function(){ show('<div class="warn">' + FRF_L.net + '</div>'); });
}

/* ---- Step 3: 断开客户端（自动跳过） ---- */
function step3(){
  document.getElementById('sub').textContent = FRF_L.s3Title;
  show('<div class="step-title">' + FRF_L.s3Step + '</div>'+
       '<div class="bar"><div id="frBar" style="width:0%"></div></div>'+
       '<p class="hint" id="frSub">' + FRF_L.s3Note + '</p>');
  var i = 0;
  var t = setInterval(function(){
    i += 10;
    var b = document.getElementById('frBar'); if (b) b.style.width = Math.min(100,i)+'%';
    var s = document.getElementById('frSub'); if (s) s.textContent = '('+Math.min(10,Math.round(i/10))+'/10 online browsers)';
    if (i >= 100){ clearInterval(t); }
  }, 900);
  setTimeout(step4, 8000);
}

/* ---- Step 4: 新管理员凭据 ---- */
function step4(){
  document.getElementById('sub').textContent = FRF_L.s4Title;
  show('<div class="step-title">' + FRF_L.s4Step + '</div>'+
       '<div class="fg"><label>' + FRF_L.s4User + '</label><input type="text" id="frNewU" autocomplete="off"></div>'+
       '<div class="fg"><label>' + FRF_L.s4Pass + '</label><input type="password" id="frNewP" autocomplete="new-password"></div>'+
       '<div style="margin:16px 0 10px;padding-top:12px;border-top:1px solid #333;font-size:.8em;color:#888">' + FRF_L.s4MaintHdr + '</div>'+
       '<div class="fg"><label>' + FRF_L.s4MaintUser + '</label><input type="text" id="frMaintU" autocomplete="off" placeholder="' + FRF_L.s4Blank + '"></div>'+
       '<div class="fg"><label>' + FRF_L.s4MaintPass + '</label><input type="password" id="frMaintP" autocomplete="new-password" placeholder="' + FRF_L.s4Blank + '"></div>'+
       '<label class="check"><input type="checkbox" id="frSkipDump"> ' + FRF_L.s4SkipDump + '</label>'+
       actions('<button class="btn danger" onclick="frAbort()">' + FRF_L.btnAbort + '</button><button class="btn primary" onclick="frSetup()">' + FRF_L.btnContinue + '</button>'));
}
function frSetup(){
  var u = document.getElementById('frNewU').value.trim();
  var p = document.getElementById('frNewP').value;
  var mu = document.getElementById('frMaintU').value.trim();
  var mp = document.getElementById('frMaintP').value;
  var skip = document.getElementById('frSkipDump').checked;
  if (!u || !p){ alert(FRF_L.alertCreds); return; }
  if ((!!mu) !== (!!mp)){ alert(FRF_L.alertMaint); return; }
  frApi('setup_creds', { username:u, password:p, maint_user:mu, maint_pass:mp, skip_dump: skip?'1':'0' }).then(function(d){
    if (!d.success){ alert(d.error); return; }
    step5(skip);
  });
}

/* ---- Step 5: 删除 + 重建 ---- */
function step5(skip){
  document.getElementById('sub').textContent = FRF_L.s5Title;
  show('<div class="step-title">' + FRF_L.s5Step + '</div>'+
       '<p class="hint" id="frStatus">'+(skip ? FRF_L.s5Prep : FRF_L.s5Backup)+'</p>');
  setTimeout(function(){ var s=document.getElementById('frStatus'); if(s) s.textContent = FRF_L.s5Del; }, 800);
  setTimeout(function(){ var s=document.getElementById('frStatus'); if(s) s.textContent = FRF_L.s5Schema; }, 1800);
  frApi('rebuild').then(function(d){
    if (!d.success){ show('<div class="warn">' + FRF_L.s5Fail + (d.error||FRF_L.s5Unknown) + '</div>'+actions('<button class="btn" onclick="frClose()">' + FRF_L.s5Close + '</button>')); return; }
    step6(d);
  }).catch(function(){ show('<div class="warn">' + FRF_L.net + '</div>'); });
}

/* ---- Step 6: 完成 ---- */
function step6(d){
  document.getElementById('sub').textContent = FRF_L.s6Title;
  show('<div class="step-title ok">' + FRF_L.s6Ok + '</div>'+
       '<p class="hint">' + FRF_L.s6Login + '</p>'+
       '<div class="mono">' + FRF_L.s6AdminUser + ' <b>'+d.username+'</b><br>' + FRF_L.s6Pass + ' <b>'+d.password+'</b></div>'+
       (d.maint_user ? '<div class="mono">' + FRF_L.s6MaintUser + ' <b>'+d.maint_user+'</b><br>' + FRF_L.s6MaintPass + ' <b>'+d.maint_pass+'</b></div>' : '')+
       (d.backup ? '<p class="hint">' + FRF_L.s6Backup + d.backup+'</p>' : '')+
       actions('<button class="btn primary" onclick="frLogout()">' + FRF_L.s6Relogin + '</button>'));
}
function frLogout(){
  var topWin = (window.top || window);
  fetch('/api/auth.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=logout' })
    .then(function(){ topWin.location.href='/modern/wp/login.php'; })
    .catch(function(){ topWin.location.href='/modern/wp/login.php'; });
}

stepConfirm();
</script>
<?php include __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>
