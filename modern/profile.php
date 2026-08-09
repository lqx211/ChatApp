<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();

// --- support viewing other users via ?user=xxx ---
$viewUsername = $_GET['user'] ?? null;
$isSelf = !$viewUsername || $viewUsername === ($currentUser['username'] ?? '');
$profileUser = $currentUser;

if (!$isSelf) {
    // Fetch target user info directly from DB
    $pdo = db();
    $stmt = $pdo->prepare("SELECT username, display_name, user_id, avatar, enabled, restricted, restricted_reason, placeholder, dnd, role, created_at, last_login, exp, level, bg_image, bg_updated_at, bg_privacy, bg_blacklist, bg_whitelist, bg_no_friend, bg_private_image, custom_title, gender, gender_privacy, birthday FROM users WHERE username = ?");
    $stmt->execute([$viewUsername]);
    $row = $stmt->fetch();
    if ($row) {
        $profileUser = $row;
        $profileUser['enabled'] = (int)$row['enabled'];
        $profileUser['restricted'] = (int)$row['restricted'];
        $profileUser['dnd'] = (int)$row['dnd'];
        $profileUser['level'] = (int)$row['level'];
        $profileUser['exp'] = (int)$row['exp'];
        $profileUser['user_id'] = (int)$row['user_id'];
        $profileUser['bg_image'] = $row['bg_image'] ?? '';
        $profileUser['bg_updated_at'] = $row['bg_updated_at'] ?? '';
        $profileUser['bg_privacy'] = $row['bg_privacy'] ?? 0;
        $profileUser['bg_blacklist'] = $row['bg_blacklist'] ?? '';
        $profileUser['bg_whitelist'] = $row['bg_whitelist'] ?? '';
        $profileUser['bg_no_friend'] = $row['bg_no_friend'] ?? 0;
        $profileUser['bg_private_image'] = $row['bg_private_image'] ?? '';
        $profileUser['custom_title'] = $row['custom_title'] ?? '';
        $profileUser['gender'] = $row['gender'] ?? '';
        $profileUser['gender_privacy'] = $row['gender_privacy'] ?? 0;
        $profileUser['birthday'] = $row['birthday'] ?? '';
    }
}

$displayName = htmlspecialchars($profileUser['display_name'] ?? $profileUser['username'] ?? '');
$userId = (int)($profileUser['user_id'] ?? 0);
$avatar = $profileUser['avatar'] ?? '';
$bg = $profileUser['bg_image'] ?? '';
$dnd = (int)($profileUser['dnd'] ?? 0);
$restricted = (int)($profileUser['restricted'] ?? 0);
$statusText = htmlspecialchars($profileUser['custom_title'] ?? '……');

// ---- 性别可见性过滤（0=所有人可见 1=仅好友可见 2=所有人不可见）----
$genderVal = $profileUser['gender'] ?? '';
$genderPrivacy = (int)($profileUser['gender_privacy'] ?? 0);
$canSeeGender = false;
if ($isSelf) {
    $canSeeGender = true;
} elseif ($genderPrivacy === 0) {
    $canSeeGender = true;
} elseif ($genderPrivacy === 1) {
    $meUid = (int)($currentUser['user_id'] ?? 0);
    $targetUid = (int)($profileUser['user_id'] ?? 0);
    if ($meUid > 0 && $targetUid > 0) {
        $cstmt = db()->prepare("SELECT COUNT(*) FROM contacts WHERE status='accepted' AND ((user_from=? AND user_to=?) OR (user_from=? AND user_to=?))");
        $cstmt->execute([$meUid, $targetUid, $targetUid, $meUid]);
        $canSeeGender = (int)$cstmt->fetchColumn() > 0;
    }
}
$genderDisplay = '';
if ($canSeeGender) {
    $genderDisplay = ($genderVal === '0' || $genderVal === 0) ? '女' : (($genderVal === '1' || $genderVal === 1) ? '男' : '');
}

