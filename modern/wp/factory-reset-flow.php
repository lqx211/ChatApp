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
  <h1>Factory Reset</h1>
  <p class="sub" id="sub">擦除数据库并重建</p>
  <div id="body">Loading…</div>
</div>

<script>
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
  document.getElementById('sub').textContent = '擦除数据库并重建';
  show(
    '<div class="warn">确定要执行工厂重置吗？将删除所有人的数据、日志与配置，<b>不可撤销</b>。</div>'+
    '<label class="check"><input type="checkbox" id="frConfirm2"> 我已确认要删除一切，包括所有用户数据、日志和配置，并明白此操作不可恢复。</label>'+
    actions('<button class="btn danger" onclick="frAbort()">放弃</button><button class="btn primary" onclick="frContinue()">继续</button>')
  );
}
function frContinue(){
  if (!document.getElementById('frConfirm2').checked){ alert('请先勾选确认。'); return; }
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
  document.getElementById('sub').textContent = '正在准备';
  show('<div class="step-title">正在配置删除请求</div><p class="hint" id="frStatus">联系服务器…</p>');
  frApi('status').then(function(){
    var s = document.getElementById('frStatus');
    if (s) s.textContent = '服务器已就绪，准备删除…';
    setTimeout(step2, 1200);
  }).catch(function(){
    var s = document.getElementById('frStatus');
    if (s) s.textContent = '服务器不可达。';
  });
}

/* ---- Step 2: 过期所有会话 ---- */
function step2(){
  document.getElementById('sub').textContent = '正在过期所有会话';
  show('<div class="step-title">正在使所有其他用户会话过期</div>'+
       '<div class="bar"><div id="frBar" style="width:0%"></div></div>'+
       '<p class="hint" id="frSub">(0/0 valid tokens) …</p>');
  frApi('expire_tokens').then(function(d){
    if (!d.success){ show('<div class="warn">'+(d.error||'失败')+'</div>'+actions('<button class="btn" onclick="stepConfirm()">返回</button>')); return; }
    var pct = d.total ? Math.round(d.expired/d.total*100) : 100;
    document.getElementById('frBar').style.width = pct+'%';
    document.getElementById('frSub').textContent = '('+d.expired+'/'+d.total+' valid tokens)';
    setTimeout(step3, 6000);
  }).catch(function(){ show('<div class="warn">网络错误。</div>'); });
}

/* ---- Step 3: 断开客户端（自动跳过） ---- */
function step3(){
  document.getElementById('sub').textContent = '正在断开客户端';
  show('<div class="step-title">正在断开其他在线客户端</div>'+
       '<div class="bar"><div id="frBar" style="width:0%"></div></div>'+
       '<p class="hint" id="frSub">(0/10 online browsers) — 正在强制客户端刷新…</p>');
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
  document.getElementById('sub').textContent = '设置新管理员';
  show('<div class="step-title">请输入新的管理员凭据</div>'+
       '<div class="fg"><label>用户名</label><input type="text" id="frNewU" autocomplete="off"></div>'+
       '<div class="fg"><label>密码</label><input type="password" id="frNewP" autocomplete="new-password"></div>'+
       '<div style="margin:16px 0 10px;padding-top:12px;border-top:1px solid #333;font-size:.8em;color:#888">Maintenance Portal（维护门户，留空 = 保持现有）</div>'+
       '<div class="fg"><label>维护用户名</label><input type="text" id="frMaintU" autocomplete="off" placeholder="留空保持现有"></div>'+
       '<div class="fg"><label>维护密码</label><input type="password" id="frMaintP" autocomplete="new-password" placeholder="留空保持现有"></div>'+
       '<label class="check"><input type="checkbox" id="frSkipDump"> 跳过数据库备份（危险）</label>'+
       actions('<button class="btn danger" onclick="frAbort()">放弃</button><button class="btn primary" onclick="frSetup()">继续</button>'));
}
function frSetup(){
  var u = document.getElementById('frNewU').value.trim();
  var p = document.getElementById('frNewP').value;
  var mu = document.getElementById('frMaintU').value.trim();
  var mp = document.getElementById('frMaintP').value;
  var skip = document.getElementById('frSkipDump').checked;
  if (!u || !p){ alert('请输入管理员用户名和密码。'); return; }
  if ((!!mu) !== (!!mp)){ alert('维护门户的用户名和密码需同时填写（或都留空）。'); return; }
  frApi('setup_creds', { username:u, password:p, maint_user:mu, maint_pass:mp, skip_dump: skip?'1':'0' }).then(function(d){
    if (!d.success){ alert(d.error); return; }
    step5(skip);
  });
}

/* ---- Step 5: 删除 + 重建 ---- */
function step5(skip){
  document.getElementById('sub').textContent = '正在重建数据库';
  show('<div class="step-title">删除并重建数据库，请稍候。</div>'+
       '<p class="hint" id="frStatus">'+(skip ? '准备删除…' : '正在备份数据库…')+'</p>');
  setTimeout(function(){ var s=document.getElementById('frStatus'); if(s) s.textContent = '> 删除数据库…'; }, 800);
  setTimeout(function(){ var s=document.getElementById('frStatus'); if(s) s.textContent = '> 从 schema.sql 重建…'; }, 1800);
  frApi('rebuild').then(function(d){
    if (!d.success){ show('<div class="warn">重建失败: '+(d.error||'未知错误')+'</div>'+actions('<button class="btn" onclick="frClose()">关闭</button>')); return; }
    step6(d);
  }).catch(function(){ show('<div class="warn">网络错误。</div>'); });
}

/* ---- Step 6: 完成 ---- */
function step6(d){
  document.getElementById('sub').textContent = '重置完成';
  show('<div class="step-title ok">✓ 工厂重置成功</div>'+
       '<p class="hint">请重新登录，使用新的凭据：</p>'+
       '<div class="mono">管理员 用户名: <b>'+d.username+'</b><br>密码: <b>'+d.password+'</b></div>'+
       (d.maint_user ? '<div class="mono">维护门户 用户名: <b>'+d.maint_user+'</b><br>密码: <b>'+d.maint_pass+'</b></div>' : '')+
       (d.backup ? '<p class="hint">备份文件: '+d.backup+'</p>' : '')+
       actions('<button class="btn primary" onclick="frLogout()">重新登录</button>'));
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
