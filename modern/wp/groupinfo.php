<?php
/**
 * ChatApp - 群信息页（朋友会）
 * 右侧 Sidebar 抽屉内展示：群头像 / 群名 / 群号 / 群成员
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
$myUser = chatapp_get_user();
$myUid = (int)($myUser['user_id'] ?? 0);
$pdo = db();

$gid = (int)($_GET['gid'] ?? 0);
if ($gid <= 0) { http_response_code(404); exit; }

$g = $pdo->query("SELECT * FROM `groups` WHERE group_id=$gid")->fetch();
if (!$g) { http_response_code(404); echo '<body style="background:#0f1117;color:#9ca3af;font-family:sans-serif;padding:40px;text-align:center">' . htmlspecialchars(t('g_not_found')) . '</body>'; exit; }

$myRole = $pdo->query("SELECT role FROM group_members WHERE group_id=$gid AND user_id=$myUid")->fetchColumn();
if (!$myRole) {
    echo '<body style="background:#0f1117;color:#9ca3af;font-family:sans-serif;padding:40px;text-align:center">' . htmlspecialchars(t('g_not_member')) . '</body>';
    exit;
}

$isOwner = ($myRole === 'owner');
$canManage = ($myRole === 'owner' || $myRole === 'admin');

$membersStmt = $pdo->prepare("SELECT gm.role, gm.muted, u.user_id, u.username, COALESCE(u.display_name, u.username) AS display_name, u.avatar FROM group_members gm JOIN users u ON u.user_id=gm.user_id WHERE gm.group_id=? ORDER BY FIELD(gm.role,'owner','admin','member'), gm.joined_at ASC");
$membersStmt->execute([$gid]);
$members = $membersStmt->fetchAll();
foreach ($members as &$mm) { $mm['avatar_url'] = chatapp_avatar_url($mm['avatar'] ?? '', $mm['username'] ?? ''); }
unset($mm);

$avatarUrl = chatapp_group_avatar_url($g['avatar'] ?? '', $gid);
$gname = htmlspecialchars($g['name']);
$ann = trim((string)($g['announcement'] ?? ''));
$memberCount = count($members);
$roleLabels = ['owner' => t('g_role_owner'), 'admin' => t('g_role_admin'), 'member' => t('g_role_member')];
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo $gname;?> · <?php echo t('g_title');?></title>
<link rel="stylesheet" href="../style/profile.css">
<link rel="stylesheet" href="../style/groupinfo.css?v=<?php echo time();?>">
</head>
<body>

<div class="card slide-in">
  <div class="g-body">

    <div class="nav-bar">
      <button class="nav-btn" onclick="parent.closeMyProfile()">‹</button>
      <span class="nav-title"><?php echo t('g_title');?></span>
      <span style="width:28px"></span>
    </div>

    <!-- 群头像 + 群名 + 群号 -->
    <div class="g-head">
      <div class="g-avatar" id="gAvatarWrap"<?php if($canManage):?> onclick="document.getElementById('gAvatarInput').click()" style="cursor:pointer" title="<?php echo htmlspecialchars(t('g_upload_avatar'));?>"<?php endif;?>>
        <?php if($avatarUrl):?><img src="<?php echo htmlspecialchars($avatarUrl);?>" alt=""><?php else:?><span class="g-avatar-ph">群</span><?php endif;?>
      </div>
      <div class="g-name-col">
        <span class="g-name"><?php echo $gname;?></span>
        <span class="g-gid"><?php echo t('g_gid');?>：<?php echo $gid;?></span>
        <span class="g-type"><?php echo $g['public'] ? t('g_public') : t('g_private');?> · <?php echo $memberCount;?><?php echo t('g_people');?></span>
      </div>
    </div>
    <input type="file" id="gAvatarInput" accept="image/*" style="display:none" onchange="onGAvatarChange(this)">

    <!-- 群公告 -->
    <?php if($ann !== ''):?>
    <div class="g-announce">
      <span class="g-ann-label"><?php echo t('g_announcement');?></span>
      <span class="g-ann-text"><?php echo htmlspecialchars($ann);?></span>
    </div>
    <?php endif;?>

    <div class="section-divider"></div>

    <!-- 群成员 -->
    <div class="g-members-head"><?php echo t('g_members');?>（<?php echo $memberCount;?>）</div>
    <div class="g-member-grid">
      <?php foreach($members as $m):?>
      <div class="g-member" data-uid="<?php echo (int)$m['user_id'];?>" data-uname="<?php echo htmlspecialchars($m['username'], ENT_QUOTES);?>" data-name="<?php echo htmlspecialchars($m['display_name'], ENT_QUOTES);?>" data-role="<?php echo htmlspecialchars($m['role']);?>" data-muted="<?php echo (int)$m['muted'];?>" onclick="onMemberClick(this)">
        <div class="g-member-av">
          <?php if(!empty($m['avatar_url'])):?><img src="<?php echo htmlspecialchars($m['avatar_url']);?>" alt=""><?php else:?><span class="g-member-ph"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($m['display_name'], 0, 1)));?></span><?php endif;?>
          <?php if($m['role'] !== 'member'):?><span class="g-role-tag g-role-<?php echo htmlspecialchars($m['role']);?>"><?php echo htmlspecialchars($roleLabels[$m['role']] ?? '');?></span><?php endif;?>
          <?php if((int)$m['muted']):?><span class="g-muted-tag"><?php echo t('g_muted_tag');?></span><?php endif;?>
        </div>
        <span class="g-member-name"><?php echo htmlspecialchars($m['display_name']);?></span>
      </div>
      <?php endforeach;?>
    </div>

    <!-- 管理操作（群主/管理员） -->
    <?php if($canManage):?>
    <div class="section-divider"></div>
    <div class="g-actions">
      <div class="g-action" onclick="document.getElementById('gAvatarInput').click()"><span><?php echo t('g_upload_avatar');?></span><span class="arrow">›</span></div>
      <div class="g-action" onclick="inviteMember()"><span><?php echo t('g_invite');?></span><span class="arrow">›</span></div>
      <?php if($isOwner):?>
      <div class="g-action" onclick="renameGroup()"><span><?php echo t('g_rename');?></span><span class="arrow">›</span></div>
      <?php endif;?>
      <div class="g-action" onclick="setAnnouncement()"><span><?php echo t('g_set_announcement');?></span><span class="arrow">›</span></div>
      <?php if($isOwner):?>
      <div class="g-action" onclick="toggleMuteAll()"><span id="muteAllLabel"><?php echo $g['all_muted'] ? t('g_unmute_all') : t('g_mute_all');?></span><span class="arrow">›</span></div>
      <?php endif;?>
    </div>
    <?php endif;?>

    <!-- 退出 / 解散 -->
    <div class="section-divider"></div>
    <?php if($isOwner):?>
    <div class="g-action g-danger" onclick="dissolveGroup()"><span><?php echo t('g_dissolve');?></span><span class="arrow">›</span></div>
    <?php else:?>
    <div class="g-action g-danger" onclick="leaveGroup()"><span><?php echo t('g_leave');?></span><span class="arrow">›</span></div>
    <?php endif;?>

  </div>
</div>

<!-- 成员操作底部弹层 -->
<div class="picker-overlay" id="memOverlay" onclick="closeMemMenu()"></div>
<div class="picker-panel" id="memPanel">
  <div class="picker-header">
    <span class="picker-title" id="memPanelTitle"></span>
    <button class="picker-cancel" onclick="closeMemMenu()"><?php echo t('btn_cancel');?></button>
  </div>
  <div class="picker-option" onclick="memViewProfile()"><?php echo t('btn_view_profile');?></div>
  <div class="picker-option" id="memOptSetAdmin" onclick="memSetAdmin()"><?php echo t('g_set_admin');?></div>
  <div class="picker-option" id="memOptUnsetAdmin" onclick="memUnsetAdmin()"><?php echo t('g_unset_admin');?></div>
  <div class="picker-option" id="memOptMute" onclick="memMute()"><?php echo t('g_mute');?></div>
  <div class="picker-option" id="memOptUnmute" onclick="memUnmute()"><?php echo t('g_unmute');?></div>
  <div class="picker-option" id="memOptTransfer" onclick="memTransfer()"><?php echo t('g_transfer_owner');?></div>
  <div class="picker-option danger" id="memOptKick" onclick="memKick()"><?php echo t('g_kick');?></div>
</div>

<!-- 底部按钮栏 -->
<div class="bottom-bar">
  <button class="btn-edit" onclick="parent.closeMyProfile()"><?php echo t('g_back_chat');?></button>
</div>

<div class="save-toast" id="saveToast">✓ <?php echo t('g_saved');?></div>

<script>
var GID = <?php echo $gid;?>;
var GCUR_NAME = <?php echo json_encode($g['name']);?>;
var GCUR_ANN = <?php echo json_encode($ann);?>;
var MY_UID = <?php echo $myUid;?>;
var MY_ROLE = <?php echo json_encode($myRole);?>;
var CAN_MANAGE = <?php echo $canManage ? 'true' : 'false';?>;
var IS_OWNER = <?php echo $isOwner ? 'true' : 'false';?>;
var ALL_MUTED = <?php echo (int)($g['all_muted'] ?? 0);?>;
var GI_FAIL = <?php echo json_encode(t('g_failed'));?>;
var GI_MUTE_CONFIRM = <?php echo json_encode(t('g_mute_confirm'));?>;
var GI_TRANSFER_CONFIRM = <?php echo json_encode(t('g_transfer_confirm'));?>;
var GI_KICK_CONFIRM = <?php echo json_encode(t('g_kick_confirm'));?>;

var _mem = null;
function onMemberClick(el) {
    var uid = parseInt(el.getAttribute('data-uid'), 10);
    var role = el.getAttribute('data-role');
    if (CAN_MANAGE && uid !== MY_UID) {
        if (role === 'owner') { openMemView(el); return; }
        if (role === 'admin' && !IS_OWNER) { openMemView(el); return; }
        openMemMenu(el);
    } else {
        openMemView(el);
    }
}
function openMemView(el) {
    window.parent.openMyProfile(el.getAttribute('data-uname'));
}
function openMemMenu(el) {
    _mem = {
        uid: parseInt(el.getAttribute('data-uid'), 10),
        uname: el.getAttribute('data-uname'),
        name: el.getAttribute('data-name'),
        role: el.getAttribute('data-role'),
        muted: parseInt(el.getAttribute('data-muted'), 10)
    };
    document.getElementById('memPanelTitle').textContent = _mem.name;
    var isAdmin = _mem.role === 'admin';
    showMemOpt('memOptSetAdmin', IS_OWNER && !isAdmin);
    showMemOpt('memOptUnsetAdmin', IS_OWNER && isAdmin);
    showMemOpt('memOptMute', !_mem.muted);
    showMemOpt('memOptUnmute', !!_mem.muted);
    showMemOpt('memOptTransfer', IS_OWNER);
    showMemOpt('memOptKick', true);
    document.getElementById('memOverlay').classList.add('active');
    document.getElementById('memPanel').classList.add('active');
}
function showMemOpt(id, show) {
    var b = document.getElementById(id);
    if (b) b.style.display = show ? '' : 'none';
}
function closeMemMenu() {
    document.getElementById('memOverlay').classList.remove('active');
    document.getElementById('memPanel').classList.remove('active');
}
function memViewProfile() { closeMemMenu(); if (_mem) window.parent.openMyProfile(_mem.uname); }
function memAction(action, extra, doneMsg) {
    var data = { user_id: _mem ? _mem.uid : 0 };
    if (extra) for (var k in extra) data[k] = extra[k];
    return groupApi(action, data).then(function(d) {
        closeMemMenu();
        if (d.success) { if (doneMsg) showToast(doneMsg); setTimeout(function() { location.reload(); }, 600); }
        else showErr(d.error || GI_FAIL);
    });
}
function memSetAdmin() { memAction('set_admin', {}, null); }
function memUnsetAdmin() { memAction('unset_admin', {}, null); }
function memMute() {
    if (!confirm(GI_MUTE_CONFIRM)) return;
    memAction('mute_member', {}, null);
}
function memUnmute() { memAction('unmute_member', {}, null); }
function memTransfer() {
    if (!confirm(GI_TRANSFER_CONFIRM)) return;
    memAction('transfer_owner', {}, null);
}
function memKick() {
    if (!confirm(GI_KICK_CONFIRM)) return;
    memAction('kick', {}, null);
}
function toggleMuteAll() {
    var turnOn = !ALL_MUTED;
    groupApi(turnOn ? 'mute_all' : 'unmute_all', {}).then(function(d) {
        if (d.success) { showToast(); setTimeout(function() { location.reload(); }, 600); }
        else showErr(d.error || GI_FAIL);
    });
}

function showToast(msg) {
    var t = document.getElementById('saveToast');
    t.textContent = msg || ('✓ ' + <?php echo json_encode(t('g_saved'));?>);
    t.style.background = '#2a4a2a'; t.style.borderColor = '#3a6a3a'; t.style.color = '#e0e0e0';
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
function showErr(msg) {
    var t = document.getElementById('saveToast');
    t.textContent = msg;
    t.style.background = '#4a2020'; t.style.borderColor = '#5c2a2a'; t.style.color = '#ffb3b3';
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2600);
}
function groupApi(action, data) {
    var f = new URLSearchParams();
    f.append('action', action);
    f.append('group_id', GID);
    for (var k in (data || {})) f.append(k, data[k]);
    return fetch('../../api/group.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); });
}

function onGAvatarChange(input) {
    var f = input.files[0];
    if (!f) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        groupApi('upload_avatar', { avatar: e.target.result }).then(function(d) {
            if (d.success) { showToast(<?php echo json_encode(t('g_avatar_updated'));?>); setTimeout(function() { location.reload(); }, 700); }
            else showErr(d.error || <?php echo json_encode(t('g_failed'));?>);
        });
    };
    reader.readAsDataURL(f);
}

function inviteMember() {
    var v = prompt(<?php echo json_encode(t('g_invite_prompt'));?> + ':');
    if (v === null) return;
    v = v.trim();
    if (!v) return;
    groupApi('invite', { username: v }).then(function(d) {
        if (d.success) { showToast(<?php echo json_encode(t('g_invite_done'));?>); setTimeout(function() { location.reload(); }, 600); }
        else showErr(inviteErrText(d.error) || d.error || GI_FAIL);
    });
}
function inviteErrText(code) {
    var map = {
        'Forbidden': <?php echo json_encode(t('g_invite_forbidden'));?>,
        'User not found': <?php echo json_encode(t('g_invite_not_found'));?>,
        'Already a member': <?php echo json_encode(t('g_invite_already'));?>,
        'Placeholder user': <?php echo json_encode(t('g_invite_placeholder'));?>,
        'Empty username': <?php echo json_encode(t('g_invite_empty'));?>
    };
    return map[code] || '';
}
function renameGroup() {
    var v = prompt(<?php echo json_encode(t('g_rename_prompt'));?> + ':', GCUR_NAME);
    if (v === null) return;
    v = v.trim();
    if (!v) return;
    groupApi('rename', { name: v }).then(function(d) {
        if (d.success) {
            showToast();
            setTimeout(function() { location.reload(); if (window.parent && window.parent.loadMyGroups) window.parent.loadMyGroups(); }, 700);
        } else showErr(d.error || <?php echo json_encode(t('g_failed'));?>);
    });
}

function setAnnouncement() {
    var v = prompt(<?php echo json_encode(t('g_announcement_prompt'));?> + ':', GCUR_ANN);
    if (v === null) return;
    groupApi('set_announcement', { announcement: v }).then(function(d) {
        if (d.success) { showToast(); setTimeout(function() { location.reload(); }, 700); }
        else showErr(d.error || <?php echo json_encode(t('g_failed'));?>);
    });
}

function leaveGroup() {
    if (!confirm(<?php echo json_encode(t('g_leave_confirm'));?>)) return;
    groupApi('leave', {}).then(function(d) {
        if (d.success) {
            if (window.parent && window.parent.afterGroupLeave) window.parent.afterGroupLeave();
            else { if (window.parent && window.parent.loadMyGroups) window.parent.loadMyGroups(); window.parent.closeMyProfile(); }
        } else showErr(d.error || <?php echo json_encode(t('g_failed'));?>);
    });
}

function dissolveGroup() {
    if (!confirm(<?php echo json_encode(t('g_dissolve_confirm'));?>)) return;
    groupApi('dissolve', {}).then(function(d) {
        if (d.success) {
            if (window.parent && window.parent.afterGroupLeave) window.parent.afterGroupLeave();
            else { if (window.parent && window.parent.loadMyGroups) window.parent.loadMyGroups(); window.parent.closeMyProfile(); }
        } else showErr(d.error || <?php echo json_encode(t('g_failed'));?>);
    });
}
</script>

</body>
</html>