// ---- 生日 -> 年龄 + 月日 + 星座 ----
$birthdayVal = $profileUser['birthday'] ?? '';
$ageDisplay = '';
$monthDayDisplay = '';
$zodiacDisplay = '';
$birthTs = $birthdayVal ? strtotime($birthdayVal) : 0;
if ($birthTs > 0) {
    $by = (int)date('Y', $birthTs);
    $cy = (int)date('Y');
    $bm = (int)date('n', $birthTs);
    $bd = (int)date('j', $birthTs);
    $age = $cy - $by;
    // 未到今年生日则减一岁
    if ((int)date('n') < $bm || ((int)date('n') === $bm && (int)date('j') < $bd)) $age--;
    $ageDisplay = $age . '岁';
    $monthDayDisplay = date('n月j日', $birthTs);
    $zodiacMap = [
        [20, '水瓶座'],[19, '双鱼座'],[21, '白羊座'],[20, '金牛座'],
        [21, '双子座'],[22, '巨蟹座'],[23, '狮子座'],[23, '处女座'],
        [23, '天秤座'],[24, '天蝎座'],[23, '射手座'],[22, '摩羯座']
    ];
    $z = $zodiacMap[$bm - 1];
    $zodiacDisplay = ($bd >= $z[0]) ? $z[1] : $zodiacMap[($bm - 2 + 12) % 12][1];
}
// ---- 背景图可见性（0=黑名单 1=白名单 2=仅自己）----
$bgPrivacy = (int)($profileUser['bg_privacy'] ?? 0);
$bgNoFriend = (int)($profileUser['bg_no_friend'] ?? 0);
$bgBlackRaw = $profileUser['bg_blacklist'] ?? '';
$bgWhiteRaw = $profileUser['bg_whitelist'] ?? '';
$bgBlackList = $bgBlackRaw ? json_decode($bgBlackRaw, true) : [];
$bgWhiteList = $bgWhiteRaw ? json_decode($bgWhiteRaw, true) : [];
if (!is_array($bgBlackList)) $bgBlackList = [];
if (!is_array($bgWhiteList)) $bgWhiteList = [];

$canSeeBg = false;
$meUid = (int)($currentUser['user_id'] ?? 0);
$targetUid = (int)($profileUser['user_id'] ?? 0);
if ($isSelf) {
    $canSeeBg = true;
} elseif ($bgPrivacy === 2) {
    $canSeeBg = false; // 仅自己能看见
} elseif (!empty($bg)) {
    if ($bgPrivacy === 0) { // 黑名单模式
        $canSeeBg = !in_array($meUid, $bgBlackList, true);
    } elseif ($bgPrivacy === 1) { // 白名单模式
        $canSeeBg = in_array($meUid, $bgWhiteList, true);
    }
    // 禁止非朋友关系查看（黑白名单模式均生效）：非好友一律隐藏
    if ($bgNoFriend && $meUid > 0 && $targetUid > 0 && !$isSelf) {
        $cstmt = db()->prepare("SELECT COUNT(*) FROM contacts WHERE status='accepted' AND ((user_from=? AND user_to=?) OR (user_from=? AND user_to=?))");
        $cstmt->execute([$meUid, $targetUid, $targetUid, $meUid]);
        $isFriend = (int)$cstmt->fetchColumn() > 0;
        if (!$isFriend) $canSeeBg = false;
    }
}
// 构造背景图 URL
$bgUrl = '';
if ($canSeeBg && !empty($bg)) {
    $ts = strtotime($profileUser['bg_updated_at'] ?? '') ?: time();
    if (strpos($bg, 'bgi/') === 0) {
        // data/bgi/<uid>.png 走 file.php 隐私鉴权（不暴露真实路径）
        $bgUrl = '../api/file.php?type=bgi&u=' . $targetUid . '&v=' . $ts;
    } elseif (strpos($bg, 'res/wallpaper/') === 0) {
        // 预设壁纸是公开资源，直接静态访问
        $bgUrl = '../data/' . $bg . '?v=' . $ts;
    } else {
        // 旧 user/<uid>/bg.* 走 file.php 原逻辑
        $bgUrl = '../api/file.php?f=' . rawurlencode($bg) . '&v=' . $ts;
    }
}
// 无权限看背景时：优先用「不可见时背景图」（预设静态图），否则默认 gx.jpg
$priImg = $profileUser['bg_private_image'] ?? '';
if ($canSeeBg) {
    $bgImg = $bgUrl ?: 'gx.jpg';
} else {
    if ($priImg && strpos($priImg, 'bgi/') === 0) {
        // 自上传「不可见时背景图」→ file.php 鉴权读取（登录即可看，防猜测路径）
        $bgImg = '../api/file.php?type=bgi_private&u=' . $targetUid;
    } elseif ($priImg && strpos($priImg, 'res/wallpaper/') === 0) {
        $bgImg = '../data/' . $priImg;
    } else {
        $bgImg = 'gx.jpg';
    }
}

