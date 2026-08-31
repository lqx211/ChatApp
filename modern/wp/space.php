<?php
/**
 * ChatApp 个人空间（桌面版灰色简洁主页）
 * 展示用户：本人或 ?user=xxx。UI 原创；数据接真实 users 字段。
 * 「精选相片」来自个人空间(朋友圈) —— 目前为示例占位，后续接 api/space.php。
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();

// embed=1：内嵌在聊天面板(iframe)时隐藏自带顶栏，避免双层工具栏
$embedMode = isset($_GET['embed']) ? 1 : 0;

$viewUsername = isset($_GET['user']) ? trim((string)$_GET['user']) : '';
$viewUid = (int)($_GET['uid'] ?? 0);
$meName = (string)($currentUser['username'] ?? '');

// 支持 ?uid=<数字> 按用户ID访问空间，或 ?user=<用户名>，缺省为本人
$pdo = db();
db_add_column_if_missing('users', 'space_ears', "TINYINT(1) NOT NULL DEFAULT 1");
if ($viewUid > 0) {
    $stmt = $pdo->prepare("SELECT username, display_name, user_id, avatar, custom_title, gender, gender_privacy, birthday, profile_bg_image, profile_bg_updated_at, level, exp, likes, created_at, dnd, enabled, placeholder, space_ears FROM users WHERE user_id = ?");
    $stmt->execute([$viewUid]);
} else {
    $target = $viewUsername !== '' ? $viewUsername : $meName;
    $stmt = $pdo->prepare("SELECT username, display_name, user_id, avatar, custom_title, gender, gender_privacy, birthday, profile_bg_image, profile_bg_updated_at, level, exp, likes, created_at, dnd, enabled, placeholder, space_ears FROM users WHERE username = ?");
    $stmt->execute([$target]);
}
$u = $stmt->fetch();
if (!$u || !(int)$u['enabled'] || (int)$u['placeholder']) {
    header('Location: chat.php');
    exit;
}
// 是否本人空间（按 user_id 判断，兼容 uid/user 两种访问方式）
$isSelf = ((int)$u['user_id'] === (int)($currentUser['user_id'] ?? 0));

$displayName = $u['display_name'] ?: $u['username'];
$avatarUrl = chatapp_avatar_url($u['avatar'] ?? '', $u['username']);
$sig = trim((string)($u['custom_title'] ?? ''));
$level = (int)$u['level']; $exp = (int)$u['exp']; $likes = (int)$u['likes'];
$gender = $u['gender']; // 0/1/2? 按现有 profile 语义显示
$birthday = $u['birthday'] ?? '';
$uid = (int)$u['user_id'];
$meUid = (int)($currentUser['user_id'] ?? 0);
$spaceTitle = $displayName . '的空间';

// 桌面版个人空间：不用个人上传封面（缩放观感差），从 modern/bg/ 随机选默认壁纸
$bgFiles = glob(__DIR__ . '/../bg/*.{jpg,jpeg,png,webp,gif}', GLOB_BRACE);
$bgFiles = array_values(array_filter($bgFiles, 'is_file'));
$coverUrl = $bgFiles ? ('../bg/' . basename($bgFiles[array_rand($bgFiles)])) : '';

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

/** 内联 SVG 图标（灰线图标，currentColor 着色，禁止 emoji） */
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
        'globe'   => '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18" stroke-linecap="round"/></svg>',
        'lock'    => '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="9" rx="1"/><path d="M8 11V7a4 4 0 0 1 8 0v4" stroke-linecap="round"/></svg>',
        'at'      => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a3 3 0 0 0 6 0v-1a10 10 0 1 0-3.92 7.94"/></svg>',
        'hash'    => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/></svg>',
        'down'    => '<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>',
        'close'   => '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>',
        'right'   => '<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>',
    ];
    return $map[$n] ?? '';
}
$ch = mb_strtoupper(mb_substr($displayName, 0, 1));
// 精选相片（暂为示例占位，后续接相册）
$samplePhotos = [];
for ($i = 0; $i < 9; $i++) { $samplePhotos[] = ['src' => sp_ph($i, $ch, '#ffb300','#ff7043'), 'cap' => '相册 ' . ($i + 1)]; }

