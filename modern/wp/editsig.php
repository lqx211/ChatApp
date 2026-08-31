<?php
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();
$sig = $currentUser['custom_title'] ?? '';
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title>编辑签名</title>
<link rel="stylesheet" href="/plan/editinfo.css?v=20260809">
<style>
.sig-area { padding: 14px 16px; }
.sig-area textarea {
  width: 100%;
  min-height: 120px;
  background: #1e1e1e;
  border: 1px solid #444;
  border-radius: 8px;
  color: #e0e0e0;
  font-size: 15px;
  font-family: inherit;
  padding: 12px;
  outline: none;
  resize: vertical;
  line-height: 1.6;
}
.sig-area textarea::placeholder { color: #5a6270; }
.sig-count { padding: 8px 16px; font-size: 12px; color: #5a6270; text-align: right; }
</style>
</head>
<body>

<div class="card slide-in">
  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title">个人签名</span>
    <button class="nav-save" onclick="saveSig()">保存</button>
  </div>

  <div class="hint-text">编辑你的个性签名，保存后会展示在你的个人主页里</div>

  <div class="sig-area">
    <textarea id="sigInput" maxlength="100" placeholder="写点东西吧，爱你爱你！"><?php echo htmlspecialchars($sig);?></textarea>
  </div>
  <div class="sig-count"><span id="countVal"><?php echo mb_strlen($sig);?></span>/100</div>

  <!-- 同步到个人空间：保存签名时发布一条内容为签名的朋友圈 -->
  <div class="form-row" style="cursor:default">
    <span class="row-label">同步到个人空间</span>
    <input type="checkbox" class="ios-switch" id="sigSync">
    <label for="sigSync" class="ios-switch-label" style="margin-left:auto"></label>
  </div>
  <div class="hint-text" style="padding-top:4px">开启后，保存签名时会发布一条内容为签名的朋友圈</div>

  <div class="section-divider"></div>
  <div class="form-row" onclick="openSigPrivacy()">
    <span class="row-label">签名隐私设置</span>
    <span class="row-arrow">›</span>
  </div>
</div>

<div class="save-toast" id="saveToast">已保存</div>

<script>
var txt = document.getElementById('sigInput');
var countEl = document.getElementById('countVal');
txt.addEventListener('input', function () {
  countEl.textContent = txt.value.length;
});

function goBack() {
  var card = document.querySelector('.card');
  if (!card) { parent.closeMyProfile(); return; }
  card.classList.add('slide-out-right');
  setTimeout(function () {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
      window.parent.document.getElementById('profileFrame').src = 'profile.php';
    }
  }, 260);
}

function openSigPrivacy() {
  if (window.parent && window.parent.document.getElementById('profileFrame')) {
    window.parent.document.getElementById('profileFrame').src = 'editsigprivacy.php';
  }
}

function showToast() {
  var t = document.getElementById('saveToast');
  t.classList.add('show');
  setTimeout(function () { t.classList.remove('show'); }, 2000);
}

function saveSig() {
  var v = txt.value.trim();
  var f = new URLSearchParams();
  f.append('action', 'change_custom_title');
  f.append('custom_title', v);
  fetch('../../api/settings.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: f.toString()
  }).then(function (r) { return r.json(); }).then(function (d) {
    if (!d.success) { alert('保存失败'); return; }
    // 同步到个人空间：开启且签名非空时，发布一条内容为签名的朋友圈
    var sync = document.getElementById('sigSync');
    if (sync && sync.checked && v) {
      var f2 = new URLSearchParams();
      f2.append('action', 'post');
      f2.append('content', v);
      fetch('../../api/space.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f2.toString()
      }).then(function (r) { return r.json(); }).then(function (d2) {
        showToast();
        setTimeout(goBack, 650);
      }).catch(function () { showToast(); setTimeout(goBack, 650); });
      return;
    }
    showToast();
    setTimeout(goBack, 650);
  });
}
</script>

</body>
</html>