// 判断封面资源是否为视频（mp4/webm）并给出 MIME（供 <video> 使用）
$isBgVideo = false;
$bgMimeType = 'image/png';
$bgSrcKey = (($canSeeBg && !empty($bg)) ? $bg : $priImg);
if (preg_match('/\.(mp4|webm)$/', $bgSrcKey, $em)) {
    $isBgVideo = true;
    $bgMimeType = $em[1] === 'mp4' ? 'video/mp4' : 'video/webm';
}
// 不暴露内联路径：封面元素通过 data-src 交给 JS 流式 fetch（顺便驱动进度条）
$bgFetchSrc = ($bgImg !== 'gx.jpg') ? $bgImg : '';

$statusLabel = $restricted ? 'Restricted' : ($dnd ? '请勿打扰' : '在线');
$statusClass = $restricted ? 'rstr' : ($dnd ? 'dnd' : 'on');
$targetUsername = htmlspecialchars($profileUser['username'] ?? $viewUsername ?? '');
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo htmlspecialchars($displayName);?> · 个人主页</title>
<link rel="stylesheet" href="profile.css">
</head>
<body>

<div class="card">

  <!-- 1. 导航栏 - fixed 在顶部 -->
  <div class="nav-bar">
    <button class="nav-btn" onclick="parent.closeMyProfile()">‹</button>
    <div class="nav-right">
      <button class="nav-btn">⋯</button>
    </div>
  </div>

  <!-- 2. 封面 - wrap + cover 双层，wrap 裁剪溢出
       data-src 交给 JS 流式 fetch（驱动进度条）；无外部资源（gx.jpg）则直接显示 -->
  <div class="cover-wrap" id="coverWrap">
    <div class="cover" id="coverEl">
      <?php if ($bgFetchSrc):?>
        <?php if ($isBgVideo):?>
        <video id="coverMedia" data-src="<?php echo htmlspecialchars($bgFetchSrc);?>" data-mime="<?php echo htmlspecialchars($bgMimeType);?>" autoplay muted loop playsinline></video>
        <?php else:?>
        <img id="coverMedia" data-src="<?php echo htmlspecialchars($bgFetchSrc);?>" alt="背景图">
        <?php endif;?>
      <?php else:?>
        <img src="gx.jpg" alt="背景图" id="coverMedia">
      <?php endif;?>
      <!-- 加载进度条（n% + 速率 B/kB/s） -->
      <div class="bg-progress" id="bgProgress">
        <div class="bg-progress-bar"><div class="bg-progress-fill" id="bgProgressFill"></div></div>
        <div class="bg-progress-text" id="bgProgressText">0% · 0 B/s</div>
      </div>
    </div>
  </div>

  <!-- 3. 头像 + 昵称 + UID + 点赞 -->
  <div class="profile-row">
    <div class="avatar"><?php if($avatar):?><img src="<?php echo htmlspecialchars($avatar);?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:50%"><?php endif;?></div>
    <div class="name-col">
      <div class="nickname-row">
        <span class="nickname"><?php echo htmlspecialchars($displayName);?></span>
        <span class="status-tag <?php echo $statusClass;?>"><?php echo htmlspecialchars($statusLabel);?></span>
      </div>
      <?php if(!$isSelf):?>
      <div class="uid-row"><span class="uid">Username: <?php echo $targetUsername;?></span></div>
      <?php endif;?>
      
      <div class="uid-row">
        <span class="uid">UID：<?php echo $userId;?></span>
      </div>
    </div>
    <button class="like-btn">
      <span class="like-icon">♡</span>
      <span class="like-num">7</span>
    </button>
  </div>

  <!-- 4. 个人信息行（仅自己可点击跳转编辑） -->
  <div class="info-line"<?php if($isSelf):?> onclick="parent.document.getElementById('profileFrame').src='editinfo.php'"<?php endif;?>><?php echo $genderDisplay ? $genderDisplay . ' | ' : '';?><?php echo $ageDisplay ? $ageDisplay . ' | ' : '';?><?php echo $monthDayDisplay ? $monthDayDisplay . ' ' . $zodiacDisplay . ' | ' : '';?>现居中国 | example@example.com</div>

  <!-- 5. 个性签名 -->
  <div class="sig-row">
    <span class="sig-text"><?php echo $statusText;?></span>
    <span class="arrow">›</span>
  </div>

  <!-- 6. 粗分隔线 -->
  <div class="section-divider"></div>

  <!-- 7. 添加标签 -->
  <div class="tag-row">
    <span>添加标签</span>
    <span class="tag-hint">添加更多标签让更多人认识你</span>
    <span class="arrow">›</span>
  </div>

  <!-- 8. 精选照片 - 横滑滚动 -->
  <div class="photo-section">
    <div class="photo-title">精选照片</div>
    <div class="photo-scroll">
      <!--
      <img src="../data/user/..." alt="photo">
      <img src="../data/user/..." alt="photo">
      <img src="../data/user/..." alt="photo">
      <img src="../data/user/..." alt="photo">
    -->
    </div>
  </div>

  <!-- 9. 资料完成度 -->
  <div class="completion-row">
    <span>📋</span>
    <!-- <span class="comp-text">资料完成度NaN%</span> -->
     <span class="comp-text">还在努力研发🙃</span>
    <span class="comp-action">去完善</span>
    <span class="arrow">›</span>
  </div>

