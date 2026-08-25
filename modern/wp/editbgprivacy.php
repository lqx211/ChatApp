<?php
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();
$from = $_GET['from'] ?? '';   // 'settings' => 返回设置页
$bgPrivacy = (int)($currentUser['bg_privacy'] ?? 0);
$bgNoFriend = (int)($currentUser['bg_no_friend'] ?? 0);
$bgPrivateImage = $currentUser['bg_private_image'] ?? '';
$bgBlackRaw = $currentUser['bg_blacklist'] ?? '';
$bgWhiteRaw = $currentUser['bg_whitelist'] ?? '';
$bgBlackList = $bgBlackRaw ? json_decode($bgBlackRaw, true) : [];
$bgWhiteList = $bgWhiteRaw ? json_decode($bgWhiteRaw, true) : [];
if (!is_array($bgBlackList)) $bgBlackList = [];
if (!is_array($bgWhiteList)) $bgWhiteList = [];
// 预设壁纸列表
$bgPresets = [];
$wpDir = __DIR__ . '/../../data/res/wallpaper';
if (is_dir($wpDir)) {
    foreach (glob($wpDir . '/*.{jpg,png}', GLOB_BRACE) as $f) {
        $bgPresets[] = basename($f);
    }
}
$bgPrivateLabel = '默认';
if ($bgPrivateImage) {
    if (strpos($bgPrivateImage, 'bgi/') === 0) {
        $bgPrivateLabel = '我上传的图片';
    } else {
        $bgPrivateLabel = basename($bgPrivateImage, '.' . pathinfo($bgPrivateImage, PATHINFO_EXTENSION));
    }
}

$modeLabels = [0 => '黑名单', 1 => '白名单', 2 => '仅自己能看见'];
$blackCount = count($bgBlackList);
$whiteCount = count($bgWhiteList);
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title>背景图隐私设置</title>
<link rel="stylesheet" href="../../plan/editinfo.css?v=20260809">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title">背景图隐私设置</span>
    <span style="width:28px"></span>
  </div>

  <div class="hint-text">选择背景图的可见模式，并配置相应的黑名单/白名单。</div>

  <!-- 选择模式 -->
  <div class="form-row" onclick="openModePicker()">
    <span class="row-label">选择模式</span>
    <span class="row-value" id="modeVal"><?php echo htmlspecialchars($modeLabels[$bgPrivacy] ?? '黑名单');?></span>
    <span class="row-arrow">›</span>
  </div>

  <!-- 黑名单配置入口（仅黑名单模式显示） -->
  <?php if ($bgPrivacy === 0):?>
  <div class="form-row" onclick="openBgList('black')">
    <span class="row-label">黑名单配置</span>
    <span class="row-value">当前 <?php echo $blackCount;?> 人</span>
    <span class="row-arrow">›</span>
  </div>
  <?php endif;?>

  <!-- 白名单配置入口（仅白名单模式显示） -->
  <?php if ($bgPrivacy === 1):?>
  <div class="form-row" onclick="openBgList('white')">
    <span class="row-label">白名单配置</span>
    <span class="row-value">当前 <?php echo $whiteCount;?> 人</span>
    <span class="row-arrow">›</span>
  </div>
  <?php endif;?>

  <!-- 禁止非朋友关系查看背景图（黑白名单模式均显示） -->
  <?php if ($bgPrivacy === 0 || $bgPrivacy === 1):?>
  <div class="form-row" onclick="toggleNoFriend()">
    <span class="row-label">禁止非朋友关系查看</span>
    <span class="row-value" id="noFriendVal"><?php echo $bgNoFriend ? '已开启' : '已关闭';?></span>
    <span class="row-arrow">›</span>
  </div>
  <?php endif;?>

  <!-- 不可见时背景图（因隐私看不到背景时展示的默认图） -->
  <div class="form-row" onclick="openPrivatePicker()">
    <span class="row-label">不可见时背景图</span>
    <span class="row-value" id="privateVal"><?php echo htmlspecialchars($bgPrivateLabel);?></span>
    <span class="row-arrow">›</span>
  </div>

</div>

