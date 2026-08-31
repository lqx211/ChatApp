<?php
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();

$currentUser = chatapp_get_user();
$isAdmin = chatapp_has_permission($currentUser['user_id'] ?? 0, 'users.view');
$isRoot = chatapp_get_role((int)($currentUser['user_id'] ?? 0)) === 'root';
$customTitle = $currentUser['custom_title'] ?? '';

// WebSocket 通讯模式：本地/私网/公网 三个地址（config/wss_server.php，root 可改）。
// 前端根据当前访问来源自动选择对应地址（见 wss_client.js 的 wssTargetUrl）。
$__wssCfg = chatapp_wss_config();
$__wssUrls = [
    'local'   => chatapp_wss_url($__wssCfg['local']),
    'private' => chatapp_wss_url($__wssCfg['private']),
    'public'  => chatapp_wss_url($__wssCfg['public']),
];
// 全空兜底：按访问 Host 自动推断（localhost 直连 9090 / 其余走公网 Tunnel）。
if ($__wssUrls['local'] === '' && $__wssUrls['private'] === '' && $__wssUrls['public'] === '') {
    $__host = $_SERVER['HTTP_HOST'] ?? '';
    // HTTP_HOST 可能带端口（如 localhost:8080），去掉端口再拼 ws 地址，避免 ws://host:8080:9090 这种错误
    $__hostNoPort = preg_replace('/:\d+$/', '', $__host);
    if (stripos($__host, 'localhost') !== false || stripos($__host, '127.0.0.1') !== false) {
        $__wssUrls['local'] = (defined('FORCE_HTTPS') && FORCE_HTTPS ? 'wss://' : 'ws://') . $__hostNoPort . ':9090';
    } else {
        $__wssUrls['public'] = 'wss://wss.lqx211.com';
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $customTitle ? htmlspecialchars($customTitle) : 'ChatApp'; ?></title>
<link rel="stylesheet" href="../../css/global.css">
<link rel="stylesheet" href="../style/chat.css?v=<?php echo time();?>">
<style>
/* ===== 加载动画关键样式（内联，确保 Loading UI 最先渲染） ===== */
#loader-wrapper {
  position: fixed; top: 0; left: 0; width: 100%; height: 100%;
  z-index: 999; overflow: hidden;
}
.loader {
  width: 100%; height: 100%; position: absolute; top: 0; left: 0;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
}
.loader .loader-circle {
  width: 150px; height: 150px; border-radius: 50%;
  border: 3px solid transparent; border-top-color: #fff;
  animation: spin 1.8s linear infinite; z-index: 2;
}
.loader .loader-circle:before {
  content: ""; position: absolute; top: 5px; left: 5px; right: 5px; bottom: 5px;
  border-radius: 50%; border: 3px solid transparent; border-top-color: #a4a4a4;
  animation: spin-reverse 0.6s linear infinite;
}
.loader .loader-circle:after {
  content: ""; position: absolute; top: 15px; left: 15px; right: 15px; bottom: 15px;
  border-radius: 50%; border: 3px solid transparent; border-top-color: #d3d3d3;
  animation: spin 1s linear infinite;
}
.loader .loader-text {
  display: flex; flex-direction: column; align-items: center; color: #fff;
  z-index: 2; margin-top: 40px; font-size: 24px;
}
.loader .loader-text .tip { margin-top: 6px; font-size: 18px; opacity: 0.6; }
.loader-section { position: fixed; top: 0; width: 51%; height: 100%; background: #333; z-index: 1; }
.loader-section.section-left { left: 0; }
.loader-section.section-right { right: 0; }
#loader-wrapper.loaded {
  visibility: hidden; transform: translateY(-100%);
  transition: transform 0.3s 1s ease-out, visibility 0.3s 1s ease-out;
}
#loader-wrapper.loaded .loader .loader-circle,
#loader-wrapper.loaded .loader .loader-text { opacity: 0; transition: opacity 0.3s ease-out; }
#loader-wrapper.loaded .loader-section.section-left { transform: translateX(-100%); transition: transform 0.5s 0.3s cubic-bezier(0.645, 0.045, 0.355, 1); }
#loader-wrapper.loaded .loader-section.section-right { transform: translateX(100%); transition: transform 0.5s 0.3s cubic-bezier(0.645, 0.045, 0.355, 1); }
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
@keyframes spin-reverse { 0% { transform: rotate(0deg); } 100% { transform: rotate(-360deg); } }
</style>
</head>
<body>
 <!-- 消息多选操作栏（转发/汇出） -->
 <div id="msgSelectBar" style="display:none">
   <span class="msel-count" id="msgSelectCount"><?php echo t('msel_count','已选 0 条');?></span>
   <button class="bsm" id="msgSelectForwardBtn" onclick="forwardSelected()"><?php echo t('menu_forward');?></button>
   <button class="bsm" id="msgSelectExportBtn" onclick="exportSelected()"><?php echo t('msel_export');?></button>
   <button class="bsm" onclick="cancelMsgSelect()"><?php echo t('btn_cancel');?></button>
 </div>
 <!-- 加载动画 -->
 <div id="loader-wrapper">
   <div class="loader">
     <div class="loader-circle"></div>
     <div class="loader-text">
       <span class="name"><?php echo t('enterchat_loading_pagename');?></span>
       <span class="tip"><?php echo t('enterchat_loading_loadstr');?></span>
     </div>
   </div>
   <div class="loader-section section-left"></div>
   <div class="loader-section section-right"></div>
 </div>
 <div class="sidebar">
   <div class="sidebar-profile" id="sidebarProfile">
    <div class="sa" id="sidebarAvatar" onclick="openMyProfile()" title="<?php echo t('btn_view_profile','View Profile');?>" style="cursor:pointer"><?php if($currentUser['avatar']):?><img src="<?php echo htmlspecialchars(chatapp_avatar_url($currentUser['avatar'] ?? '', $currentUser['username'] ?? '', (int)($currentUser['user_id'] ?? 0)));?>"><?php endif;?></div>
   <div class="sun" id="sidebarName"><?php echo chatapp_display_name($currentUser);?></div>
   <a class="sdnd <?php echo ($currentUser['restricted'] ?? 0) ? 'rstr' : (($currentUser['dnd'] ?? 0) ? 'dnd' : 'on'); ?>" id="dndToggle" onclick="<?php echo ($currentUser['restricted'] ?? 0) ? '' : 'toggleDnd()'; ?>" style="<?php echo ($currentUser['restricted'] ?? 0) ? 'cursor:default' : ''; ?>"><?php echo ($currentUser['restricted'] ?? 0) ? t('admin_restricted_status') : (($currentUser['dnd'] ?? 0) ? t('msg_dnd_status') : t('msg_online_status')); ?></a>
  </div>
  <div class="sidebar-nav" id="sidebarNavDefault">
   <div class="ng">
    <div class="ngh" onclick="toggleGroup('contactsGroup')"><span><?php echo t('title_contacts');?></span><span class="ar op" id="arrow-contactsGroup">&#9654;</span></div>
    <div class="ngb op" id="body-contactsGroup">
     <div class="csi<?php echo (!empty($currentUser['pin_self'])) ? ' pinned' : ''; ?>" onclick="openDm('<?php echo htmlspecialchars($currentUser['username']);?>')" oncontextmenu="openUserCtxMenu(event,'<?php echo htmlspecialchars($currentUser['username']);?>')"><div class="ca" id="contactSelfAvatar"><?php if($currentUser['avatar']):?><img src="<?php echo htmlspecialchars(chatapp_avatar_url($currentUser['avatar'] ?? '', $currentUser['username'] ?? '', (int)($currentUser['user_id'] ?? 0)));?>"><?php endif;?></div><div class="cn"><?php echo chatapp_display_name($currentUser);?> <?php echo t('msg_online');?><span id="selfPinMark" style="display:<?php echo (!empty($currentUser['pin_self'])) ? 'inline' : 'none'; ?>;margin-left:4px"><img src="../../data/res/cil/cil-pin.svg" style="width:11px;height:11px;vertical-align:-1px"></span></div></div>
     <div id="friendContacts"></div>
     <div id="pendingBadge" style="display:none"><div class="na" onclick="togglePendingSidebar()" style="color:#e0a040"><?php echo t('msg_friend_requests');?> (<span id="pendingCount">0</span>)</div></div>
     <div id="pendingList" style="display:none"></div>
     <div class="na" onclick="toggleAddContact()"><?php echo t('btn_add_contact');?></div>
     <div id="addContactBox" style="display:none"><div class="sbox"><input type="text" id="searchInput" placeholder="<?php echo t('label_search_username');?>" oninput="searchUsers()" autocomplete="off"></div><div class="sr" id="searchResults"></div></div>
    </div>
   </div>
   <div class="ng">
    <div class="ngh" onclick="toggleGroup('groupsGroup')"><span><?php echo t('title_groups', 'Groups');?></span><span class="ar" id="arrow-groupsGroup">&#9654;</span></div>
    <div class="ngb" id="body-groupsGroup">
     <div id="myGroups"></div>
     <div class="na" onclick="showCreateGroupModal()"><?php echo t('sb_create_group');?></div>
     <div class="na" onclick="showJoinGroupModal()"><?php echo t('sb_join_group');?></div>
    </div>
   </div>
   <div class="ng"><div class="ngh" onclick="switchPanel('requests')" style="cursor:pointer"><span><?php echo t('title_pending_requests');?></span><span class="ngh-badge" id="reqBadge" style="display:none">0</span></div></div>
   <div class="ng"><div class="ngh" onclick="switchPanel('search')" style="cursor:pointer"><span><?php echo t('title_search');?></span></div></div>
   <div class="ng">
    <div class="ngh" onclick="toggleGroup('appsGroup')"><span><?php echo t('title_apps');?></span><span class="ar" id="arrow-appsGroup">&#9654;</span></div>
    <div class="ngb" id="body-appsGroup">
     <div class="na" onclick="switchPanel('music')"><?php echo t('label_music_player');?></div>
     <div class="na" onclick="switchPanel('dscview')"><?php echo t('sb_dscview');?></div>
     <div class="na" onclick="switchPanel('midi')"><?php echo t('sb_midi');?></div>
     <div class="na" onclick="switchPanel('proxy')"><?php echo t('sb_proxy');?></div>
     <?php if($isRoot):?><div class="na" onclick="switchPanel('filemgr')"><?php echo t('sb_filemgr');?></div><?php endif;?>
     <div class="na" onclick="switchPanel('spessasynth')"><?php echo t('sb_spessasynth');?></div>
     <div class="na" onclick="confirmExternal('https://sites.google.com/wingkwong.edu.hk/eng/lesson-materials')"><?php echo t('sb_school_res');?></div>
     <div class="na" onclick="confirmExternal('https://www.hkeaa.edu.hk/en/')"><?php echo t('sb_hkeaa');?></div>
    </div>
   </div>
   <div class="ng"><div class="ngh" onclick="switchPanel('public-emoji')" style="cursor:pointer"><span><?php echo t('title_public_emoji');?></span></div></div>
   <div class="ng"><div class="ngh" onclick="switchPanel('announcements')" style="cursor:pointer"><span><?php echo t('title_announcements');?></span></div></div>
   <?php if($isAdmin):?>
   <div class="ng"><div class="ngh" onclick="switchPanel('reports')" style="cursor:pointer"><span><?php echo t('title_report_incidents');?></span><span class="ngh-badge" id="repBadge" style="display:none">0</span></div></div>
   <div class="ng"><div class="ngh" onclick="switchPanel('users')" style="cursor:pointer"><span><?php echo t('title_all_users');?></span></div></div>
   <?php endif;?>
   <div class="ng"><div class="ngh" onclick="switchPanel('support')" style="cursor:pointer;<?php echo ($currentUser['restricted']??0)?'background:#3a2a1e;border-left:3px solid #e0a040;':''?>"><?php if ($isAdmin): ?><span><?php echo t('title_support');?> <?php echo ($currentUser['restricted']??0)?'<img src="../../data/res/cil/cil-warning.svg" style="width:13px;height:13px;vertical-align:-2px;margin-left:3px">':' ';?><span id="supAdminCount" style="color:#e0a040;font-weight:700">(0+0+0)</span></span><?php else: ?><span><?php echo t('title_support');?> <?php echo ($currentUser['restricted']??0)?'<img src="../../data/res/cil/cil-warning.svg" style="width:13px;height:13px;vertical-align:-2px;margin-left:3px">':' ';?></span><span class="ngh-badge sup-badge" id="supBadge" style="display:none">0</span><?php endif; ?></div></div>
   <?php if($isAdmin):?>
   <div class="ng"><div class="ngh" onclick="switchPanel('logs')" style="cursor:pointer"><span><?php echo t('sb_logs');?></span></div></div>
   <?php endif;?>
   <?php if($isRoot):?>
   <div class="ng"><div class="ngh" onclick="switchPanel('dbadmin')" style="cursor:pointer"><span><?php echo t('sb_dbadmin');?></span></div></div>
   <div class="ng"><div class="ngh" onclick="switchPanel('wssettings');loadWssSettings()" style="cursor:pointer"><span>WebSocket Settings</span></div></div>
   <div class="ng"><div class="ngh" onclick="switchPanel('oobe')" style="cursor:pointer"><span>OOBE 引导</span></div></div>
   <div class="ng"><div class="ngh" onclick="location.href='/maintenance/portal.php'" style="cursor:pointer"><span><?php echo svg_ic('wrench', 14);?> 维护门户</span></div></div>
   <?php endif;?>
   <div class="ng"><div class="ngh" onclick="switchPanel('level')" style="cursor:pointer"><span><?php echo t('title_level');?></span></div></div>
   <div class="ng"><div class="ngh" onclick="openSettings()" style="cursor:pointer"><span><?php echo t('title_settings');?></span></div></div>
   <div class="ng"><div class="ngh" onclick="switchPanel('profile-mgmt')" style="cursor:pointer"><span><?php echo t('title_profile_mgmt');?></span></div></div>
   <div class="ng">
    <div class="ngh" onclick="toggleGroup('moreGroup')"><span><?php echo t('title_more');?></span><span class="ar" id="arrow-moreGroup">&#9654;</span></div>
    <div class="ngb" id="body-moreGroup">
     <div class="na" onclick="switchPanel('donations')"><?php echo t('sb_donations');?></div>
    </div>
   </div>
  </div>
  <div class="sidebar-nav" id="sidebarNavUser" style="display:none"></div>
  <div class="sidebar-footer"><div class="ngh" onclick="switchPanel('space')" style="cursor:pointer;display:block;color:#8fb6d9"><span><?php echo t('m_space', '个人空间');?></span></div><a class="ngh" href="m.php" style="cursor:pointer;display:block;color:#8fb6d9"><span><?php echo t('m_switch_mobile', '手机版');?></span></a><div class="ngh" id="logoutLink" onclick="logout()" style="cursor:pointer"><span><?php echo isset($_SESSION['admin_username']) ? t('sb_back_all_users') : t('title_logout');?></span></div></div>
 </div>

<div class="main-content">
 <!-- 个人空间（内嵌 iframe） -->
 <div class="panel" id="panel-space">
  <div class="music-frame" style="height:100%"><iframe id="spaceFrame" data-src="space.php?embed=1"></iframe></div>
 </div>
 <div class="panel" id="panel-announcements">
  <div class="ch"><h2><?php echo t('title_announcements');?></h2></div>
  <div class="ma" id="messagesArea"><div class="es"><p><?php echo t('msg_no_announcements');?></p></div></div>
  <?php if(chatapp_has_permission($currentUser['user_id']??0, 'announcements.send') && !($currentUser['restricted']??0)):?>
  <div class="upload-progress" id="uploadProgress"><div></div></div>
  <div class="md-preview" id="mdPreviewAnn"></div>
  <div class="cia"><textarea id="messageInput" oninput="autoResize(this);onMdInput('mdPreviewAnn','messageInput','mdCheckAnn')" placeholder="<?php echo t('label_type_announcement');?>" maxlength="32767" rows="1" style="resize:none;overflow-y:auto;line-height:1.4;max-height:20em"></textarea><input type="file" id="mediaFile" multiple style="display:none" onchange="mediaFilesChosen(this,'ann')"><button class="bsm" onclick="toggleEmojiPicker(event,'messageInput')" title="Emoji"><img src="../../data/res/cil/cil-smile.svg" style="width:16px;height:16px;vertical-align:-3px"></button><button class="bsm" onclick="toggleFlashMenu(event,this)" title="Attach"><img src="../../data/res/cil/cil-folder.svg" style="width:16px;height:16px;vertical-align:-3px"></button><button class="bsm" onclick="togglePenMenu(event,this)" title="Doodle / Live Draw"><img src="../../data/res/cil/cil-pen.svg" style="width:16px;height:16px;vertical-align:-3px"></button><input type="file" id="flashMediaFile" multiple style="display:none" onchange="flashFileChosen(this,'announcement')"><label class="md-check"><input type="checkbox" id="mdCheckAnn" onchange="onMdInput('mdPreviewAnn','messageInput','mdCheckAnn')"> Markdown</label><button class="bs" id="sendBtn" onclick="sendAnnouncement()"><?php echo t('btn_send');?></button></div>
  <?php else:?>
  <div class="cia" style="justify-content:center;color:#666;font-size:.82em;padding:14px 20px"><?php echo t('msg_read_only');?></div>
  <?php endif;?>
 </div>

 <div class="panel" id="panel-dm">
  <div class="ch"><h2 id="dmTitle"><?php echo t('title_chat');?></h2><span id="dmE2eeBadge" class="dm-e2ee-badge" style="display:none"></span><div class="dm-options-wrap"><button class="bsm" onclick="toggleDmOptions(event)"><?php echo t('btn_options');?></button><div class="dm-options-menu" id="dmOptionsMenu"><button class="grp-opt" onclick="openGroupInfo()"><?php echo t('g_view_group');?></button><button class="grp-opt" id="grpPinBtn" onclick="togglePinGroup()"><?php echo t('d_pin');?></button><button class="dm-opt" onclick="viewDmProfile()"><?php echo t('btn_view_profile');?></button><button class="dm-opt" id="dmE2eeBtn" onclick="toggleDmE2ee()"><?php echo t('opt_e2ee');?></button><button class="dm-opt" onclick="openSafetyVerify()"><?php echo t('opt_safety_verify');?></button><button class="dm-opt" onclick="startVoiceCall()"><img src="../../data/res/svg/phone_24.svg" width="15" style="vertical-align:-2px"> <?php echo t('opt_voice_call');?></button><button class="dm-opt" onclick="startVideoCall()"><img src="../../data/res/svg/video_24.svg" width="15" style="vertical-align:-2px"> <?php echo t('opt_video_call');?></button><button class="dm-opt" onclick="startStandaloneShare()"><img src="../../data/res/svg/share_screen_24.svg" width="15" style="vertical-align:-2px"> <?php echo t('opt_share_screen');?></button><button class="dm-opt" onclick="reportDmUser()"><?php echo t('btn_report_user');?></button><button class="dm-opt" onclick="openDmSearch()"><?php echo t('d_search_history');?></button><button class="dm-opt" onclick="changeNickname()"><?php echo t('d_change_nickname');?></button><button class="dm-opt" id="dmReloadBtn" onclick="reloadDmClient()"><?php echo t('opt_reload_client');?></button><button class="dm-opt" id="dmPinBtn" onclick="togglePinContact()"><?php echo t('d_pin');?></button><button class="dm-opt danger" onclick="deleteDmContact()"><?php echo t('btn_delete_contact');?></button></div></div></div>
  <div class="ma" id="dmMessagesArea"><div class="es"><p><?php echo t('msg_select_contact');?></p></div></div>
  <div class="typing-indicator" id="typingIndicator"></div>
  <div class="upload-progress" id="dmUploadProgress"><div></div></div>
  <div class="md-preview" id="mdPreviewDm"></div>
  <div class="reply-bar" id="replyBar" style="display:none"><span id="replyBarText"></span><button class="bsm" onclick="cancelReply()">&#x2715;</button></div>
  <div class="rec-bar" id="dmRecBar" style="display:none"><span class="rec-dot"></span><span>录音中</span><span id="dmRecTimer">0:00</span><button class="bsm" onclick="cancelVoiceRec()">&#x2715; 取消</button></div>
  <div class="cia"><textarea id="dmMessageInput" oninput="autoResize(this);onDmInput();onMdInput('mdPreviewDm','dmMessageInput','mdCheckDm')" placeholder="<?php echo t('label_type_message');?>" maxlength="32767" rows="1" style="resize:none;overflow-y:auto;line-height:1.4;max-height:20em"></textarea><input type="file" id="dmMediaFile" multiple style="display:none" onchange="mediaFilesChosen(this,'dm')"><button class="bsm" id="dmEmojiBtn" onclick="toggleEmojiPicker(event,'dmMessageInput')" title="Emoji"><img src="../../data/res/svg/expression_24.svg" width="16" style="vertical-align:-2px"></button><button class="bsm nine-hide" onclick="toggleFlashMenu(event,this)" title="Attach"><img src="../../data/res/svg/folder_24.svg" width="16" style="vertical-align:-2px"></button><button class="bsm nine-hide" onclick="togglePenMenu(event,this)" title="Doodle / Live Draw"><img src="../../data/res/svg/brush_24.svg" width="16" style="vertical-align:-2px"></button><button class="bsm" id="dmNineBtn" onclick="toggleDmNineMenu(event,this)" title="更多"><svg width="16" height="16" viewBox="0 0 24 24" fill="#ccc"><circle cx="5" cy="5" r="1.8"/><circle cx="12" cy="5" r="1.8"/><circle cx="19" cy="5" r="1.8"/><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/><circle cx="5" cy="19" r="1.8"/><circle cx="12" cy="19" r="1.8"/><circle cx="19" cy="19" r="1.8"/></svg></button><button class="bsm ime-toggle" id="imeToggle" type="button" title="拼音输入开关">EN</button><button class="bsm nine-hide" id="dmMicBtn" onclick="toggleVoiceRec()" title="语音消息"><img src="../../data/res/svg/microphone_on_24.svg" width="16" style="vertical-align:-2px"></button><input type="file" id="flashMediaFileDm" multiple style="display:none" onchange="flashFileChosen(this,'dm')"><label class="md-check"><input type="checkbox" id="mdCheckDm" onchange="onMdInput('mdPreviewDm','dmMessageInput','mdCheckDm')"> Markdown</label><button class="bs" id="dmSendBtn" onclick="sendDmMessage()"><?php echo t('btn_send');?></button></div>
  <div class="nine-menu" id="dmNineMenu" style="display:none">
    <div class="nine-cell" onclick="nineEmoji()"><img src="../../data/res/svg/expression_24.svg" alt=""><span><?php echo t('nine_emoji');?></span></div>
    <div class="nine-cell" onclick="nineFlash()"><img src="../../data/res/svg/fast_folder_16.svg" alt=""><span><?php echo t('nine_flash');?></span></div>
    <div class="nine-cell" onclick="nineUpload()"><img src="../../data/res/svg/folder_16.svg" alt=""><span><?php echo t('nine_upload');?></span></div>
    <div class="nine-cell" onclick="ninePen()"><img src="../../data/res/svg/brush_24.svg" alt=""><span><?php echo t('nine_pen');?></span></div>
    <div class="nine-cell" onclick="nineVoice()"><img src="../../data/res/svg/microphone_on_24.svg" alt=""><span><?php echo t('nine_voice_msg');?></span></div>
    <div class="nine-cell" onclick="nineMy()"><img src="../../data/res/svg/folder_16.svg" alt=""><span><?php echo t('nine_my');?></span></div>
    <div class="nine-cell" onclick="nineShare()"><img src="../../data/res/svg/share_screen_24.svg" alt=""><span><?php echo t('nine_share_screen');?></span></div>
    <div class="nine-cell" onclick="nineVoiceCall()"><img src="../../data/res/svg/phone_24.svg" alt=""><span><?php echo t('nine_voice_call');?></span></div>
    <div class="nine-cell" onclick="nineVideoCall()"><img src="../../data/res/svg/video_24.svg" alt=""><span><?php echo t('nine_video_call');?></span></div>
  </div>
 </div>

 <?php if($isAdmin):?>
 <div class="panel" id="panel-reports">
  <div class="ch"><h2><?php echo t('title_report_incidents');?></h2></div>
  <div class="ma" id="reportsArea"><div class="es"><p><?php echo t('msg_loading');?></p></div></div>
 </div>
 <div class="panel" id="panel-users">
  <div class="ch"><h2><?php echo t('title_all_users');?></h2><div class="support-tabs" style="display:inline-block;margin-left:16px"><button class="active" id="usrTabBtn" onclick="showUsersSubTab()">Users</button><button id="roleTabBtn" onclick="showRolesSubTab()">Roles</button></div></div>
  <div id="usersSubTab">
  <div class="adm-toolbar">
   <input type="text" id="admSearch" placeholder="<?php echo t('admin_search_username');?>">
   <button onclick="adminList(1)"><?php echo t('btn_search');?></button>
   <label><input type="checkbox" id="admRegex" onchange="admRegexToggled()"> Regex</label>
   <label><input type="checkbox" id="admDeleted" onchange="admDeletedToggled()"> Show deleted</label>
   <button onclick="showAddUserModal()"><?php echo t('admin_add_user');?></button>
   <button onclick="showAddPlaceholderModal()"><?php echo t('admin_add_placeholder');?></button>
   <button onclick="document.getElementById('admSearch').value='';document.getElementById('admRegex').checked=false;adminList(1)" style="margin-left:auto"><?php echo t('btn_clear');?></button>
  </div>
  <div class="adm-table-wrap">
   <table>
    <thead><tr><th class="adm-sortable" onclick="adminSort('username')"><?php echo t('label_username');?></th><th class="adm-sortable" onclick="adminSort('user_id')">UID</th><th class="adm-sortable" onclick="adminSort('status')"><?php echo t('admin_status');?></th><th class="adm-sortable" onclick="adminSort('last_login')"><?php echo t('admin_last_login');?></th><th class="adm-sortable" onclick="adminSort('created_at')"><?php echo t('admin_created');?></th></tr></thead>
    <tbody id="admTable"><tr><td colspan="4" style="text-align:center;color:#555"><?php echo t('msg_loading');?></td></tr></tbody>
   </table>
  </div>
  <div class="srch-pagination" id="admPagination"><span id="admInfo"></span><span id="admBtns"></span></div>
  </div>
  <div id="rolesSubTab" style="display:none">
   <div class="adm-toolbar"><button onclick="showAddRoleModal()">+ Add Role</button></div>
   <div class="adm-table-wrap"><table><thead><tr><th>Role</th><th>Editable</th><th>Permissions</th><th>Actions</th></tr></thead><tbody id="roleTable"><tr><td colspan="4" style="text-align:center;color:#555">Loading...</td></tr></tbody></table></div>
  </div>
 </div>
 <?php endif;?>

 <div class="panel" id="panel-support">
  <div class="ch"><h2><?php echo t('title_support');?></h2></div>
  <div class="support-tabs" id="supportTabs">
   <button class="active" onclick="loadSupportTickets('open')"><?php echo t('btn_open_tickets');?></button>
   <button onclick="loadSupportTickets('closed')"><?php echo t('btn_closed_tickets');?></button>
  </div>
  <div class="support-bar">
   <input type="text" id="supSearch" placeholder="Filter by subject or ID...">
   <button class="bsm" onclick="loadSupportTickets(supTab)">Search</button>
   <select style="padding:6px 8px;background:#1e1e1e;border:1px solid #444;color:#ccc;font-family:inherit;font-size:.8em;margin-left:8px" onchange="changeSupPerPage(this.value)">
    <option value="10" selected>10</option>
    <option value="16">16</option>
    <option value="5">5</option>
   </select>
   <button class="bsm" onclick="showCreateTicket()" style="background:<?php echo ($currentUser['restricted']??0)?'#e08a20':'#2a4a2a';?>;border-color:<?php echo ($currentUser['restricted']??0)?'#e0a040':'#3a6a3a';?>;font-weight:<?php echo ($currentUser['restricted']??0)?'bold':'normal';?>;animation:<?php echo ($currentUser['restricted']??0)?'pulse 1.5s infinite':'none';?>">+ <?php echo t('title_create_ticket');?></button>
  </div>
  <div class="ma" id="supportList"><div class="es"><p><?php echo t('msg_loading');?></p></div></div>
  <div class="support-pagination" id="supPagination"><span id="supInfo"></span><span id="supBtns"></span></div>
 </div>

 <div class="panel" id="panel-donations">
  <div class="ch"><h2>Donations</h2></div>
  <div class="don-toolbar" style="display:flex;gap:8px;padding:10px 12px;align-items:center;flex-wrap:wrap">
   <button onclick="showAddDonationModal()" style="background:#2a4a2a;border:1px solid #3a6a3a;color:#e0e0e0;padding:6px 14px;font-size:.8em;cursor:pointer;font-family:inherit">+ Add</button>
  </div>
  <div class="don-table-wrap" style="overflow-x:auto">
   <table style="width:100%;border-collapse:collapse;font-size:.8em">
    <thead><tr style="background:#252525">
     <th style="padding:8px 12px;text-align:left;border-bottom:1px solid #444">#</th>
     <th style="padding:8px 12px;text-align:left;border-bottom:1px solid #444">DateTime</th>
     <th style="padding:8px 12px;text-align:left;border-bottom:1px solid #444">UID</th>
     <th style="padding:8px 12px;text-align:left;border-bottom:1px solid #444">User</th>
     <th style="padding:8px 12px;text-align:left;border-bottom:1px solid #444">DisplayName</th>
     <th style="padding:8px 12px;text-align:left;border-bottom:1px solid #444">WeixinID</th>
     <th style="padding:8px 12px;text-align:left;border-bottom:1px solid #444">QQ</th>
     <th style="padding:8px 12px;text-align:left;border-bottom:1px solid #444">Actions</th>
    </tr></thead>
    <tbody id="donationsTable"><tr><td colspan="8" style="text-align:center;color:#555;padding:12px">Loading...</td></tr></tbody>
   </table>
  </div>
  <div class="srch-pagination" id="donPagination"><span id="donInfo"></span><span id="donBtns"></span></div>
 </div>

 <div class="panel" id="panel-music">
  <div class="music-frame"><iframe id="playerFrame" data-src="../../apps/music/index.html"></iframe></div>
 </div>

 <div class="panel" id="panel-dscview">
  <div class="music-frame"><iframe data-src="../../apps/dscview/index.php"></iframe></div>
 </div>

 <div class="panel" id="panel-midi">
  <div class="music-frame"><iframe data-src="../../apps/midi_obfuscation/index.php"></iframe></div>
 </div>

 <div class="panel" id="panel-proxy">
  <div class="music-frame"><iframe data-src="../../apps/proxy/index.php"></iframe></div>
 </div>

 <!-- SpessaSynth 官方在线 MIDI 播放器（iframe 嵌入，允许 Web MIDI） -->
 <div class="panel" id="panel-spessasynth">
  <div class="music-frame"><iframe data-src="https://spessasus.github.io/SpessaSynth/" allow="midi"></iframe></div>
 </div>

 <?php if($isRoot):?>
 <!-- 文件管理器 (root only) -->
 <div class="panel" id="panel-filemgr">
  <div class="music-frame"><iframe data-src="../../apps/filemgr/index.php"></iframe></div>
 </div>
 <?php endif;?>

 <?php if($isRoot):?>
 <!-- Database Admin (root only) -->
 <div class="panel" id="panel-dbadmin">
  <div class="ch"><h2>数据库管理</h2><span style="color:#e0a040;font-size:.75em;margin-left:12px">Root Only</span></div>
  <div class="db-toolbar" style="display:flex;flex-wrap:wrap;gap:8px;padding:10px 12px;align-items:center">
   <select id="dbTableSelect" style="padding:6px 10px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit;font-size:.8em;min-width:180px" onchange="dbShowTable()">
    <option value="">-- 选择表 --</option>
   </select>
   <button class="bsm" onclick="dbShowTable()" style="background:#2a4a2a;border-color:#3a6a3a">查看结构</button>
   <button class="bsm" onclick="dbExport()" style="background:#3a3a2a;border-color:#5a5a3a">导出 .sql</button>
  </div>
  <div id="dbStructure" style="overflow-x:auto;margin:0 12px;font-size:.72em;color:#ccc;display:none">
   <div class="db-info" id="dbTableInfo" style="margin-bottom:8px;color:#aaa"></div>
   <pre id="dbCreateSQL" style="background:#111;padding:10px;border:1px solid #333;white-space:pre-wrap;word-break:break-all;max-height:300px;overflow-y:auto"></pre>
   <table style="width:100%;border-collapse:collapse;margin-top:8px">
    <thead><tr style="background:#252525"><th style="padding:4px 8px;text-align:left;border-bottom:1px solid #444">Field</th><th style="padding:4px 8px;text-align:left;border-bottom:1px solid #444">Type</th><th style="padding:4px 8px;text-align:left;border-bottom:1px solid #444">Null</th><th style="padding:4px 8px;text-align:left;border-bottom:1px solid #444">Key</th><th style="padding:4px 8px;text-align:left;border-bottom:1px solid #444">Default</th><th style="padding:4px 8px;text-align:left;border-bottom:1px solid #444">Extra</th></tr></thead>
    <tbody id="dbColumns"></tbody>
   </table>
  </div>
  <div class="db-query-area" style="padding:10px 12px">
   <div style="margin-bottom:6px;font-size:.75em;color:#888">仅允许 SELECT / SHOW / DESCRIBE / EXPLAIN 查询</div>
   <textarea id="dbQueryInput" style="width:100%;padding:8px;background:#111;border:1px solid #444;color:#e0e0e0;font-family:monospace;font-size:.8em;resize:vertical;min-height:60px" placeholder="SELECT * FROM users LIMIT 10"></textarea>
   <div style="margin-top:6px;display:flex;gap:8px;align-items:center">
    <button class="bsm" onclick="dbRunQuery()" style="background:#2a4a2a;border-color:#3a6a3a">执行</button>
    <span id="dbQueryStatus" style="color:#888;font-size:.72em"></span>
   </div>
  </div>
  <div style="overflow-x:auto;margin:0 12px 12px">
   <table style="width:100%;border-collapse:collapse;font-size:.72em;display:none" id="dbResultTable">
    <thead id="dbResultHead"></thead>
    <tbody id="dbResultBody"></tbody>
   </table>
  </div>
 </div>
 <?php endif;?>

 <?php if($isRoot):?>
 <div class="panel" id="panel-wssettings">
  <div class="ch"><h2>WebSocket Settings</h2><span style="color:#e0a040;font-size:.75em;margin-left:12px">Root Only</span></div>
  <div style="padding:12px">
   <div style="font-size:.75em;color:#888;margin-bottom:10px">三个通讯模式分别填地址（host:port 或完整 ws:// / wss:// URL）。前端按当前访问来源自动选择：localhost 走「本地」，私网 IP 走「私网」，公网域名走「公网」。留空 = 该模式不启用。</div>
   <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:10px">
    <div style="display:flex;gap:8px;align-items:flex-end"><span style="width:64px;font-size:.78em;color:#ccc;padding-bottom:8px;white-space:nowrap"><?php echo svg_ic('monitor', 13);?> 本地</span><div class="uinput" style="flex:1;min-width:0"><input type="text" id="wssLocalInput" placeholder="127.0.0.1:9090"></div></div>
    <div style="display:flex;gap:8px;align-items:flex-end"><span style="width:64px;font-size:.78em;color:#ccc;padding-bottom:8px;white-space:nowrap"><?php echo svg_ic('home', 13);?> 私网</span><div class="uinput" style="flex:1;min-width:0"><input type="text" id="wssPrivateInput" placeholder="0.0.0.0:9090"></div></div>
    <div style="display:flex;gap:8px;align-items:flex-end"><span style="width:64px;font-size:.78em;color:#ccc;padding-bottom:8px;white-space:nowrap"><?php echo svg_ic('globe', 13);?> 公网</span><div class="uinput" style="flex:1;min-width:0"><input type="text" id="wssPublicInput" placeholder="wss://wss.lqx211.com"></div></div>
   </div>
   <div style="display:flex;gap:8px;align-items:center">
    <button type="button" class="bsm" onclick="saveWssSettings()" style="background:#2a4a2a;border-color:#3a6a3a">保存</button>
    <span style="font-size:.72em;color:#aaa" id="wssActiveMode"></span>
   </div>
   <div style="margin-top:6px;font-size:.72em;color:#aaa" id="wssSaveStatus"></div>
  </div>
 </div>
 <div class="panel" id="panel-oobe">
  <div class="ch"><h2>OOBE 首次引导</h2><span style="color:#e0a040;font-size:.75em;margin-left:12px">Root Only</span></div>
  <div style="padding:12px">
   <div style="font-size:.75em;color:#888;margin-bottom:10px">重新运行首次配置引导（语言 / 功能导览 / 安全初始化）。幂等操作，不会改动或删除任何数据。</div>
   <button class="bsm" onclick="rerunOobe()" style="background:#4a3a2a;border-color:#5a4a3a">重新运行 OOBE</button>
  </div>
 </div>
 <?php endif;?>

 <?php if($isAdmin):?>
 <div class="panel" id="panel-logs">
  <div class="ch"><h2>Logs</h2></div>
  <div class="support-tabs">
   <button class="active" onclick="loadAdminLogs(1)">Admin Logs</button>
   <button onclick="loadLoginLogs(1)">Login Logs</button>
   <button onclick="loadExpLogs(1)">Exp Logs</button>
  </div>
  <div class="support-bar">
   <input type="text" id="logSearch" placeholder="Filter...">
   <button class="bsm" onclick="loadLogs(1)">Search</button>
  </div>
  <div class="adm-table-wrap" style="max-height:70vh;overflow-y:auto">
   <table style="width:100%;border-collapse:collapse;font-size:.75em">
    <thead><tr style="background:#252525">
     <th style="padding:6px 10px;text-align:left;border-bottom:1px solid #444">Time</th>
     <th style="padding:6px 10px;text-align:left;border-bottom:1px solid #444">Admin</th>
     <th style="padding:6px 10px;text-align:left;border-bottom:1px solid #444">Action</th>
     <th style="padding:6px 10px;text-align:left;border-bottom:1px solid #444">Target</th>
     <th style="padding:6px 10px;text-align:left;border-bottom:1px solid #444">Details</th>
     <th style="padding:6px 10px;text-align:left;border-bottom:1px solid #444">IP</th>
    </tr></thead>
    <tbody id="logsTable"><tr><td colspan="6" style="text-align:center;color:#555;padding:12px">Loading...</td></tr></tbody>
   </table>
  </div>
   <div class="srch-pagination" id="logPagination"><span id="logInfo"></span><span id="logBtns"></span></div>
 </div>
 <?php endif;?>

 <div class="panel" id="panel-search">
  <div class="ch"><h2><?php echo t('title_search');?></h2><div class="support-tabs"><button class="active" id="searchTabUsers" onclick="showSearchTab('users')">Users</button><button id="searchTabMsgs" onclick="showSearchTab('messages')">Messages</button></div></div>
  <div id="searchSubTabUsers">
   <div class="srch-toolbar">
    <input type="text" id="discoverSearch" placeholder="<?php echo t('label_search_users');?>">
    <button onclick="discoverUsers(1)"><?php echo t('btn_search');?></button>
    <button onclick="document.getElementById('discoverSearch').value='';discoverUsers(1)"><?php echo t('btn_clear');?></button>
   </div>
   <div class="srch-table"><table><thead><tr><th>UID</th><th>User</th><th>Display Name</th><th></th></tr></thead>
     <tbody id="discoverTable"><tr><td colspan="4" style="text-align:center;color:#555"><?php echo t('msg_loading');?></td></tr></tbody></table></div>
   <div class="srch-pagination" id="discoverPagination"><span id="discoverInfo"></span><span id="discoverBtns"></span></div>
  </div>
  <div id="searchSubTabMsgs" style="display:none">
   <div class="srch-toolbar">
    <input type="text" id="msgSearchInput" placeholder="Search messages..." onkeydown="if(event.key==='Enter')searchMessages(1)">
    <button onclick="searchMessages(1)"><?php echo t('btn_search');?></button>
    <button onclick="document.getElementById('msgSearchInput').value='';document.getElementById('msgSearchResults').innerHTML=''"><?php echo t('btn_clear');?></button>
   </div>
   <div class="srch-table" id="msgSearchResultsWrap" style="flex:1;overflow-y:auto;padding:0 20px 20px">
    <div id="msgSearchResults" style="padding:0"></div>
   </div>
   <div class="srch-pagination" id="msgSearchPagination" style="display:none"><span id="msgSearchInfo"></span><span id="msgSearchBtns"></span></div>
  </div>
  </div>

 <div class="panel" id="panel-public-emoji">
  <div class="ch"><h2><?php echo t('title_public_emoji');?></h2><span class="pes-stats" id="publicEmojiStats"></span><span class="pes-size-label"><?php echo t('label_show_size');?></span><select id="publicEmojiSize" class="pes-size-select" onchange="setPublicEmojiSize(this.value)"><option value="64">64x64</option><option value="96">96x96</option><option value="128">128x128</option></select><input type="file" id="publicEmojiFile" accept="image/*" multiple style="display:none" onchange="uploadPublicEmoji()"><button class="bsm" onclick="document.getElementById('publicEmojiFile').click()" style="background:#2a4a2a;border-color:#3a6a3a">+ <?php echo t('btn_upload_emoji');?></button></div>
  <div class="public-emoji-grid" id="publicEmojiGrid"><div class="es"><p><?php echo t('msg_loading');?></p></div></div>
  <div class="public-emoji-selected" id="publicEmojiSelected"></div>
 </div>

 <div class="panel" id="panel-requests">
  <div class="ch"><h2><?php echo t('title_pending_requests');?></h2></div>
  <div class="ma" id="reqArea"><div class="es"><p><?php echo t('msg_no_pending');?></p></div></div>
 </div>

 <div class="panel" id="panel-level">
  <div class="ch"><h2><?php echo t('title_level');?></h2><span class="lvl-sub" id="lvlRankInfo"></span></div>
  <div class="lvl-wrap">
   <div class="lvl-hero" id="lvlHero">
    <div class="lvl-badge" id="lvlBadge">Lv.1</div>
    <div class="lvl-hero-info">
     <div class="lvl-expbar"><div class="lvl-expfill" id="lvlExpFill" style="width:0%"></div></div>
     <div class="lvl-expmeta" id="lvlExpMeta">0 / 100</div>
     <div class="lvl-progress" id="lvlProgress">0%</div>
     <div class="lvl-upgrade-row" style="margin-top:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <button class="bs bsm" id="lvlUpgradeBtn" onclick="doUpgrade()" style="display:none;background:#2a4a2a;border-color:#3a6a3a;color:#e0e0e0"><?php echo t('btn_upgrade', '升级');?></button>
      <span class="lvl-upgrade-info" id="lvlUpgradeInfo" style="color:#888;font-size:.72em"></span>
     </div>
    </div>
   </div>
   <div class="lvl-section">
    <div class="lvl-sign-row">
     <button class="bs" id="lvlSignBtn" onclick="doSign()"><?php echo t('btn_sign_in');?></button>
     <span class="lvl-sign-info" id="lvlSignInfo"></span>
    </div>
   </div>
   <div class="lvl-section">
    <h3><?php echo t('title_level_limits');?></h3>
    <div class="lvl-limits" id="lvlLimits">
     <div class="lvl-limit"><span><?php echo t('label_max_attach');?></span><b id="lvlLimitAttach">8 MB</b></div>
     <div class="lvl-limit"><span><?php echo t('label_max_groups');?></span><b id="lvlLimitGroups">5</b></div>
     <div class="lvl-limit"><span><?php echo t('label_max_contacts');?></span><b id="lvlLimitContacts">100</b></div>
    </div>
   </div>
   <div class="lvl-section">
    <h3><?php echo t('title_exp_history');?></h3>
    <div class="lvl-history" id="lvlHistory"><div class="es"><p><?php echo t('msg_loading');?></p></div></div>
   </div>
   <div class="lvl-section">
    <h3><?php echo t('title_leaderboard');?></h3>
    <div class="lvl-board" id="lvlBoard"><div class="es"><p><?php echo t('msg_loading');?></p></div></div>
   </div>
  </div>
 </div>

 <div class="panel" id="panel-more">
  <div class="ch"><h2><?php echo t('title_settings');?></h2></div>
  <div class="sc">
   <?php if($isRoot):?>
   <div class="ss"><h3>Reload All Clients</h3>
     <p style="color:#888;font-size:.78em;margin-bottom:8px">强制所有在线客户端刷新（仅 Root）</p>
     <button class="bsm" onclick="reloadAllClients()" style="background:#4a2020;border-color:#5c2a2a;color:#e06060">Reload All Clients</button>
   </div>
   <?php endif;?>
   <div class="er" id="errorMsg"></div><div class="su" id="successMsg"></div>
   <div class="ss"><h3><?php echo t('title_preferred_language');?></h3><div class="fg"><select id="languageSelect" style="width:100%;max-width:300px;padding:8px 12px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.85em;font-family:inherit;outline:none"><option value="en"<?php echo ($currentUser['preferred_language']??'en')==='en'?' selected':'';?>><?php echo t('lang_en');?></option><option value="zh"<?php echo ($currentUser['preferred_language']??'en')==='zh'?' selected':'';?>><?php echo t('lang_zh');?></option><option value="zh_egg"<?php echo ($currentUser['preferred_language']??'en')==='zh_egg'?' selected':'';?>><?php echo t('lang_zh_egg');?></option><option value="wyw"<?php echo ($currentUser['preferred_language']??'en')==='wyw'?' selected':'';?>><?php echo t('lang_wyw');?></option><option value="raw"<?php echo ($currentUser['preferred_language']??'en')==='raw'?' selected':'';?>><?php echo t('lang_raw');?></option></select></div><button class="bsm" onclick="changeLanguage()"><?php echo t('btn_save');?></button></div>
   <div class="ss"><h3><?php echo t('title_display_name');?></h3><div class="fg"><label><?php echo t('msg_display_name_hint');?></label><input type="text" id="displayNameInput" maxlength="256" placeholder="<?php echo t('msg_leave_empty');?>" value="<?php echo htmlspecialchars($currentUser['display_name'] ?? '');?>"></div><button class="bsm" onclick="changeDisplayName()"><?php echo t('btn_save');?></button></div>
   <div class="ss"><h3><?php echo t('title_custom_title');?></h3><div class="fg" style="display:flex;align-items:center;gap:12px"><button class="bsm" id="customTitleBtn" onclick="toggleCustomTitle()" style="min-width:80px"><?php echo $customTitle ? t('btn_disable') : t('btn_enable');?></button><span id="customTitleStatus" style="color:#888;font-size:.78em"><?php echo $customTitle ? t('msg_custom_title_on') : t('msg_custom_title_off');?></span></div><div class="fg" id="customTitleField"<?php echo $customTitle ? '' : ' style="display:none"';?>><input type="text" id="customTitleInput" maxlength="100" placeholder="<?php echo t('label_custom_title_placeholder');?>" value="<?php echo htmlspecialchars($customTitle);?>" style="width:100%;max-width:300px"><button class="bsm" onclick="saveCustomTitle()" style="margin-top:6px"><?php echo t('btn_save');?></button></div></div>
   <div class="ss"><h3><?php echo t('title_privacy_settings');?></h3><div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="privacySearchable" <?php echo ($currentUser['searchable']??1)?'checked':'';?> style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_searchable_label');?></label></div><div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="privacySearchableByUid" <?php echo ($currentUser['searchable_by_uid']??1)?'checked':'';?> style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_searchable_uid_label');?></label></div><button class="bsm" onclick="savePrivacySettings()"><?php echo t('btn_save');?></button></div>
   <div class="ss"><h3><?php echo t('title_data_saver');?></h3><div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="dataSaver" <?php echo ($currentUser['data_saver']??0)?'checked':'';?> onchange="toggleDataSaver()" style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_data_saver_label');?></label></div></div>
   <div class="ss"><h3><?php echo t('title_auto_focus');?></h3><div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="autoFocusToggle" <?php echo ($currentUser['auto_focus_input']??1)?'checked':'';?> onchange="toggleAutoFocus()" style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_auto_focus_label');?></label></div></div>
    <div class="ss"><h3><?php echo t('title_local_cache');?></h3>
     <div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="localCacheToggle" <?php echo ($currentUser['local_cache_enabled']??0)?'checked':'';?> onchange="toggleLocalCache()" style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_local_cache_label');?></label></div>
     <div class="fg"><button class="bsm" onclick="clearLocalCache()" style="color:#e0a040"><?php echo t('btn_clear_local_cache');?></button></div>
    </div>
   <div class="ss"><h3>Emoji Settings</h3>
    <div class="fg"><label>Emoji panel display:</label><select id="emojiPanelMode" style="width:100%;max-width:300px;padding:8px 12px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.85em;font-family:inherit;outline:none"><option value="dynamic">Dynamic (always animated)</option><option value="hover">Dynamic on hover (static preview)</option><option value="static">Static only</option></select></div>
    <div class="fg"><label>Chat emoji display:</label><select id="emojiChatMode" style="width:100%;max-width:300px;padding:8px 12px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.85em;font-family:inherit;outline:none"><option value="dynamic">Dynamic (animated)</option><option value="static">Static only</option></select></div>
    <button class="bsm" onclick="saveEmojiSettings()"><?php echo t('btn_save');?></button></div>
   <div class="ss"><h3><?php echo t('title_profile_photo');?></h3><div style="text-align:center;margin-bottom:10px"><div class="sa" id="moreAvatar" style="margin:0 auto;width:80px;height:80px"><?php if($currentUser['avatar']):?><img src="<?php echo htmlspecialchars(chatapp_avatar_url($currentUser['avatar'] ?? '', $currentUser['username'] ?? '', (int)($currentUser['user_id'] ?? 0)));?>"><?php endif;?></div></div><input type="file" id="avatarFile" accept="image/*" style="color:#aaa;font-size:.8em;margin-bottom:8px"><button class="bsm" onclick="uploadAvatar()"><?php echo t('btn_upload_photo');?></button></div>
   <div class="ss"><h3><?php echo t('title_change_password');?></h3><form onsubmit="changePassword(event)"><div class="fg"><label><?php echo t('label_current_password');?></label><input type="password" id="currentPassword" required></div><div class="fg"><label><?php echo t('label_new_password');?></label><input type="password" id="newPassword" required placeholder="<?php echo t('msg_login_password_hint');?>"></div><button type="submit" class="bsm"><?php echo t('btn_change_password');?></button></form></div>
   <div class="ss"><h3><?php echo t('title_duress');?></h3><button class="bsm" onclick="showDuressModal()" style="background:#4a2020;border-color:#5c2a2a;color:#e06060"><?php echo t('btn_set_duress');?></button></div>
   <div class="ss"><h3><?php echo t('title_timezone');?></h3><div class="fg"><label><?php echo t('msg_timezone_hint');?></label><input type="text" id="timezoneInput" maxlength="6" placeholder="+08:00" value="<?php echo htmlspecialchars($currentUser['timezone'] ?? '+08:00');?>"></div><button class="bsm" onclick="changeTimezone()"><?php echo t('btn_save');?></button></div>
   <div class="ss"><h3><?php echo t('title_delete_account');?></h3><p style="color:#888;font-size:.78em;margin-bottom:8px"><?php echo t('msg_delete_warning');?></p><button class="bsm danger" onclick="showDeleteModal()"><?php echo t('btn_delete_account');?></button></div>

   <!-- Wallpaper (custom background) -->
   <div class="ss"><h3><?php echo t('title_wallpaper', 'Wallpaper');?></h3>
    <div class="fg">
     <label><?php echo t('label_wallpaper_hint', '自定义背景（全屏）');?></label>
     <input type="file" id="bgFile" accept="image/png,image/jpeg,image/webp" style="color:#aaa;font-size:.8em;margin-bottom:8px">
     <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
      <button class="bsm" onclick="uploadBg()" style="background:#2a4a2a;border-color:#3a6a3a"><?php echo t('btn_upload_bg', '上传背景');?></button>
      <button class="bsm" onclick="removeBg()" style="color:#e06060"><?php echo t('btn_remove_bg', '移除背景');?></button>
      <button class="bsm" onclick="forceRefreshBg()"><?php echo t('btn_refresh_bg', '强制刷新');?></button>
     </div>
     <div class="bg-preview" id="bgPreview"><span><?php echo t('label_no_bg', '无背景');?></span></div>
    </div>
    <div class="fg" style="margin-top:8px">
     <label><?php echo t('label_bg_presets', '系统预设');?></label>
     <div class="bg-preset-grid" id="bgPresets"></div>
    </div>
    <div class="fg" style="margin-top:8px">
     <label><?php echo t('label_bg_blur', '模糊度');?>: <span id="bgBlurVal">0px</span></label>
     <input type="range" id="bgBlur" min="0" max="40" value="0" oninput="onBgBlur(this.value)" style="width:100%;max-width:300px;accent-color:#6a9fd8">
    </div>
    <div class="fg">
     <label><?php echo t('label_bg_opacity', '透明度');?>: <span id="bgOpacityVal">100%</span></label>
     <input type="range" id="bgOpacity" min="0" max="70" value="30" oninput="onBgOpacity(this.value)" style="width:100%;max-width:300px;accent-color:#6a9fd8">
    </div>
   </div>
  </div>
 </div>
 <div class="panel" id="panel-profile-mgmt">
  <div class="ch"><h2><?php echo t('title_profile_mgmt');?></h2><button class="bsm" onclick="closePm()" style="margin-left:8px"><?php echo t('pmg_back');?></button></div>
  <div class="pmg-tabs" id="pmgTabs">
   <button class="pmg-tab active" data-type="all" onclick="pmTab('all')"><?php echo t('pmg_all');?></button>
   <button class="pmg-tab" data-type="photo" onclick="pmTab('photo')"><?php echo t('pmg_photos');?></button>
   <button class="pmg-tab" data-type="video" onclick="pmTab('video')"><?php echo t('pmg_videos');?></button>
   <button class="pmg-tab" data-type="file" onclick="pmTab('file')"><?php echo t('pmg_files');?></button>
  </div>
  <div class="ma" id="pmList" style="flex:1;overflow-y:auto"><div class="es"><p>Loading...</p></div></div>
 </div>
 </div>

 <!-- DM Search panel (per-chat search, reached via Options menu) -->
 <div class="panel" id="panel-dm-search">
  <div class="ch"><h2 id="dmSearchTitle">Search history</h2><button class="bsm" onclick="backToDm()" style="margin-left:8px">← Back</button></div>
  <div class="srch-toolbar">
   <input type="text" id="dmSearchInput" placeholder="Search messages in this chat..." autocomplete="off" onkeydown="if(event.key==='Enter')dmSearchMessages(1)">
   <button onclick="dmSearchMessages(1)"><?php echo t('btn_search');?></button>
   <button onclick="document.getElementById('dmSearchInput').value='';document.getElementById('dmSearchResults').innerHTML=''"><?php echo t('btn_clear');?></button>
  </div>
  <div class="ma" id="dmSearchResultsWrap" style="flex:1;overflow-y:auto;padding:0 20px">
   <div id="dmSearchResults"><div class="es"><p>Enter a search term to find messages in this chat.</p></div></div>
  </div>
  <div class="srch-pagination" id="dmSearchPagination" style="display:none"><span id="dmSearchInfo"></span><span id="dmSearchBtns"></span></div>
 </div>

 <!-- Full-screen background layer -->
