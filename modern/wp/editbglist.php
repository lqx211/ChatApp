<?php
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();
$meUid = (int)($currentUser['user_id'] ?? 0);
$meName = $currentUser['username'] ?? '';

// 类型：black / white
$type = $_GET['type'] ?? '';
if ($type !== 'black' && $type !== 'white') $type = 'black';

$bgPrivacy = (int)($currentUser['bg_privacy'] ?? 0);
// PHP 端模式校验：非对应模式 → 显示提示不可编辑
$modeMismatch = false;
if ($type === 'black' && $bgPrivacy !== 0) $modeMismatch = true;
if ($type === 'white' && $bgPrivacy !== 1) $modeMismatch = true;
$listLabel = $type === 'black' ? '黑名单' : '白名单';

$rawList = '';
if ($type === 'black') {
    $rawList = $currentUser['bg_blacklist'] ?? '';
} else {
    $rawList = $currentUser['bg_whitelist'] ?? '';
}
$curList = $rawList ? json_decode($rawList, true) : [];
if (!is_array($curList)) $curList = [];
$curList = array_values(array_unique(array_map('intval', $curList)));

$bgNoFriend = (int)($currentUser['bg_no_friend'] ?? 0);

// 拉取名单中用户信息（头像/昵称），好友状态一并判断
$uidInfo = [];
if ($curList) {
    $in = implode(',', array_fill(0, count($curList), '?'));
    $stmt = db()->prepare("SELECT user_id, username, display_name, avatar FROM users WHERE user_id IN ($in)");
    $stmt->execute($curList);
    foreach ($stmt->fetchAll() as $row) {
        $uidInfo[(int)$row['user_id']] = [
            'uid' => (int)$row['user_id'],
            'username' => $row['username'] ?? '',
            'name' => ($row['display_name'] ?: $row['username']),
            'avatar' => $row['avatar'] ?? '',
        ];
    }
}