<!-- 模式选择底部弹层 -->
<div class="picker-overlay" id="modeOverlay" onclick="closeModePicker()"></div>
<div class="picker-panel" id="modePanel">
  <div class="picker-header">
    <button class="picker-cancel" onclick="closeModePicker()">取消</button>
    <span class="picker-title">选择模式</span>
    <button class="picker-confirm" onclick="confirmMode()">确定</button>
  </div>
  <div class="picker-option" data-mode="0" onclick="selectModeOpt(0)">黑名单</div>
  <div class="picker-option" data-mode="1" onclick="selectModeOpt(1)">白名单</div>
  <div class="picker-option" data-mode="2" onclick="selectModeOpt(2)">仅自己能看见</div>
</div>

<!-- 不可见时背景图选择弹层 -->
<div class="picker-overlay" id="privateOverlay" onclick="closePrivatePicker()"></div>
<div class="picker-panel" id="privatePanel">
  <div class="picker-header">
    <button class="picker-cancel" onclick="closePrivatePicker()">取消</button>
    <span class="picker-title">不可见时背景图</span>
    <span style="width:28px"></span>
  </div>
  <div class="picker-option" data-img="" onclick="selectPrivateImg('')">默认</div>
  <div class="picker-option" onclick="uploadPrivateBg()">上传图片</div>
  <?php foreach ($bgPresets as $p): $pname = basename($p, '.' . pathinfo($p, PATHINFO_EXTENSION));?>
  <div class="picker-option" data-img="res/wallpaper/<?php echo htmlspecialchars($p);?>" onclick="selectPrivateImg('res/wallpaper/<?php echo htmlspecialchars($p);?>')"><?php echo htmlspecialchars($pname);?></div>
  <?php endforeach;?>
</div>
<input type="file" id="privateBgInput" accept="image/*,video/mp4,video/webm" style="display:none" onchange="onPrivateBgChange(this)">

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
var FROM_SETTINGS = <?php echo json_encode($from === 'settings');?>;
var _curMode = <?php echo (int)$bgPrivacy;?>;
var _noFriend = <?php echo (int)$bgNoFriend;?>;
var _privateImg = <?php echo json_encode($bgPrivateImage);?>;

function openPrivatePicker() {
    document.querySelectorAll('#privatePanel .picker-option').forEach(function(o) {
        o.classList.toggle('selected', o.getAttribute('data-img') === _privateImg);
    });
    document.getElementById('privateOverlay').classList.add('active');
    document.getElementById('privatePanel').classList.add('active');
}
function closePrivatePicker() {
    document.getElementById('privateOverlay').classList.remove('active');
    document.getElementById('privatePanel').classList.remove('active');
}
function selectPrivateImg(img) {
    _privateImg = img;
    // 显示为名字（默认 / 预设名 / 我上传的图片）
    var label = img === '' ? '默认' : (img.indexOf('res/wallpaper/') === 0 ? img.replace('res/wallpaper/', '').replace(/\.(jpg|png)$/i, '') : '我上传的图片');
    document.getElementById('privateVal').textContent = label;
    var f = new URLSearchParams();
    f.append('action', 'set_bg_private');
    f.append('private_image', img);
    fetch('../../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) showToast();
    });
    closePrivatePicker();
}
function uploadPrivateBg() {
    closePrivatePicker();
    document.getElementById('privateBgInput').click();
}
// 页内顶部进度条（为此页临时插入一个简单条）
function privProgShow(pct, rateTxt) {
    var b = document.getElementById('privUploadBar');
    if (!b) {
        var bar = document.createElement('div');
        bar.id = 'privUploadBar';
        bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:200;background:#1a1d27;border-bottom:1px solid #262c38;padding:8px 14px;font-size:12px;color:#e0e3ea;';
        bar.innerHTML = '<div style="height:3px;border-radius:2px;background:rgba(255,255,255,.2);overflow:hidden"><div id="privUpFill" style="height:100%;width:0%;background:#3b82f6;transition:width .1s linear"></div></div>' +
                        '<div id="privUpTxt" style="margin-top:5px;text-align:right;font-variant-numeric:tabular-nums">0% · 0 B/s</div>';
        document.body.appendChild(bar);
        b = document.getElementById('privUploadBar');
    }
    document.getElementById('privUpFill').style.width = (pct || 0) + '%';
    document.getElementById('privUpTxt').textContent = (pct || 0) + '% · ' + (rateTxt || '0 B') + '/s';
}
function privProgHide() {
    var b = document.getElementById('privUploadBar');
    if (b) b.remove();
}
function onPrivateBgChange(input) {
    var f = input.files[0];
    if (!f) return;

    var xhr = new XMLHttpRequest();
    var form = new FormData();
    form.append('action', 'upload_bg_private');
    form.append('file', f);

    var _loaded = 0, _total = f.size || 1;
    var _lastLoaded = 0, _lastTs = Date.now(), _rate = 0;
    var pctTimer = null, rateTimer = null;
    function fmtB(n) {
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' kB';
        return (n / 1048576).toFixed(2) + ' MB';
    }
    pctTimer = setInterval(function () {
        privProgShow(Math.min(100, Math.round(_loaded / _total * 100)), fmtB(_rate));
    }, 100);
    rateTimer = setInterval(function () {
        var now = Date.now(), dt = (now - _lastTs) / 1000;
        if (dt > 0) { _rate = Math.round((_loaded - _lastLoaded) / dt); _lastLoaded = _loaded; _lastTs = now; }
    }, 500);

    privProgShow(0, fmtB(0));

    xhr.open('POST', '../../api/settings.php', true);
    xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) { _loaded = e.loaded; _total = e.total; }
    };
    xhr.onload = function () {
        if (pctTimer) clearInterval(pctTimer);
        if (rateTimer) clearInterval(rateTimer);
        privProgHide();
        try { var d = JSON.parse(xhr.responseText); } catch (e) { alert('上传失败'); input.value=''; return; }
        if (d && d.success) {
            document.getElementById('privateVal').textContent = '我上传的图片';
            _privateImg = d.private_image || '';
            showToast();
        } else { alert((d && d.error) || '上传失败'); }
        input.value = '';
    };
    xhr.onerror = function () {
        if (pctTimer) clearInterval(pctTimer);
        if (rateTimer) clearInterval(rateTimer);
        privProgHide();
        alert('网络错误，上传失败');
        input.value = '';
    };
    xhr.send(form);
}

