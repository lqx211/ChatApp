<?php
/**
 * ChatApp · Factory Reset 独立流程窗口（Win8.1 红色标题风格）
 * 由 settings-factory.php 验证通过后 window.open 打开。
 * 流程：二次确认 → 过期会话 → 断开客户端 → 新凭据 → DROP+重建 → 完成。
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=600, initial-scale=1.0">
<title>Factory Reset</title>
<style>
  body{margin:0;font-family:'Segoe UI',Tahoma,Arial,sans-serif;background:#ececec}
  .win{width:560px;margin:28px auto;background:#f5f5f5;border:1px solid #8a8a8a;box-shadow:0 2px 12px rgba(0,0,0,.45)}
  .win-title{background:linear-gradient(#d8432f,#a82a1a);color:#fff;padding:9px 14px;font-size:14px;font-weight:600;letter-spacing:.5px;display:flex;justify-content:space-between;align-items:center}
  .win-title .x{color:#fff;opacity:.9;font-weight:700;cursor:pointer;font-size:15px}
  .win-body{padding:22px 26px;font-size:13px;color:#222;min-height:220px}
  .warn{color:#c00;font-weight:700;font-size:13px;line-height:1.5}
  .hint{color:#666;font-size:12px;line-height:1.6}
  .bar{height:18px;border:1px solid #999;background:#fff;margin:12px 0;border-radius:2px;overflow:hidden}
  .bar div{height:100%;background:#4a9dd8;transition:width .3s}
  .btn{padding:6px 22px;border:1px solid #8a8a8a;background:#e6e6e6;font-size:13px;cursor:pointer;min-width:84px}
  .btn:hover{background:#fff}
  .btn.primary{background:#4a9dd8;border-color:#2a7db8;color:#fff}
  .btn.primary:hover{background:#5aadd8}
  input[type=text],input[type=password]{padding:5px 7px;border:1px solid #999;font-size:13px;width:62%}
  label.inline{display:block;margin:10px 0;font-size:12px;color:#a33}
  .mono{font-family:Consolas,monospace;font-size:13px}
  .foot{margin-top:16px;text-align:right}
  .step-title{font-weight:600;font-size:13px;margin-bottom:10px}
</style>
</head>
<body>
<div class="win">
  <div class="win-title"><span>Factory Reset</span><span class="x" onclick="frAbort()">✕</span></div>
  <div class="win-body" id="body">Loading…</div>
</div>

<script>
function frApi(action, data){
  var f = new URLSearchParams();
  f.append('action', action);
  for (var k in (data||{})) f.append(k, data[k]);
  return fetch('/api/factory_reset.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:f.toString() }).then(function(r){ return r.json(); });
}
function show(html){ document.getElementById('body').innerHTML = html; }

/* ---- Step 0: 二次确认 ---- */
function stepConfirm(){
  show(
    '<div class="warn">Are you really sure to factory reset your database? This can\'t be reverted.</div>'+
    '<label class="inline"><input type="checkbox" id="frConfirm2"> I\'m sure to delete everything, including everyone\'s data, all logs and all configurations and understand its irreversible.</label>'+
    '<div class="foot"><button class="btn" onclick="frAbort()">Abort</button> <button class="btn primary" onclick="frContinue()">Continue</button></div>'
  );
}
function frContinue(){
  if (!document.getElementById('frConfirm2').checked){ alert('Please confirm before continuing.'); return; }
  step1();
}
function frAbort(){
  frApi('abort').then(function(){ window.close(); }).catch(function(){ window.close(); });
}

/* ---- Step 1: configuring deletion ---- */
function step1(){
  show('<div class="step-title">Factory Reset</div><p>Please wait while setup is configuring your deletion request.</p>'+
       '<p class="hint" id="frStatus">Contacting server…</p>');
  frApi('status').then(function(d){
    var s = document.getElementById('frStatus');
    s.textContent = 'Server is preparing deletion…';
    setTimeout(step2, 1500);
  }).catch(function(){
    document.getElementById('frStatus').textContent = 'Server unreachable.';
  });
}