// 好友列表（accepted 双向）
$friendList = [];
if ($meUid > 0) {
    $stmt = db()->prepare("SELECT user_from, user_to FROM contacts WHERE status='accepted' AND (user_from=? OR user_to=?)");
    $stmt->execute([$meUid, $meUid]);
    foreach ($stmt->fetchAll() as $row) {
        $fuid = (int)($row['user_from'] == $meUid ? $row['user_to'] : $row['user_from']);
        $friendList[$fuid] = $fuid;
    }
}
$friendInfo = [];
if ($friendList) {
    $in = implode(',', array_fill(0, count($friendList), '?'));
    $stmt = db()->prepare("SELECT user_id, username, display_name, avatar FROM users WHERE user_id IN ($in)");
    $stmt->execute(array_values($friendList));
    foreach ($stmt->fetchAll() as $row) {
        $friendInfo[(int)$row['user_id']] = [
            'uid' => (int)$row['user_id'],
            'username' => $row['username'] ?? '',
            'name' => ($row['display_name'] ?: $row['username']),
            'avatar' => $row['avatar'] ?? '',
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo htmlspecialchars($listLabel);?>配置</title>
<link rel="stylesheet" href="/plan/editinfo.css?v=20260809">
<style>
.uid-list { padding: 0 16px; }
.uid-item {
  display: flex; align-items: center;
  padding: 10px 0;
  border-bottom: 0.5px solid #262c38;
  gap: 10px;
}
.uid-item .u-avatar { width: 28px; height: 28px; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: #232936; }
.uid-item .u-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.friend-item .u-avatar { width: 28px !important; height: 28px !important; border-radius: 50%; overflow: hidden; flex-shrink: 0; background: #232936; }
.friend-item .u-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.uid-item .u-name { flex: 1; color: #e0e3ea; font-size: 15px; }
.uid-item .u-uid { color: #6b7280; font-size: 12px; }
.uid-item .u-del { color: #e06060; cursor: pointer; font-size: 13px; padding: 4px; }
.friend-item {
  display: flex; align-items: center;
  padding: 10px 16px;
  border-bottom: 0.5px solid #262c38;
  gap: 10px;
  cursor: pointer;
}
.friend-item .f-check { width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid #4a5260; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
.friend-item.in .f-check { background: #2a4a2a; border-color: #3a6a3a; }
.friend-item .f-check::after { content: '✓'; color: #6ab87a; font-size: 13px; opacity: 0; }
.friend-item.in .f-check::after { opacity: 1; }
.add-uid-row { padding: 10px 16px; display: flex; gap: 8px; }
.add-uid-row input { flex: 1; padding: 8px 12px; background: #1e1e1e; border: 1px solid #444; color: #e0e0e0; font-family: inherit; outline: none; border-radius: 6px; }
.add-uid-row input::placeholder { color: #5a6270; }
.add-uid-row button { background: #2a4a2a; border: 1px solid #3a6a3a; color: #e0e0e0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-family: inherit; }
.mismatch-tip { padding: 30px 24px; text-align: center; color: #8b93a1; font-size: 14px; line-height: 1.8; }
.mismatch-tip button { margin-top: 16px; background: #232936; border: 1px solid #444; color: #e0e0e0; padding: 10px 28px; border-radius: 8px; cursor: pointer; font-family: inherit; }
</style>
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo htmlspecialchars($listLabel);?>配置</span>
    <span style="width:28px"></span>
  </div>

  <?php if ($modeMismatch):?>
  <div class="mismatch-tip">
    <p>当前并非<?php echo htmlspecialchars($listLabel);?>模式，无法修改<?php echo htmlspecialchars($listLabel);?>配置。</p>
    <button onclick="goBack()">返回</button>
  </div>
  <?php else:?>

  <div class="hint-text">当前有 <?php echo count($curList);?> 个进入<?php echo htmlspecialchars($listLabel);?>的用户。</div>

  <!-- 当前名单（按 UID 列表） -->
  <div class="section-divider"></div>
  <div class="uid-list" id="uidList">
    <?php foreach ($curList as $uid):?>
    <?php
      $info = $uidInfo[$uid] ?? ['uid' => $uid, 'name' => 'UID ' . $uid, 'avatar' => ''];
      $isFriend = isset($friendList[$uid]);
    ?>
    <div class="uid-item" data-uid="<?php echo $uid;?>">
      <?php if (!empty($info['avatar'])):?><div class="u-avatar"><img src="<?php echo htmlspecialchars(chatapp_avatar_url($info['avatar'], $info['username'] ?? ''));?>" alt=""></div><?php endif;?>
      <div class="u-name"><?php echo htmlspecialchars($info['name']);?><?php if ($isFriend):?><span style="color:#6ab87a;font-size:11px;margin-left:6px">好友</span><?php endif;?></div>
      <div class="u-uid">UID <?php echo $uid;?></div>
      <span class="u-del" onclick="removeUid(<?php echo $uid;?>)">删除</span>
    </div>
    <?php endforeach;?>
    <?php if (!$curList):?>
    <div style="padding:20px 0;text-align:center;color:#5a6270;font-size:13px">名单为空</div>
    <?php endif;?>
  </div>

  <!-- 按 UID 列表选择：输入添加 -->
  <div class="section-divider"></div>
  <div class="add-uid-row">
    <input type="number" id="uidInput" placeholder="输入UID，加入<?php echo htmlspecialchars($listLabel);?>">
    <button onclick="addUid()">添加</button>
  </div>

  <!-- 按朋友列表选择 -->
  <div class="section-divider"></div>
  <div class="hint-text">按朋友列表选择（点击勾选添加/移除）</div>
  <div id="friendList">
    <?php foreach ($friendInfo as $f):?>
    <?php $inList = in_array($f['uid'], $curList, true);?>
    <div class="friend-item<?php echo $inList ? ' in':'';?>" data-fuid="<?php echo $f['uid'];?>" onclick="toggleFriend(this, <?php echo $f['uid'];?>)">
      <div class="f-check"></div>
      <?php if (!empty($f['avatar'])):?><div class="u-avatar"><img src="<?php echo htmlspecialchars(chatapp_avatar_url($f['avatar'], $f['username'] ?? ''));?>" alt=""></div><?php endif;?>
      <div class="u-name"><?php echo htmlspecialchars($f['name']);?></div>
      <div class="u-uid">UID <?php echo $f['uid'];?></div>
    </div>
    <?php endforeach;?>
    <?php if (!$friendInfo):?>
    <div style="padding:20px 0;text-align:center;color:#5a6270;font-size:13px">暂无好友</div>
    <?php endif;?>
  </div>

  <!-- 禁止非朋友关系查看背景图 -->
  <div class="section-divider"></div>
  <div class="form-row" onclick="toggleNoFriend()">
    <span class="row-label">禁止非朋友关系查看</span>
    <span class="row-value" id="noFriendVal"><?php echo $bgNoFriend ? '已开启' : '已关闭';?></span>
    <span class="row-arrow">›</span>
  </div>

  <?php endif;?>

</div>

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
var _type = <?php echo json_encode($type);?>;
var _curUids = <?php echo json_encode($curList);?>;
var _noFriend = <?php echo (int)$bgNoFriend;?>;

function goBack() {
    var card = document.querySelector('.card');
    if (!card) { parent.closeMyProfile(); return; }
    card.classList.add('slide-out-right');
    setTimeout(function() {
        if (window.parent && window.parent.document.getElementById('profileFrame')) {
            window.parent.document.getElementById('profileFrame').src = 'editbgprivacy.php';
        }
    }, 260);
}

function showToast() {
    var t = document.getElementById('saveToast');
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}

function saveList() {
    var key = _type === 'black' ? 'blacklist' : 'whitelist';
    var action = _type === 'black' ? 'set_bg_blacklist' : 'set_bg_whitelist';
    var f = new URLSearchParams();
    f.append('action', action);
    f.append(key, JSON.stringify(_curUids));
    fetch('../../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) showToast();
    });
}

function render() {
    // 更新「当前有 N 个」提示
    var hint = document.querySelector('.hint-text');
    if (hint) hint.textContent = '当前有 ' + _curUids.length + ' 个进入' + (_type === 'black' ? '黑名单' : '白名单') + '的用户。';
    // 简单实现：重新加载页面保持简洁
    // （当前采用保存+整页刷新方式保持一致）
}

function addUid() {
    var input = document.getElementById('uidInput');
    var v = parseInt(input.value, 10);
    if (!v || v <= 0) { alert('请输入有效的UID'); return; }
    if (_curUids.indexOf(v) !== -1) { alert('该UID已在名单中'); input.value=''; return; }
    _curUids.push(v);
    saveList();
    setTimeout(function(){ location.reload(); }, 400);
}

function removeUid(uid) {
    _curUids = _curUids.filter(function(u){ return u !== uid; });
    saveList();
    setTimeout(function(){ location.reload(); }, 400);
}

function toggleFriend(el, fuid) {
    var idx = _curUids.indexOf(fuid);
    if (idx !== -1) {
        _curUids.splice(idx, 1);
        el.classList.remove('in');
    } else {
        _curUids.push(fuid);
        el.classList.add('in');
    }
    saveList();
    render();
}

function toggleNoFriend() {
    _noFriend = _noFriend ? 0 : 1;
    document.getElementById('noFriendVal').textContent = _noFriend ? '已开启' : '已关闭';
    var f = new URLSearchParams();
    f.append('action', 'set_bg_no_friend');
    f.append('no_friend', String(_noFriend));
    fetch('../../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) showToast();
    });
}
</script>

</body>
</html>