function goBack() {
    var card = document.querySelector('.card');
    if (!card) { parent.closeMyProfile(); return; }
    card.classList.add('slide-out-right');
    setTimeout(function() {
        if (window.parent && window.parent.document.getElementById('profileFrame')) {
            window.parent.document.getElementById('profileFrame').src = FROM_SETTINGS ? 'settings.php' : 'profile.php';
        }
    }, 260);
}

function showToast() {
    var t = document.getElementById('saveToast');
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}

// ---- 模式选择弹层 ----
function openModePicker() {
    _curMode = <?php echo (int)$bgPrivacy;?>;
    document.querySelectorAll('#modePanel .picker-option').forEach(function(o) {
        o.classList.toggle('selected', o.getAttribute('data-mode') == _curMode);
    });
    document.getElementById('modeOverlay').classList.add('active');
    document.getElementById('modePanel').classList.add('active');
}
function closeModePicker() {
    document.getElementById('modeOverlay').classList.remove('active');
    document.getElementById('modePanel').classList.remove('active');
}
function selectModeOpt(m) {
    _curMode = m;
    document.querySelectorAll('#modePanel .picker-option').forEach(function(o) {
        o.classList.toggle('selected', o.getAttribute('data-mode') == m);
    });
}
function confirmMode() {
    var labels = ['黑名单','白名单','仅自己能看见'];
    document.getElementById('modeVal').textContent = labels[_curMode];
    var f = new URLSearchParams();
    f.append('action', 'set_bg_privacy');
    f.append('privacy', String(_curMode));
    fetch('../../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) { showToast(); setTimeout(function(){ location.reload(); }, 600); }
    });
    closeModePicker();
}

// ---- 进入黑白名单配置 ----
function openBgList(type) {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
        window.parent.document.getElementById('profileFrame').src = 'editbglist.php?type=' + type;
    }
}

// ---- 禁止非朋友关系查看 ----
function toggleNoFriend() {
    _noFriend = _noFriend ? 0 : 1;
    document.getElementById('noFriendVal').textContent = _noFriend ? '已开启' : '已关闭';
    var f = new URLSearchParams();
    f.append('action', 'set_bg_no_friend');
    f.append('no_friend', String(_noFriend));
    fetch('../../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) showToast();
    });
}
</script>

</body>
</html>