/* ---- Step 2: expire all sessions ---- */
function step2(){
  show('<div class="step-title">Factory Reset</div>'+
       '<p>Please wait until all other user sessions are expired.</p>'+
       '<div class="bar"><div id="frBar" style="width:0%"></div></div>'+
       '<p class="hint" id="frSub">(0/0 valid tokens) …</p>');
  frApi('expire_tokens').then(function(d){
    if (!d.success){ show('<div class="warn">'+d.error+'</div><div class="foot"><button class="btn" onclick="stepConfirm()">Back</button></div>'); return; }
    var pct = d.total ? Math.round(d.expired/d.total*100) : 100;
    document.getElementById('frBar').style.width = pct+'%';
    document.getElementById('frSub').textContent = '('+d.expired+'/'+d.total+' valid tokens)';
    setTimeout(step3, 10000);   // 10 秒后自动进入下一步（不等待完成）
  }).catch(function(){ show('<div class="warn">Network error.</div>'); });
}

/* ---- Step 3: disconnect clients（10 秒自动跳过） ---- */
function step3(){
  show('<div class="step-title">Factory Reset</div>'+
       '<p>Please wait until all other active user sessions are disconnected.</p>'+
       '<div class="bar"><div id="frBar" style="width:0%"></div></div>'+
       '<p class="hint" id="frSub">(0/0 online browsers) — forcing client reload…</p>');
  var i = 0;
  var t = setInterval(function(){
    i += 10;
    document.getElementById('frBar').style.width = Math.min(100,i)+'%';
    document.getElementById('frSub').textContent = '('+Math.min(10,Math.round(i/10))+'/10 online browsers)';
    if (i >= 100){ clearInterval(t); }
  }, 900);
  setTimeout(step4, 10000);    // 10 秒自动跳转，不关心进度
}

/* ---- Step 4: new admin credentials ---- */
function step4(){
  show('<div class="step-title">Factory Reset</div><p>Please enter new administrator credentials:</p>'+
       '<div style="margin:8px 0"><label>Username:</label> <input type="text" id="frNewU" autocomplete="off"></div>'+
       '<div style="margin:8px 0"><label>Password:</label> <input type="text" id="frNewP" autocomplete="off"></div>'+
       '<label class="inline"><input type="checkbox" id="frSkipDump"> Skip database dump (dangerous)</label>'+
       '<div class="foot"><button class="btn" onclick="frAbort()">Abort</button> <button class="btn primary" onclick="frSetup()">Continue</button></div>');
}
function frSetup(){
  var u = document.getElementById('frNewU').value.trim();
  var p = document.getElementById('frNewP').value;
  var skip = document.getElementById('frSkipDump').checked;
  if (!u || !p){ alert('Enter both username and password.'); return; }
  frApi('setup_creds', { username:u, password:p, skip_dump: skip?'1':'0' }).then(function(d){
    if (!d.success){ alert(d.error); return; }
    step5(skip);
  });
}

/* ---- Step 5: drop + rebuild ---- */
function step5(skip){
  show('<div class="step-title">Dropping and rebuilding database, please wait.</div>'+
       '<p class="hint" id="frStatus">'+ (skip ? 'Preparing to drop…' : 'Please wait while server is dumping database…') +'</p>');
  setTimeout(function(){ document.getElementById('frStatus').textContent = '> Dropping…'; }, 1000);
  setTimeout(function(){ document.getElementById('frStatus').textContent = '> Rebuilding from /schema.sql…'; }, 2600);
  frApi('rebuild').then(function(d){
    if (!d.success){ show('<div class="warn">Rebuild failed: '+d.error+'</div><div class="foot"><button class="btn" onclick="window.close()">Close</button></div>'); return; }
    step6(d);
  }).catch(function(){ show('<div class="warn">Network error.</div>'); });
}

/* ---- Step 6: success ---- */
function step6(d){
  show('<div class="step-title">Factory reset success.</div>'+
       '<p>Please reload your session and login with new creds:</p>'+
       '<p class="mono">Username: <b>'+d.username+'</b><br>Password: <b>'+d.password+'</b></p>'+
       (d.backup ? '<p class="hint">Backup: '+d.backup+'</p>' : '')+
       '<div class="foot"><button class="btn primary" onclick="location.href=\'/modern/wp/login.php\'">Reload</button></div>');
}

stepConfirm();
</script>
</body>
</html>