</div>

<!-- 10. 底部按钮栏 - 固定在屏幕最下方 -->
<div class="bottom-bar">
<?php if($isSelf):?>
  <button class="btn-edit">编辑资料</button>
  <button class="btn-chat">发消息</button>
<?php else:?>
  <button class="btn-edit" onclick="parent.closeMyProfile()">返回</button>
  <button class="btn-chat">发消息</button>
<?php endif;?>
</div>

<!-- 背景图操作底部弹层（selection：更换/清空/隐私设置） -->
<div class="picker-overlay" id="bgMenuOverlay" onclick="closeBgMenu()"></div>
<div class="picker-panel" id="bgMenuPanel">
  <div class="picker-header">
    <span class="picker-title">背景图</span>
    <button class="picker-cancel" onclick="closeBgMenu()">取消</button>
  </div>
  <div class="picker-option" onclick="changeBg()">更换背景图</div>
  <div class="picker-option" onclick="clearBg()">清空背景图</div>
  <div class="picker-option" onclick="openBgPrivacy()">隐私设置</div>
</div>
<input type="file" id="bgFileInput" accept="image/*,video/mp4,video/webm" style="display:none" onchange="onBgFileChange(this)">

<!-- 背景图剪裁遮罩：手机全屏 / 桌面居中窗口（宽度固定 428px 与 app 一致）
     id/class 用 bgi- 前缀，避免与 chat.php 里已有的 cropOverlay（头像 canvas 裁剪）冲突 -->
