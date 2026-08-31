<?php
/**
 * ChatApp · Downgrade System 管理页（极度危险）
 * 从 git 历史选版本 → 三重验证 → 回退代码（排除 config/data/maintenance）。
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();

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
<title><?php echo t('dg_title', 'Downgrade System'); ?></title>
<link rel="stylesheet" href="/plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="/modern/style/settings.css?v=20260828">
<style>
  @font-face{font-family:'Roboto';src:url('../../css/fonts/Roboto-Regular.ttf') format('truetype');font-weight:400;font-style:normal}
  @font-face{font-family:'Chinese';src:url('../../css/fonts/chinese.otf') format('opentype');font-weight:400;font-style:normal}
  body{font-family:'Roboto','Chinese',-apple-system,BlinkMacSystemFont,'Segoe UI','Helvetica Neue',sans-serif}
  .fr-title{color:#ff6b6b;font-size:1.05em;font-weight:700;margin:14px 0 6px;text-align:center}
  .fr-desc{color:#bbb;font-size:.76em;line-height:1.6;margin:0 4px 8px;word-break:break-word}
  .fr-desc b{color:#ff8a8a}
  .dg-danger{
    margin:10px 0 14px;padding:12px;border:1px solid #ff3b3b;border-radius:8px;
    background:rgba(255,59,59,0.08);color:#ff9a9a;font-size:.78em;line-height:1.6;
  }
  .dg-danger b{color:#ff5555}
  .dg-result{display:none;text-align:center;padding:20px 6px}
  .dg-result .ok{color:#7ddb9a;font-size:1.05em;font-weight:700;margin:8px 0}
  .dg-result .sub{color:#bbb;font-size:.78em;line-height:1.7}
</style>
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title" style="color:#ff6b6b"><?php echo t('dg_title', 'Downgrade System'); ?></span>
    <span style="width:28px"></span>
  </div>

  <div id="dgForm">
    <div class="dg-danger"><?php echo t('dg_danger', '<b>EXTREMELY DANGEROUS</b>: reverts the entire codebase to an older version. Database schema and code may become incompatible; features added after that version will disappear. Effectively one-way — proceed only if you know what you are doing.'); ?></div>
    <p class="fr-desc"><?php echo t('dg_desc', 'Enter the current ChatApp git version in uppercase, the current password of Administrator %s (10000) and credentials of Maintenance Portal to proceed.', $__adminLabel); ?></p>

    <div class="set-block">
      <div class="set-field">
        <label><?php echo t('dg_lbl_head', 'Current version'); ?></label>
        <input type="text" id="dgHead" readonly placeholder="<?php echo t('dg_loading', 'Loading versions…'); ?>">
      </div>
      <div class="set-field">
        <label><?php echo t('dg_lbl_target', 'Select target version'); ?></label>
        <select id="dgTarget" style="width:100%;padding:10px 12px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.85em;font-family:inherit;outline:none"></select>
      </div>
      <div class="set-field">
        <label><?php echo t('dg_lbl_pwd', 'Password'); ?></label>
        <input type="password" id="dgPwd" autocomplete="current-password" placeholder="Administrator (10000)">
      </div>
      <div class="set-field">
        <label><?php echo t('dg_lbl_maint_user', 'Maintenance Mode Account'); ?></label>
        <input type="text" id="dgMUser" autocomplete="off">
      </div>
      <div class="set-field">
        <label><?php echo t('dg_lbl_maint_secret', 'Maintenance Mode Passphrase'); ?></label>
        <input type="password" id="dgMSecret" autocomplete="off">
      </div>
      <div class="set-field">
        <label><?php echo t('dg_lbl_hash1', 'Enter current git hash'); ?></label>
        <input type="text" id="dgHash1" autocomplete="off" spellcheck="false" placeholder="git log -1 --format=%H">
      </div>
      <div class="set-field">
        <label><?php echo t('dg_lbl_hash2', 'Re-enter current git hash'); ?></label>
        <input type="text" id="dgHash2" autocomplete="off" spellcheck="false" placeholder="git log -1 --format=%H">
      </div>
      <label class="set-check-row"><input type="checkbox" id="dgConfirm"> <span style="color:#ff8a8a"><?php echo t('dg_chk_confirm', 'I understand this is extremely dangerous and may break the installation.'); ?></span></label>
      <button class="set-btn danger" onclick="runDowngrade()"><?php echo t('dg_btn_go', 'Downgrade now'); ?></button>
      <button class="set-btn" style="margin-top:8px" onclick="goBack()"><?php echo t('dg_btn_abort', 'Abort'); ?></button>
    </div>
  </div>

  <div class="dg-result" id="dgResult">
    <div style="font-size:34px"><?php echo svg_ic('warning', 34);?></div>
    <div class="ok" id="dgResultText"></div>
    <div class="sub"><?php echo t('dg_done_sub', 'The codebase has been reverted. Reloading the page will show the selected (older) version.'); ?></div>
  </div>

</div>

<div class="save-toast" id="saveToast">✓</div>

<script>
var DG_L = {
  req: <?php echo json_encode(t('dg_err_req', 'All fields are required')); ?>,
  hash: <?php echo json_encode(t('dg_err_hash', 'Git hash mismatch')); ?>,
  conf: <?php echo json_encode(t('dg_err_conf', 'Please confirm before downgrading')); ?>,
  list: <?php echo json_encode(t('dg_err_list', 'Failed to load versions')); ?>,
  fail: <?php echo json_encode(t('dg_err_fail', 'Downgrade failed')); ?>,
  net: <?php echo json_encode(t('dg_err_net', 'Network error')); ?>
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
function api(action, extra){
  var f = new URLSearchParams();
  f.append('action', action);
  for (var k in (extra || {})) f.append(k, extra[k]);
  return fetch('/api/downgrade.php', {
    method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: f.toString()
  }).then(function(r){ return r.json(); });
}
function loadList(){
  api('list').then(function(d){
    if (!d.success) { showErr(d.error || DG_L.list); return; }
    document.getElementById('dgHead').value = d.head.slice(0, 12);
    var sel = document.getElementById('dgTarget');
    sel.innerHTML = '';
    if (!d.commits.length) {
      var o = document.createElement('option'); o.textContent = DG_L.list; sel.appendChild(o);
      return;
    }
    d.commits.forEach(function(c){
      var o = document.createElement('option');
      o.value = c.hash;
      o.textContent = (c.current ? '★ ' : '') + c.short + '  ·  ' + c.subject + '  ·  ' + c.date;
      sel.appendChild(o);
    });
  }).catch(function(){ showErr(DG_L.net); });
}
function runDowngrade(){
  var pwd = document.getElementById('dgPwd').value;
  var mu  = document.getElementById('dgMUser').value.trim();
  var ms  = document.getElementById('dgMSecret').value;
  var h1  = document.getElementById('dgHash1').value.trim().toUpperCase();
  var h2  = document.getElementById('dgHash2').value.trim().toUpperCase();
  var target = document.getElementById('dgTarget').value;
  var conf = document.getElementById('dgConfirm').checked;
  if (!pwd || !mu || !ms || !h1 || !h2 || !target) { showErr(DG_L.req); return; }
  if (h1 !== h2) { showErr(DG_L.hash); return; }
  if (!conf) { showErr(DG_L.conf); return; }
  api('perform', { password: pwd, maint_user: mu, maint_secret: ms, git_hash: h1, git_hash2: h2, target: target }).then(function(d){
    if (d.success) {
      document.getElementById('dgForm').style.display = 'none';
      var r = document.getElementById('dgResult');
      document.getElementById('dgResultText').textContent = (d.from || '').slice(0, 12) + ' → ' + (d.to || '').slice(0, 12);
      r.style.display = 'block';
    } else {
      showErr(d.error || DG_L.fail);
    }
  }).catch(function(){ showErr(DG_L.net); });
}
loadList();
</script>
</body>
</html>
