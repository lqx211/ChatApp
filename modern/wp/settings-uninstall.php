<?php
/**
 * ChatApp · Uninstall 管理页（单行道，三重验证）
 * 由 api/uninstall.php 执行：阶段一删 /var/www/html（留 api/ 与 modern/）→ 返回成功 →
 * 阶段二后台彻底删除 + Apache 仅此一站则停服。
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();

// 动态读取管理员（uid 10000）显示名
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
<title><?php echo t('un_title', 'Uninstall ChatApp'); ?></title>
<link rel="stylesheet" href="/plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="/modern/style/settings.css?v=20260828">
<style>
  @font-face{font-family:'Roboto';src:url('../../css/fonts/Roboto-Regular.ttf') format('truetype');font-weight:400;font-style:normal}
  @font-face{font-family:'Chinese';src:url('../../css/fonts/chinese.otf') format('opentype');font-weight:400;font-style:normal}
  body{font-family:'Roboto','Chinese',-apple-system,BlinkMacSystemFont,'Segoe UI','Helvetica Neue',sans-serif}
  .fr-title{color:#ff6b6b;font-size:1.05em;font-weight:700;margin:14px 0 6px;text-align:center}
  .fr-desc{color:#bbb;font-size:.76em;line-height:1.6;margin:0 4px 8px;word-break:break-word}
  .fr-desc b{color:#ff8a8a}
  #unDone{display:none;text-align:center;padding:26px 6px}
  #unDone .ok{color:#7ddb9a;font-size:1.15em;font-weight:700;margin:10px 0}
  #unDone .sub{color:#bbb;font-size:.8em;line-height:1.7}
</style>
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title" style="color:#ff6b6b">Uninstall ChatApp</span>
    <span style="width:28px"></span>
  </div>

  <div id="unForm">
    <p class="fr-desc"><?php echo t('un_desc1', 'This will <b>remove ChatApp from this server</b>: delete the deployed files (<b>/var/www/html</b>), the <b>chatapp database</b> (unless you uncheck below) and the <b>WebSocket systemd service</b>. If this server hosts no other website, <b>Apache will be stopped</b>.'); ?></p>
    <p class="fr-desc"><?php echo t('un_desc2', 'This is <b>permanent and cannot be undone</b>. Your source code in <b>~/ChatApp</b> is kept.'); ?></p>
    <p class="fr-desc"><?php echo t('un_desc3', 'Enter the current ChatApp git version in uppercase, the current password of Administrator %s (10000) and credentials of Maintenance Portal to proceed.', $__adminLabel); ?></p>

    <div class="set-block">
      <div class="set-field">
        <label><?php echo t('un_lbl_pwd', 'Password'); ?></label>
        <input type="password" id="unPwd" autocomplete="current-password" placeholder="Administrator (10000)">
      </div>
      <div class="set-field">
        <label><?php echo t('un_lbl_maint_user', 'Maintenance Mode Account'); ?></label>
        <input type="text" id="unMUser" autocomplete="off">
      </div>
      <div class="set-field">
        <label><?php echo t('un_lbl_maint_secret', 'Maintenance Mode Passphrase'); ?></label>
        <input type="password" id="unMSecret" autocomplete="off">
      </div>
      <div class="set-field">
        <label><?php echo t('un_lbl_hash1', 'Enter current git hash'); ?></label>
        <input type="text" id="unHash1" autocomplete="off" spellcheck="false" placeholder="git log -1 --format=%H">
      </div>
      <div class="set-field">
        <label><?php echo t('un_lbl_hash2', 'Re-enter current git hash'); ?></label>
        <input type="text" id="unHash2" autocomplete="off" spellcheck="false" placeholder="git log -1 --format=%H">
      </div>
      <label class="set-check-row"><input type="checkbox" id="unDbDel" checked> <span><?php echo t('un_chk_db', 'Delete database <b style="color:#ff8a8a">chatapp</b> (uncheck to KEEP data)'); ?></span></label>
      <label class="set-check-row"><input type="checkbox" id="unConfirm"> <span style="color:#ff8a8a"><?php echo t('un_chk_confirm', 'I understand: everything will be deleted and cannot be recovered.'); ?></span></label>
      <button class="set-btn danger" onclick="runUninstall()"><?php echo t('un_btn_uninstall', 'Uninstall ChatApp'); ?></button>
      <button class="set-btn" style="margin-top:8px" onclick="goBack()"><?php echo t('un_btn_abort', 'Abort'); ?></button>
    </div>
  </div>

  <div id="unDone">
    <div style="font-size:40px">🗑️</div>
    <div class="ok">✓ <?php echo t('un_done_title', 'ChatApp has been uninstalled'); ?></div>
    <div class="sub"><?php echo t('un_done_sub', 'The remaining files are being removed in the background.<br>Apache may be stopped if this server hosted no other website.<br>You can close this page / tab now.'); ?></div>
    <div style="margin-top:16px;color:#8aa6c8;font-size:.85em">💙 <?php echo t('un_thanks', 'Thanks for using ChatApp!'); ?></div>
  </div>

</div>

<!-- 二次确认弹窗（强调不可恢复） -->
<div id="unModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:20000;align-items:center;justify-content:center;padding:20px">
  <div style="background:#2a2a2a;border:1px solid #7c3434;border-radius:12px;padding:22px 20px;width:320px;max-width:100%;text-align:center;box-shadow:0 12px 40px rgba(0,0,0,0.6)">
    <div style="font-size:34px">⚠️</div>
    <div style="color:#ff8a8a;font-weight:700;margin:10px 0 6px;font-size:.95em"><?php echo t('un_modal_title', 'Are you absolutely sure?'); ?></div>
    <div style="color:#bbb;font-size:.78em;line-height:1.65;margin-bottom:16px"><?php echo t('un_modal_body', 'This will <b>permanently delete</b> the deployed ChatApp and (by default) its database. <b>This cannot be undone</b> — there is no recovery.'); ?></div>
    <div style="display:flex;gap:8px">
      <button class="set-btn" onclick="closeUnModal()"><?php echo t('un_btn_cancel', 'Cancel'); ?></button>
      <button class="set-btn danger" onclick="doUninstall()"><?php echo t('un_btn_yes', 'Yes, uninstall permanently'); ?></button>
    </div>
  </div>
</div>

<div class="save-toast" id="saveToast">✓</div>

<script>
var UN_L = {
  req: <?php echo json_encode(t('un_err_req', 'All fields are required')); ?>,
  hash: <?php echo json_encode(t('un_err_hash', 'Git hash mismatch')); ?>,
  conf: <?php echo json_encode(t('un_err_conf', 'Please confirm before uninstalling')); ?>,
  fail: <?php echo json_encode(t('un_err_fail', 'Uninstall failed')); ?>,
  net: <?php echo json_encode(t('un_err_net', 'Network error')); ?>
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
  }, 4000);
}
function runUninstall(){
  var pwd = document.getElementById('unPwd').value;
  var mu  = document.getElementById('unMUser').value.trim();
  var ms  = document.getElementById('unMSecret').value;
  var h1  = document.getElementById('unHash1').value.trim().toUpperCase();
  var h2  = document.getElementById('unHash2').value.trim().toUpperCase();
  var conf = document.getElementById('unConfirm').checked;
  if (!pwd || !mu || !ms || !h1 || !h2) { showErr(UN_L.req); return; }
  if (h1 !== h2) { showErr(UN_L.hash); return; }
  if (!conf) { showErr(UN_L.conf); return; }
  showUnModal();
}
function showUnModal(){ document.getElementById('unModal').style.display = 'flex'; }
function closeUnModal(){ document.getElementById('unModal').style.display = 'none'; }
function doUninstall(){
  closeUnModal();
  var pwd = document.getElementById('unPwd').value;
  var mu  = document.getElementById('unMUser').value.trim();
  var ms  = document.getElementById('unMSecret').value;
  var h1  = document.getElementById('unHash1').value.trim().toUpperCase();
  var h2  = document.getElementById('unHash2').value.trim().toUpperCase();
  var db  = document.getElementById('unDbDel').checked ? '1' : '0';
  var f = new URLSearchParams();
  f.append('action', 'perform');
  f.append('password', pwd);
  f.append('maint_user', mu);
  f.append('maint_secret', ms);
  f.append('git_hash', h1);
  f.append('git_hash2', h2);
  f.append('db_delete', db);
  fetch('/api/uninstall.php', {
    method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: f.toString()
  }).then(function(r){ return r.json(); })
    .then(function(d){
      if (d.success) {
        document.getElementById('unForm').style.display = 'none';
        document.getElementById('unDone').style.display = 'block';
      } else {
        showErr(d.error || UN_L.fail);
      }
    })
    .catch(function(){ showErr(UN_L.net); });
}
</script>
</body>
</html>
