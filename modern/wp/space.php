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
    ];
    return $map[$n] ?? '';
}
$ch = mb_strtoupper(mb_substr($displayName, 0, 1));
// 精选相片（暂为示例占位，后续接相册）
$samplePhotos = [];
for ($i = 0; $i < 9; $i++) { $samplePhotos[] = ['src' => sp_ph($i, $ch, '#ffb300','#ff7043'), 'cap' => '相册 ' . ($i + 1)]; }

// ===== 朋友圈：读取并过滤可见性 =====
ensure_space_feeds_table();
$isFriendView = $isSelf || space_is_friend($pdo, $meUid, $uid);
$feedRows = [];
if ($uid) {
    $fstmt = $pdo->prepare("SELECT id, content, images, visibility, visible_to, likes, liked_by, created_at FROM space_feeds WHERE user_id=? AND enabled=1 ORDER BY id DESC LIMIT 200");
    $fstmt->execute([$uid]);
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
          <span class="btn-head btn-primary"><a>加好友</a></span>
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
      </div>
      <div class="head-detail">
        <div class="head-detail-name"><span class="user-name"><?php echo htmlspecialchars($displayName);?></span></div>
        <div class="head-detail-sub">Lv.<?php echo $level;?> · 空间ID <?php echo $uid;?></div>
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

            <!-- 发表说说（仅自己） -->
            <?php if ($isSelf): ?>
            <div class="qz-poster">
              <div class="qz-poster-bd">
                <?php if ($avatarUrl):?><img class="poster-av" src="<?php echo htmlspecialchars($avatarUrl);?>" alt=""><?php endif;?>
                <div class="qz-inputer" data-ph="说点什么吧..." contenteditable="true" id="spPoster"></div>
              </div>
              <div class="qz-poster-ft">
                <div class="attach-icons">
                  <span title="照片" onclick="spAlert('照片')"><?php echo sp_ic('image');?></span>
                  <span title="表情" onclick="spAlert('表情')"><?php echo sp_ic('smile');?></span>
                  <span title="@好友" onclick="spAlert('@好友')">@</span>
                  <span title="话题" onclick="spAlert('话题')">#</span>
                </div>
                <div class="vis-select" id="spVisBtn" onclick="spVisToggle(event)">
                  <span class="vis-ic"><?php echo sp_ic('globe');?></span>
                  <span class="vis-label" id="spVisLabel">所有人可见</span>
                  <span class="vis-arrow">▾</span>
                </div>
                <div class="op"><button class="btn-post" id="spPostBtn" onclick="spPost()">发表</button></div>
              </div>
            </div>
            <?php endif; ?>

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
                  <?php if ($avatarUrl):?><img class="user-avatar" src="<?php echo htmlspecialchars($avatarUrl);?>" alt=""><?php endif;?>
                  <div class="user-info">
                    <div class="f-nick"><?php echo htmlspecialchars($displayName);?></div>
                    <div class="info-detail"><?php echo htmlspecialchars($f['time']);?><?php if ($isSelf):?> · <span class="f-vis"><?php echo space_vis_label((int)$f['vis']);?></span><?php endif;?></div>
                  </div>
                </div>
                <div class="f-single-content">
                  <div class="f-ct-text"><?php echo nl2br(htmlspecialchars($f['text']));?></div>
                  <?php if (!empty($f['images'])): ?>
                  <div class="f-ct-txtimg"><div class="img-box<?php echo count($f['images']) === 1 ? ' one' : '';?>">
                    <?php foreach ($f['images'] as $im): ?>
                    <a class="img-item"><img src="<?php echo htmlspecialchars($im);?>" alt=""></a>
                    <?php endforeach; ?>
                  </div></div>
                  <?php endif; ?>
                </div>
                <div class="f-single-foot">
                  <ul class="op-list">
                    <li class="op-like<?php echo $f['liked'] ? ' liked' : '';?>" data-id="<?php echo (int)$f['id'];?>"><span class="op-ic"><?php echo sp_ic('like');?></span> 赞<?php if ((int)$f['likes'] > 0):?> (<?php echo (int)$f['likes'];?>)<?php endif;?></li>
                    <li class="op-comment" onclick="spAlert('评论')"><span class="op-ic"><?php echo sp_ic('comment');?></span> 评论</li>
                    <?php if ($isSelf): ?>
                    <li class="op-del" data-id="<?php echo (int)$f['id'];?>"><span class="op-ic"><?php echo sp_ic('top');?></span> 删除</li>
                    <?php endif; ?>
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

<!-- 选择好友弹窗 -->
<div class="sp-fm-mask" id="spFmMask" style="display:none" onclick="if(event.target===this)spFmClose()">
  <div class="sp-fm-box">
    <div class="sp-fm-head"><span>选择好友</span><span class="sp-fm-x" onclick="spFmClose()">×</span></div>
    <div class="sp-fm-body">
      <div class="sp-fm-left">
        <div class="sp-fm-search"><input id="spFmSearch" placeholder="搜索好友"><span class="sp-fm-sbtn"><?php echo sp_ic('search');?></span></div>
        <label class="sp-fm-g"><input type="checkbox" id="spFmAll" onchange="spFmSetAll(this.checked)"> 全部好友</label>
        <div class="sp-fm-hint">最多可勾选 1000 位好友</div>
      </div>
      <div class="sp-fm-right" id="spFmList"></div>
    </div>
    <div class="sp-fm-foot">
      <span class="sp-fm-count" id="spFmCount">已选 0 位</span>
      <button class="sp-fm-ok" onclick="spFmConfirm()">确定</button>
    </div>
  </div>
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
var SP_SPACE = <?php echo json_encode(['self' => $isSelf, 'uid' => $uid, 'meUid' => $meUid]);?>;
var SP_POST_VIS = 0, SP_POST_FRIENDS = [], SP_FRIENDS = [];

function spAlert(what) { alert('「' + what + '」功能即将上线。'); }
function spGoto(where) { alert('「' + where + '」模块即将上线（示例 UI）。'); }

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
document.addEventListener('click', function () { var m = document.getElementById('spVisMenu'); if (m) m.style.display = 'none'; });
document.getElementById('spVisMenu').addEventListener('click', function (e) {
  var it = e.target.closest('.sp-vis-item');
  if (!it) return;
  SP_POST_VIS = +it.getAttribute('data-v');
  this.style.display = 'none';
  if (SP_POST_VIS === 2 || SP_POST_VIS === 3) { spFmOpen(); return; }
  SP_POST_FRIENDS = [];
  var lbl = document.getElementById('spVisLabel');
  if (lbl) lbl.textContent = it.textContent.replace(/\s+/g, ' ').trim();
});

/* ===== 好友选择弹窗 ===== */
function spFmOpen() {
  var mask = document.getElementById('spFmMask');
  if (!mask) return;
  mask.style.display = 'flex';
  if (SP_FRIENDS.length) { renderFmList(); return; }
  fetch('../../api/contacts.php?action=list', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d && d.success) { SP_FRIENDS = d.contacts || []; renderFmList(); } })
    .catch(function () {});
}
function renderFmList() {
  var q = (document.getElementById('spFmSearch').value || '').trim().toLowerCase();
  var list = document.getElementById('spFmList');
  var html = '';
  SP_FRIENDS.forEach(function (f) {
    var name = (f.display_name || f.username || '');
    if (q && name.toLowerCase().indexOf(q) < 0 && (f.username || '').toLowerCase().indexOf(q) < 0) return;
    var uid = +(f.user_id || 0);
    if (!uid) return;
    var checked = SP_POST_FRIENDS.indexOf(uid) >= 0;
    html += '<label class="sp-fm-item"><input type="checkbox" data-uid="' + uid + '"' + (checked ? ' checked' : '') + '>'
      + '<img src="' + (f.avatar || '') + '" alt="" onerror="this.style.visibility=\'hidden\'">'
      + '<span class="sp-fm-name">' + String(name).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }) + '</span></label>';
  });
  list.innerHTML = html || '<div class="sp-fm-empty">无匹配好友</div>';
  updateFmCount();
}
function spFmSetAll(on) {
  [].forEach.call(document.querySelectorAll('#spFmList input[type=checkbox]'), function (c) { c.checked = on; });
  updateFmCount();
}
function updateFmCount() {
  var ids = [];
  [].forEach.call(document.querySelectorAll('#spFmList input:checked'), function (c) { ids.push(+(c.getAttribute('data-uid'))); });
  var c = document.getElementById('spFmCount');
  if (c) c.textContent = '已选 ' + ids.length + ' 位';
  var all = document.getElementById('spFmAll');
  if (all) all.checked = ids.length > 0 && ids.length === SP_FRIENDS.length;
}
function spFmConfirm() {
  var ids = [];
  [].forEach.call(document.querySelectorAll('#spFmList input:checked'), function (c) { ids.push(+(c.getAttribute('data-uid'))); });
  SP_POST_FRIENDS = ids;
  document.getElementById('spFmMask').style.display = 'none';
  var lbl = document.getElementById('spVisLabel');
  if (lbl) lbl.textContent = (SP_POST_VIS === 3 ? '部分好友不可见' : '部分好友可见') + (ids.length ? '（' + ids.length + '）' : '');
}
function spFmClose() { document.getElementById('spFmMask').style.display = 'none'; }
(function () {
  var s = document.getElementById('spFmSearch'); if (s) s.addEventListener('input', renderFmList);
  var l = document.getElementById('spFmList'); if (l) l.addEventListener('change', updateFmCount);
})();

/* ===== 发表 ===== */
function spPost() {
  var p = document.getElementById('spPoster');
  if (!p) return;
  var t = (p.textContent || '').replace(/\u00a0/g, ' ').trim();
  if (!t) { p.focus(); return; }
  var btn = document.getElementById('spPostBtn');
  if (btn) { btn.disabled = true; btn.textContent = '发表中…'; }
  var f = new URLSearchParams();
  f.append('action', 'post');
  f.append('content', t);
  f.append('visibility', SP_POST_VIS);
  if (SP_POST_VIS === 2 || SP_POST_VIS === 3) f.append('visible_to', JSON.stringify(SP_POST_FRIENDS));
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
</script>
</body>
</html>
