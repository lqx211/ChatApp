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
$stmt = $pdo->prepare("SELECT username, display_name, user_id, avatar, custom_title, gender, gender_privacy, birthday, profile_bg_image, profile_bg_updated_at, bg_privacy, bg_blacklist, bg_whitelist, bg_no_friend, bg_private_image, level, exp, likes, created_at, dnd, enabled, placeholder FROM users WHERE username = ?");
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
$level = (int)$u['level']; $exp = (int)$u['exp']; $likes = (int)$u['likes'];
$gender = $u['gender']; // 0/1/2? 按现有 profile 语义显示
$birthday = $u['birthday'] ?? '';
$uid = (int)$u['user_id'];
$meUid = (int)($currentUser['user_id'] ?? 0);
$spaceTitle = $displayName . '的空间';

// ===== 封面：资源 URL（含视频）+ 可见性 + 取景/镜像参数（与 profile.php 一致） =====
$bgPrivacy = (int)($u['bg_privacy'] ?? 0);
$bgBlackList = array_filter(array_map('intval', explode(',', (string)($u['bg_blacklist'] ?? ''))));
$bgWhiteList = array_filter(array_map('intval', explode(',', (string)($u['bg_whitelist'] ?? ''))));
$bgNoFriend = (int)($u['bg_no_friend'] ?? 0);
$bgPrivateImg = trim((string)($u['bg_private_image'] ?? ''));
$canSeeBg = true;
if ($bgPrivacy === 2) {
    $canSeeBg = false; // 仅自己能看见
} elseif (!empty($cover)) {
    if ($bgPrivacy === 0) { $canSeeBg = !in_array($meUid, $bgBlackList, true); }
    elseif ($bgPrivacy === 1) { $canSeeBg = in_array($meUid, $bgWhiteList, true); }
    if ($bgNoFriend && $meUid > 0 && $uid > 0 && !$isSelf) {
        $cstmt = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE status='accepted' AND ((user_from=? AND user_to=?) OR (user_from=? AND user_to=?))");
        $cstmt->execute([$meUid, $uid, $uid, $meUid]);
        $isFriend = (int)$cstmt->fetchColumn() > 0;
        if (!$isFriend) $canSeeBg = false;
    }
}
$coverUrl = '';
$isBgVideo = false;
$bgMimeType = 'image/png';
$bgSrcKey = $canSeeBg ? $cover : $bgPrivateImg;
if (!empty($bgSrcKey)) {
    $ts = strtotime($u['profile_bg_updated_at'] ?? '') ?: time();
    if (strpos($bgSrcKey, 'data:') === 0) {
        $coverUrl = $bgSrcKey;
    } elseif (strpos($bgSrcKey, 'bgi/') === 0) {
        // data/bgi/<uid>.* 走 file.php 鉴权读取（不暴露真实路径）
        $coverUrl = '../../api/file.php?type=bgi&u=' . $uid . '&v=' . $ts;
    } elseif (strpos($bgSrcKey, 'res/wallpaper/') === 0) {
        $coverUrl = '../../data/' . $bgSrcKey . '?v=' . $ts;
    } elseif (preg_match('/^[0-9a-zA-Z_]+\.(png|jpg|jpeg|gif|webp)$/i', $bgSrcKey)) {
        $coverUrl = '../../api/avatar.php?u=' . urlencode($u['username']) . '&bg=' . urlencode($bgSrcKey);
    } elseif ($bgSrcKey !== '') {
        $coverUrl = '../../api/file.php?f=' . rawurlencode($bgSrcKey) . '&v=' . $ts;
    }
    if (preg_match('/\.(mp4|webm|mov|m4v)$/', $bgSrcKey)) {
        $isBgVideo = true;
        $bgMimeType = preg_match('/\.webm$/', $bgSrcKey) ? 'video/webm' : 'video/mp4';
    }
}
// 取景/镜像参数（封面存景：pos_x/pos_y=可视区中心%，zoom=放大倍数，flip=左右镜像）；旧库兜底建列
$pdo = db();
db_add_column_if_missing('users', 'bg_pos_x', "INT NOT NULL DEFAULT 50");
db_add_column_if_missing('users', 'bg_pos_y', "INT NOT NULL DEFAULT 0");
db_add_column_if_missing('users', 'bg_zoom', "DECIMAL(4,2) NOT NULL DEFAULT 1.00");
db_add_column_if_missing('users', 'bg_flip', "TINYINT(1) NOT NULL DEFAULT 0");
$bgPosStmt = $pdo->prepare("SELECT bg_pos_x, bg_pos_y, bg_zoom, bg_flip FROM users WHERE user_id = ?");
$bgPosStmt->execute([$uid]);
$bgPosRow = $bgPosStmt->fetch();
$bgPosX = (int)($bgPosRow['bg_pos_x'] ?? 50);
$bgPosY = (int)($bgPosRow['bg_pos_y'] ?? 0);
$bgZoom = (float)($bgPosRow['bg_zoom'] ?? 1);
$bgFlip = (int)($bgPosRow['bg_flip'] ?? 0);
if ($bgPosX < 0 || $bgPosX > 100) $bgPosX = 50;
if ($bgPosY < 0 || $bgPosY > 100) $bgPosY = 0;
if ($bgZoom < 1 || $bgZoom > 5) $bgZoom = 1;
$bgTransform = 'scale(' . number_format($bgZoom, 2, '.', '') . ')' . ($bgFlip ? ' scaleX(-1)' : '');
$bgFrameStyle = 'object-position:' . $bgPosX . '% ' . $bgPosY . '%;transform-origin:' . $bgPosX . '% ' . $bgPosY . '%;transform:' . $bgTransform;

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