// ===== 朋友圈：读取并过滤可见性 =====
ensure_space_feeds_table();
ensure_space_comments_table();
$isFriendView = $isSelf || space_is_friend($pdo, $meUid, $uid);
$feedRows = [];
$cmtCount = [];
if ($uid) {
    $fstmt = $pdo->prepare("SELECT id, content, images, visibility, visible_to, likes, liked_by, created_at FROM space_feeds WHERE user_id=? AND enabled=1 ORDER BY id DESC LIMIT 200");
    $fstmt->execute([$uid]);
    $cc = $pdo->query("SELECT feed_id, COUNT(*) c FROM space_comments WHERE enabled=1 GROUP BY feed_id");
    foreach ($cc->fetchAll() as $row) $cmtCount[(int)$row['feed_id']] = (int)$row['c'];
    foreach ($fstmt->fetchAll() as $f) {
        $vis = (int)$f['visibility'];
        if (!$isSelf) {
            if ($vis === 4) continue;                                  // 仅自己
            if ($vis === 1 && !$isFriendView) continue;                // 好友可见
            $vt = space_parse_ids($f['visible_to']);
            if ($vis === 2 && !in_array($meUid, $vt, true)) continue;  // 部分好友可见
            if ($vis === 3 && in_array($meUid, $vt, true)) continue;   // 部分好友不可见
        }
        $likedBy = space_parse_ids($f['liked_by']);
        $feedRows[] = [
            'id' => (int)$f['id'],
            'text' => (string)$f['content'],
            'images' => $f['images'] ? (json_decode($f['images'], true) ?: []) : [],
            'likes' => (int)$f['likes'],
            'liked' => in_array($meUid, $likedBy, true),
            'time' => space_fmt_time($f['created_at']),
            'vis' => $vis,
            'cmt' => $cmtCount[(int)$f['id']] ?? 0,
        ];
    }
}
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
    <div class="layout-head-inner<?php echo $coverUrl ? ' has-cover' : '';?>">
      <?php if ($coverUrl):?><img id="spaceCoverMedia" class="head-cover-img" src="<?php echo htmlspecialchars($coverUrl);?>" alt=""><?php endif;?>
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
          <?php if ($isFriendView): ?>
          <span class="btn-head" id="spSpecialBtn"><a onclick="spToggleSpecial()">特别关心</a></span>
          <?php endif; ?>
          <?php if (!$isFriendView): ?>
            <span class="btn-head btn-primary"><a>加好友</a></span>
          <?php endif; ?>
          <span class="btn-head"><a href="chat.php?user=<?php echo urlencode($u['username']);?>" target="_blank">发消息</a></span>
        <?php endif;?>
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
        <?php if ((int)($u['space_ears'] ?? 1)): ?><img class="av-ear" style="--av-size:120px" src="../../data/res/space-widget/ears.apng" alt=""><?php endif; ?>
      </div>
      <div class="head-detail">
        <div class="head-detail-name"><span class="user-name"><?php echo htmlspecialchars($displayName);?></span></div>
        <div class="head-detail-sub">Lv.<?php echo $level;?> · UID <?php echo $uid;?></div>
      </div>
    </div>
  </div>

  <!-- 内容背景 -->
  <div class="layout-background">
    <!-- 导航 tabs（主页/日志/...）放在头像栏下方，避免与昵称重叠 -->
    <div class="layout-shop-item" id="menuContainer">
      <div class="shop-item cs">
        <div class="head-nav">
          <ul class="head-nav-menu" id="coverTabs">
            <li class="cur" data-tab="home"><a title="主页">主页</a></li>
            <li data-tab="blog"><a title="日志">日志</a></li>
            <li data-tab="album"><a title="相册">相册</a></li>
            <li data-tab="board"><a title="留言板">留言板</a></li>
            <li data-tab="say"><a title="说说">说说</a></li>
            <li data-tab="profile"><a title="个人档">个人档</a></li>
          </ul>
        </div>
      </div>
    </div>
    <div class="layout-body">
      <div class="layout-page clearfix">

        <!-- ===== 左侧导航 ===== -->
        <div class="col-menu" id="leftMenu">
          <div class="mod-side-nav mod-side-nav-message">
            <div class="hd">动态</div>
            <div class="inner"><div class="bd">
              <ul class="sn-list" id="feedTypes">
                <li class="current" data-f="mine"><a onclick="spStream('mine')"><span class="sn-ico c1"><?php echo sp_ic('people');?></span><span class="sn-title">我的动态</span></a></li>
                <li data-f="friends"><a onclick="spStream('friends')"><span class="sn-ico c2"><?php echo sp_ic('people');?></span><span class="sn-title">好友动态</span></a></li>
                <li data-f="me"><a onclick="spLoadMentions()"><span class="sn-ico c3"><?php echo sp_ic('me');?></span><span class="sn-title">与我相关</span><span class="sn-badge" id="spMeBadge" style="display:none"></span></a></li>
                <li data-f="care"><a onclick="spStream('special')"><span class="sn-ico c4"><?php echo sp_ic('star');?></span><span class="sn-title">特别关心</span></a></li>
              </ul>
            </div></div>
          </div>
          <div class="mod-side-nav mod-side-nav-recently-used">
            <div class="hd">个人资料</div>
            <div class="inner"><div class="bd">
              <ul class="sn-list">
                <li><a onclick="spGoTab('profile')"><span class="sn-ico c1"><?php echo sp_ic('card');?></span><span class="sn-title">个人档案</span></a></li>
                <li><a onclick="spGoTab('album')"><span class="sn-ico c2"><?php echo sp_ic('photo');?></span><span class="sn-title">我的相册</span></a></li>
                <li><a onclick="spGoTab('say')"><span class="sn-ico c3"><?php echo sp_ic('say');?></span><span class="sn-title">我的说说</span></a></li>
              </ul>
            </div></div>
          </div>
        </div>

        <!-- ===== 主列 ===== -->
        <div class="col-main" id="main_feed_container">
          <div class="col-main-feed">

            <!-- 发表说说（仅自己） -->
            <?php if ($isSelf): ?>
            <div class="qz-poster" id="spPosterBox">
              <div class="qz-poster-bd">
                <?php if ($avatarUrl):?><img class="poster-av" src="<?php echo htmlspecialchars($avatarUrl);?>" alt=""><?php endif;?>
                <div class="qz-inputer" data-ph="说点什么吧..." contenteditable="true" id="spPoster"></div>
              </div>
              <div class="qz-poster-ft">
                <div class="attach-icons">
                  <span title="照片" onclick="spPickImages()"><?php echo sp_ic('image');?></span>
                  <span title="表情" onclick="spAlert('表情')"><?php echo sp_ic('smile');?></span>
                  <span title="@好友" onclick="spMentionOpen()"><?php echo sp_ic('at');?></span>
                  <span title="话题" onclick="spAlert('话题')"><?php echo sp_ic('hash');?></span>
                </div>
                <div class="vis-select" id="spVisBtn" onclick="spVisToggle(event)">
                  <span class="vis-ic"><?php echo sp_ic('globe');?></span>
                  <span class="vis-label" id="spVisLabel">所有人可见</span>
                  <span class="vis-arrow"><?php echo sp_ic('down');?></span>
                </div>
                <div class="op"><button class="btn-post" id="spPostBtn" onclick="spPost()">发表</button></div>
              </div>
              <!-- 艾特好友条：显示已 @ 的好友 (名字) -->
              <div class="sp-mention-bar" id="spMentionBar" style="display:none"></div>
              <!-- 图片预览 + 文件选择 -->
              <div class="sp-post-imgs" id="spPostImgs" style="display:none"></div>
              <input type="file" id="spImgInput" multiple accept="image/*" style="display:none">
              <!-- 好友选择面板：贴在发表框底部，随页面滚动，动画伸出（分组侧栏 + 已选列表） -->
              <div class="sp-fm-mask" id="spFmMask" style="display:none">
                <div class="sp-fm-box">
                  <div class="sp-fm-head"><span id="spFmTitle">选择好友</span><span class="sp-fm-x" onclick="spFmClose()"><?php echo sp_ic('close');?></span></div>
                  <div class="sp-fm-search"><input id="spFmSearch" placeholder="搜索好友"><span class="sp-fm-sbtn"><?php echo sp_ic('search');?></span></div>
                  <div class="sp-fm-body">
                    <div class="sp-fm-side">
                      <div class="sp-fm-group on" data-g="all" onclick="spFmGroup('all')">全部好友</div>
                      <div class="sp-fm-group" data-g="mine" onclick="spFmGroup('mine')">我的好友</div>
                      <div class="sp-fm-group" data-g="auth" onclick="spFmGroup('auth')">认证空间</div>
                    </div>
                    <div class="sp-fm-right">
                      <div class="sp-fm-picked" id="spFmPicked" style="display:none"></div>
                      <div id="spFmList"></div>
                    </div>
                  </div>
                  <div class="sp-fm-foot">
                    <span class="sp-fm-hint">你可以在下面添加最多 <b>30</b> 位好友（已选 <b id="spFmCount">0</b> 位）</span>
                    <button class="sp-fm-ok" onclick="spFmConfirm()">确定</button>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>

            <div id="spFeedArea">
            <!-- 动态流 tab -->
            <div class="feed-control">
              <div class="feed-control-tab">
                <a class="on" data-f="all">全部动态</a>
                <a data-f="photo">相册</a>
                <a data-f="say">说说</a>
              </div>
              <div class="feed-control-op"><span onclick="location.reload()" title="刷新">刷新</span><span onclick="spAlert('设置')">设置</span></div>
            </div>

            <!-- 说说列表 -->
            <ul class="feed-list" id="feedList">
              <?php if (!$feedRows): ?>
              <li class="f-single"><div class="f-single-content"><div class="f-ct-text" style="color:#777"><?php echo $isSelf ? '还没有动态，发第一条说说吧～' : 'TA 还没有动态';?></div></div></li>
              <?php endif; ?>
              <?php foreach ($feedRows as $f): ?>
              <li class="f-single" data-id="<?php echo (int)$f['id'];?>">
                <div class="f-single-head">
                  <?php if ($avatarUrl):?><img class="user-avatar" src="<?php echo htmlspecialchars($avatarUrl);?>" alt=""><?php else:?><span class="user-avatar av-empty"><?php echo htmlspecialchars($ch);?></span><?php endif;?>
                  <div class="user-info">
                    <div class="f-nick"><?php echo htmlspecialchars($displayName);?></div>
                    <div class="info-detail"><?php echo htmlspecialchars($f['time']);?><?php if ($isSelf):?> · <span class="f-vis"><?php echo space_vis_label((int)$f['vis']);?></span><?php endif;?></div>
                  </div>
                </div>
                <div class="f-single-content">
                  <div class="f-ct-text"><?php echo nl2br(htmlspecialchars($f['text']));?></div>
                  <?php if (!empty($f['images'])): ?>
                  <div class="f-ct-txtimg"><div class="img-box<?php echo count($f['images']) === 1 ? ' one' : '';?>" data-lb="<?php echo (int)$f['id'];?>">
                    <?php foreach ($f['images'] as $di => $im): ?>
                    <a class="img-item" onclick="spOpenLightbox(<?php echo (int)$f['id'];?>, <?php echo (int)$di;?>)"><img src="<?php echo htmlspecialchars($im);?>" alt=""></a>
                    <?php endforeach; ?>
                  </div></div>
                  <?php endif; ?>
                </div>
                <div class="f-single-foot">
                  <ul class="op-list">
                    <li class="op-like<?php echo $f['liked'] ? ' liked' : '';?>" data-id="<?php echo (int)$f['id'];?>"><span class="op-ic"><?php echo sp_ic('like');?></span> 赞<?php if ((int)$f['likes'] > 0):?> (<?php echo (int)$f['likes'];?>)<?php endif;?></li>
                    <li class="op-comment" data-id="<?php echo (int)$f['id'];?>"><span class="op-ic"><?php echo sp_ic('comment');?></span> 评论<?php if ((int)$f['cmt'] > 0):?> <span class="cmt-c">(<?php echo (int)$f['cmt'];?>)</span><?php endif;?></li>
                    <?php if ($isSelf): ?>
                    <li class="op-del" data-id="<?php echo (int)$f['id'];?>"><span class="op-ic"><?php echo sp_ic('top');?></span> 删除</li>
                    <?php endif; ?>
                  </ul>
                </div>
                <div class="f-comments" data-feed="<?php echo (int)$f['id'];?>" style="display:none">
                  <div class="f-comments-list"></div>
                  <div class="f-cmt-input">
                    <input class="f-cmt-text" placeholder="评论一下..." maxlength="500">
                    <button class="btn-post btn-sm" onclick="spCmtSend(this)">发表</button>
                  </div>
                </div>
              </li>
              <?php endforeach; ?>
              <li class="f-single" id="feedFilterEmpty" style="display:none"><div class="f-single-content"><div class="f-ct-text" style="color:#777">该分类下暂无动态</div></div></li>
            </ul>
            </div>

            <!-- ===== 日志面板 ===== -->
            <section class="sp-tab" id="spTabBlog" style="display:none">
              <div class="sp-tab-head"><h3>日志</h3>
                <?php if ($isSelf):?><button class="btn-post" onclick="spBlogCompose()">写日志</button><?php endif;?>
              </div>
              <div id="spBlogList"></div>
              <div id="spBlogDetail" style="display:none"></div>
              <div id="spBlogEditor" style="display:none">
                <div class="sp-blog-ed">
                  <div class="sp-blog-ed-head">
                    <input id="spBlogTitle" placeholder="日志标题" maxlength="200">
                    <select id="spBlogVis" title="可见范围">
                      <option value="0">所有人可见</option>
                      <option value="1">好友可见</option>
                      <option value="4">仅自己可见</option>
                    </select>
                  </div>
                  <textarea id="spBlogContent" placeholder="正文..." maxlength="20000"></textarea>
                  <div class="sp-blog-ed-ft">
                    <button class="btn-post" onclick="spBlogSave()">发布</button>
                    <button class="btn-plain" onclick="spBlogCancel()">取消</button>
                  </div>
                </div>
              </div>
            </section>

            <!-- ===== 相册面板 ===== -->
            <section class="sp-tab" id="spTabAlbum" style="display:none">
              <div class="sp-tab-head"><h3>相册</h3><span class="sp-tab-sub">精选相片（示例，接入相册后替换）</span></div>
              <ul class="sp-album-grid">
                <?php for ($ai = 0; $ai < 12; $ai++): $aph = $samplePhotos[$ai % count($samplePhotos)]; ?>
                <li><div class="sp-album-item" onclick="spAlert('查看大图')"><img src="<?php echo $aph['src'];?>" alt=""><span class="cap"><?php echo htmlspecialchars($aph['cap']);?></span></div></li>
                <?php endfor; ?>
              </ul>
            </section>

            <!-- ===== 留言板面板 ===== -->
            <section class="sp-tab" id="spTabBoard" style="display:none">
              <div class="sp-tab-head"><h3>留言板</h3><span class="sp-tab-sub"><?php echo $isSelf ? '把空间分享给朋友，让 TA 们来留言吧～' : '欢迎给 ' . htmlspecialchars($displayName) . ' 留言';?></span></div>
              <div class="sp-board-input">
                <textarea id="spBoardInput" placeholder="写下你的留言..." maxlength="500"></textarea>
                <button class="btn-post" onclick="spBoardPost()">留言</button>
              </div>
              <ul class="sp-board-list" id="spBoardList"></ul>
            </section>

            <!-- ===== 个人档案面板 ===== -->
            <section class="sp-tab" id="spTabProfile" style="display:none">
              <div class="sp-tab-head"><h3>个人档案</h3></div>
              <div class="sp-profile-card">
                <div class="sp-profile-top">
                  <?php if ($avatarUrl):?><img class="sp-profile-av" src="<?php echo htmlspecialchars($avatarUrl);?>" alt=""><?php else:?><div class="sp-profile-av"><?php echo htmlspecialchars($ch);?></div><?php endif;?>
                  <div class="sp-profile-id">
                    <div class="sp-profile-name"><?php echo htmlspecialchars($displayName);?></div>
                    <div class="sp-profile-sub">@<?php echo htmlspecialchars($u['username']);?> · Lv.<?php echo $level;?></div>
                    <span class="sp-profile-status <?php echo (int)$u['dnd'] ? 'dnd' : 'on';?>"><?php echo (int)$u['dnd'] ? '忙碌' : '在线';?></span>
                  </div>
                </div>
                <ul class="sp-profile-list">
                  <li><span class="k">个性签名</span><span class="v"><?php echo $sig !== '' ? htmlspecialchars($sig) : '这个人很懒，什么都没写';?></span></li>
                  <li><span class="k">性别</span><span class="v"><?php echo htmlspecialchars($genderLabel);?></span></li>
                  <?php if ($birthday):?><li><span class="k">生日</span><span class="v"><?php echo htmlspecialchars($birthday);?></span></li><?php endif;?>
                  <li><span class="k">等级</span><span class="v">Lv.<?php echo $level;?>（<?php echo $exp;?> 经验）</span></li>
                  <li><span class="k">获赞</span><span class="v"><?php echo $likes;?></span></li>
                  <li><span class="k">UID</span><span class="v"><?php echo $uid;?></span></li>
                  <li><span class="k">注册时间</span><span class="v"><?php echo date('Y-m-d', strtotime((string)($u['created_at'] ?? '')));?></span></li>
                </ul>
              </div>
            </section>
          </div>

          <!-- ===== 右栏 ===== -->
          <div class="col-main-sidebar">

            <!-- 精选相片（来自个人空间/朋友圈） -->
            <div class="icenter-right-mod icenter-right-photo">
              <div class="hd">精选相片<span class="more" onclick="spGoTab('album')">更多相册 <?php echo sp_ic('right');?></span></div>
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
              <div class="hd">谁看过我<span class="more"><?php echo sp_ic('right');?></span></div>
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
                  <li><span class="k">UID</span><span class="v"><?php echo $uid;?></span></li>
                </ul>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="layout-copyright">© ChatApp 个人空间</div>
    </div>
  </div>
