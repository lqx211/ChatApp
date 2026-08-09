<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();
$displayName = htmlspecialchars($currentUser['display_name'] ?? $currentUser['username'] ?? '');
$avatar = $currentUser['avatar'] ?? '';
$statusText = htmlspecialchars($currentUser['custom_title'] ?? '');
$gender = $currentUser['gender'] ?? '';
$genderText = ($gender === '0' || $gender === 0) ? '女' : (($gender === '1' || $gender === 1) ? '男' : '');
$genderPrivacy = (int)($currentUser['gender_privacy'] ?? 0);
$privacyLabels = [0 => '所有人可见', 1 => '仅好友可见', 2 => '所有人不可见'];
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
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
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
    <span class="row-value<?php echo ph($genderText);?>" id="genderVal"><?php echo val($genderText);?></span>
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

<!-- 性别选择底部弹层 -->
<div class="picker-overlay" id="genderOverlay" onclick="closeGenderPicker()"></div>
<div class="picker-panel" id="genderPanel">
  <div class="picker-header">
    <button class="picker-cancel" onclick="closeGenderPicker()">取消</button>
    <span class="picker-title">选择性别</span>
    <button class="picker-confirm" onclick="confirmGender()" id="genderConfirmBtn">确定</button>
  </div>
  <div class="gender-options">
    <div class="picker-option" data-gender="1" onclick="selectGenderOpt(1)">男</div>
    <div class="picker-option" data-gender="0" onclick="selectGenderOpt(0)">女</div>
  </div>
  <div class="privacy-block">
    <div class="privacy-head" onclick="togglePrivacyList()">
      <span>谁可见</span>
      <span class="privacy-val" id="privacyVal"><?php echo htmlspecialchars($privacyLabels[$genderPrivacy] ?? '所有人可见');?></span>
      <span class="privacy-arrow" id="privacyArrow">›</span>
    </div>
    <div class="privacy-list" id="privacyList">
      <div class="privacy-option" data-privacy="0" onclick="selectPrivacyOpt(0)">所有人可见</div>
      <div class="privacy-option" data-privacy="1" onclick="selectPrivacyOpt(1)">仅好友可见</div>
      <div class="privacy-option" data-privacy="2" onclick="selectPrivacyOpt(2)">所有人不可见</div>
    </div>
  </div>
</div>

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
    // 签名真正保存到服务器（custom_title）
    var f = new URLSearchParams();
    f.append('action', 'change_custom_title');
    f.append('custom_title', v);
    fetch('../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) showToast();
    });
}

// ---- 性别选择底部弹层 ----
var _curGender = <?php echo $gender === '' || $gender === null ? 'null' : (int)$gender;?>;
var _curPrivacy = <?php echo (int)$genderPrivacy;?>;

function pickGender() {
    _curGender = <?php echo $gender === '' || $gender === null ? 'null' : (int)$gender;?>;
    _curPrivacy = <?php echo (int)$genderPrivacy;?>;
    // 高亮当前性别
    var opts = document.querySelectorAll('#genderPanel .picker-option');
    opts.forEach(function(o) {
        o.classList.toggle('selected', o.getAttribute('data-gender') == _curGender);
    });
    // 可见性标签 + 列表收起
    document.getElementById('privacyVal').textContent = ['所有人可见','仅好友可见','所有人不可见'][_curPrivacy] || '所有人可见';
    document.getElementById('privacyList').classList.remove('open');
    document.getElementById('privacyArrow').classList.remove('open');
    // 高亮当前可见性
    var pvt = document.querySelectorAll('#privacyList .privacy-option');
    pvt.forEach(function(o) {
        o.classList.toggle('selected', o.getAttribute('data-privacy') == _curPrivacy);
    });
    // 打开弹层
    document.getElementById('genderOverlay').classList.add('active');
    document.getElementById('genderPanel').classList.add('active');
}

function closeGenderPicker() {
    document.getElementById('genderOverlay').classList.remove('active');
    document.getElementById('genderPanel').classList.remove('active');
}

function selectGenderOpt(g) {
    _curGender = g;
    var opts = document.querySelectorAll('#genderPanel .picker-option');
    opts.forEach(function(o) {
        o.classList.toggle('selected', o.getAttribute('data-gender') == g);
    });
}

function togglePrivacyList() {
    var list = document.getElementById('privacyList');
    var arrow = document.getElementById('privacyArrow');
    list.classList.toggle('open');
    arrow.classList.toggle('open');
}

function selectPrivacyOpt(p) {
    _curPrivacy = p;
    document.getElementById('privacyVal').textContent = ['所有人可见','仅好友可见','所有人不可见'][p];
    var pvt = document.querySelectorAll('#privacyList .privacy-option');
    pvt.forEach(function(o) {
        o.classList.toggle('selected', o.getAttribute('data-privacy') == p);
    });
    document.getElementById('privacyList').classList.remove('open');
    document.getElementById('privacyArrow').classList.remove('open');
}

function confirmGender() {
    var el = document.getElementById('genderVal');
    if (_curGender === 0) { el.textContent = '女'; el.classList.remove('placeholder'); }
    else if (_curGender === 1) { el.textContent = '男'; el.classList.remove('placeholder'); }
    else { el.textContent = '未设置'; el.classList.add('placeholder'); }

    // 保存性别
    var f = new URLSearchParams();
    f.append('action', 'save_gender');
    f.append('gender', _curGender === null ? '' : String(_curGender));
    fetch('../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) showToast();
    });

    // 保存可见性
    var f2 = new URLSearchParams();
    f2.append('action', 'save_gender_privacy');
    f2.append('privacy', String(_curPrivacy));
    fetch('../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f2.toString()
    });

    closeGenderPicker();
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