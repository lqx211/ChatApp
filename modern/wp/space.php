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

      <div class="layout-copyright">© ChatApp 个人空间</div>
    </div>
  </div>
</div>

<!-- 返回顶部 -->
<div class="fix-layout">
  <div class="to-top" id="spToTop" title="返回顶部"><?php echo sp_ic('top');?></div>
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