</div>

<!-- 返回顶部 -->
<div class="fix-layout">
  <div class="to-top" id="spToTop" title="返回顶部"><?php echo sp_ic('top');?></div>
</div>

<!-- 可见范围下拉 -->
<div class="sp-vis-menu" id="spVisMenu" style="display:none">
  <div class="sp-vis-item" data-v="0"><span class="v-ic"><?php echo sp_ic('globe');?></span>所有人可见</div>
  <div class="sp-vis-item" data-v="1"><span class="v-ic"><?php echo sp_ic('people');?></span>好友可见</div>
  <div class="sp-vis-item" data-v="2"><span class="v-ic"><?php echo sp_ic('me');?></span>部分好友可见</div>
  <div class="sp-vis-item" data-v="3"><span class="v-ic"><?php echo sp_ic('me');?></span>部分好友不可见</div>
  <div class="sp-vis-item" data-v="4"><span class="v-ic"><?php echo sp_ic('lock');?></span>仅自己可见</div>
</div>

<!-- 图片大图查看 -->
<div class="sp-lightbox" id="spLightbox" style="display:none" onclick="if(event.target===this)spLbClose()">
  <div id="spLbArrows" style="display:none">
    <span class="sp-lb-prev" onclick="spLbNav(-1)"><?php echo sp_ic('right');?></span>
    <span class="sp-lb-next" onclick="spLbNav(1)"><?php echo sp_ic('right');?></span>
  </div>
  <img id="spLbImg" src="" alt="">
  <span class="sp-lb-close" onclick="spLbClose()"><?php echo sp_ic('close');?></span>