<div id="app-bg"></div>
<div class="bg-overlay" id="app-bg-overlay"></div>

<!-- Modals -->
<div class="crop-overlay" id="cropOverlay"><div class="crop-container"><canvas id="cropCanvas"></canvas></div><div class="crop-controls"><button onclick="doCrop()">Crop & Save</button><button onclick="cancelCrop()">Cancel</button></div></div>
<div class="img-fullscreen" id="imgFullscreen" onclick="this.classList.remove('active')"><img id="fullscreenImg" src=""></div>
<div class="modal-overlay" id="forwardModal"><div class="modal-box"><h3><?php echo t('menu_forward');?></h3><div class="fwd-list" id="forwardTargetList"></div><div class="modal-actions"><button class="bsm" onclick="closeForwardModal()"><?php echo t('btn_cancel');?></button></div></div></div>
<div class="modal-overlay" id="chatlogModal"><div class="modal-box" style="max-width:460px"><h3 id="chatlogModalTitle"><?php echo t('cl_footer');?></h3><div id="chatlogModalBody" style="max-height:62vh;overflow-y:auto;margin:10px 0;border:1px solid #333;border-radius:8px;background:#1e1e1e"></div><div class="modal-actions"><button class="bsm" onclick="closeChatlogDetail()"><?php echo t('btn_cancel');?></button></div></div></div>
<div class="modal-overlay" id="duressModal"><div class="modal-box"><h3><?php echo t('title_duress');?></h3><p style="color:#e06060;font-size:.85em;margin-bottom:10px"><?php echo t('duress_desc');?></p><p style="color:#ff8080;font-size:.9em;font-weight:bold;margin-bottom:10px"><?php echo t('duress_warning');?></p><div class="fg" style="text-align:left"><label><?php echo t('label_current_password');?></label><input type="password" id="duressCurrent" style="width:100%"></div><div class="fg" style="text-align:left"><label><?php echo t('label_duress_new');?></label><input type="password" id="duressNew" style="width:100%"></div><div class="fg" style="text-align:left"><label><?php echo t('label_duress_confirm');?></label><input type="password" id="duressNew2" style="width:100%"></div><button class="bsm" onclick="clearDuress()" style="background:#3a3a3a;color:#aaa;margin-top:4px"><?php echo t('btn_clear_duress');?></button><div class="modal-actions"><button class="bsm" onclick="closeDuressModal()"><?php echo t('btn_cancel');?></button><button class="bsm" onclick="saveDuress()" style="background:#4a2020;border-color:#5c2a2a;color:#e06060"><?php echo t('btn_save_duress');?></button></div></div></div>
<div class="modal-overlay" id="deleteModal"><div class="modal-box"><h3><?php echo t('title_delete_account');?></h3><p><?php echo t('msg_delete_warning');?></p><div class="fg" style="text-align:left"><label><input type="radio" name="delMode" value="delete" checked> <?php echo t('set_del_mode_delete');?></label></div><div class="fg" style="text-align:left"><label><input type="radio" name="delMode" value="revoke"> <?php echo t('set_del_mode_revoke');?></label></div><div class="fg" style="text-align:left"><label><input type="radio" name="delMode" value="delete_all"> <?php echo t('set_del_mode_all');?></label></div><div class="fg" style="text-align:left"><label><?php echo t('msg_enter_password_confirm');?></label><input type="password" id="deletePassword" style="width:100%"></div><div class="fg" style="text-align:left"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" id="deleteConfirm" style="width:18px;height:18px"> <?php echo t('set_del_confirm_label');?></label></div><div class="modal-actions"><button class="bsm" onclick="hideDeleteModal()"><?php echo t('btn_cancel');?></button><button class="bsm danger" onclick="confirmDeleteAccount()"><?php echo t('btn_confirm');?></button></div></div></div>
<div class="modal-overlay" id="friendReqModal"><div class="modal-box"><h3 id="friendReqTitle"><?php echo t('btn_add_friend');?></h3><div class="fg" style="text-align:left"><label><?php echo t('label_friend_message');?></label><input type="text" id="friendReqMsg" maxlength="200" style="width:100%;padding:8px 12px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit;outline:none"></div><div class="modal-actions"><button class="bsm" onclick="closeFriendReqModal()"><?php echo t('btn_cancel');?></button><button class="bsm" id="friendReqSendBtn" style="background:#2a4a2a;border-color:#3a6a3a"><?php echo t('btn_send');?></button></div></div></div>
<div class="modal-overlay" id="admModal"><div class="modal-box"><h3 id="admModalTitle"></h3><div class="modal-actions" id="admModalActions"></div><button class="bsm" onclick="closeAdmModal()" style="margin-top:12px"><?php echo t('btn_back');?></button></div></div>
<div class="modal-overlay" id="admPwdModal"><div class="modal-box"><h3><?php echo t('admin_change_password');?></h3><div style="margin-bottom:12px"><input type="text" id="admNewPwd" placeholder="<?php echo t('admin_new_password');?>" style="width:100%;padding:8px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></div><div class="modal-actions"><button class="bsm" onclick="doAdmChangePassword()"><?php echo t('btn_save');?></button><button class="bsm" onclick="closeAdmPwdModal()"><?php echo t('btn_cancel');?></button></div></div></div>
<div class="modal-overlay" id="attachModal"><div class="modal-box"><h3 id="attachTitle"><?php echo t('title_send_attachment');?></h3><p id="attachTo" style="color:#aaa;font-size:.8em"></p><div id="attachPreview"></div><div id="attachInfo" class="att-info"></div><div class="modal-actions"><button class="bsm" onclick="cancelAttachment()"><?php echo t('btn_cancel');?></button><button class="bsm" id="attachSendBtn" style="background:#2a4a2a;border-color:#3a6a3a"><?php echo t('btn_send_attachment');?></button></div></div></div>

