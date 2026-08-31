<?php
/**
 * ChatApp 个人空间 · 访客页面
 * 3 个 tab：谁看过我 / 我看过谁 / 被挡访客（预留）
 * hover 访客头像弹名片（名字/性别星座/共同好友/特别关心，无亲密度）
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
ensure_space_visits_table();
$cur = chatapp_get_user();
$meName = (string)($cur['username'] ?? '');
$meUid = (int)($cur['user_id'] ?? 0);
$meDisp = (string)($cur['display_name'] ?: $meName);
$meAvatar = chatapp_avatar_url($cur['avatar'] ?? '', $meName, $meUid);
?>
<!DOCTYPE html>
<html lang="zh-cn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>访客 - ChatApp</title>
<link rel="stylesheet" href="../style/space.css?v=<?php echo time();?>">
<!--[if IE]>
<script type="text/javascript">
    window.Aegis = null;// 待兼容
</script>
<![endif]-->
</head>
<body class="bg-body mode-theme">

<div class="top-fix-bar">
  <div class="top-fix-inner">
    <div class="top-fix-wrap">
      <a class="logo" href="space.php"><span class="logo-ico">🏠</span>个人空间</a>
      <ul class="top-nav">
        <li class="nav-list"><a href="space.php">主页</a></li>
        <li class="nav-list"><a href="chat.php">聊天</a></li>
        <li class="nav-list"><a href="settings.php">设置</a></li>
      </ul>
      <div class="user-info">
        <a class="user-home" href="space.php">
          <?php if ($meAvatar):?><img class="user-avatar" src="<?php echo htmlspecialchars($meAvatar);?>" alt=""><?php endif;?>
          <span class="user-name textoverflow"><?php echo htmlspecialchars($meDisp);?></span>
        </a>
      </div>
    </div>
  </div>
</div>

<div class="sp-visitor-page">
  <div class="sp-visitor-box">
    <div class="sp-visitor-tabs">
      <span class="on" data-t="me" onclick="spVTab('me')">谁看过我</span>
      <span data-t="you" onclick="spVTab('you')">我看过谁</span>
      <span data-t="refuse" onclick="spVTab('refuse')">被挡访客</span>
    </div>
    <div class="sp-visitor-count">今日浏览 <b id="spVToday">0</b> &nbsp;·&nbsp; 总浏览 <b id="spVTotal">0</b></div>
    <ul class="sp-visitor-grid" id="spVisitorGrid"></ul>
    <div class="sp-visitor-empty" id="spVisitorEmpty" style="display:none">暂无访客</div>
  </div>
</div>

<!-- hover 名片（细节同空间页；不显示亲密度） -->
<div class="sp-namecard" id="spNameCard" style="display:none">
  <div class="nc-av"><img id="ncAv" src="" alt=""></div>
  <div class="nc-info">
    <div class="nc-name" id="ncName"></div>
    <div class="nc-meta" id="ncMeta"></div>
    <div class="nc-common" id="ncCommon"></div>
    <button class="nc-care" id="ncCare" onclick="ncToggleCare(event)">特别关心</button>
  </div>
</div>

<script>
var SP_VTYPE = 'me', SP_NC_USERNAME = '';
function esc(s) { s = String(s == null ? '' : s); return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
function spVTab(t) {
  SP_VTYPE = t;
  Array.prototype.forEach.call(document.querySelectorAll('.sp-visitor-tabs span'), function (s) { s.classList.toggle('on', s.getAttribute('data-t') === t); });
  spLoadVisitors();
}
function spLoadVisitors() {
  var grid = document.getElementById('spVisitorGrid');
  var empty = document.getElementById('spVisitorEmpty');
  if (!grid) return;
  grid.innerHTML = '<li class="sp-vis-loading">加载中…</li>';
  fetch('../../api/space.php?action=visitor_list&type=' + SP_VTYPE, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var arr = (d && d.success) ? (d.list || []) : [];
      if (!arr.length) {
        grid.innerHTML = '';
        empty.style.display = '';
        empty.textContent = SP_VTYPE === 'refuse' ? '暂无被挡访客' : '暂无访客';
        return;
      }
      empty.style.display = 'none';
      var h = '';
      arr.forEach(function (v) {
        var av = v.avatar ? '<img src="' + v.avatar + '" alt="">' : '<div class="av-empty">' + esc((v.name || '?').charAt(0)) + '</div>';
        var op = (SP_VTYPE === 'me') ? '<span class="sp-vis-del" title="删除记录" onclick="event.stopPropagation();spVisitorDel(' + v.uid + ')">×</span>' : '';
        var hide = (SP_VTYPE === 'me') ? '<span class="sp-vis-hide" title="隐藏他的访问" onclick="event.stopPropagation();spVisitorHide(' + v.uid + ')">隐藏</span>' : '';
        h += '<li class="sp-vis-item" data-uid="' + v.uid + '" data-username="' + esc(v.username) + '" data-name="' + esc(v.name) + '" data-avatar="' + (v.avatar || '') + '" data-gender="' + v.gender + '" data-zodiac="' + esc(v.zodiac) + '" data-common="' + v.common + '" data-special="' + v.special + '" onmouseenter="spNameCardShow(this)">'
          + '<div class="sp-vis-av">' + av + op + hide + '</div>'
          + '<div class="sp-vis-name">' + esc(v.name) + '</div>'
          + '<div class="sp-vis-time">' + esc(v.time) + '</div>'
          + '</li>';
      });
      grid.innerHTML = h;
    })
    .catch(function () { grid.innerHTML = ''; empty.style.display = ''; empty.textContent = '加载失败'; });
  if (SP_VTYPE === 'me') {
    fetch('../../api/space.php?action=visit_count', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.success) return;
        var a = document.getElementById('spVToday'); if (a) a.textContent = d.today;
        var b = document.getElementById('spVTotal'); if (b) b.textContent = d.total;
      }).catch(function () {});
  }
}
function spVisitorDel(uid) {
  if (!window.confirm('删除本次访问记录？')) return;
  var f = new URLSearchParams(); f.append('action', 'visitor_delete'); f.append('uid', uid);
  fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
    .then(function (r) { return r.json(); }).then(function () { spLoadVisitors(); });
}
function spVisitorHide(uid) {
  if (!window.confirm('隐藏他的访问？以后他来访不再显示。')) return;
  var f = new URLSearchParams(); f.append('action', 'visitor_hide'); f.append('uid', uid);
  fetch('../../api/space.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
    .then(function (r) { return r.json(); }).then(function () { spLoadVisitors(); });
}
/* hover 名片 */
function spNameCardShow(el) {
  var nc = document.getElementById('spNameCard');
  if (!nc) return;
  SP_NC_USERNAME = el.getAttribute('data-username') || '';
  document.getElementById('ncAv').src = el.getAttribute('data-avatar') || '';
  document.getElementById('ncName').textContent = el.getAttribute('data-name') || '';
  var g = +el.getAttribute('data-gender');
  var meta = (g === 1 ? '男' : (g === 2 ? '女' : '未设置'));
  var zod = el.getAttribute('data-zodiac');
  if (zod) meta += ' · ' + zod;
  document.getElementById('ncMeta').textContent = meta;
  var common = +el.getAttribute('data-common');
  var ce = document.getElementById('ncCommon');
  if (common > 0) { ce.style.display = ''; ce.textContent = '你们有 ' + common + ' 个共同好友'; }
  else { ce.style.display = 'none'; ce.textContent = ''; }
  var care = document.getElementById('ncCare');
  care.textContent = (+el.getAttribute('data-special')) ? '已关心' : '特别关心';
  care.classList.toggle('on', !!+el.getAttribute('data-special'));
  nc.style.display = 'block';
  var r = el.getBoundingClientRect();
  var ncw = 230, nch = 150;
  var x = Math.min(Math.max(r.left + r.width / 2 - ncw / 2, 8), window.innerWidth - ncw - 8);
  var y = r.bottom + 10;
  if (y + nch > window.innerHeight - 8) y = Math.max(8, r.top - nch - 10);
  nc.style.left = x + 'px';
  nc.style.top = y + 'px';
}
function ncToggleCare(e) {
  e && e.stopPropagation();
  if (!SP_NC_USERNAME) return;
  var f = new URLSearchParams();
  f.append('action', 'toggle_special');
  f.append('username', SP_NC_USERNAME);
  fetch('../../api/contacts.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (d && d.success) {
        var care = document.getElementById('ncCare');
        if (!care) return;
        var on = care.classList.contains('on');
        care.textContent = on ? '特别关心' : '已关心';
        care.classList.toggle('on', !on);
      } else alert('操作失败');
    });
}
(function () {
  var grid = document.getElementById('spVisitorGrid');
  if (grid) grid.addEventListener('mouseleave', function () { var nc = document.getElementById('spNameCard'); if (nc) nc.style.display = 'none'; });
})();
spLoadVisitors();
</script>
</body>
</html>
