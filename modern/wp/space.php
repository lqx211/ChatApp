<?php
/**
 * ChatApp 个人空间（QQ 空间风格主页，桌面版）
 * 展示用户：本人或 ?user=xxx。UI 复刻 QQ 空间主页；数据接真实 users 字段。
 * 「精选相片」来自个人空间(朋友圈) —— 目前为示例占位，后续接 api/space.php。
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();

// embed=1：内嵌在聊天面板(iframe)时隐藏自带顶栏，避免双层工具栏
$embedMode = isset($_GET['embed']) ? 1 : 0;

$viewUsername = isset($_GET['user']) ? trim((string)$_GET['user']) : '';
$isSelf = ($viewUsername === '' || $viewUsername === ($currentUser['username'] ?? ''));
$target = $isSelf ? ($currentUser['username'] ?? '') : $viewUsername;

$pdo = db();
$stmt = $pdo->prepare("SELECT username, display_name, user_id, avatar, custom_title, gender, gender_privacy, birthday, profile_bg_image, profile_bg_updated_at, level, exp, likes, created_at, dnd, enabled, placeholder FROM users WHERE username = ?");
$stmt->execute([$target]);
$u = $stmt->fetch();
if (!$u || !(int)$u['enabled'] || (int)$u['placeholder']) {
    header('Location: chat.php');
    exit;
}

$displayName = $u['display_name'] ?: $u['username'];
$avatarUrl = chatapp_avatar_url($u['avatar'] ?? '', $u['username']);
$sig = trim((string)($u['custom_title'] ?? ''));
$cover = trim((string)($u['profile_bg_image'] ?? ''));
// 封面：profile_bg_image 是文件名 → 走 api/file 或 data 取图；这里统一拼 URL（data URI 原样保留）
if ($cover !== '' && strpos($cover, 'data:') !== 0 && preg_match('/^[0-9a-zA-Z_]+\.(png|jpg|jpeg|gif|webp)$/i', $cover)) {
    $coverUrl = '../../api/avatar.php?u=' . urlencode($u['username']) . '&bg=' . urlencode($cover);
} elseif ($cover !== '' && strpos($cover, 'data:') === 0) {
    $coverUrl = $cover;
} else {
    $coverUrl = '';
}
$level = (int)$u['level']; $exp = (int)$u['exp']; $likes = (int)$u['likes'];
$gender = $u['gender']; // 0/1/2? 按现有 profile 语义显示
$birthday = $u['birthday'] ?? '';
$uid = (int)$u['user_id'];
$meUid = (int)($currentUser['user_id'] ?? 0);
$spaceTitle = $displayName . '的空间';

function sp_ph(int $i, string $ch, string $a, string $b): string {
    // 精选相片占位图（正方形 SVG data URI）——后续替换为真实朋友圈图片
    $colors = [['#ffb300','#ff7043'],['#26c6da','#1a76e0'],['#66bb6a','#2e7d32'],['#ab47bc','#5e35b1'],['#ff7043','#d32f2f'],['#29b6f6','#1565c0']];
    $c = $colors[$i % count($colors)];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160">'
        . '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="' . $a . '"/><stop offset="1" stop-color="' . $b . '"/></linearGradient></defs>'
        . '<rect width="160" height="160" fill="url(#g)"/>'
        . '<text x="80" y="92" font-size="56" text-anchor="middle" fill="rgba(255,255,255,.85)" font-family="sans-serif">' . htmlspecialchars($ch) . '</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
$ch = mb_strtoupper(mb_substr($displayName, 0, 1));
// 示例精选相片（后续从 api/space.php?action=photos 读取）
$samplePhotos = [];
for ($i = 0; $i < 9; $i++) { $samplePhotos[] = ['src' => sp_ph($i, $ch, '#ffb300','#ff7043'), 'cap' => '相册 ' . ($i + 1)]; }
// 示例说说（后续从 api/space.php?action=feed 读取）
$sampleFeeds = [
    ['text' => '这里是 ' . $displayName . ' 的个人空间，欢迎来做客～（示例内容，接入朋友圈后替换）', 'photos' => [0, 1, 2], 'time' => '刚刚', 'likes' => 0],
    ['text' => '晒一张今天拍的照片 📷', 'photos' => [3], 'time' => '昨天', 'likes' => 2],
];
$genderLabel = $gender === 1 ? '男' : ($gender === 2 ? '女' : '未设置');
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($spaceTitle);?> - ChatApp</title>
<link rel="stylesheet" href="../style/space.css?v=<?php echo time();?>">
</head>
<body class="bg-body mode-theme<?php echo $embedMode ? ' embed' : '';?>">

<!-- ================= 顶部工具栏 ================= -->
<div class="top-fix-bar">
  <div class="top-fix-inner">
    <div class="top-fix-wrap">
      <a class="logo" href="chat.php" title="返回聊天"><span class="logo-ico">家</span>个人空间</a>
      <ul class="top-nav">
        <li class="nav-list"><a href="space.php<?php echo $isSelf ? '' : '?user=' . urlencode($u['username']);?>" class="on">主页</a></li>
        <li class="nav-list"><a href="chat.php">聊天</a></li>
        <li class="nav-list"><a href="settings.php">设置</a></li>
      </ul>
      <div class="top-search">
        <div class="search-box">
          <input class="search-input" placeholder="用户/动态" id="spSearchInput">
          <a class="search-button" title="搜索用户">🔍</a>
        </div>
      </div>
      <div class="user-info">
        <a class="user-home" href="space.php">
          <?php if ($avatarUrl):?><img class="user-avatar" src="<?php echo htmlspecialchars($avatarUrl);?>" alt=""><?php endif;?>
          <span class="user-name textoverflow"><?php echo htmlspecialchars($currentUser['display_name'] ?: $currentUser['username']);?></span>
        </a>
        <a class="logout-new" onclick="spLogout()">退出</a>
      </div>
    </div>
  </div>
</div>

<!-- ================= 背景分层 ================= -->
<div class="background-container">

  <!-- 封面头 -->
  <div class="layout-head anti-color">
    <div class="layout-head-inner<?php echo $coverUrl ? ' has-cover' : '';?>" <?php if($coverUrl):?>style="background-image:url('<?php echo htmlspecialchars($coverUrl);?>')"<?php endif;?>>
      <?php if($coverUrl):?><img class="head-cover-img" src="<?php echo htmlspecialchars($coverUrl);?>" alt="" style="display:none"><?php endif;?>
      <div class="head-cover-tint"></div>

      <div class="head-info">
        <h1 class="head-title">
          <span class="title-text"><?php echo htmlspecialchars($spaceTitle);?></span>
          <span class="qz-level-flag">Lv.<?php echo $level;?></span>
        </h1>
        <div class="head-description"><span class="description-text"><?php echo $sig !== '' ? htmlspecialchars($sig) : '这个人很懒，什么都没写';?></span></div>
      </div>

      <div class="actions profile-hd-actions">
        <?php if ($isSelf):?>
          <span class="btn-head"><a href="editinfo.php">编辑资料</a></span>
        <?php else:?>
          <span class="btn-head btn-primary"><a>加好友</a></span>
          <span class="btn-head"><a href="chat.php?user=<?php echo urlencode($u['username']);?>" target="_blank">发消息</a></span>
        <?php endif;?>
      </div>

      <!-- 封面浮层导航 -->
      <div class="layout-shop-item" id="menuContainer">
        <div class="shop-item cs">
          <div class="head-nav">
            <ul class="head-nav-menu" id="coverTabs">
              <li class="cur"><a title="主页">主页</a></li>
              <li><a title="日志">日志</a></li>
              <li><a title="相册">相册</a></li>
              <li><a title="留言板">留言板</a></li>
              <li><a title="说说">说说</a></li>
              <li><a title="个人档">个人档</a></li>
            </ul>
          </div>
        </div>
      </div>

      <!-- 访客统计 -->
      <div class="layout-shop-item" id="visitorsDiv">
        <div class="visit-module">
          <div class="other-info">
            <p class="visit-today">今日访客 <span class="count">0</span></p>
            <p class="visit-count">访客总量 <span class="count">0</span></p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 头像栏（压在封面上） -->
  <div class="layout-nav">
    <div class="layout-nav-inner">
      <div class="head-avatar">
        <?php if ($avatarUrl):?><img src="<?php echo htmlspecialchars($avatarUrl);?>" alt=""><?php else:?><div class="av-empty"><?php echo htmlspecialchars($ch);?></div><?php endif;?>
      </div>
      <div class="head-detail">
        <div class="head-detail-name"><span class="user-name"><?php echo htmlspecialchars($displayName);?></span></div>
        <div class="head-detail-sub">Lv.<?php echo $level;?> · 空间ID <?php echo $uid;?></div>
      </div>
    </div>
  </div>

  <!-- 内容背景 -->
  <div class="layout-background">
    <div class="layout-body">
      <div class="layout-page clearfix">

        <!-- ===== 左侧导航 ===== -->
        <div class="col-menu" id="leftMenu">
          <div class="mod-side-nav mod-side-nav-message">
            <div class="hd">动态</div>
            <div class="inner"><div class="bd">
              <ul class="sn-list" id="feedTypes">
                <li class="current" data-f="all"><a><span class="sn-ico c1">友</span><span class="sn-title">好友动态</span></a></li>
                <li data-f="me"><a><span class="sn-ico c2">我</span><span class="sn-title">与我相关</span></a></li>
                <li data-f="photo"><a><span class="sn-ico c3">片</span><span class="sn-title">我的相册</span></a></li>
                <li data-f="say"><a><span class="sn-ico c4">说</span><span class="sn-title">我的说说</span></a></li>
              </ul>
            </div></div>
          </div>
          <div class="mod-side-nav mod-side-nav-recently-used">
            <div class="hd">个人资料</div>
            <div class="inner"><div class="bd">
              <ul class="sn-list">
                <li><a><span class="sn-ico c5">名</span><span class="sn-title textoverflow"><?php echo htmlspecialchars($displayName);?></span></a></li>
                <li><a><span class="sn-ico c6">级</span><span class="sn-title">等级 Lv.<?php echo $level;?></span></a></li>
              </ul>
            </div></div>
          </div>
        </div>

        <!-- ===== 主列 ===== -->
        <div class="col-main" id="main_feed_container">
          <div class="col-main-feed">

            <!-- 发表说说 -->
            <div class="qz-poster">
              <div class="qz-poster-bd">
                <?php if ($avatarUrl):?><img class="poster-av" src="<?php echo htmlspecialchars($avatarUrl);?>" alt=""><?php endif;?>
                <div class="qz-inputer" data-ph="说点什么吧..." contenteditable="true" id="spPoster"></div>
              </div>
              <div class="qz-poster-ft">
                <div class="attach-icons">
                  <span title="照片">🖼️</span>
                  <span title="表情">😊</span>
                  <span title="@好友">@</span>
                  <span title="话题">#</span>
                </div>
                <div class="op"><button class="btn-post" id="spPostBtn" onclick="spPost()">发表</button></div>
              </div>
            </div>

            <!-- 动态流 tab -->
            <div class="feed-control">
              <div class="feed-control-tab">
                <a class="on" data-f="all">全部动态</a>
                <a data-f="photo">相册</a>
                <a data-f="say">说说</a>
              </div>
              <div class="feed-control-op"><span>刷新</span><span>设置</span></div>
            </div>

            <!-- 说说列表 -->
            <ul class="feed-list" id="feedList">
              <?php foreach ($sampleFeeds as $fi => $fd): ?>
              <li class="f-single">
                <div class="f-single-head">
                  <?php if ($avatarUrl):?><img class="user-avatar" src="<?php echo htmlspecialchars($avatarUrl);?>" alt=""><?php endif;?>
                  <div class="user-info">
                    <div class="f-nick"><?php echo htmlspecialchars($displayName);?></div>
                    <div class="info-detail"><?php echo htmlspecialchars($fd['time']);?></div>
                  </div>
                </div>
                <div class="f-single-content">
                  <div class="f-ct-text"><?php echo htmlspecialchars($fd['text']);?></div>
                  <?php if (!empty($fd['photos'])): ?>
                  <div class="f-ct-txtimg">
                    <div class="img-box<?php echo count($fd['photos']) === 1 ? ' one' : '';?>">
                      <?php foreach ($fd['photos'] as $pi): $ph = $samplePhotos[$pi % count($samplePhotos)]; ?>
                      <a class="img-item"><img src="<?php echo $ph['src'];?>" alt=""></a>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <?php endif; ?>
                </div>
                <div class="f-single-foot">
                  <ul class="op-list">
                    <li class="op-share">↪ 转发</li>
                    <li class="op-comment">💬 评论</li>
                    <li class="op-like<?php echo $fd['likes'] > 0 ? ' liked' : '';?>" data-n="<?php echo (int)$fd['likes'];?>">👍 赞<?php echo $fd['likes'] > 0 ? ' (' . (int)$fd['likes'] . ')' : '';?></li>
                  </ul>
                </div>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>

          <!-- ===== 右栏 ===== -->
          <div class="col-main-sidebar">

            <!-- 精选相片（来自个人空间/朋友圈） -->
            <div class="icenter-right-mod icenter-right-photo">
              <div class="hd">精选相片<span class="more" onclick="spGoto('album')">更多相册 ›</span></div>
              <div class="bd">
                <ul class="photo-grid" id="photoGrid">
                  <?php foreach ($samplePhotos as $pi => $ph): ?>
                  <li title="<?php echo htmlspecialchars($ph['cap']);?>"><img src="<?php echo $ph['src'];?>" alt=""><span class="cap"><?php echo htmlspecialchars($ph['cap']);?></span></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </div>

            <!-- 谁看过我 -->
            <div class="icenter-right-mod">
              <div class="hd">谁看过我<span class="more">›</span></div>
              <div class="bd">
                <ul class="visitor-list" id="visitorList">
                  <li><div class="av-empty" style="width:52px;height:52px;border-radius:50%;background:#e3e8ef;display:flex;align-items:center;justify-content:center;margin:0 auto 3px;color:#fff;font-size:20px">?</div><div class="un">暂无访客</div></li>
                </ul>
              </div>
            </div>

            <!-- 个人信息 -->
            <div class="icenter-right-mod">
              <div class="hd">个人信息</div>
              <div class="bd">
                <ul class="info-list">
                  <li><span class="k">昵称</span><span class="v textoverflow"><?php echo htmlspecialchars($displayName);?></span></li>
                  <li><span class="k">用户名</span><span class="v textoverflow"><?php echo htmlspecialchars($u['username']);?></span></li>
                  <li><span class="k">性别</span><span class="v"><?php echo htmlspecialchars($genderLabel);?></span></li>
                  <?php if ($birthday):?><li><span class="k">生日</span><span class="v"><?php echo htmlspecialchars($birthday);?></span></li><?php endif;?>
                  <li><span class="k">等级</span><span class="v">Lv.<?php echo $level;?>（<?php echo $exp;?> 经验）</span></li>
                  <li><span class="k">获赞</span><span class="v"><?php echo $likes;?></span></li>
                  <li><span class="k">空间ID</span><span class="v"><?php echo $uid;?></span></li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="layout-copyright">© ChatApp 个人空间 · 复刻自 QQ 空间布局</div>
    </div>
  </div>
</div>

<!-- 返回顶部 -->
<div class="fix-layout">
  <div class="to-top" id="spToTop" title="返回顶部">↑</div>
</div>

<script>
var SP_USER = <?php echo json_encode(['self' => $isSelf, 'username' => $u['username'], 'display' => $displayName]);?>;
// 封面 tab 切换（UI 高亮）
document.getElementById('coverTabs').addEventListener('click', function (e) {
    var li = e.target.closest('li');
    if (!li) return;
    [].forEach.call(this.children, function (x) { x.classList.remove('cur'); });
    li.classList.add('cur');
});
// 左侧/顶部动态类型切换
function bindFeedFilter(sel) {
    var box = document.querySelector(sel);
    if (!box) return;
    box.addEventListener('click', function (e) {
        var el = e.target.closest('[data-f]');
        if (!el) return;
        [].forEach.call(this.children, function (x) { x.classList.remove('current', 'on'); });
        el.classList.add('current', 'on');
    });
}
bindFeedFilter('#feedTypes');
bindFeedFilter('.feed-control-tab');
// 返回顶部
var toTop = document.getElementById('spToTop');
toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
// 发表（占位）
function spPost() {
    var p = document.getElementById('spPoster');
    var t = (p.textContent || '').trim();
    if (!t) return;
    alert('个人空间/朋友圈发布功能即将上线（示例 UI）。已输入：' + t);
}
function spGoto(where) { alert('「' + where + '」模块即将上线（示例 UI）。'); }
// 搜索用户
document.getElementById('spSearchInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') {
        var q = this.value.trim();
        if (q) location.href = 'space.php?user=' + encodeURIComponent(q);
    }
});
// 退出
function spLogout() {
    var f = new URLSearchParams();
    f.append('action', 'logout');
    fetch('../../api/auth.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
        .then(function () { location.href = 'login.php'; });
}
</script>
</body>
</html>