/** 内联 SVG 图标（QQ 风格灰线图标，currentColor 着色，禁止 emoji） */
function sp_ic(string $n): string {
    $map = [
        'home'    => '<svg viewBox="0 0 24 24" width="15" height="15" fill="currentColor"><path d="M12 3 2 12h3v9h5v-6h4v6h5v-9h3z"/></svg>',
        'search'  => '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5" stroke-linecap="round"/></svg>',
        'people'  => '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><circle cx="9" cy="8" r="4"/><path d="M9 14c-4.4 0-7 2-7 5v1h14v-1c0-3-2.6-5-7-5z"/><path d="M17 12.5A3.5 3.5 0 1 0 13.5 9 3.5 3.5 0 0 0 17 12.5z" opacity=".65"/><path d="M16 14.6c.7.3 1.4.7 2 1.1.9.7 1.6 1.5 2 2.3v1h4v-1c0-2.5-3-4.6-8-5.4z"/></svg>',
        'me'      => '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zm0 2c-3.3 0-7 1.7-7 4v2h14v-2c0-2.3-3.7-4-7-4z"/></svg>',
        'photo'   => '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m3 17 5-5 3 3 3-3 7 6"/></svg>',
        'say'     => '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-5 4V6a2 2 0 0 1 2-2z"/></svg>',
        'card'    => '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><path d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm2 4v2h12V8zm0 4v2h8v-2z"/></svg>',
        'star'    => '<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><path d="m12 3 2.7 5.6 6.3.9-4.6 4.4 1.1 6.1L12 17.8 6.5 20l1.1-6.1L3 9.5l6.3-.9z"/></svg>',
        'image'   => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m3 17 5-5 3 3 3-3 7 6"/></svg>',
        'smile'   => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M8 14a4 4 0 0 0 8 0" stroke-linecap="round"/><path d="M9 9.5h.01M15 9.5h.01" stroke-linecap="round" stroke-width="2.4"/></svg>',
        'share'   => '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M14 5v3C8 8.4 4.6 11 3.2 15.6 4.8 13.6 6.8 12.6 14 12.6v3L22 9z"/></svg>',
        'comment' => '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 3C6.5 3 2 6.7 2 11.3c0 2.5 1.3 4.8 3.4 6.3L4 21.2l3.8-1.9c1.3.4 2.7.6 4.2.6 5.5 0 10-3.7 10-8.3S17.5 3 12 3z"/></svg>',
        'like'    => '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 21s-7.5-4.7-10-9.2C.6 9 2 5.6 5.2 5c1.8-.3 3.6.5 4.8 2L12 9l2-2c1.2-1.5 3-2.3 4.8-2C22 5.6 23.4 9 22 11.8 19.5 16.3 12 21 12 21z"/></svg>',
        'top'     => '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="m12 4 7 8h-4v8h-6v-8H5z"/></svg>',
    ];
    return $map[$n] ?? '';
}
$ch = mb_strtoupper(mb_substr($displayName, 0, 1));
// 示例精选相片（后续从 api/space.php?action=photos 读取）
$samplePhotos = [];
for ($i = 0; $i < 9; $i++) { $samplePhotos[] = ['src' => sp_ph($i, $ch, '#ffb300','#ff7043'), 'cap' => '相册 ' . ($i + 1)]; }
// 示例说说（后续从 api/space.php?action=feed 读取）
$sampleFeeds = [
    ['text' => '这里是 ' . $displayName . ' 的个人空间，欢迎来做客～（示例内容，接入朋友圈后替换）', 'photos' => [0, 1, 2], 'time' => '刚刚', 'likes' => 0],
    ['text' => '晒一张今天拍的照片', 'photos' => [3], 'time' => '昨天', 'likes' => 2],
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
      <a class="logo" href="chat.php" title="返回聊天"><span class="logo-ico"><?php echo sp_ic('home');?></span>个人空间</a>
      <ul class="top-nav">
        <li class="nav-list"><a href="space.php<?php echo $isSelf ? '' : '?user=' . urlencode($u['username']);?>" class="on">主页</a></li>
        <li class="nav-list"><a href="chat.php">聊天</a></li>
        <li class="nav-list"><a href="settings.php">设置</a></li>
      </ul>
      <div class="top-search">
        <div class="search-box">
          <input class="search-input" placeholder="用户/动态" id="spSearchInput">
          <a class="search-button" title="搜索用户"><?php echo sp_ic('search');?></a>
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
    <div class="layout-head-inner<?php echo $coverUrl ? ' has-cover' : '';?>" id="spaceCoverHead">
      <?php if ($coverUrl):?>
        <?php if ($isBgVideo):?>
        <video id="spaceCoverMedia" class="head-cover-img" src="<?php echo htmlspecialchars($coverUrl);?>" autoplay muted loop playsinline style="<?php echo $bgFrameStyle;?>"></video>
        <?php else:?>
        <img id="spaceCoverMedia" class="head-cover-img" src="<?php echo htmlspecialchars($coverUrl);?>" alt="" style="<?php echo $bgFrameStyle;?>">
        <?php endif;?>
      <?php endif;?>
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
          <span class="btn-head btn-bg-edit" id="spBgEditBtn" onclick="spOpenBgMenu(event)">更换背景图</span>
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
                <li class="current" data-f="all"><a><span class="sn-ico c1"><?php echo sp_ic('people');?></span><span class="sn-title">好友动态</span></a></li>
                <li data-f="me"><a><span class="sn-ico c2"><?php echo sp_ic('me');?></span><span class="sn-title">与我相关</span></a></li>
                <li data-f="photo"><a><span class="sn-ico c3"><?php echo sp_ic('photo');?></span><span class="sn-title">我的相册</span></a></li>
                <li data-f="say"><a><span class="sn-ico c4"><?php echo sp_ic('say');?></span><span class="sn-title">我的说说</span></a></li>
              </ul>
            </div></div>
          </div>
          <div class="mod-side-nav mod-side-nav-recently-used">
            <div class="hd">个人资料</div>
            <div class="inner"><div class="bd">
              <ul class="sn-list">
                <li><a><span class="sn-ico c5"><?php echo sp_ic('card');?></span><span class="sn-title textoverflow"><?php echo htmlspecialchars($displayName);?></span></a></li>
                <li><a><span class="sn-ico c6"><?php echo sp_ic('star');?></span><span class="sn-title">等级 Lv.<?php echo $level;?></span></a></li>
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
                  <span title="照片"><?php echo sp_ic('image');?></span>
                  <span title="表情"><?php echo sp_ic('smile');?></span>
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
                    <li class="op-share"><span class="op-ic"><?php echo sp_ic('share');?></span> 转发</li>
                    <li class="op-comment"><span class="op-ic"><?php echo sp_ic('comment');?></span> 评论</li>
                    <li class="op-like<?php echo $fd['likes'] > 0 ? ' liked' : '';?>" data-n="<?php echo (int)$fd['likes'];?>"><span class="op-ic"><?php echo sp_ic('like');?></span> 赞<?php echo $fd['likes'] > 0 ? ' (' . (int)$fd['likes'] . ')' : '';?></li>
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
  <div class="to-top" id="spToTop" title="返回顶部"><?php echo sp_ic('top');?></div>
</div>

<!-- 封面编辑：背景图菜单 + 文件选择 + 就地编辑控制条 -->
<div class="sp-bg-menu" id="spBgMenu">
  <div class="sp-bg-menu-item" onclick="spPickBgFile()">更换背景图（图片/视频）</div>
  <div class="sp-bg-menu-item" onclick="spAdjustCover()">调整当前封面</div>
  <div class="sp-bg-menu-item danger" onclick="spClearBg()">清空背景图</div>
</div>
<input type="file" id="spBgFileInput" accept="image/*,video/*" style="display:none" onchange="spOnBgFileChange(this)">
<div class="sp-cover-edit-bar" id="spCoverEditBar" style="display:none">
  <span class="sp-eb-hint">拖动移动 · 滚轮/双指缩放</span>
  <button type="button" class="sp-eb-btn" id="spEbFlip" onclick="spCoverFlip()">⇄ 镜像</button>
  <button type="button" class="sp-eb-btn" onclick="spCoverZoom(-1)">−</button>
  <button type="button" class="sp-eb-btn" onclick="spCoverZoom(1)">＋</button>
  <button type="button" class="sp-eb-btn sp-eb-ok" onclick="spCoverConfirm()">完成</button>
  <button type="button" class="sp-eb-btn" onclick="spCoverCancel()">取消</button>
  <span class="sp-eb-progress" id="spEbProgress"></span>
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

<script>
/* ============ 封面就地编辑：直接在本页移动/缩放/镜像（头像可见，无需额外标记） ============ */
var SP_COVER_SAVED = <?php echo json_encode(['posX' => $bgPosX, 'posY' => $bgPosY, 'zoom' => (float)$bgZoom, 'flip' => $bgFlip, 'isVideo' => $isBgVideo, 'hadCover' => ($coverUrl !== '')]);?>;
var SPCOV = { active:false, mode:'adjust', file:null, url:'', isVideo:false, nw:0, nh:0, ox:0, oy:0, sx:1, flip:0, frameW:428, frameH:250, origSrc:'', origStyle:'', pts:{}, pinch:null };

function hideBgMenu() { var m = document.getElementById('spBgMenu'); if (m) m.style.display = 'none'; }
function spOpenBgMenu(e) {
  e && e.stopPropagation();
  var m = document.getElementById('spBgMenu');
  if (!m) return;
  if (m.style.display === 'block') { hideBgMenu(); return; }
  m.style.display = 'block';
  var r = (e && e.target) ? e.target.getBoundingClientRect() : null;
  if (r) {
    m.style.left = Math.min(window.innerWidth - 200, r.left) + 'px';
    m.style.top = (r.bottom + 6) + 'px';
  } else {
    m.style.left = '50%'; m.style.top = '80px';
  }
}
function spPickBgFile() { hideBgMenu(); var i = document.getElementById('spBgFileInput'); if (i) i.click(); }
function spOnBgFileChange(input) {
  var f = input.files && input.files[0];
  if (input) input.value = '';
  if (!f) return;
  // iOS 视频常无 type → 用扩展名兜底
  var isVid = f.type.indexOf('video/') === 0 || /\.(mp4|mov|m4v|webm|mkv)$/i.test(f.name);
  startCoverEdit(f, isVid);
}
function spAdjustCover() { hideBgMenu(); startCoverEdit(null, SP_COVER_SAVED.isVideo); }
function spClearBg() {
  hideBgMenu();
  if (!window.confirm('确定清空背景图？')) return;
  var f = new URLSearchParams(); f.append('action', 'remove_profile_bg');
  fetch('../../api/settings.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.success) location.reload(); else alert('清空失败'); })
    .catch(function () { alert('清空失败'); });
}

function showEditBar() {
  var b = document.getElementById('spCoverEditBar'); if (b) b.style.display = 'flex';
  var fb = document.getElementById('spEbFlip'); if (fb) fb.classList.toggle('active', !!SPCOV.flip);
}
function hideEditBar() { var b = document.getElementById('spCoverEditBar'); if (b) b.style.display = 'none'; }

function startCoverEdit(file, isVideo) {
  var head = document.getElementById('spaceCoverHead');
  if (!head || SPCOV.active) return;
  var media = document.getElementById('spaceCoverMedia');
  SPCOV.active = true;
  SPCOV.mode = file ? 'file' : 'adjust';
  SPCOV.file = file || null;
  SPCOV.isVideo = !!isVideo;
  SPCOV.frameW = head.clientWidth || 428;
  SPCOV.frameH = head.clientHeight || 250;
  if (!media) {
    media = document.createElement(isVideo ? 'video' : 'img');
    media.id = 'spaceCoverMedia';
    media.className = 'head-cover-img';
    head.insertBefore(media, head.firstChild);
  }
  SPCOV.origSrc = media.getAttribute('src') || '';
  SPCOV.origStyle = media.getAttribute('style') || '';
  head.classList.add('sp-editing');
  if (file) {
    if (SPCOV.url) URL.revokeObjectURL(SPCOV.url);
    SPCOV.url = URL.createObjectURL(file);
    media.src = SPCOV.url;
    if (isVideo) media.onloadedmetadata = function () { computeFromMedia(media); };
    else media.onload = function () { computeFromMedia(media); };
  } else {
    // 调整现有封面：不换源，直接用当前媒体（已加载则立即计算）
    if (isVideo) {
      if (media.readyState >= 1) computeFromMedia(media);
      else media.onloadedmetadata = function () { computeFromMedia(media); };
    } else {
      if (media.complete && media.naturalWidth) computeFromMedia(media);
      else media.onload = function () { computeFromMedia(media); };
    }
  }
}
function computeFromMedia(media) {
  var nw = media.videoWidth || media.naturalWidth || 0;
  var nh = media.videoHeight || media.naturalHeight || 0;
  if (!nw || !nh) return;
  SPCOV.nw = nw; SPCOV.nh = nh;
  // 切为“手动编辑”模式：自然尺寸 + translate/scale
  media.style.cssText = 'position:absolute;left:0;top:0;width:' + nw + 'px;height:' + nh + 'px;object-fit:none;z-index:0;transform-origin:0 0;';
  var cs = Math.max(SPCOV.frameW / nw, SPCOV.frameH / nh);
  var cx = (SP_COVER_SAVED.posX / 100) * nw, cy = (SP_COVER_SAVED.posY / 100) * nh;
  // 新封面从非镜像开始；调整现有封面沿用已保存的镜像状态
  SPCOV.flip = (SPCOV.mode === 'adjust' && SP_COVER_SAVED.flip) ? 1 : 0;
  SPCOV.sx = Math.max(cs, (SP_COVER_SAVED.zoom || 1) * cs);
  SPCOV.ox = SPCOV.frameW / 2 - cx * SPCOV.sx;
  SPCOV.oy = SPCOV.frameH / 2 - cy * SPCOV.sx;
  clampCover(); renderCover();
  showEditBar();
  if (SPCOV.isVideo) { var p = media.play(); if (p && p.catch) p.catch(function () {}); }
}
function renderCover() {
  var media = document.getElementById('spaceCoverMedia');
  if (!media) return;
  media.style.transformOrigin = '0 0';
  if (SPCOV.flip) {
    media.style.transform = 'translate(' + (SPCOV.frameW - SPCOV.ox) + 'px,' + SPCOV.oy + 'px) scale(' + (-SPCOV.sx) + ',' + SPCOV.sx + ')';
  } else {
    media.style.transform = 'translate(' + SPCOV.ox + 'px,' + SPCOV.oy + 'px) scale(' + SPCOV.sx + ')';
  }
}
function clampCover() {
  var w = SPCOV.nw * SPCOV.sx, h = SPCOV.nh * SPCOV.sx;
  SPCOV.ox = Math.min(0, Math.max(SPCOV.frameW - w, SPCOV.ox));
  SPCOV.oy = Math.min(0, Math.max(SPCOV.frameH - h, SPCOV.oy));
}
function coverZoomAt(cx, cy, k) {
  var oldS = SPCOV.sx;
  var wx = (cx - SPCOV.ox) / oldS, wy = (cy - SPCOV.oy) / oldS;
  var ns = Math.min(5, Math.max(oldS * 0.2, oldS * k));
  SPCOV.sx = ns;
  SPCOV.ox = cx - wx * ns;
  SPCOV.oy = cy - wy * ns;
  clampCover(); renderCover();
}
function spCoverZoom(dir) { coverZoomAt(SPCOV.frameW / 2, SPCOV.frameH / 2, dir > 0 ? 1.25 : 0.8); }
function spCoverFlip() {
  SPCOV.flip = SPCOV.flip ? 0 : 1;
  var b = document.getElementById('spEbFlip'); if (b) b.classList.toggle('active', !!SPCOV.flip);
  renderCover();
}
function spCoverConfirm() {
  if (!SPCOV.active) return;
  var nw = SPCOV.nw, nh = SPCOV.nh;
  if (!nw || !nh) return;
  var cs = Math.max(SPCOV.frameW / nw, SPCOV.frameH / nh);
  var zoom = Math.max(1, SPCOV.sx / cs);
  var cx = (-SPCOV.ox) / SPCOV.sx + SPCOV.frameW / (2 * SPCOV.sx);
  var cy = (-SPCOV.oy) / SPCOV.sx + SPCOV.frameH / (2 * SPCOV.sx);
  var data = {
    pos_x: Math.round(Math.max(0, Math.min(100, cx / nw * 100))),
    pos_y: Math.round(Math.max(0, Math.min(100, cy / nh * 100))),
    zoom: +zoom.toFixed(2),
    flip: SPCOV.flip ? 1 : 0
  };
  if (SPCOV.mode === 'file' && SPCOV.file) {
    uploadBgFile(SPCOV.file, data, function () { location.reload(); });
  } else {
    saveFraming(data, function () { location.reload(); });
  }
}
function uploadBgFile(file, data, ondone) {
  var bar = document.getElementById('spEbProgress');
  var xhr = new XMLHttpRequest();
  var form = new FormData();
  form.append('action', 'upload_profile_bg');
  form.append('file', file);
  form.append('pos_x', data.pos_x); form.append('pos_y', data.pos_y);
  form.append('zoom', data.zoom); form.append('flip', data.flip);
  xhr.open('POST', '../../api/settings.php');
  xhr.onload = function () {
    if (bar) bar.textContent = '';
    if (xhr.status === 200) {
      try { var d = JSON.parse(xhr.responseText); if (d && d.success) { ondone && ondone(d); return; } } catch (e) {}
    }
    if (bar) bar.textContent = '上传失败';
    alert('上传失败');
  };
  xhr.onerror = function () { if (bar) bar.textContent = '上传失败'; alert('上传失败'); };
  xhr.upload.onprogress = function (e) { if (e.lengthComputable && bar) bar.textContent = Math.round(e.loaded / e.total * 100) + '%'; };
  xhr.send(form);
}
function saveFraming(data, ondone) {
  var f = new URLSearchParams();
  f.append('action', 'save_bg_framing');
  f.append('pos_x', data.pos_x); f.append('pos_y', data.pos_y);
  f.append('zoom', data.zoom); f.append('flip', data.flip);
  fetch('../../api/settings.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.success) { ondone && ondone(); } else alert('保存失败'); })
    .catch(function () { alert('保存失败'); });
}
function spCoverCancel() {
  var head = document.getElementById('spaceCoverHead');
  var media = document.getElementById('spaceCoverMedia');
  if (head) head.classList.remove('sp-editing');
  hideEditBar();
  SPCOV.active = false;
  if (media) {
    media.onload = media.onloadedmetadata = null;
    media.removeAttribute('style');
    if (SPCOV.origStyle) media.setAttribute('style', SPCOV.origStyle);
    if (SPCOV.mode === 'file') {
      if (SPCOV.origSrc) media.src = SPCOV.origSrc;
      else if (!SP_COVER_SAVED.hadCover && media.parentNode) media.parentNode.removeChild(media);
    }
  }
  if (SPCOV.url) { URL.revokeObjectURL(SPCOV.url); SPCOV.url = ''; }
}

// 事件绑定：编辑态下 拖拽平移 + 双指缩放 + 滚轮缩放
(function () {
  var head = document.getElementById('spaceCoverHead');
  if (!head) return;
  function up(e) { delete SPCOV.pts[e.pointerId]; if (Object.keys(SPCOV.pts).length < 2) SPCOV.pinch = null; }
  head.addEventListener('pointerdown', function (e) {
    if (!SPCOV.active) return;
    try { head.setPointerCapture(e.pointerId); } catch (err) {}
    SPCOV.pts[e.pointerId] = { x: e.clientX, y: e.clientY };
    if (Object.keys(SPCOV.pts).length === 2) SPCOV.pinch = null;
  });
  head.addEventListener('pointermove', function (e) {
    if (!SPCOV.active || SPCOV.pts[e.pointerId] == null) return;
    var ks = Object.keys(SPCOV.pts);
    if (ks.length === 1) {
      var p = SPCOV.pts[e.pointerId];
      SPCOV.ox += e.clientX - p.x; SPCOV.oy += e.clientY - p.y;
      SPCOV.pts[e.pointerId] = { x: e.clientX, y: e.clientY };
      clampCover(); renderCover();
    } else if (ks.length === 2) {
      var other = ks.find(function (k) { return k != e.pointerId; });
      if (other == null) return;
      var a = SPCOV.pts[other], b = SPCOV.pts[e.pointerId];
      var nx = e.clientX, ny = e.clientY;
      var d1 = Math.hypot(a.x - nx, a.y - ny);
      if (SPCOV.pinch && SPCOV.pinch.d > 0) {
        coverZoomAt(SPCOV.pinch.mx, SPCOV.pinch.my, d1 / SPCOV.pinch.d);
      }
      SPCOV.pinch = { d: d1, mx: (a.x + nx) / 2, my: (a.y + ny) / 2 };
      SPCOV.pts[e.pointerId] = { x: nx, y: ny };
    }
  });
  head.addEventListener('pointerup', up);
  head.addEventListener('pointercancel', up);
  head.addEventListener('wheel', function (e) {
    if (!SPCOV.active) return;
    e.preventDefault();
    var r = head.getBoundingClientRect();
    coverZoomAt(e.clientX - r.left, e.clientY - r.top, e.deltaY < 0 ? 1.1 : 0.9);
  }, { passive: false });
  // 点击页面其它处关闭背景图菜单
  document.addEventListener('click', function () { hideBgMenu(); });
})();
</script>
</body>
</html>
