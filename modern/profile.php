<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();

// --- support viewing other users via ?user=xxx ---
$viewUsername = $_GET['user'] ?? null;
$isSelf = !$viewUsername || $viewUsername === ($currentUser['username'] ?? '');
$profileUser = $currentUser;

if (!$isSelf) {
    // Fetch target user info via local API call (reuse current session cookie)
    $apiUrl = 'http://[::1]:8080/api/admin.php';
    $cookie = '';
    foreach ($_COOKIE as $k => $v) { $cookie .= urlencode($k) . '=' . urlencode($v) . '; '; }
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nCookie: $cookie",
        'content' => http_build_query(['action' => 'user_detail', 'username' => $viewUsername]),
        'timeout' => 5
    ]]);
    $resp = @file_get_contents($apiUrl, false, $ctx);
    if ($resp) {
        $data = json_decode($resp, true);
        if (!empty($data['success']) && !empty($data['user'])) {
            $profileUser = $data['user'];
        }
    }
}

$displayName = htmlspecialchars($profileUser['display_name'] ?? $profileUser['username'] ?? '');
$userId = (int)($profileUser['user_id'] ?? 0);
$avatar = $profileUser['avatar'] ?? '';
$bg = $profileUser['bg'] ?? '';
$dnd = (int)($profileUser['dnd'] ?? 0);
$restricted = (int)($profileUser['restricted'] ?? 0);
$statusText = htmlspecialchars($profileUser['status'] ?? '……');
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

  <!-- 2. 封面 - wrap + cover 双层，wrap 裁剪溢出 -->
  <div class="cover-wrap">
    <div class="cover"></div>
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

  <!-- 4. 个人信息行 -->
  <div class="info-line">女 | n岁 | y月m日 x1+x2座 | 来自p1+p2 | example@example.com</div>

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

<!-- 连续缩放 + 视差 -->
<script>
(function () {
  var cover = document.querySelector('.cover');
  var maxScroll = 300;
  var minScale = 1.0;
  var maxScale = 1.15;

  function tick() {
    var y = window.scrollY || window.pageYOffset;

    if (y <= 0) {
      cover.style.backgroundPositionY = '0px';
      cover.style.transform = 'scale(1)';
      return;
    }

    // 背景视差：图片 0.4 倍速下移
    cover.style.backgroundPositionY = (y * 0.4) + 'px';

    // 连续缩放
    var t = Math.min(y, maxScroll) / maxScroll;
    var scale = minScale + t * (maxScale - minScale);
    cover.style.transform = 'scale(' + scale.toFixed(3) + ')';
  }

  window.addEventListener('scroll', function () {
    requestAnimationFrame(tick);
  }, { passive: true });

  tick();
})();
</script>

</body>
</html>