</div>

<script>window.EARS_ON = <?php echo (int)($currentUser['space_ears'] ?? 0) ? 'true' : 'false'; ?>;</script>
<script src="../scripts/ears.js?v=<?php echo time();?>"></script>
<script>
var SP_USER = <?php echo json_encode(['self' => $isSelf, 'username' => $u['username'], 'display' => $displayName]);?>;

/* ===== Tab 切换 ===== */
function spGoTab(name) {
  // 顶部 tab 高亮
  [].forEach.call(document.querySelectorAll('#coverTabs li'), function (li) {
    li.classList.toggle('cur', li.getAttribute('data-tab') === name);
  });
  // 隐藏所有 Tab 面板
  ['spTabBlog', 'spTabAlbum', 'spTabBoard', 'spTabProfile'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
  });
  var poster = document.getElementById('spPosterBox');
  var feedArea = document.getElementById('spFeedArea');
  // 左侧菜单（动态/个人资料）仅主页显示
  var leftMenu = document.getElementById('leftMenu');
  if (leftMenu) leftMenu.style.display = (name === 'home') ? '' : 'none';
  // 主页/说说显示动态区；主页(自己)显示发表框
  if (poster) poster.style.display = (name === 'home' && SP_USER.self) ? 'block' : 'none';
  if (feedArea) feedArea.style.display = (name === 'home' || name === 'say') ? 'block' : 'none';
  // 显示目标面板（日志/相册/留言板/个人档案）——.sp-tab 有 CSS display:none，必须显式 block
  var panelMap = { blog: 'spTabBlog', album: 'spTabAlbum', board: 'spTabBoard', profile: 'spTabProfile' };
  if (panelMap[name]) {
    var panel = document.getElementById(panelMap[name]);
    if (panel) panel.style.display = 'block';
  }
  // 说说/主页 联动过滤
  if (name === 'say') setFeedFilter('say');
  if (name === 'home') setFeedFilter('all');
  // 进入日志/留言板时按需加载
  if (name === 'blog') spLoadBlogList();
  if (name === 'board') spLoadBoard();
}
document.getElementById('coverTabs').addEventListener('click', function (e) {
  var li = e.target.closest('li');
  if (!li) return;
  spGoTab(li.getAttribute('data-tab') || 'home');
});

/* ===== 动态流过滤（全部/相册/说说） ===== */
function setFeedFilter(f) {
  // 高亮 feed-control
  [].forEach.call(document.querySelectorAll('.feed-control-tab a'), function (a) {
    a.classList.toggle('on', a.getAttribute('data-f') === f);
  });
  // 高亮左侧菜单
  [].forEach.call(document.querySelectorAll('#feedTypes li'), function (li) {
    li.classList.toggle('current', li.getAttribute('data-f') === f);
  });
  // 过滤列表
  var items = document.querySelectorAll('#feedList .f-single:not(#feedFilterEmpty)');
  [].forEach.call(items, function (li) {
    var hasImg = !!li.querySelector('.f-ct-txtimg');
    var show = (f === 'all') || (f === 'say' && !hasImg) || (f === 'photo' && hasImg);
    li.style.display = show ? '' : 'none';
  });
  var empty = document.getElementById('feedFilterEmpty');
  var anyVisible = [].some.call(items, function (li) { return li.style.display !== 'none'; });
  if (empty) empty.style.display = anyVisible ? 'none' : '';
}
document.querySelector('.feed-control-tab').addEventListener('click', function (e) {
  var a = e.target.closest('a');
  if (!a) return;
  setFeedFilter(a.getAttribute('data-f') || 'all');
});
function spNavFeed(f) {
  spGoTab('home');
  setFeedFilter(f);
}

/* ===== 动态中心：我的动态 / 好友动态 / 特别关心（流式加载） ===== */
function spStream(filter) {
  spGoTab('home');
  // 高亮左侧 feedTypes：mine/friends → 对应 data-f，special → care
  var map = { mine: 'mine', friends: 'friends', special: 'care' };
  [].forEach.call(document.querySelectorAll('#feedTypes li'), function (li) {
    li.classList.toggle('current', li.getAttribute('data-f') === (map[filter] || 'mine'));
  });
  fetch('../../api/space.php?action=stream&filter=' + encodeURIComponent(filter), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.success) { renderStreamFeeds(d.feeds || []); } })
    .catch(function () {});
}

/* ===== 特别关心（空间页按钮，仅好友空间） ===== */
function spToggleSpecial() {
  var uname = <?php echo json_encode($u['username']);?>;
  var f = new URLSearchParams();
  f.append('action', 'toggle_special');
  f.append('username', uname);
  fetch('../../api/contacts.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, credentials: 'same-origin', body: f.toString() })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.success) { renderSpecialBtn(d.special); } else { alert('操作失败'); } });
}
function renderSpecialBtn(sp) {
  var b = document.getElementById('spSpecialBtn');
  if (!b) return;
  var a = b.querySelector('a');
  if (a) a.textContent = sp ? '已特别关心' : '特别关心';
  b.classList.toggle('btn-primary', !!sp);
}
function initSpecialBtn() {
  if (!SP_SPACE || SP_SPACE.self || !SP_SPACE.friend) return;
  fetch('../../api/space.php?action=special_state&uid=' + SP_SPACE.uid, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.success) renderSpecialBtn(d.special); });
}
function renderStreamFeeds(feeds) {
  var area = document.getElementById('feedList');
  if (!area) return;
  var html = '';
  if (!feeds.length) {
    html = '<li class="f-single"><div class="f-single-content"><div class="f-ct-text" style="color:#777">暂无动态</div></div></li>';
  } else {
    feeds.forEach(function (f) {
      var ch = (f.author || '?').charAt(0);
      var isMine = (window.SP_SPACE && SP_SPACE.meUid === f.user_id);
      html += '<li class="f-single" data-id="' + f.id + '">'
        + '<div class="f-single-head">'
        + (f.avatar ? '<img class="user-avatar" src="' + f.avatar + '" alt="">' : '<span class="user-avatar av-empty">' + esc(ch) + '</span>')
        + '<div class="user-info">'
        + '<div class="f-nick">' + esc(f.author) + (f.special ? ' <span class="sp-special-tag">\u2665 特别关心</span>' : '') + '</div>'
        + '<div class="info-detail">' + esc(f.time) + '</div>'
        + '</div></div>'
        + '<div class="f-single-content"><div class="f-ct-text">' + esc(f.content).replace(/\n/g, '<br>') + '</div>'
        + (f.images && f.images.length ? '<div class="f-ct-txtimg"><div class="img-box' + (f.images.length === 1 ? ' one' : '') + '" data-lb="' + f.id + '">' + f.images.map(function (im, i) { return '<a class="img-item" onclick="spOpenLightbox(' + f.id + ',' + i + ')"><img src="' + im + '" alt=""></a>'; }).join('') + '</div></div>' : '')
        + '</div>'
        + '<div class="f-single-foot"><ul class="op-list">'
        + '<li class="op-like' + (f.liked ? ' liked' : '') + '" data-id="' + f.id + '">' + SP_ICONS.like + ' 赞' + (f.likes ? ' (' + f.likes + ')' : '') + '</li>'
        + '<li class="op-comment" data-id="' + f.id + '">' + SP_ICONS.comment + ' 评论</li>'
        + (isMine ? '<li class="op-del" data-id="' + f.id + '">' + SP_ICONS.top + ' 删除</li>' : '')
        + '</ul></div>'
        + '<div class="f-comments" data-feed="' + f.id + '" style="display:none"><div class="f-comments-list"></div><div class="f-cmt-input"><input class="f-cmt-text" placeholder="评论一下..." maxlength="500"><button class="btn-post btn-sm" onclick="spCmtSend(this)">发表</button></div></div>'
        + '</li>';
    });
  }
  area.innerHTML = html + '<li class="f-single" id="feedFilterEmpty" style="display:none"><div class="f-single-content"><div class="f-ct-text" style="color:#777">该分类下暂无动态</div></div></li>';
}
// 返回顶部
var toTop = document.getElementById('spToTop');
toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
var SP_SPACE = <?php echo json_encode(['self' => $isSelf, 'uid' => $uid, 'meUid' => $meUid, 'friend' => $isFriendView ? 1 : 0]);?>;
var SP_POST_VIS = 0, SP_POST_FRIENDS = [], SP_FRIENDS = [], SP_POST_IMAGES = [];
var SP_FM_MODE = 'vis', SP_MENTIONS = [];
var SP_ICONS = { say: '<?php echo sp_ic('say');?>', comment: '<?php echo sp_ic('comment');?>', like: '<?php echo sp_ic('like');?>', top: '<?php echo sp_ic('top');?>' };
var SP_X_IC = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';