<!-- 批量文件发送预览（多选/拖拽 → 确认后逐个普通发送） -->
<div class="modal-overlay" id="batchModal"><div class="modal-box"><h3 id="batchTitle"><?php echo t('batch_title', 'Send files');?></h3><p id="batchTo" style="color:#aaa;font-size:.8em"></p><div id="batchList" style="max-height:300px;overflow-y:auto;margin:10px 0"></div><div id="batchInfo" style="color:#888;font-size:.78em;margin-bottom:10px"></div><div class="modal-actions"><button class="bsm" onclick="cancelBatch()"><?php echo t('btn_cancel');?></button><button class="bsm" id="batchSendBtn" style="background:#2a4a2a;border-color:#3a6a3a" onclick="confirmBatch()"><?php echo t('batch_send', 'Send');?></button></div></div></div>

<!-- 可拖拽迷你音频播放窗口（点音频消息打开；标题栏可拖动；含下载按钮） -->
<div id="audioWin" style="display:none">
  <div class="awin-bar" id="audioWinBar">
    <span style="font-size:14px">&#127925;</span>
    <div style="flex:1;min-width:0">
      <div class="awin-title" id="audioWinTitle">audio</div>
      <div class="awin-sub" id="audioWinSub"></div>
    </div>
    <button class="awin-close" onclick="closeAudioWin()" title="<?php echo t('btn_close', 'Close');?>">&#10005;</button>
  </div>
  <div style="padding:12px 14px">
    <audio id="audioWinAudio" style="display:none"></audio>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
      <button id="audioWinPlay" onclick="toggleAudioWinPlay()" style="width:38px;height:38px;flex-shrink:0;background:#4a9dd8;border:none;color:#fff;font-size:14px;cursor:pointer;border-radius:0">&#9654;</button>
      <div style="flex:1;min-width:0">
        <input type="range" id="audioWinSeek" min="0" max="1000" value="0" style="width:100%" oninput="audioWinSeekInput()">
        <div style="display:flex;justify-content:space-between;font-size:.68em;color:#888"><span id="audioWinCur">0:00</span><span id="audioWinDur">0:00</span></div>
      </div>
      <input type="range" id="audioWinVol" min="0" max="100" value="100" style="width:64px;flex-shrink:0" title="<?php echo t('title_volume', 'Volume');?>" oninput="audioWinVolInput()">
    </div>
    <div style="display:flex;gap:8px">
      <a id="audioWinDownload" href="#" download style="flex:1;text-align:center;padding:7px;background:#2a4a2a;border:1px solid #3a6a3a;color:#c8f5d8;text-decoration:none;font-size:.8em">&#11015; <?php echo t('btn_download', 'Download');?></a>
      <button onclick="closeAudioWin()" style="padding:7px 14px;background:#3a3a3a;border:1px solid #4a4a4a;color:#bbb;cursor:pointer;font-size:.8em;font-family:inherit"><?php echo t('btn_close', 'Close');?></button>
    </div>
  </div>
