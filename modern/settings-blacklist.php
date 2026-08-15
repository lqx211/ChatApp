<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('set_blacklist');?></title>
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('set_blacklist', 'Block list');?></span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint"><?php echo t('set_blacklist_hint', 'Users added to the block list cannot send you private messages or friend requests.');?></p>

  <div class="set-group"><?php echo t('set_blacklist_add', 'Add to block list');?></div>
  <div class="set-block" style="display:flex;gap:8px;padding:12px 16px">
    <input type="number" id="blockUid" placeholder="<?php echo t('set_blacklist_uid', 'Enter user UID');?>" style="flex:1;padding:10px 12px;background:#14161d;border:1px solid #2c3240;border-radius:10px;color:#e0e3ea;font-size:14px;font-family:inherit;outline:none">
    <button class="set-btn" style="width:auto;margin:0;padding:10px 16px" onclick="addBlock()"><?php echo t('set_blacklist_add_btn', 'Add');?></button>
  </div>

  <div class="set-group"><?php echo t('set_blacklist_list', 'Block list');?></div>
  <div id="blockList"><div style="padding:20px;color:#5a6270;font-size:13px;text-align:center"><?php echo t('set_loading', 'Loading...');?></div></div>

</div>

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
function goBack() {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
        window.parent.document.getElementById('profileFrame').src = 'settings-privacy.php';
    } else { history.back(); }
}
function showToast() {
    var t = document.getElementById('saveToast');
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
function showErr(msg) {
    var t = document.getElementById('saveToast');
    t.textContent = ' ' + msg;
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
function api(action, data, method) {
    method = method || 'POST';
    var f = new URLSearchParams();
    f.append('action', action);
    for (var k in (data || {})) f.append(k, data[k]);
    var opts = { method: method, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } };
    if (method !== 'GET') opts.body = f.toString();
    return fetch('../api/settings.php' + (method === 'GET' ? '?' + f.toString() : ''), opts)
        .then(function(r) { return r.json(); });
}
function load() {
    api('get_blocks', {}, 'GET').then(function(d) {
        var host = document.getElementById('blockList');
        if (!d.success) { host.innerHTML = '<div style="padding:20px;color:#5a6270;text-align:center"><?php echo t('msg_login_something_wrong', 'Something went wrong.');?></div>'; return; }
        if (!d.blocks || !d.blocks.length) {
            host.innerHTML = '<div style="padding:20px;color:#5a6270;text-align:center"><?php echo t('set_blacklist_empty', 'Block list is empty');?></div>';
            return;
        }
        var h = '';
        var removeLbl = '<?php echo t('set_blacklist_remove', 'Remove');?>';
        for (var i = 0; i < d.blocks.length; i++) {
            var b = d.blocks[i];
            h += '<div class="set-row"><span class="row-label" style="display:flex;align-items:center;gap:8px;min-width:0">'
               + (b.avatar ? '<img class="set-avatar" src="' + b.avatar + '" alt="">' : '')
               + '<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + b.display_name + ' <span style="color:#5a6270;font-size:12px">(' + b.uid + ')</span></span></span>'
               + '<button class="set-btn ghost" style="width:auto;margin:0;padding:6px 14px;font-size:13px" onclick="removeBlock(' + b.uid + ')">' + removeLbl + '</button></div>';
        }
        host.innerHTML = h;
    });
}
function addBlock() {
    var v = document.getElementById('blockUid').value.trim();
    if (!v) { showErr('<?php echo t('set_blacklist_need_uid', 'Please enter a UID.');?>'); return; }
    api('add_block', { uid: v }).then(function(d) {
        if (d.success) { document.getElementById('blockUid').value = ''; showToast(); load(); }
        else showErr(d.error || '<?php echo t('set_save_fail', 'Save failed.');?>');
    });
}
function removeBlock(uid) {
    if (!confirm('<?php echo t('set_blacklist_remove_confirm', 'Are you sure you want to remove this user from the block list?');?>')) return;
    api('remove_block', { uid: uid }).then(function(d) {
        if (d.success) { showToast(); load(); }
    });
}
load();
</script>

</body>
</html>