function spAlert(what) { alert('「' + what + '」功能即将上线。'); }
function spGoto(where) { alert('「' + where + '」模块即将上线（示例 UI）。'); }

/* ===== 艾特通知（与我相关） ===== */
function spLoadMentions() {
  spGoTab('home');
  // 高亮左侧「与我相关」+ 顶部 tab
  [].forEach.call(document.querySelectorAll('#feedTypes li'), function (li) {
    li.classList.toggle('current', li.getAttribute('data-f') === 'me');
  });
  [].forEach.call(document.querySelectorAll('.feed-control-tab a'), function (a) {
    a.classList.toggle('on', a.getAttribute('data-f') === 'me');
  });
  fetch('../../api/space.php?action=mentions', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.success) { renderMentions(d.mentions || []); markMentionsRead(); }
    })
    .catch(function () {});
}
function renderMentions(list) {
  var area = document.getElementById('feedList');
  if (!area) return;
  var html = '';
  if (!list.length) {
    html = '<li class="f-single"><div class="f-single-content"><div class="f-ct-text" style="color:#777">暂无相关通知</div></div></li>';
  } else {
    list.forEach(function (m) {
      var ch = (m.by_display || '?').charAt(0);
      var typeLabel = { mention: '在说说中提到了你', like: '赞了你的说说', comment: '评论了你的说说' }[m.type] || '提到了你';
      var quoteText = (m.type === 'comment') ? (m.comment_content || m.feed_content || '') : (m.feed_content || '');
      var delTxt = (m.type === 'comment') ? '（原说说已删除）' : '（原说说已删除）';
      html += '<li class="f-single sp-mention-item" data-mid="' + m.id + '" data-feed="' + m.feed_id + '">'
        + '<div class="f-single-head">'
        + (m.by_avatar ? '<img class="user-avatar" src="' + m.by_avatar + '" alt="">' : '<span class="sp-mention-avph">' + esc(ch) + '</span>')
        + '<div class="user-info">'
        + '<div class="f-nick">' + esc(m.by_display) + '</div>'
        + '<div class="info-detail">' + esc(m.time) + ' · ' + esc(typeLabel) + '</div>'
        + '</div></div>'
        + '<div class="f-single-content"><div class="f-ct-text">'
        + (quoteText ? '<span class="sp-mention-quote">' + esc(quoteText) + '</span>' : (m.feed_enabled ? '' : '<span class="sp-mention-quote">' + delTxt + '</span>'))
        + '</div></div>'
        + (m.feed_enabled ? '<div class="f-single-foot"><a class="sp-mention-go" data-feed="' + m.feed_id + '" data-user="' + esc(m.by_username) + '">查看原说说 ›</a></div>' : '')
        + '</li>';
    });
  }
  area.innerHTML = html + '<li class="f-single"><div class="f-single-content"><a class="sp-mention-back" onclick="spNavFeed(\'all\')">‹ 返回动态</a></div></li>';
  area.querySelectorAll('.sp-mention-go').forEach(function (a) {
    a.addEventListener('click', function () {
      var fid = +(a.getAttribute('data-feed'));
      var user = a.getAttribute('data-user') || '';
      // 说说不在此空间时，跳到发者空间并定位
      if (user && user !== (window.SP_USER ? SP_USER.username : '')) {
        location.href = 'space.php?user=' + encodeURIComponent(user) + '#feed-' + fid;
        return;
      }
      spNavFeed('all');
      setTimeout(function () {
        var el = document.querySelector('#feedList .f-single[data-id="' + fid + '"]');
        if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); el.classList.add('sp-feed-flash'); setTimeout(function () { el.classList.remove('sp-feed-flash'); }, 2000); }
      }, 120);
    });
  });
}
function markMentionsRead() {
  var f = new URLSearchParams(); f.append('action', 'mention_read');
  fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() }).catch(function () {});
  var b = document.getElementById('spMeBadge'); if (b) b.style.display = 'none';
}
function spLoadMentionCount() {
  fetch('../../api/space.php?action=mention_count', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var b = document.getElementById('spMeBadge');
      if (!b) return;
      var c = d && d.success ? (+d.count || 0) : 0;
      if (c > 0) { b.textContent = c > 99 ? '99+' : c; b.style.display = 'inline-block'; }
      else b.style.display = 'none';
    })
    .catch(function () {});
}
spLoadMentionCount();
initSpecialBtn();
// 从「查看原说说」带 #feed-<id> 进入：加载后滚动定位并高亮
(function () {
  var h = location.hash;
  if (h && h.indexOf('#feed-') === 0) {
    var fid = +(h.slice(6));
    setTimeout(function () {
      var el = document.querySelector('#feedList .f-single[data-id="' + fid + '"]');
      if (el) { el.scrollIntoView({ block: 'center' }); el.classList.add('sp-feed-flash'); setTimeout(function () { el.classList.remove('sp-feed-flash'); }, 2000); }
    }, 300);
  }
})();

/* ===== 可见范围下拉 ===== */
function spVisToggle(e) {
  e && e.stopPropagation();
  var m = document.getElementById('spVisMenu');
  if (!m) return;
  if (m.style.display === 'block') { m.style.display = 'none'; return; }
  m.style.display = 'block';
  var r = document.getElementById('spVisBtn').getBoundingClientRect();
  m.style.left = Math.min(window.innerWidth - 170, r.left) + 'px';
  m.style.top = (r.bottom + 6) + 'px';
  [].forEach.call(m.children, function (it) { it.classList.toggle('cur', +(it.getAttribute('data-v')) === SP_POST_VIS); });
}
document.addEventListener('click', function (e) {
  var m = document.getElementById('spVisMenu'); if (m) m.style.display = 'none';
  var fm = document.getElementById('spFmMask');
  if (fm && fm.style.display === 'flex' && !fm.contains(e.target)) fm.style.display = 'none';
});
document.getElementById('spVisMenu').addEventListener('click', function (e) {
  var it = e.target.closest('.sp-vis-item');
  if (!it) return;
  e.stopPropagation(); // 阻止冒泡到 document 立即关掉刚打开的好友面板
  SP_POST_VIS = +it.getAttribute('data-v');
  this.style.display = 'none';
  SP_FM_MODE = 'vis';
  spFmClose(); // 先收起好友面板
  if (SP_POST_VIS === 2 || SP_POST_VIS === 3) { spFmOpen(); return; }
  SP_POST_FRIENDS = [];
  var lbl = document.getElementById('spVisLabel');
  if (lbl) lbl.textContent = it.textContent.replace(/\s+/g, ' ').trim();
});