</div>
<div class="modal-overlay" id="addUserModal"><div class="modal-box"><h3><?php echo t('admin_add_user');?></h3><div class="fg" style="text-align:left"><label><?php echo t('label_username');?></label><input type="text" id="addUserName" maxlength="20" style="width:100%;padding:8px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></div><div class="fg" style="text-align:left"><label><?php echo t('label_password');?></label><input type="password" id="addUserPwd" style="width:100%;padding:8px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></div><div class="modal-actions"><button class="bsm" onclick="closeAddUserModal()"><?php echo t('btn_cancel');?></button><button class="bsm" onclick="doAddUser()" style="background:#2a4a2a;border-color:#3a6a3a"><?php echo t('btn_save');?></button></div></div></div>
<div class="modal-overlay" id="addPlaceholderModal"><div class="modal-box"><h3><?php echo t('admin_add_placeholder');?></h3><p><?php echo t('admin_placeholder_confirm');?></p><div class="fg" style="text-align:left"><label><?php echo t('label_username');?></label><input type="text" id="addPhName" maxlength="20" style="width:100%;padding:8px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></div><div class="modal-actions"><button class="bsm" onclick="closeAddPlaceholderModal()"><?php echo t('btn_cancel');?></button><button class="bsm danger" onclick="doAddPlaceholder()"><?php echo t('btn_confirm');?></button></div></div></div>
<div class="modal-overlay" id="reportModal"><div class="modal-box"><h3 id="reportTitle"><?php echo t('title_report_user');?></h3><div class="fg" style="text-align:left"><label><?php echo t('label_report_reason');?></label><input type="text" id="reportReason" maxlength="1000" style="width:100%;padding:8px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></div><div id="reportMsgCheckboxes" style="text-align:left;max-height:150px;overflow-y:auto"></div><div class="modal-actions"><button class="bsm" onclick="closeReportModal()"><?php echo t('btn_cancel');?></button><button class="bsm" id="reportSendBtn" onclick="doReport()" style="background:#c44;border-color:#c44"><?php echo t('btn_submit_report');?></button></div></div></div>
<div class="modal-overlay" id="noteModal"><div class="modal-box"><h3><?php echo t('label_friend_note');?></h3><div class="fg" style="text-align:left"><label><?php echo t('label_friend_note');?></label><input type="text" id="noteInput" maxlength="500" style="width:100%;padding:8px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit" placeholder="<?php echo t('label_friend_note');?>"></div><div class="modal-actions"><button class="bsm" onclick="closeNoteModal()"><?php echo t('btn_cancel');?></button><button class="bsm" onclick="doAcceptWithNote()" style="background:#2a4a2a;border-color:#3a6a3a"><?php echo t('btn_confirm');?></button></div></div></div>
<div class="modal-overlay" id="changeStatusModal"><div class="modal-box"><h3><?php echo t('admin_change_status');?></h3><div class="modal-actions" style="flex-direction:column;gap:8px"></div><button class="bsm" onclick="closeChangeStatusModal()" style="margin-top:8px"><?php echo t('btn_back');?></button></div></div>
<div class="modal-overlay" id="createTicketModal"><div class="modal-box"><h3><?php echo t('title_create_ticket');?></h3><form id="ticketForm">
<div class="fg" style="text-align:left"><label><?php echo t('label_ticket_type');?></label><select id="ticketType" class="modal-select"><option value="bug"><?php echo t('type_bug');?></option><option value="recommendation"><?php echo t('type_recommendation');?></option><option value="account_issue"><?php echo t('type_account_issue');?></option></select></div>
<div class="fg" style="text-align:left"><label><?php echo t('label_ticket_subject');?></label><input type="text" id="ticketSubject" maxlength="500" style="width:100%;padding:8px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></div>
<div class="fg" style="text-align:left"><label><?php echo t('label_ticket_content');?></label><textarea id="ticketContent" class="modal-textarea" maxlength="5000"></textarea></div>
<div class="fg" style="text-align:left"><label>Attach images</label><input type="file" id="ticketImages" accept="image/*" multiple style="color:#aaa;font-size:.8em"></div>
<div class="fg" style="text-align:left"><label><?php echo t('label_ticket_priority');?></label><select id="ticketPriority" class="modal-select"><option value="task">Task</option><option value="low">Low</option><option value="normal" selected>Normal</option><option value="medium">Medium</option><option value="high">High</option><option value="urgent">Urgent</option><option value="critical">Critical</option><option value="nopriority">No Priority</option></select></div>
<div class="modal-actions"><button type="button" class="bsm" onclick="closeCreateTicket()"><?php echo t('btn_cancel');?></button><button type="button" class="bsm" onclick="doCreateTicket()" style="background:#2a4a2a;border-color:#3a6a3a"><?php echo t('title_create_ticket');?></button></div></form></div></div>