<div class="bgi-crop-overlay" id="bgiCropOverlay">
  <div class="bgi-crop-stage" id="bgiCropStage">
    <div class="bgi-crop-stage-title">移动或缩放图片，调整背景图</div>
    <div class="bgi-crop-frame" id="bgiCropFrame"></div>
    <div class="bgi-crop-toolbar">
      <button class="bgi-crop-btn" onclick="cancelCrop()">取消</button>
      <button class="bgi-crop-btn bgi-crop-zoom" onclick="cropZoom(-1)">−</button>
      <button class="bgi-crop-btn bgi-crop-zoom" onclick="cropZoom(1)">＋</button>
      <button class="bgi-crop-btn bgi-crop-ok" onclick="confirmCrop()">完成</button>
    </div>
  </div>
</div>

<!-- 连续缩放 + 视差 -->
<script>
(function () {
  var cover = document.getElementById('coverEl');
  var maxScroll = 300;
  var minScale = 1.0;
  var maxScale = 1.15;

  function tick() {
    var y = window.scrollY || window.pageYOffset;

    if (y <= 0) {
      cover.style.transform = 'scale(1)';
      return;
    }

    var t = Math.min(y, maxScroll) / maxScroll;
    var scale = minScale + t * (maxScale - minScale);
    cover.style.transform = 'scale(' + scale.toFixed(3) + ')';
  }

  window.addEventListener('scroll', function () {
    requestAnimationFrame(tick);
  }, { passive: true });

  tick();
})();

// ---- 封面媒体流式加载 + 进度条（n% 每 0.1s、速率 B/kB/s 每 0.5s）----
(function () {
  var media = document.getElementById('coverMedia');
  var prog = document.getElementById('bgProgress');
  var fill = document.getElementById('bgProgressFill');
  var txt = document.getElementById('bgProgressText');
  if (!media || !media.dataset.src) return; // gx.jpg 等直接显示，不需要进度条

  var src = media.dataset.src;
  var pctTimer = null, rateTimer = null;
  var lastLoaded = 0, lastRateTs = 0, bytesPerSec = 0;

  function fmtBytes(n) {
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' kB';
    return (n / 1024 / 1024).toFixed(2) + ' MB';
  }
  function showProgress() {
    prog.style.opacity = '1';
  }
  function hideProgress() {
    if (pctTimer) { clearInterval(pctTimer); pctTimer = null; }
    if (rateTimer) { clearInterval(rateTimer); rateTimer = null; }
    prog.style.opacity = '0';
  }

  fetch(src, { credentials: 'same-origin' })
    .then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      var total = parseInt(r.headers.get('Content-Length') || '0', 10);
      var reader = r.body.getReader();
      var received = 0;
      var chunks = [];

      showProgress();

      // n% 每 0.1s
      pctTimer = setInterval(function () {
        if (!total) { txt.textContent = fmtBytes(received) + ' · ' + fmtBytes(bytesPerSec) + '/s'; }
        else {
          var p = Math.min(100, Math.round(received / total * 100));
          fill.style.width = p + '%';
          txt.textContent = p + '% · ' + fmtBytes(bytesPerSec) + '/s';
        }
      }, 100);

      // 速率每 0.5s（移动平均）
      rateTimer = setInterval(function () {
        var now = Date.now();
        var dt = (now - lastRateTs) / 1000;
        if (dt > 0) {
          bytesPerSec = Math.round((received - lastLoaded) / dt);
          lastLoaded = received;
          lastRateTs = now;
        }
      }, 500);

      function pump() {
        return reader.read().then(function (res) {
          if (res.done) {
            var blob = new Blob(chunks, { type: media.dataset.mime || 'application/octet-stream' });
            var url = URL.createObjectURL(blob);
            if (media.tagName === 'VIDEO') {
              media.src = url;
              media.play().catch(function(){});
            } else {
              media.src = url;
            }
            media.removeAttribute('data-src');
            fill.style.width = '100%';
            txt.textContent = '100% · ' + fmtBytes(bytesPerSec) + '/s';
            setTimeout(hideProgress, 400);
            return;
          }
          received += res.value.length;
          chunks.push(res.value);
          // 最后速率刷新
          var now = Date.now();
          if (now - lastRateTs >= 500) {
            var dt = (now - lastRateTs) / 1000;
            if (dt > 0) {
              bytesPerSec = Math.round((received - lastLoaded) / dt);
              lastLoaded = received;
              lastRateTs = now;
            }
          }
          if (!total) { txt.textContent = fmtBytes(received) + ' · ' + fmtBytes(bytesPerSec) + '/s'; }
          else {
            var p = Math.min(100, Math.round(received / total * 100));
            fill.style.width = p + '%';
            txt.textContent = p + '% · ' + fmtBytes(bytesPerSec) + '/s';
          }
          return pump();
        });
      }
      return pump();
    })
    .catch(function (err) {
      // 加载失败：回退到默认 gx.jpg
      media.src = 'gx.jpg';
      txt.textContent = '加载失败';
      setTimeout(hideProgress, 1500);
    });
})();