/* ===== 好友选择弹窗 ===== */
var SP_FM_GROUP = 'all', SP_FM_SELECTED = [];
function spFmOpen() {
  var mask = document.getElementById('spFmMask');
  if (!mask) return;
  // 标题/按钮随模式变化：vis=部分可见选人，mention=艾特
  var fT = document.getElementById('spFmTitle');
  var fOk = document.querySelector('#spFmMask .sp-fm-ok');
  if (SP_FM_MODE === 'mention') {
    if (fT) fT.textContent = '选择要 @ 的好友';
    if (fOk) fOk.textContent = '确定艾特';
    SP_FM_SELECTED = SP_MENTIONS.map(function (m) { return m.uid; });
  } else {
    if (fT) fT.textContent = '选择好友';
    if (fOk) fOk.textContent = '确定';
    SP_FM_SELECTED = SP_POST_FRIENDS.slice();
  }
  // 面板贴发表框底部（absolute 定位随页面滚动），无需手动设置坐标
  mask.style.display = 'flex';
  mask.classList.remove('sp-open');
  void mask.offsetHeight; // 强制 reflow，确保动画每次重新触发
  mask.classList.add('sp-open');
  if (SP_FRIENDS.length) { renderFmList(); return; }
  fetch('../../api/contacts.php?action=list', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.success) { SP_FRIENDS = d.contacts || []; renderFmList(); } })
    .catch(function () {});
}
function spFmGroup(g) {
  SP_FM_GROUP = g;
  [].forEach.call(document.querySelectorAll('.sp-fm-group'), function (el) { el.classList.toggle('on', el.getAttribute('data-g') === g); });
  renderFmList();
}
function renderFmList() {
  var q = (document.getElementById('spFmSearch').value || '').trim().toLowerCase();
  var list = document.getElementById('spFmList');
  var html = '';
  if (SP_FM_GROUP === 'auth') {
    list.innerHTML = '<div class="sp-fm-empty">暂无认证空间</div>';
    renderFmPicked();
    return;
  }
  SP_FRIENDS.forEach(function (f) {
    var name = (f.display_name || f.username || '');
    if (q && name.toLowerCase().indexOf(q) < 0 && (f.username || '').toLowerCase().indexOf(q) < 0) return;
    var uid = +(f.user_id || 0);
    if (!uid) return;
    var on = SP_FM_SELECTED.indexOf(uid) >= 0;
    html += '<div class="sp-fm-item' + (on ? ' on' : '') + '" data-uid="' + uid + '" onclick="spFmToggle(' + uid + ')">'
      + '<img src="' + (f.avatar || '') + '" alt="" onerror="this.style.visibility=\'hidden\'">'
      + '<span class="sp-fm-name">' + spEsc(name) + '</span>'
      + (on ? '<span class="sp-fm-ck">' + SP_X_IC + '</span>' : '')
      + '</div>';
  });
  list.innerHTML = html || '<div class="sp-fm-empty">无匹配好友</div>';
  renderFmPicked();
}
function renderFmPicked() {
  var box = document.getElementById('spFmPicked');
  var c = document.getElementById('spFmCount');
  if (!box) return;
  if (!SP_FM_SELECTED.length) { box.style.display = 'none'; box.innerHTML = ''; }
  else {
    box.style.display = 'flex';
    var html = '';
    SP_FRIENDS.forEach(function (f) {
      var uid = +(f.user_id || 0);
      if (SP_FM_SELECTED.indexOf(uid) >= 0) {
        var name = (f.display_name || f.username || '');
        html += '<span class="sp-fm-pick" data-uid="' + uid + '" title="移除" onclick="spFmToggle(' + uid + ')">'
          + '<img src="' + (f.avatar || '') + '" alt="" onerror="this.style.visibility=\'hidden\'">'
          + '<span>' + spEsc(name) + '</span>' + SP_X_IC + '</span>';
      }
    });
    box.innerHTML = html;
  }
  if (c) c.textContent = SP_FM_SELECTED.length;
}
function spFmToggle(uid) {
  uid = +uid;
  var idx = SP_FM_SELECTED.indexOf(uid);
  if (idx >= 0) SP_FM_SELECTED.splice(idx, 1);
  else {
    if (SP_FM_SELECTED.length >= 30) { alert('最多添加 30 位好友'); return; }
    SP_FM_SELECTED.push(uid);
  }
  renderFmList();
}
function spFmConfirm() {
  if (SP_FM_MODE === 'mention') { spMentionConfirm(SP_FM_SELECTED); return; }
  SP_POST_FRIENDS = SP_FM_SELECTED.slice();
  document.getElementById('spFmMask').style.display = 'none';
  var lbl = document.getElementById('spVisLabel');
  if (lbl) lbl.textContent = (SP_POST_VIS === 3 ? '部分好友不可见' : '部分好友可见') + (SP_POST_FRIENDS.length ? '（' + SP_POST_FRIENDS.length + '）' : '');
}

/* ===== 艾特（@好友） ===== */
function spMentionOpen() {
  SP_FM_MODE = 'mention';
  spFmOpen();
}
function spEsc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
}
function spMentionConfirm(ids) {
  var mask = document.getElementById('spFmMask');
  if (mask) mask.style.display = 'none';
  if (!ids.length) return;
  var names = [];
  ids.forEach(function (uid) {
    var f = null;
    SP_FRIENDS.forEach(function (x) { if (+(x.user_id) === uid) f = x; });
    var n = (f && (f.display_name || f.username)) || ('用户' + uid);
    names.push(n);
  });
  if (!names.length) return;
  // 把 @名字 追加进说说内容（纯 string）
  var p = document.getElementById('spPoster');
  if (p) {
    var cur = (p.textContent || '').replace(/\u00a0/g, ' ');
    var add = names.map(function (n) { return '@' + n; }).join(' ');
    p.textContent = (cur ? cur + ' ' : '') + add;
  }
  // 合并进艾特列表（按 uid 去重）
  var seen = {};
  SP_MENTIONS.forEach(function (m) { seen[m.uid] = 1; });
  names.forEach(function (n, i) {
    var uid = ids[i];
    if (!seen[uid]) { seen[uid] = 1; SP_MENTIONS.push({ uid: uid, name: n }); }
  });
  renderMentionBar();
}
function renderMentionBar() {
  var bar = document.getElementById('spMentionBar');
  if (!bar) return;
  if (!SP_MENTIONS.length) { bar.style.display = 'none'; bar.innerHTML = ''; return; }
  bar.style.display = 'flex';
  bar.innerHTML = SP_MENTIONS.map(function (m, i) {
    return '<span class="sp-mention-tag">(' + spEsc(m.name) + ')<span class="sp-mention-x" title="移除" onclick="spMentionRemove(' + i + ')">' + SP_X_IC + '</span></span>';
  }).join('');
}
function spMentionRemove(i) {
  var m = SP_MENTIONS[i];
  if (!m) return;
  SP_MENTIONS.splice(i, 1);
  // 同步从内容里删掉对应的 @名字
  var p = document.getElementById('spPoster');
  if (p) {
    var cur = (p.textContent || '').replace(/\u00a0/g, ' ');
    cur = cur.split('@' + m.name).join('').replace(/\s{2,}/g, ' ').trim();
    p.textContent = cur;
  }
  renderMentionBar();
}
function spFmClose() {
  var m = document.getElementById('spFmMask');
  if (m) { m.style.display = 'none'; m.classList.remove('sp-open'); }
}
(function () {
  var s = document.getElementById('spFmSearch'); if (s) s.addEventListener('input', renderFmList);
})();

/* ===== 发表 ===== */
function spPost() {
  var p = document.getElementById('spPoster');
  if (!p) return;
  var t = (p.textContent || '').replace(/\u00a0/g, ' ').trim();
  if (!t && !SP_POST_IMAGES.length) { p.focus(); return; }
  var btn = document.getElementById('spPostBtn');
  if (btn) { btn.disabled = true; btn.textContent = '发表中…'; }
  var f = new URLSearchParams();
  f.append('action', 'post');
  f.append('content', t);
  if (SP_POST_IMAGES.length) f.append('images', JSON.stringify(SP_POST_IMAGES));
  f.append('visibility', SP_POST_VIS);
  if (SP_POST_VIS === 2 || SP_POST_VIS === 3) f.append('visible_to', JSON.stringify(SP_POST_FRIENDS));
  if (SP_MENTIONS.length) f.append('mentions', JSON.stringify(SP_MENTIONS.map(function (m) { return m.uid; })));
  fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.success) { location.reload(); }
      else { if (btn) { btn.disabled = false; btn.textContent = '发表'; } alert('发表失败'); }
    })
    .catch(function () { if (btn) { btn.disabled = false; btn.textContent = '发表'; } alert('网络错误'); });
}