<script>
  var LANG=<?php echo json_encode(lang_load());?>;
var U=<?php echo json_encode($currentUser['username']);?>;
var CSRF=<?php echo json_encode(chatapp_csrf_token());?>;
var TZ='<?php echo $currentUser['timezone'] ?? '+08:00';?>';
var DND=<?php echo (int)($currentUser['dnd'] ?? 0);?>;
var RSTR=<?php echo (int)($currentUser['restricted'] ?? 0);?>;
var DS=<?php echo (int)($currentUser['data_saver'] ?? 0);?>;
var AUTO_FOCUS=<?php echo (int)($currentUser['auto_focus_input'] ?? 1);?>;
var NOTIF_SYS=<?php echo (int)($currentUser['notif_system'] ?? 1);?>;
var NOTIF_BANNER=<?php echo (int)($currentUser['notif_banner'] ?? 1);?>;
var TYPING_VIS=<?php echo (int)($currentUser['typing_visible'] ?? 1);?>;
var ADMIN=<?php echo $isAdmin ? 'true' : 'false';?>;
var IS_ROOT=<?php echo $isRoot ? 'true' : 'false';?>;
var CACHE_KEY='<?php echo htmlspecialchars($currentUser['cache_key'] ?? '', ENT_QUOTES);?>';
var LOCAL_CACHE=<?php echo (int)($currentUser['local_cache_enabled'] ?? 0);?>;
var MYUID=<?php echo (int)($currentUser['user_id'] ?? 0);?>;
var EMOJI_PANEL='<?php echo $currentUser['emoji_panel_mode'] ?? 'dynamic';?>';
var EMOJI_CHAT='<?php echo $currentUser['emoji_chat_mode'] ?? 'dynamic';?>';
var MYLV=<?php echo (int)($currentUser['level'] ?? 1);?>;
var MYEXP=<?php echo (int)($currentUser['exp'] ?? 0);?>;
var WSS_URLS=<?php echo json_encode($__wssUrls);?>;
// 服务器数据库本地时区偏移（PHP date_default_timezone_set 决定，如 +08:00）
// fmtTime/relTime 用它把 "YYYY-MM-DD HH:MM:SS" 字符串精确换算成时间戳
var SERVER_TZ='<?php echo date('P');?>';
var MYSELF_PIN=<?php echo (int)($currentUser['pin_self'] ?? 1);?>;
</script>
<script src="../scripts/vendor/nacl.min.js"></script>
<script src="../scripts/vendor/nacl-util.min.js"></script>
<script src="../scripts/e2ee.js?v=<?php echo time();?>"></script>
<script src="../scripts/chat.js?v=<?php echo time();?>"></script>
<script>window.EARS_ON = <?php echo (int)($currentUser['space_ears'] ?? 0) ? 'true' : 'false'; ?>;</script>
<script src="../scripts/ears.js?v=<?php echo time();?>"></script>
<script src="../scripts/markdown.js?v=<?php echo time();?>"></script>
<script src="../scripts/wss_client.js?v=<?php echo time();?>"></script>
<script>try{wssInit();}catch(e){}</script>
<script>try{if(window.E2EE)E2EE.init().catch(function(){});}catch(e){}</script>
<!-- 拼音输入法（引擎小，8MB 词典首次输入时懒加载） -->
<script src="../kb/ime_engine.js?v=<?php echo time();?>"></script>
<script src="../kb/ime_ui.js?v=<?php echo time();?>"></script>
<script>
try {
  ImePinyinUI.attach(document.getElementById('dmMessageInput'), 'imeToggle');
} catch (e) {}
</script>
<div class="modal-overlay" id="addDonModal"><div class="modal-box"><h3>Add Donation</h3><table style="width:100%;border-collapse:collapse;font-size:.82em"><tr><td style="padding:6px 12px">DateTime</td><td style="padding:6px"><input type="text" id="addDonDateTime" placeholder="YYYY-MM-DD HH:MM:SS" style="width:100%;padding:6px 10px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></td></tr><tr><td style="padding:6px 12px">User</td><td style="padding:6px"><input type="text" id="addDonUserSearch" placeholder="Search username or UID..." autocomplete="off" oninput="searchDonUser()" style="width:100%;padding:6px 10px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"><input type="hidden" id="addDonUserId"><div id="donUserSearchResults" style="max-height:120px;overflow-y:auto;border:1px solid #333;background:#1a1a1a;display:none"></div></td></tr><tr><td style="padding:6px 12px">WeixinID</td><td style="padding:6px"><input type="text" id="addDonWeixin" placeholder="Optional" style="width:100%;padding:6px 10px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></td></tr><tr><td style="padding:6px 12px">QQ</td><td style="padding:6px"><input type="text" id="addDonQQ" placeholder="Optional" style="width:100%;padding:6px 10px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></td></tr></table><div class="modal-actions" style="margin-top:10px"><button class="bsm" onclick="closeAddDonModal()">Cancel</button><button class="bsm" onclick="doAddDonation()" style="background:#2a4a2a;border-color:#3a6a3a">Save</button></div></div></div>
<input type="file" id="customEmojiFile" accept="image/*" multiple style="display:none" onchange="uploadCustomEmoji()"><div class="emoji-popup" id="emojiPopup" style="display:none"><div class="emoji-sidebar"><button class="active" id="emojiTabBuiltin" onclick="switchEmojiTab('builtin')">内置表情</button><button id="emojiTabCustom" onclick="switchEmojiTab('custom')">自定义表情</button></div><div class="emoji-grid" id="emojiGrid"></div></div>

