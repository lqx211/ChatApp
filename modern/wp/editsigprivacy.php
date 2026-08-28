<?php
/**
 * ChatApp · 签名隐私设置（与背景图隐私同模型）
 * 模式：黑名单 / 白名单 / 仅自己能看见；禁止非朋友查看；不可见时签名。
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();
$from = $_GET['from'] ?? '';   // 'settings' => 返回设置页
$sigPrivacy = (int)($currentUser['sig_privacy'] ?? 0);
$sigNoFriend = (int)($currentUser['sig_no_friend'] ?? 0);
$sigHiddenText = (string)($currentUser['sig_hidden_text'] ?? '');
$sigBlackRaw = $currentUser['sig_blacklist'] ?? '';
$sigWhiteRaw = $currentUser['sig_whitelist'] ?? '';
$sigBlackList = $sigBlackRaw ? json_decode($sigBlackRaw, true) : [];
$sigWhiteList = $sigWhiteRaw ? json_decode($sigWhiteRaw, true) : [];
if (!is_array($sigBlackList)) $sigBlackList = [];
if (!is_array($sigWhiteList)) $sigWhiteList = [];

$modeLabels = [0 => '黑名单', 1 => '白名单', 2 => '仅自己能看见'];
$blackCount = count($sigBlackList);
$whiteCount = count($sigWhiteList);
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title>签名隐私设置</title>
<link rel="stylesheet" href="/plan/editinfo.css?v=20260809">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title">签名隐私设置</span>
    <span style="width:28px"></span>
  </div>

  <div class="hint-text">选择个性签名的可见模式，并配置相应的黑名单/白名单。</div>

  <!-- 选择模式 -->
  <div class="form-row" onclick="openModePicker()">
    <span class="row-label">选择模式</span>
    <span class="row-value" id="modeVal"><?php echo htmlspecialchars($modeLabels[$sigPrivacy] ?? '黑名单');?></span>
    <span class="row-arrow">›</span>
  </div>

  <!-- 黑名单配置入口（仅黑名单模式显示） -->
  <?php if ($sigPrivacy === 0):?>
  <div class="form-row" onclick="openSigList('black')">
    <span class="row-label">黑名单配置</span>
    <span class="row-value">当前 <?php echo $blackCount;?> 人</span>
    <span class="row-arrow">›</span>
  </div>
  <?php endif;?>

  <!-- 白名单配置入口（仅白名单模式显示） -->
  <?php if ($sigPrivacy === 1):?>
  <div class="form-row" onclick="openSigList('white')">
    <span class="row-label">白名单配置</span>
    <span class="row-value">当前 <?php echo $whiteCount;?> 人</span>
    <span class="row-arrow">›</span>
  </div>
  <?php endif;?>

  <!-- 禁止非朋友关系查看签名（黑白名单模式均显示） -->
  <?php if ($sigPrivacy === 0 || $sigPrivacy === 1):?>
  <div class="form-row" onclick="toggleNoFriend()">
    <span class="row-label">禁止非朋友关系查看</span>
    <span class="row-value" id="noFriendVal"><?php echo $sigNoFriend ? '已开启' : '已关闭';?></span>
    <span class="row-arrow">›</span>
  </div>
  <?php endif;?>

  <!-- 不可见时签名（因隐私看不到签名时展示的文字；留空则不显示签名行） -->
  <div class="form-row" onclick="editHiddenText()">
    <span class="row-label">不可见时签名</span>
    <span class="row-value" id="hiddenVal"><?php echo $sigHiddenText !== '' ? htmlspecialchars($sigHiddenText) : '（空）';?></span>
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

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
var FROM_SETTINGS = <?php echo json_encode($from === 'settings');?>;
var _curMode = <?php echo (int)$sigPrivacy;?>;
var _noFriend = <?php echo (int)$sigNoFriend;?>;
var _hiddenText = <?php echo json_encode($sigHiddenText);?>;

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

// ---- 模式选择 ----
function openModePicker() {
    _curMode = <?php echo (int)$sigPrivacy;?>;
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
    f.append('action', 'set_sig_privacy');
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

// ---- 进入黑白名单配置（复用 editbglist.php?kind=sig） ----
function openSigList(type) {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
        window.parent.document.getElementById('profileFrame').src = 'editbglist.php?type=' + type + '&kind=sig';
    }
}

// ---- 禁止非朋友关系查看 ----
function toggleNoFriend() {
    _noFriend = _noFriend ? 0 : 1;
    document.getElementById('noFriendVal').textContent = _noFriend ? '已开启' : '已关闭';
    var f = new URLSearchParams();
    f.append('action', 'set_sig_no_friend');
    f.append('no_friend', String(_noFriend));
    fetch('../../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) showToast();
    });
}

// ---- 不可见时签名 ----
function editHiddenText() {
    var v = window.prompt('不可见时签名（留空则不显示）', _hiddenText);
    if (v === null) return;
    v = v.trim().slice(0, 100);
    _hiddenText = v;
    document.getElementById('hiddenVal').textContent = v !== '' ? v : '（空）';
    var f = new URLSearchParams();
    f.append('action', 'set_sig_hidden_text');
    f.append('hidden_text', v);
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