// ---- 背景图操作菜单（仅自己可操作）----
var _isSelf = <?php echo $isSelf ? 'true' : 'false';?>;

function openBgMenu() {
  if (!_isSelf) return; // 仅自己可更换/清空/隐私
  document.getElementById('bgMenuOverlay').classList.add('active');
  document.getElementById('bgMenuPanel').classList.add('active');
}
function closeBgMenu() {
  document.getElementById('bgMenuOverlay').classList.remove('active');
  document.getElementById('bgMenuPanel').classList.remove('active');
}

function changeBg() {
  closeBgMenu();
  document.getElementById('bgFileInput').click();
}
function clearBg() {
  closeBgMenu();
  if (!confirm('确定清空背景图？')) return;
  var form = new URLSearchParams();
  form.append('action', 'remove_background');
  fetch('../api/settings.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: form.toString()
  }).then(function(r) { return r.json(); }).then(function(d) {
    if (d.success) { alert('已清空背景图'); location.reload(); }
  });
}
function openBgPrivacy() {
  closeBgMenu();
  parent.document.getElementById('profileFrame').src = 'editbgprivacy.php';
}

function onBgFileChange(input) {
  var f = input.files[0];
  if (!f) return;
  input.value = '';
  // 视频：直接上传（剪裁只针对图片）；图片：先打开剪裁
  if (f.type && f.type.indexOf('video/') === 0) {
    startBgUpload(f);
  } else {
    openCrop(f);
  }
}

// ---- 上传（带进度条 n% 每 0.1s、速率 B/kB/s 每 0.5s）----
function startBgUpload(media) {
  var prog = document.getElementById('bgProgress');
  var fill = document.getElementById('bgProgressFill');
  var txt = document.getElementById('bgProgressText');
  if (!prog) { alert('浏览器不支持'); return; }

  function fmtB(n) {
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' kB';
    return (n / 1048576).toFixed(2) + ' MB';
  }

  var xhr = new XMLHttpRequest();
  var form = new FormData();
  form.append('action', 'upload_background');
  form.append('file', media);

  var _loaded = 0, _total = (media.size) || 1;
  var _lastLoaded = 0, _lastTs = Date.now(), _rate = 0;
  var pctTimer = null, rateTimer = null;

  // n% 每 0.1s
  pctTimer = setInterval(function () {
    var p = Math.min(100, Math.round(_loaded / _total * 100));
    fill.style.width = p + '%';
    txt.textContent = p + '% · ' + fmtB(_rate) + '/s';
  }, 100);
  // 速率每 0.5s
  rateTimer = setInterval(function () {
    var now = Date.now();
    var dt = (now - _lastTs) / 1000;
    if (dt > 0) {
      _rate = Math.round((_loaded - _lastLoaded) / dt);
      _lastLoaded = _loaded;
      _lastTs = now;
    }
  }, 500);

  prog.style.opacity = '1';
  txt.textContent = '0% · 0 B/s';
  fill.style.width = '0%';

  xhr.open('POST', '../api/settings.php', true);
  xhr.upload.onprogress = function (e) {
    if (e.lengthComputable) { _loaded = e.loaded; _total = e.total; }
  };
  xhr.onload = function () {
    if (pctTimer) clearInterval(pctTimer);
    if (rateTimer) clearInterval(rateTimer);
    prog.style.opacity = '0';
    try { var d = JSON.parse(xhr.responseText); }
    catch (e) { alert('上传失败'); return; }
    if (d && d.success) {
      // 上传完成 → 立即刷新封面（新时间戳，走流式加载进度条）
      location.reload();
    } else {
      alert((d && d.error) || '上传失败');
    }
  };
  xhr.onerror = function () {
    if (pctTimer) clearInterval(pctTimer);
    if (rateTimer) clearInterval(rateTimer);
    prog.style.opacity = '0';
    alert('网络错误，上传失败');
  };
  xhr.send(form);
}

