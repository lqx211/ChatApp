<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();
$displayName = htmlspecialchars(chatapp_display_name($currentUser));
$avatar = $currentUser['avatar'] ?? '';
$statusText = htmlspecialchars($currentUser['custom_title'] ?? '');
$gender = $currentUser['gender'] ?? '';
$birthday = $currentUser['birthday'] ?? '';
$location = $currentUser['location'] ?? '';

function val($v, $placeholder = '未设置') {
    return $v !== '' && $v !== null ? $v : $placeholder;
}
function ph($v) { return $v === '' || $v === null ? ' placeholder' : ''; }
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title>编辑资料</title>
<link rel="stylesheet" href="../plan/editinfo.css">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title">编辑资料</span>
    <button class="nav-save" onclick="saveProfile()">完成</button>
  </div>

  <div class="hint-text">设置权限为公开的内容，会用作社交信息对外公开展示。</div>

  <!-- 头像 -->
  <div class="form-row" onclick="document.getElementById('avatarInput').click()">
    <span class="row-label">头像</span>
    <span class="row-value"><?php echo $avatar ? '<img src="'.htmlspecialchars($avatar).'" style="width:36px;height:36px;border-radius:50%;object-fit:cover;vertical-align:middle" alt="">' : '未设置';?></span>
    <span class="row-arrow">›</span>
  </div>
  <input type="file" id="avatarInput" accept="image/*" style="display:none" onchange="onAvatarChange(this)">

  <!-- 签名 -->
  <div class="form-row" onclick="promptEdit('sigVal','签名','<?php echo addslashes($currentUser['custom_title'] ?? '');?>')">
    <span class="row-label">签名</span>
    <span class="row-value<?php echo ph($statusText);?>" id="sigVal"><?php echo val($statusText, '……');?></span>
    <span class="row-arrow">›</span>
  </div>

  <div class="section-divider"></div>

  <!-- 昵称 -->
  <div class="form-row">
    <span class="row-label">昵称</span>
    <input type="text" class="row-input" id="nicknameInput" value="<?php echo $displayName;?>" placeholder="昵称" maxlength="20">
  </div>

  <!-- 性别 -->
  <div class="form-row" onclick="pickGender()">
    <span class="row-label">性别</span>
    <span class="row-value<?php echo ph($gender);?>" id="genderVal"><?php echo val($gender);?></span>
    <span class="row-arrow">›</span>
  </div>

  <!-- 生日 -->
  <div class="form-row" onclick="window.parent.document.getElementById('profileFrame').src='editbirthday.php'">
    <span class="row-label">生日</span>
    <span class="row-value<?php echo ph($birthday);?>" id="birthdayVal"><?php echo val($birthday);?></span>
    <span class="row-arrow">›</span>
  </div>

  <div class="section-divider"></div>

  <!-- 精选照片 -->
  <div class="form-row">
    <span class="row-label">精选照片</span>
    <span class="row-value placeholder">未设置</span>
    <span class="row-arrow">›</span>
  </div>

  <!-- 所在地 -->
  <div class="form-row" onclick="pickLocation()">
    <span class="row-label">所在地</span>
    <span class="row-value<?php echo ph($location);?>" id="locationVal"><?php echo val($location, '不展示');?></span>
    <span class="row-arrow">›</span>
  </div>

</div>

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
function goBack() {
    var card = document.querySelector('.card');
    if (!card) { parent.closeMyProfile(); return; }
    card.classList.add('slide-out-right');
    setTimeout(function() {
        if (window.parent && window.parent.document.getElementById('profileFrame')) {
            window.parent.document.getElementById('profileFrame').src = 'profile.php';
        }
    }, 260);
}

function showToast() {
    var t = document.getElementById('saveToast');
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}

function saveProfile() {
    var f = new URLSearchParams();
    f.append('action', 'change_display_name');
    f.append('display_name', document.getElementById('nicknameInput').value.trim());
    fetch('../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) showToast();
    });
}

function promptEdit(elId, label, curVal) {
    var v = prompt(label + ':', curVal || '');
    if (v === null) return;
    var el = document.getElementById(elId);
    if (el) el.textContent = v || '未设置';
}

function pickGender() {
    var v = prompt('性别 (男/女/不展示):', document.getElementById('genderVal').textContent);
    if (v === null) return;
    document.getElementById('genderVal').textContent = v || '未设置';
}

function pickLocation() {
    var v = prompt('所在地:', document.getElementById('locationVal').textContent);
    if (v === null) return;
    document.getElementById('locationVal').textContent = v || '不展示';
}

function onAvatarChange(input) {
    var f = input.files[0];
    if (!f) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        var b64 = e.target.result;
        var form = new URLSearchParams();
        form.append('action', 'upload_avatar');
        form.append('avatar', b64);
        fetch('../api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: form.toString()
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) { showToast(); location.reload(); }
        });
    };
    reader.readAsDataURL(f);
}
</script>

</body>
</html>