<!-- Flash transfer menu (shared by announcement + DM composer) -->
<div class="flash-menu" id="flashMenu" style="display:none">
 <div onclick="flashMenuUpload()"><img src="../../data/res/svg/folder_16.svg" width="14" alt=""><span><?php echo t('flash_upload', '上传');?></span></div>
 <div onclick="flashMenuFlash()"><img src="../../data/res/svg/fast_folder_16.svg" width="14" alt=""><span><?php echo t('flash_flash', '闪传（临时）');?></span></div>
 <div onclick="flashMenuMy()"><img src="../../data/res/svg/folder_16.svg" width="14" alt=""><span><?php echo t('flash_my', '我的闪传文件');?></span></div>
</div>

<!-- My flash files modal -->
<div class="modal-overlay" id="flashMyModal"><div class="modal-box"><h3><?php echo t('flash_my', '我的闪传文件');?></h3><div id="flashMyList" style="max-height:360px;overflow-y:auto;text-align:left;margin-bottom:14px"></div><div class="modal-actions"><button class="bsm" onclick="closeFlashMyModal()"><?php echo t('btn_cancel');?></button></div></div></div>
<div class="modal-overlay" id="customDialog"><div class="modal-box cd-win"><div class="cd-titlebar"><span class="cd-title" id="cdTitle"></span><button type="button" class="cd-close" onclick="closeCustomDialog(false)" title="Close"><img src="../../data/res/cil/cil-x.svg" style="width:14px;height:14px"></button></div><div class="cd-body"><p class="cd-msg" id="cdMsg"></p><input type="text" id="cdInput" class="cd-input" style="display:none"><div class="modal-actions cd-actions"><button class="bsm" id="cdCancel" onclick="cdResolve(false)">Cancel</button><button class="bsm" id="cdOk" onclick="cdResolve(true)">OK</button></div></div></div></div>