// ================= 背景图剪裁（手机全屏 / 桌面居中窗口） =================
// 剪裁框固定为封面比例 428:223；图片可拖拽平移 + 缩放，完成后按 2x 裁出上传
var _crop = { img: null, url: '', sx: 1, ox: 0, oy: 0, frameW: 0, frameH: 0 };
var _cropPts = {}, _pinch0 = null;

function openCrop(file) {
  var overlay = document.getElementById('bgiCropOverlay');
  var stage = document.getElementById('bgiCropStage');
  var frame = document.getElementById('bgiCropFrame');
  overlay.style.display = 'flex';
  // 强制 reflow：display:none → flex 后需让浏览器先完成布局，否则 clientWidth 仍为 0
  void overlay.offsetHeight;
  // 框尺寸：适配舞台，但保持封面比例 428×223（最小 200 兜底防负数）
  _crop.frameW = Math.max(200, Math.min(stage.clientWidth - 24, 396));
  _crop.frameH = Math.round(_crop.frameW * 223 / 428);
  frame.style.width = _crop.frameW + 'px';
  frame.style.height = _crop.frameH + 'px';

  _crop.url = URL.createObjectURL(file);
  var img = new Image();
  _crop.img = img;
  img.src = _crop.url;
  frame.appendChild(img);
  img.onload = function () {
    img.style.width = img.naturalWidth + 'px';
    img.style.height = img.naturalHeight + 'px';
    _crop.sx = Math.max(_crop.frameW / img.naturalWidth, _crop.frameH / img.naturalHeight);
    _crop.ox = (_crop.frameW - img.naturalWidth * _crop.sx) / 2;
    _crop.oy = (_crop.frameH - img.naturalHeight * _crop.sx) / 2;
    renderCrop();
  };
}
function renderCrop() {
  var im = _crop.img; if (!im) return;
  im.style.transformOrigin = '0 0';
  im.style.transform = 'translate(' + _crop.ox + 'px,' + _crop.oy + 'px) scale(' + _crop.sx + ')';
}
function clampCrop() {
  var w = _crop.img.naturalWidth * _crop.sx, h = _crop.img.naturalHeight * _crop.sx;
  _crop.ox = Math.min(0, Math.max(_crop.frameW - w, _crop.ox));
  _crop.oy = Math.min(0, Math.max(_crop.frameH - h, _crop.oy));
}
function cropZoomAt(cx, cy, k) {
  var im = _crop.img; if (!im) return;
  var frameRect = document.getElementById('bgiCropFrame').getBoundingClientRect();
  var fx = cx - frameRect.left, fy = cy - frameRect.top;
  var oldS = _crop.sx;
  var wx = (fx - _crop.ox) / oldS, wy = (fy - _crop.oy) / oldS;
  var ns = Math.min(5, Math.max(oldS * 0.2, oldS * k));
  _crop.sx = ns;
  _crop.ox = fx - wx * ns;
  _crop.oy = fy - wy * ns;
  clampCrop(); renderCrop();
}
function cropZoom(dir) {
  var fr = document.getElementById('bgiCropFrame').getBoundingClientRect();
  cropZoomAt(fr.left + fr.width / 2, fr.top + fr.height / 2, dir > 0 ? 1.25 : 0.8);
}
function cancelCrop() {
  var overlay = document.getElementById('bgiCropOverlay');
  overlay.style.display = 'none';
  var frame = document.getElementById('bgiCropFrame');
  var im = _crop.img;
  if (im && im.parentNode) { try { im.parentNode.removeChild(im); } catch (e) {} }
  if (_crop.url) URL.revokeObjectURL(_crop.url);
  _crop = { img: null, url: '', sx: 1, ox: 0, oy: 0, frameW: 0, frameH: 0 };
  _cropPts = {}; _pinch0 = null;
}
function confirmCrop() {
  var im = _crop.img; if (!im) return;
  var outW = Math.round(_crop.frameW * 2), outH = Math.round(_crop.frameH * 2);
  var c = document.createElement('canvas');
  c.width = outW; c.height = outH;
  var ctx = c.getContext('2d');
  var s = _crop.sx;
  // 源区域（图片自然像素）→ 输出 2x
  ctx.drawImage(im, -_crop.ox / s, -_crop.oy / s, _crop.frameW / s, _crop.frameH / s, 0, 0, outW, outH);
  var overlay = document.getElementById('bgiCropOverlay');
  overlay.style.display = 'none';
  if (c.toBlob) {
    c.toBlob(function (b) {
      if (b) startBgUpload(b);
      else alert('裁切失败');
    }, 'image/png');
  } else { alert('浏览器不支持'); }
  if (_crop.url) URL.revokeObjectURL(_crop.url);
  _crop = { img: null, url: '', sx: 1, ox: 0, oy: 0, frameW: 0, frameH: 0 };
  _cropPts = {}; _pinch0 = null;
}