/* ===== 点赞 / 删除（事件委托） ===== */
(function () {
  var feed = document.getElementById('feedList');
  if (!feed) return;
  feed.addEventListener('click', function (e) {
    var cmt = e.target.closest('.op-comment');
    if (cmt) { spCmtToggle(cmt.closest('.f-single')); return; }
    var like = e.target.closest('.op-like');
    if (like) {
      var id = +(like.getAttribute('data-id'));
      var f = new URLSearchParams(); f.append('action', 'toggle_like'); f.append('id', id);
      fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.success) {
            like.classList.toggle('liked', !!d.liked);
            var ic = like.querySelector('.op-ic');
            like.innerHTML = (ic ? ic.outerHTML : '') + ' 赞' + (d.likes ? ' (' + d.likes + ')' : '');
          }
        });
      return;
    }
    var del = e.target.closest('.op-del');
    if (del) {
      var id2 = +(del.getAttribute('data-id'));
      if (window.confirm('删除这条说说？')) {
        var f2 = new URLSearchParams(); f2.append('action', 'delete'); f2.append('id', id2);
        fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f2.toString() })
          .then(function () { location.reload(); });
      }
      return;
    }
  });
})();

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

/* ===== 朋友圈图片上传 ===== */
function spPickImages() { var i = document.getElementById('spImgInput'); if (i) i.click(); }
function renderPostImgs() {
  var box = document.getElementById('spPostImgs');
  if (!box) return;
  if (!SP_POST_IMAGES.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
  box.style.display = 'flex';
  var html = '';
  SP_POST_IMAGES.forEach(function (u, i) {
    html += '<div class="sp-post-img"><img src="' + u + '" alt=""><span class="sp-post-img-x" title="移除" onclick="spDropImg(' + i + ')">' + SP_X_IC + '</span></div>';
  });
  box.innerHTML = html;
}
function spDropImg(i) { SP_POST_IMAGES.splice(i, 1); renderPostImgs(); }
(function () {
  var inp = document.getElementById('spImgInput');
  if (!inp) return;
  inp.addEventListener('change', function () {
    var files = Array.prototype.slice.call(inp.files || []);
    if (!files.length) return;
    if (SP_POST_IMAGES.length + files.length > 9) { alert('最多上传 9 张图片'); inp.value = ''; return; }
    files.forEach(function (file) {
      if (file.type.indexOf('image/') !== 0) return;
      if (file.size > 10 * 1024 * 1024) { alert('单张图片最大 10MB：' + file.name); return; }
      var fd = new FormData();
      fd.append('images', file);
      fetch('../../api/space.php?action=upload_image', { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.success && d.urls && d.urls.length) { SP_POST_IMAGES = SP_POST_IMAGES.concat(d.urls); renderPostImgs(); }
          else alert('图片上传失败');
        })
        .catch(function () { alert('图片上传失败'); });
    });
    inp.value = '';
  });
})();

/* ===== 朋友圈评论 ===== */
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}
function spCmtToggle(li) {
  var box = li.querySelector('.f-comments');
  if (!box) return;
  var open = box.style.display !== 'none';
  box.style.display = open ? 'none' : 'block';
  if (!open && !box.getAttribute('data-loaded')) { box.setAttribute('data-loaded', '1'); spCmtLoad(box); }
}
function spCmtLoad(box) {
  var feedId = +(box.getAttribute('data-feed'));
  fetch('../../api/space.php?action=list_comments&feed_id=' + feedId, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.success) renderComments(box, d.comments, d.i_am_owner); })
    .catch(function () {});
}
function spCmtReload(box) { box.setAttribute('data-loaded', '1'); spCmtLoad(box); }
function renderComments(box, comments, iAmOwner) {
  var feedId = +(box.getAttribute('data-feed'));
  var list = box.querySelector('.f-comments-list');
  if (!list) return;
  if (!comments.length) { list.innerHTML = '<div class="f-cmt-empty">还没有评论，来说两句～</div>'; return; }
  var tops = comments.filter(function (c) { return !c.parent_id; });
  var html = '';
  tops.forEach(function (c) {
    html += cmtTopHtml(c, iAmOwner);
    var reps = comments.filter(function (r) { return r.parent_id === c.id; });
    if (reps.length) {
      html += '<div class="f-cmt-replies">';
      reps.forEach(function (r) { html += cmtReplyHtml(r, iAmOwner); });
      html += '</div>';
    }
  });
  list.innerHTML = html;
  var btn = document.querySelector('.op-comment[data-id="' + feedId + '"]');
  if (btn) {
    var ic = btn.querySelector('.op-ic');
    btn.innerHTML = (ic ? ic.outerHTML : '') + ' 评论' + (comments.length ? ' <span class="cmt-c">(' + comments.length + ')</span>' : '');
  }
}
function cmtTopHtml(c, iAmOwner) {
  var del = (c.mine || iAmOwner) ? '<a class="f-cmt-del" data-id="' + c.id + '">删除</a>' : '';
  return '<div class="f-cmt" data-id="' + c.id + '">'
    + (c.card.avatar ? '<img class="f-cmt-av" src="' + c.card.avatar + '" alt="">' : '<div class="f-cmt-av av-empty">' + esc(c.card.name.charAt(0)) + '</div>')
    + '<div class="f-cmt-bd">'
    + '<div class="f-cmt-txt"><b>' + esc(c.card.name) + '</b>：' + esc(c.content).replace(/\n/g, '<br>') + '</div>'
    + '<div class="f-cmt-meta">' + c.time + ' · <a class="f-cmt-reply" data-id="' + c.id + '" data-name="' + esc(c.card.name) + '">回复</a>' + del + '</div>'
    + '</div></div>';
}
function cmtReplyHtml(r, iAmOwner) {
  var del = (r.mine || iAmOwner) ? '<a class="f-cmt-del" data-id="' + r.id + '">删除</a>' : '';
  return '<div class="f-cmt-reply-item" data-id="' + r.id + '"><b>' + esc(r.card.name) + '</b>：' + esc(r.content).replace(/\n/g, '<br>') + ' <span class="f-cmt-meta">' + r.time + del + '</span></div>';
}
function spCmtSend(btn) {
  var box = btn.closest('.f-comments');
  var input = box.querySelector('.f-cmt-text');
  var txt = (input.value || '').trim();
  if (!txt) { input.focus(); return; }
  var feedId = +(box.getAttribute('data-feed'));
  var replyId = +(input.getAttribute('data-reply') || 0);
  var f = new URLSearchParams();
  f.append('action', 'add_comment');
  f.append('feed_id', feedId);
  f.append('content', txt);
  if (replyId) f.append('parent_id', replyId);
  fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.success) { spCmtReload(box); input.value = ''; input.removeAttribute('data-reply'); input.placeholder = '评论一下...'; }
      else alert('评论失败');
    })
    .catch(function () { alert('网络错误'); });
}
(function () {
  var feed = document.getElementById('feedList');
  if (!feed) return;
  feed.addEventListener('click', function (e) {
    var r = e.target.closest('.f-cmt-reply');
    if (r) {
      var box = e.target.closest('.f-comments');
      var input = box ? box.querySelector('.f-cmt-text') : null;
      if (input) { input.setAttribute('data-reply', r.getAttribute('data-id')); input.placeholder = '回复 ' + r.getAttribute('data-name') + '：'; input.focus(); }
      return;
    }
    var d = e.target.closest('.f-cmt-del');
    if (d) {
      if (window.confirm('删除这条评论？')) {
        var f = new URLSearchParams(); f.append('action', 'delete_comment'); f.append('id', d.getAttribute('data-id'));
        fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
          .then(function (r) { return r.json(); })
          .then(function (res) { if (res && res.success) { var bx = e.target.closest('.f-comments'); if (bx) spCmtReload(bx); } });
      }
      return;
    }
  });
})();