<!-- EXP toast container (bottom-right, in-page) -->
<div id="expToasts"></div>

<!-- Mobile sidebar drawer: overlay + toggle button -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>
<button id="sidebarToggleBtn" class="hidden" onclick="openMobileSidebar()" title="菜单">&#x276E;</button>

<!-- ================================================================
     Profile Drawer (right side overlay, iframe renders test.html)
     ================================================================ -->
<div class="profile-overlay" id="profileOverlay" onclick="closeMyProfile()"></div>
<div class="user-sidebar" id="userSidebar">
 <iframe id="profileFrame" src="" title="Profile"></iframe>
</div>
<div class="modal-overlay" id="codePreviewModal" onclick="if(event.target===this)closeCodePreview()"><div class="cp-box"><div class="cp-head"><div class="cp-head-l"><span class="cp-file-icon">&#128196;</span><span class="cp-name" id="cpName"></span><span class="cp-lang" id="cpLang"></span><span class="cp-size" id="cpSize"></span></div><div class="cp-actions"><button class="bsm" onclick="copyCodePreview()"><?php echo t('btn_copy', 'Copy');?></button><button class="bsm" onclick="closeCodePreview()"><?php echo t('btn_close', 'Close');?></button></div></div><div class="cp-body" id="cpBody"><div class="cp-loading" id="cpLoading"><?php echo t('msg_loading');?></div><div id="cpEditor" class="cp-editor" style="display:none"></div></div></div></div>
<script>
// 加载动画控制：等全部资源下载完才结束，且至少显示 300~600ms
(function () {
  var loaderWrapper = document.getElementById("loader-wrapper");
  if (!loaderWrapper) return;
  var tipEl = loaderWrapper.querySelector(".loader-text .tip");
  var baseText = tipEl ? tipEl.textContent : "";

  var MIN_DELAY = Math.floor(Math.random() * 301) + 300; // 300~600ms 最短展示时间
  var MAX_WAIT = 40000;                                  // 保险上限：最多 40 秒（让 30s 提示也能显示）
  var startTime = Date.now();

  // verbose 日志：高精度时间 + 加载项目（console.verbose 非标准，做成别名）
  if (!window.console) window.console = {};
  if (typeof console.verbose !== "function") console.verbose = console.debug || console.log;
  var vlog = function (msg, detail) {
    var t = (performance && performance.now) ? performance.now() : Date.now();
    console.verbose("[loader][" + t.toFixed(3) + "ms]", msg, detail || "");
  };

  function finishLoading() {
    vlog("加载完成，收起 Loading UI");
    if (typeof progTimer !== "undefined") clearInterval(progTimer);
    loaderWrapper.classList.add("loaded");
  }

  // 收集页面初始需要下载的资源（JS / CSS / 图片 / iframe；忽略懒加载图片）
  function collectUrls() {
    var nodes = document.querySelectorAll(
      'script[src], link[rel="stylesheet"], img[src], iframe[src], audio[src], video[src]'
    );
    var urls = [];
    for (var i = 0; i < nodes.length; i++) {
      var n = nodes[i];
      if (n.tagName === "IMG" && n.getAttribute("loading") === "lazy") continue;
      var u = n.src || n.href;
      // data:/blob: 是内联/本地资源，不是真实网络下载，跳过
      // （否则会当成长文件名显示成一大串 base64 乱码）
      if (!u || u.indexOf("data:") === 0 || u.indexOf("blob:") === 0) continue;
      urls.push(u);
    }
    return urls;
  }

  // 已下载完成的资源集合（Resource Timing API）
  function loadedSet() {
    var s = new Set();
    if (!window.performance || !performance.getEntriesByType) return s;
    var entries = performance.getEntriesByType("resource");
    for (var i = 0; i < entries.length; i++) s.add(entries[i].name);
    return s;
  }

  var urls = collectUrls();
  var total = urls.length;
  vlog("开始加载，共 " + total + " 个资源", urls.join("\n"));

  // 长时间加载时的提示（按经过秒数切换）
  var LOAD_MSGS = [
    { at: 5,  text: "<?php echo t('enterchat_loading_5s');?>" },
    { at: 10, text: "<?php echo t('enterchat_loading_10s');?>" },
    { at: 15, text: "<?php echo t('enterchat_loading_15s');?>" },
    { at: 20, text: "<?php echo t('enterchat_loading_20s');?>" },
    { at: 25, text: "<?php echo t('enterchat_loading_25s');?>" },
    { at: 30, text: "<?php echo t('enterchat_loading_30s');?>" }
  ];

  // 根据已过秒数选一条提示（取已超过的最高档）
  function curMsg() {
    var elapsed = (Date.now() - startTime) / 1000;
    var msg = "";
    for (var i = 0; i < LOAD_MSGS.length; i++) {
      if (elapsed >= LOAD_MSGS[i].at) msg = LOAD_MSGS[i].text;
    }
    return msg;
  }

  // verbose：记录每一个新下载完成的资源（高精度时间 + 文件名）
  var logged = new Set();
  var logNewlyLoaded = function () {
    if (!window.performance || !performance.getEntriesByType) return;
    var entries = performance.getEntriesByType("resource");
    for (var i = 0; i < entries.length; i++) {
      var en = entries[i];
      if (logged.has(en.name)) continue;
      logged.add(en.name);
      var fname = en.name.split("/").pop() || en.name;
      var size = en.transferSize ? (en.transferSize / 1024).toFixed(1) + "KB" : en.duration.toFixed(1) + "ms";
      vlog("已加载 " + fname, size);
    }
  };

  // 每隔 120ms 刷新“正在下载 xxx”的进度文字
  var progTimer = setInterval(function () {
    logNewlyLoaded();
    var loaded = loadedSet();
    var doneCount = 0, current = "";
    for (var i = 0; i < urls.length; i++) {
      if (loaded.has(urls[i])) doneCount++;
      else if (!current) current = urls[i].split("/").pop();
    }
    if (tipEl) {
      var txt = (curMsg() || baseText) + (total ? " " + doneCount + "/" + total : "");
      if (current) txt += " · " + current;
      tipEl.textContent = txt;
    }
  }, 120);

  // 等 window load（全部资源下载完成）后再等满最短展示时间
  function maybeFinish() {
    var elapsed = Date.now() - startTime;
    if (elapsed >= MIN_DELAY) {
      finishLoading();
    } else {
      setTimeout(maybeFinish, 50);
    }
  }
  window.addEventListener("load", maybeFinish);

  // 保险：就算有资源卡住，也绝不超过 MAX_WAIT
  setTimeout(finishLoading, MAX_WAIT);
})();
</script>

<!-- Doodle 涂鸦：画在聊天画面上（矢量笔迹 + 激光光效） -->
<div id="doodleOverlay" class="doodle-overlay" style="display:none">
  <canvas id="doodleCanvas"></canvas>
  <div class="doodle-toolbar">
    <span class="doodle-title">Doodle</span>
    <span class="doodle-colors" id="doodleColors">
      <button class="dc" data-c="#ffffff" style="background:#ffffff" title="白"></button>
      <button class="dc" data-c="#ff5d5d" style="background:#ff5d5d" title="红"></button>
      <button class="dc" data-c="#ffb84d" style="background:#ffb84d" title="橙"></button>
      <button class="dc" data-c="#ffe94d" style="background:#ffe94d" title="黄"></button>
      <button class="dc" data-c="#6dff6d" style="background:#6dff6d" title="绿"></button>
      <button class="dc active" data-c="#4dd8ff" style="background:#4dd8ff" title="青"></button>
      <button class="dc" data-c="#4d7dff" style="background:#4d7dff" title="蓝"></button>
      <button class="dc" data-c="#d84dff" style="background:#d84dff" title="紫"></button>
    </span>
    <label class="doodle-size">粗细 <input type="range" id="doodleSize" min="1" max="40" value="6"></label>
    <button class="bsm" id="doodleEraserBtn" onclick="toggleDoodleEraser()">橡皮</button>
    <button class="bsm" onclick="undoDoodle()">撤销</button>
    <button class="bsm" onclick="clearDoodle()">清空</button>
    <button class="bsm" onclick="closeDoodle()"><img src="../../data/res/cil/cil-x.svg" style="width:13px;height:13px;vertical-align:-2px;margin-right:4px"> 取消</button>
    <label class="doodle-switch" title="Apple Pen 模式：开启后仅 Pencil 可画，忽略手指（Pencil 触碰时自动开启）"><input type="checkbox" id="doodlePenSwitch"><span class="ds-track"><span class="ds-thumb"></span></span><span class="ds-label">Apple Pen</span></label>
    <button class="bs" id="doodleSendBtn" onclick="sendDoodle()" style="background:#2a4a2a;border-color:#3a6a3a">发送</button>
  </div>