// 舞台事件：拖拽平移 + 双指缩放（pointer events）
(function () {
  var frame = document.getElementById('bgiCropFrame');
  if (!frame) return;
  frame.addEventListener('pointerdown', function (e) {
    try { frame.setPointerCapture(e.pointerId); } catch (err) {}
    _cropPts[e.pointerId] = { x: e.clientX, y: e.clientY };
    if (Object.keys(_cropPts).length === 2) _pinch0 = null;
  });
  frame.addEventListener('pointermove', function (e) {
    if (_cropPts[e.pointerId] == null) return;
    var keys = Object.keys(_cropPts);
    if (keys.length === 1) {
      var p = _cropPts[e.pointerId];
      _crop.ox += e.clientX - p.x;
      _crop.oy += e.clientY - p.y;
      _cropPts[e.pointerId] = { x: e.clientX, y: e.clientY };
      clampCrop(); renderCrop();
    } else if (keys.length === 2) {
      var other = keys.find(function (k) { return k != e.pointerId; });
      if (other == null) return;
      var a = _cropPts[other], b = _cropPts[e.pointerId];
      var nx = e.clientX, ny = e.clientY;
      var d1 = Math.hypot(a.x - nx, a.y - ny);
      if (_pinch0 && _pinch0.d > 0) {
        var k = d1 / _pinch0.d;
        cropZoomAt(_pinch0.mx, _pinch0.my, k);
      }
      _pinch0 = { d: d1, mx: (a.x + nx) / 2, my: (a.y + ny) / 2 };
      _cropPts[e.pointerId] = { x: nx, y: ny };
    }
  });
  function up(e) {
    delete _cropPts[e.pointerId];
    if (Object.keys(_cropPts).length < 2) _pinch0 = null;
  }
  frame.addEventListener('pointerup', up);
  frame.addEventListener('pointercancel', up);
  // 桌面滚轮缩放
  frame.addEventListener('wheel', function (e) {
    e.preventDefault();
    cropZoomAt(e.clientX, e.clientY, e.deltaY < 0 ? 1.1 : 0.9);
  }, { passive: false });
})();

// 点击封面 / ⋯ 按钮打开菜单
document.getElementById('coverWrap').addEventListener('click', function() {
  if (_isSelf) openBgMenu();
});
document.querySelector('.nav-right .nav-btn').addEventListener('click', openBgMenu);

// PC 右键背景图弹菜单（阻止默认菜单）
document.getElementById('coverWrap').addEventListener('contextmenu', function(e) {
  e.preventDefault();
  if (_isSelf) openBgMenu();
});
</script>

</body>
</html>