/* ===== 留言板 ===== */
var SP_BOARD_LOADED = false;
function spLoadBoard() {
  var list = document.getElementById('spBoardList');
  if (!list) return;
  if (SP_BOARD_LOADED) return;
  SP_BOARD_LOADED = true;
  fetch('../../api/space.php?action=list_messages&to_uid=' + SP_SPACE.uid, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.success) renderBoard(d.messages, d.i_am_owner); })
    .catch(function () {});
}
function renderBoard(msgs, iAmOwner) {
  var list = document.getElementById('spBoardList');
  if (!list) return;
  if (!msgs.length) { list.innerHTML = '<li class="sp-board-empty"><div class="sp-empty-ic">' + SP_ICONS.comment + '</div>还没有留言～</li>'; return; }
  var html = '';
  msgs.forEach(function (m) {
    var del = (m.mine || iAmOwner) ? '<a class="sp-board-del" data-id="' + m.id + '">删除</a>' : '';
    html += '<li class="sp-board-item" data-id="' + m.id + '">'
      + (m.card.avatar ? '<img class="sp-board-av" src="' + m.card.avatar + '" alt="">' : '<div class="sp-board-av av-empty">' + esc(m.card.name.charAt(0)) + '</div>')
      + '<div class="sp-board-bd">'
      + '<div class="sp-board-top"><b>' + esc(m.card.name) + '</b><span class="sp-board-time">' + m.time + '</span></div>'
      + '<div class="sp-board-ct">' + esc(m.content).replace(/\n/g, '<br>') + '</div>'
      + (del ? '<div class="sp-board-op">' + del + '</div>' : '')
      + '</div></li>';
  });
  list.innerHTML = html;
}
function spBoardPost() {
  var ta = document.getElementById('spBoardInput');
  var txt = (ta.value || '').trim();
  if (!txt) { ta.focus(); return; }
  var f = new URLSearchParams();
  f.append('action', 'add_message'); f.append('to_uid', SP_SPACE.uid); f.append('content', txt);
  fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.success) { ta.value = ''; SP_BOARD_LOADED = false; spLoadBoard(); }
      else alert('留言失败');
    })
    .catch(function () { alert('网络错误'); });
}
(function () {
  var list = document.getElementById('spBoardList');
  if (!list) return;
  list.addEventListener('click', function (e) {
    var d = e.target.closest('.sp-board-del');
    if (!d) return;
    if (window.confirm('删除这条留言？')) {
      var f = new URLSearchParams(); f.append('action', 'delete_message'); f.append('id', d.getAttribute('data-id'));
      fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res && res.success) { SP_BOARD_LOADED = false; spLoadBoard(); } });
    }
  });
})();

/* ===== 日志 ===== */
function spLoadBlogList() {
  var list = document.getElementById('spBlogList');
  if (!list) return;
  fetch('../../api/space.php?action=list_blogs&uid=' + SP_SPACE.uid, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.success) renderBlogList(d.blogs); })
    .catch(function () {});
}
function renderBlogList(blogs) {
  var list = document.getElementById('spBlogList');
  if (!list) return;
  if (!blogs.length) {
    list.innerHTML = '<div class="sp-empty"><div class="sp-empty-ic">' + SP_ICONS.say + '</div><p>' + (SP_SPACE.self ? '还没有日志，写第一篇吧～' : '期待 TA 的第一篇日志～') + '</p></div>';
    return;
  }
  var html = '';
  var visLbl = ['所有人可见', '好友可见', '部分好友可见', '部分好友不可见', '仅自己可见'];
  blogs.forEach(function (b) {
    html += '<div class="sp-blog-item" data-id="' + b.id + '">'
      + '<div class="sp-blog-title" onclick="spOpenBlog(' + b.id + ')">' + esc(b.title) + '</div>'
      + '<div class="sp-blog-summary" onclick="spOpenBlog(' + b.id + ')">' + esc(b.summary) + '</div>'
      + '<div class="sp-blog-meta">' + b.time + ' · 浏览 ' + b.views
      + (b.visibility != null ? ' · <span class="f-vis">' + visLbl[b.visibility] + '</span>' : '')
      + (SP_SPACE.self ? ' · <a class="sp-blog-del" data-id="' + b.id + '">删除</a>' : '')
      + '</div></div>';
  });
  list.innerHTML = html;
}
function spOpenBlog(id) {
  fetch('../../api/space.php?action=get_blog&id=' + id, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || !d.success) { alert('无法查看该日志'); return; }
      var b = d.blog;
      var detail = document.getElementById('spBlogDetail');
      detail.innerHTML = '<div class="sp-blog-view">'
        + '<h2 class="sp-blog-view-title">' + esc(b.title) + '</h2>'
        + '<div class="sp-blog-view-meta">' + b.time + ' · 浏览 ' + b.views + '</div>'
        + '<div class="sp-blog-view-ct">' + esc(b.content).replace(/\n/g, '<br>') + '</div>'
        + '<div class="sp-blog-view-ft"><button class="btn-plain" onclick="spBlogBack()">返回列表</button></div>'
        + '</div>';
      document.getElementById('spBlogList').style.display = 'none';
      document.getElementById('spBlogEditor').style.display = 'none';
      detail.style.display = 'block';
    })
    .catch(function () {});
}
function spBlogBack() {
  document.getElementById('spBlogDetail').style.display = 'none';
  document.getElementById('spBlogEditor').style.display = 'none';
  document.getElementById('spBlogList').style.display = '';
}
function spBlogCompose() { spBlogBack(); document.getElementById('spBlogEditor').style.display = 'block'; }
function spBlogCancel() { spBlogBack(); }
function spBlogSave() {
  var t = document.getElementById('spBlogTitle').value.trim();
  var c = document.getElementById('spBlogContent').value.trim();
  if (!t || !c) { alert('标题和正文都不能为空'); return; }
  var v = +(document.getElementById('spBlogVis').value || 0);
  var f = new URLSearchParams();
  f.append('action', 'add_blog'); f.append('title', t); f.append('content', c); f.append('visibility', v);
  fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.success) {
        document.getElementById('spBlogTitle').value = '';
        document.getElementById('spBlogContent').value = '';
        spBlogBack();
        spLoadBlogList();
      } else alert('发布失败');
    })
    .catch(function () { alert('网络错误'); });
}
(function () {
  var list = document.getElementById('spBlogList');
  if (!list) return;
  list.addEventListener('click', function (e) {
    var d = e.target.closest('.sp-blog-del');
    if (!d) return;
    if (window.confirm('删除这篇日志？')) {
      var f = new URLSearchParams(); f.append('action', 'delete_blog'); f.append('id', d.getAttribute('data-id'));
      fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res && res.success) spLoadBlogList(); });
    }
  });
})();

/* ===== 图片大图查看 ===== */
var SP_LB_CUR = [], SP_LB_IDX = 0;
function spOpenLightbox(feedId, idx) {
  var box = document.querySelector('.img-box[data-lb="' + feedId + '"]');
  if (!box) return;
  var imgs = Array.prototype.map.call(box.querySelectorAll('img'), function (i) { return i.getAttribute('src'); });
  if (!imgs.length) return;
  SP_LB_CUR = imgs; SP_LB_IDX = idx;
  var lb = document.getElementById('spLightbox');
  document.getElementById('spLbImg').src = imgs[idx];
  lb.style.display = 'flex';
  document.getElementById('spLbArrows').style.display = imgs.length > 1 ? 'flex' : 'none';
}
function spLbNav(d) {
  if (!SP_LB_CUR.length) return;
  SP_LB_IDX = (SP_LB_IDX + d + SP_LB_CUR.length) % SP_LB_CUR.length;
  document.getElementById('spLbImg').src = SP_LB_CUR[SP_LB_IDX];
}
function spLbClose() { document.getElementById('spLightbox').style.display = 'none'; SP_LB_CUR = []; }
document.addEventListener('keydown', function (e) {
  if (document.getElementById('spLightbox').style.display !== 'flex') return;
  if (e.key === 'Escape') spLbClose();
  if (e.key === 'ArrowLeft') spLbNav(-1);
  if (e.key === 'ArrowRight') spLbNav(1);
});
</script>
</body>
</html>