</div>

<!-- Pen 菜单：Doodle（本地涂鸦）/ Live Draw（双人实时画板） -->
<div class="flash-menu pen-menu" id="penMenu" style="display:none">
  <div onclick="openDoodle();hidePenMenu()"><img src="../../data/res/cil/cil-pen.svg" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px"> Doodle（本地涂鸦）</div>
  <div onclick="openLiveDrawSetup();hidePenMenu()"><img src="../../data/res/cil/cil-pen-nib.svg" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px"> Live Draw（双人实时画板）</div>
</div>

<!-- Live Draw 发起设置弹窗（发起者：选对象 + 设画板大小） -->
<div class="modal-overlay" id="ldSetupOverlay">
  <div class="modal-box ld-setup">
    <h3><img src="../../data/res/cil/cil-pen-nib.svg" style="width:16px;height:16px;vertical-align:-3px;margin-right:4px"> Live Draw 发起协作画板</h3>
    <div class="fg" style="text-align:left"><label>邀请对象（当前对话）</label>
      <div class="ld-invitee" id="ldInvitee">…</div>
      <div class="ld-size-note" id="ldInviteeNote"></div>
    </div>
    <div class="fg" style="text-align:left"><label>画板大小</label>
      <div class="ld-size-opts" id="ldSizeOpts">
        <button type="button" class="ld-size-btn active" data-size="mine">我的窗口</button>
        <button type="button" class="ld-size-btn" data-size="peer">对方窗口</button>
        <button type="button" class="ld-size-btn" data-size="1024x768">1024 × 768</button>
        <button type="button" class="ld-size-btn" data-size="640x480">640 × 480</button>
        <button type="button" class="ld-size-btn" data-size="custom">自定义</button>
      </div>
      <div class="ld-custom-row" id="ldCustomRow" style="display:none">
        <label>宽 <input type="number" id="ldCustomW" step="any" min="64" value="800"></label>
        <label>高 <input type="number" id="ldCustomH" step="any" min="64" value="600"></label>
      </div>
      <div class="ld-size-note" id="ldSizeNote"></div>
    </div>
    <div class="modal-actions">
      <button type="button" class="bsm" id="ldSetupCancel">取消</button>
      <button type="button" class="bsm" id="ldSetupStart" style="background:#2a4a2a;border-color:#3a6a3a">发起</button>
    </div>
  </div>
</div>

<!-- Live Draw 等待对方接受（发起方：发完邀请后先等「同意」才进画板） -->
<div class="modal-overlay" id="ldWaitOverlay">
  <div class="modal-box ld-setup">
    <h3><img src="../../data/res/cil/cil-pen-nib.svg" style="width:16px;height:16px;vertical-align:-3px;margin-right:4px"> 等待接受邀请</h3>
    <div class="ld-wait-text" style="text-align:center;color:#aaa;font-size:.9em;margin:18px 0">正在等待 <b id="ldWaitName" style="color:#e0e0e0">…</b> 接受邀请…</div>
    <div class="modal-actions">
      <button type="button" class="bsm" id="ldWaitCancel" style="background:#4a2a2a;border-color:#6a3a3a">取消邀请</button>
    </div>
  </div>
</div>

<!-- Live Draw 协作画板覆盖层（双方共用；复用 doodle 覆盖层/工具栏样式） -->
<div id="ldOverlay" class="doodle-overlay" style="display:none">
  <canvas id="ldCanvas"></canvas>
  <div class="doodle-toolbar">
    <span class="doodle-title">Live Draw — <span id="ldPeerName"></span></span>
    <span class="doodle-colors" id="ldColors">
      <button class="dc" data-c="#ffffff" style="background:#ffffff" title="白"></button>
      <button class="dc" data-c="#ff5d5d" style="background:#ff5d5d" title="红"></button>
      <button class="dc" data-c="#ffb84d" style="background:#ffb84d" title="橙"></button>
      <button class="dc" data-c="#ffe94d" style="background:#ffe94d" title="黄"></button>
      <button class="dc" data-c="#6dff6d" style="background:#6dff6d" title="绿"></button>
      <button class="dc active" data-c="#4dd8ff" style="background:#4dd8ff" title="青"></button>
      <button class="dc" data-c="#4d7dff" style="background:#4d7dff" title="蓝"></button>
      <button class="dc" data-c="#d84dff" style="background:#d84dff" title="紫"></button>
    </span>
    <label class="doodle-size">粗细 <input type="range" id="ldSize" min="1" max="40" value="6"></label>
    <button class="bsm" id="ldEraserBtn" type="button">橡皮</button>
    <button class="bsm" id="ldUndoBtn" type="button">撤销</button>
    <button class="bsm" id="ldClearBtn" type="button">清空</button>
    <button class="bsm" id="ldExitBtn" type="button" style="background:#4a2a2a;border-color:#6a3a3a"><img src="../../data/res/cil/cil-x.svg" style="width:13px;height:13px;vertical-align:-2px;margin-right:4px"> 退出</button>
  </div>
  <div class="ld-banner" id="ldBanner" style="display:none"></div>
</div>

<!-- WebRTC 通话：来电提示 -->
<div class="modal-overlay" id="callIncomingOverlay" style="display:none">
  <div class="modal-box call-incoming">
    <div class="call-avatar" id="callIncomingIcon"><img src="../../data/res/svg/phone_24.svg" width="46" style="vertical-align:middle"></div>
    <div class="call-title"><?php echo t('call_incoming');?></div>
    <div class="call-name" id="callIncomingName">…</div>
    <div class="modal-actions">
      <button class="bsm call-btn call-decline" onclick="rejectCall()"><img src="../../data/res/cil/cil-x.svg" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px"> <?php echo t('btn_reject');?></button>
      <button class="bsm call-btn call-answer" onclick="acceptCall()"><img src="../../data/res/cil/cil-check.svg" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px"> <?php echo t('btn_accept');?></button>
    </div>
  </div>
</div>

<!-- WebRTC 通话：通话窗口（语音/视频共用，非全屏可拖动/最小化） -->
<div id="callOverlay">
  <div class="call-top">
    <span class="call-top-title" id="callTopName">…</span>
    <button class="bsm call-min-btn" id="callMinBtn" onclick="ChatCall.minimize()" title="<?php echo t('call_minimize');?>">－</button>
  </div>
  <div class="call-video-wrap" id="callVideoWrap" style="display:none">
    <video id="callRemoteVideo" autoplay playsinline style="display:none"></video>
    <video id="callLocalVideo" autoplay playsinline muted style="display:none"></video>
  </div>
  <div class="call-audio-wrap" id="callAudioWrap" style="display:none">
    <div class="call-avatar big"><img src="../../data/res/svg/phone_24.svg" width="64" style="vertical-align:middle"></div>
    <div class="call-name" id="callPeerName">…</div>
    <div class="call-dur" id="callDur">0:00</div>
    <div class="call-status" id="callStatus"></div>
    <div class="call-stats" id="callStats" style="display:none"></div>
  </div>
  <div class="call-waves">
    <div class="call-wave-row">
      <span class="call-wave-label">对方</span>
      <canvas id="callWaveRemote" class="call-wave" width="560" height="48"></canvas>
    </div>
    <div class="call-wave-row">
      <span class="call-wave-label">自己</span>
      <canvas id="callWaveLocal" class="call-wave" width="560" height="48"></canvas>
    </div>
  </div>
  <div class="call-controls">
    <button class="bsm" id="callMuteBtn" onclick="toggleCallMute()"><img src="../../data/res/svg/microphone_on_24.svg" width="16" style="vertical-align:-3px"> <?php echo t('call_mute');?></button>
    <button class="bsm call-hangup" onclick="hangupCall()"><img src="../../data/res/svg/hangup_filled_24.svg" width="16" style="vertical-align:-3px"> <?php echo t('call_hangup');?></button>
  </div>
</div>
<!-- 通话最小化后的悬浮条 -->
<div id="callMinimized" style="display:none">
  <span class="call-min-title" id="callMinTitle">…</span>
  <button class="bsm call-min-hangup" id="callMinHangupBtn" onclick="hangupCall()"><img src="../../data/res/svg/hangup_filled_24.svg" width="14" style="vertical-align:-2px;margin-right:4px"> <?php echo t('call_hangup');?></button>
  <button class="bsm" id="callMinRestoreBtn" onclick="ChatCall.restore()"><?php echo t('call_restore');?></button>
</div>

<!-- 独立屏幕共享（非通话）：收到屏幕共享邀请提示 -->
<div class="modal-overlay" id="shareIncomingOverlay" style="display:none">
  <div class="modal-box call-incoming">
    <div class="call-avatar" id="shareIncomingIcon"><img src="../../data/res/svg/share_screen_24.svg" width="46" style="vertical-align:middle"></div>
    <div class="call-title"><?php echo t('share_invite_title');?></div>
    <div class="call-name" id="shareIncomingName">…</div>
    <div class="modal-actions">
      <button class="bsm call-btn call-decline" onclick="rejectShare()"><img src="../../data/res/cil/cil-x.svg" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px"> <?php echo t('share_reject');?></button>
      <button class="bsm call-btn call-answer" onclick="acceptShare()"><img src="../../data/res/cil/cil-check.svg" style="width:14px;height:14px;vertical-align:-2px;margin-right:4px"> <?php echo t('share_view');?></button>
    </div>
  </div>
</div>

<!-- 独立屏幕共享（非通话）：全屏显示窗口（共享方=自己预览+停止；查看方=对方屏幕+退出） -->
<div id="shareOverlay" style="display:none">
  <div class="share-top">
    <span class="share-title" id="shareTitle"><?php echo t('share_sharing');?></span>
    <button class="bsm share-audio-btn" id="shareAudioBtn" onclick="ChatShare.toggleAudio()" style="display:none"><?php echo svg_ic('volume', 14);?></button>
    <button class="bsm share-audio-btn" id="shareMuteBtn" onclick="ChatShare.toggleMute()" style="display:none" title="静音对方屏幕声音"><?php echo svg_ic('volume', 14);?></button>
    <button class="bsm share-min-btn" id="shareMinBtn" onclick="ChatShare.minimize()" title="<?php echo t('share_minimize');?>">－</button>
    <button class="bsm danger" id="shareStopBtn" onclick="ChatShare.stopShare()" style="display:none"><?php echo t('share_stop');?></button>
    <button class="bsm" id="shareCloseBtn" onclick="ChatShare.closeViewer()" style="display:none"><?php echo t('share_exit');?></button>
  </div>
  <div class="share-stats" id="shareStats" style="display:none"></div>
  <div class="share-wait" id="shareWaitMsg" style="display:none"><?php echo t('share_waiting');?></div>
  <video id="shareVideo" autoplay playsinline style="display:none"></video>
</div>
<div id="shareMinimized" style="display:none">
  <span class="share-min-title" id="shareMinTitle">…</span>
  <button class="bsm" id="shareMinRestoreBtn" onclick="ChatShare.restore()"><?php echo t('share_restore');?></button>
</div>

<!-- 端到端加密安全码验证（WhatsApp 式 60 位比对） -->
<div class="modal-overlay" id="safetyVerifyModal">
  <div class="modal-box">
    <h3><?php echo t('sv_title');?></h3>
    <p id="safetyVerifySub" style="color:#aaa">…</p>
    <div id="safetyVerifyNum" class="safety-number"><?php echo t('sv_calculating');?></div>
    <p style="color:#888;font-size:.74em;line-height:1.6"><?php echo t('sv_desc');?></p>
    <div class="modal-actions">
      <button class="bsm" onclick="copySafetyNumber()"><?php echo t('sv_copy');?></button>
      <button class="bsm" onclick="closeSafetyVerify()"><?php echo t('sv_close');?></button>
    </div>
  </div>
</div>
</body></html>
