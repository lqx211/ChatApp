<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();
$isAdmin = chatapp_has_permission($currentUser['user_id'] ?? 0, 'users.view');
$isRoot = chatapp_get_role((int)($currentUser['user_id'] ?? 0)) === 'root';
$customTitle = $currentUser['custom_title'] ?? '';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $customTitle ? htmlspecialchars($customTitle) : 'ChatApp'; ?></title>
<link rel="stylesheet" href="../css/global.css">
<link rel="stylesheet" href="chat.css?v=<?php echo time();?>">
</head>
<body>
 <div class="sidebar">
  <div class="sidebar-profile" id="sidebarProfile">
   <div class="sa" id="sidebarAvatar"><?php if($currentUser['avatar']):?><img src="<?php echo htmlspecialchars($currentUser['avatar']);?>"><?php endif;?></div>
   <div class="sun" id="sidebarName"><?php echo chatapp_display_name($currentUser);?></div>
   <a class="sdnd <?php echo ($currentUser['restricted'] ?? 0) ? 'rstr' : (($currentUser['dnd'] ?? 0) ? 'dnd' : 'on'); ?>" id="dndToggle" onclick="<?php echo ($currentUser['restricted'] ?? 0) ? '' : 'toggleDnd()'; ?>" style="<?php echo ($currentUser['restricted'] ?? 0) ? 'cursor:default' : ''; ?>"><?php echo ($currentUser['restricted'] ?? 0) ? t('admin_restricted_status') : (($currentUser['dnd'] ?? 0) ? t('msg_dnd_status') : t('msg_online_status')); ?></a>
  </div>
  <div class="sidebar-nav" id="sidebarNavDefault">
   <div class="ng">
    <div class="ngh" onclick="toggleGroup('contactsGroup')"><span><?php echo t('title_contacts');?></span><span class="ar op" id="arrow-contactsGroup">&#9654;</span></div>
    <div class="ngb op" id="body-contactsGroup">
     <div class="csi" onclick="openDm('<?php echo htmlspecialchars($currentUser['username']);?>')"><div class="ca" id="contactSelfAvatar"><?php if($currentUser['avatar']):?><img src="<?php echo htmlspecialchars($currentUser['avatar']);?>"><?php endif;?><span class="online-dot on"></span></div><div class="cn"><?php echo chatapp_display_name($currentUser);?> <?php echo t('msg_online');?></div></div>
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
     <div class="na" onclick="showCreateGroupModal()">+ Create Group</div>
     <div class="na" onclick="showJoinGroupModal()">+ Join Group</div>
    </div>
   </div>
   <div class="ng"><div class="ngh" onclick="switchPanel('requests')" style="cursor:pointer"><span><?php echo t('title_pending_requests');?></span><span class="ngh-badge" id="reqBadge" style="display:none">0</span></div></div>
   <div class="ng"><div class="ngh" onclick="switchPanel('search')" style="cursor:pointer"><span><?php echo t('title_search');?></span></div></div>
   <div class="ng">
    <div class="ngh" onclick="toggleGroup('appsGroup')"><span><?php echo t('title_apps');?></span><span class="ar" id="arrow-appsGroup">&#9654;</span></div>
    <div class="ngb" id="body-appsGroup">
     <div class="na" onclick="switchPanel('music')"><?php echo t('label_music_player');?></div>
    </div>
   </div>
   <div class="ng"><div class="ngh" onclick="switchPanel('public-emoji')" style="cursor:pointer"><span><?php echo t('title_public_emoji');?></span></div></div>
   <div class="ng"><div class="ngh" onclick="switchPanel('announcements')" style="cursor:pointer"><span><?php echo t('title_announcements');?></span></div></div>
   <?php if($isAdmin):?>
   <div class="ng"><div class="ngh" onclick="switchPanel('reports')" style="cursor:pointer"><span><?php echo t('title_report_incidents');?></span><span class="ngh-badge" id="repBadge" style="display:none">0</span></div></div>
   <div class="ng"><div class="ngh" onclick="switchPanel('users')" style="cursor:pointer"><span><?php echo t('title_all_users');?></span></div></div>
   <?php endif;?>
   <div class="ng"><div class="ngh" onclick="switchPanel('support')" style="cursor:pointer;<?php echo ($currentUser['restricted']??0)?'background:#3a2a1e;border-left:3px solid #e0a040;':''?>"><span><?php echo t('title_support');?> <?php echo ($currentUser['restricted']??0)?'⚠':' ';?></span></div></div>
   <?php if($isAdmin):?>
   <div class="ng"><div class="ngh" onclick="switchPanel('logs')" style="cursor:pointer"><span>Logs</span></div></div>
   <?php endif;?>
   <?php if($isRoot):?>
   <div class="ng"><div class="ngh" onclick="switchPanel('dbadmin')" style="cursor:pointer"><span>数据库管理</span></div></div>
   <?php endif;?>
   <div class="ng"><div class="ngh" onclick="switchPanel('level')" style="cursor:pointer"><span><?php echo t('title_level');?></span></div></div>
   <div class="ng"><div class="ngh" onclick="switchPanel('more')" style="cursor:pointer"><span><?php echo t('title_settings');?></span></div></div>
   <div class="ng">
    <div class="ngh" onclick="toggleGroup('moreGroup')"><span><?php echo t('title_more');?></span><span class="ar" id="arrow-moreGroup">&#9654;</span></div>
    <div class="ngb" id="body-moreGroup">
     <div class="na" onclick="switchPanel('donations')">Donations</div>
    </div>
   </div>
  </div>
  <div class="sidebar-nav" id="sidebarNavUser" style="display:none"></div>
  <div class="sidebar-footer"><div class="ngh" id="logoutLink" onclick="logout()" style="cursor:pointer"><span><?php echo isset($_SESSION['admin_username']) ? 'Back to all users' : t('title_logout');?></span></div></div>
 </div>

<div class="main-content">
 <div class="panel" id="panel-announcements">
  <div class="ch"><h2><?php echo t('title_announcements');?></h2></div>
  <div class="ma" id="messagesArea"><div class="es"><p><?php echo t('msg_no_announcements');?></p></div></div>
  <?php if(chatapp_has_permission($currentUser['user_id']??0, 'announcements.send') && !($currentUser['restricted']??0)):?>
  <div class="upload-progress" id="uploadProgress"><div></div></div>
  <div class="md-preview" id="mdPreviewAnn"></div>
  <div class="cia"><textarea id="messageInput" oninput="autoResize(this);onMdInput('mdPreviewAnn','messageInput','mdCheckAnn')" placeholder="<?php echo t('label_type_announcement');?>" maxlength="1000" rows="1" style="resize:none;overflow-y:auto;line-height:1.4;max-height:20em"></textarea><input type="file" id="mediaFile" style="display:none" onchange="previewAttachment(this, sendAnnouncement, 'sendBtn')"><button class="bsm" onclick="toggleEmojiPicker(event,'messageInput')" title="Emoji">😊</button><button class="bsm" onclick="toggleFlashMenu(event,this)" title="Attach">📁</button><input type="file" id="flashMediaFile" style="display:none" onchange="flashFileChosen(this,'announcement')"><label class="md-check"><input type="checkbox" id="mdCheckAnn" onchange="onMdInput('mdPreviewAnn','messageInput','mdCheckAnn')"> Markdown</label><button class="bs" id="sendBtn" onclick="sendAnnouncement()"><?php echo t('btn_send');?></button></div>
  <?php else:?>
  <div class="cia" style="justify-content:center;color:#666;font-size:.82em;padding:14px 20px"><?php echo t('msg_read_only');?></div>
  <?php endif;?>
 </div>

 <div class="panel" id="panel-dm">
  <div class="ch"><h2 id="dmTitle"><?php echo t('title_chat');?></h2><div class="dm-options-wrap"><button class="bsm" onclick="toggleDmOptions(event)"><?php echo t('btn_options');?></button><div class="dm-options-menu" id="dmOptionsMenu"><button onclick="viewDmProfile()"><?php echo t('btn_view_profile');?></button><button onclick="reportDmUser()"><?php echo t('btn_report_user');?></button><button onclick="openDmSearch()">Search history</button><button onclick="changeNickname()">Change nickname</button><button class="danger" onclick="deleteDmContact()"><?php echo t('btn_delete_contact');?></button></div></div></div>
  <div class="ma" id="dmMessagesArea"><div class="es"><p><?php echo t('msg_select_contact');?></p></div></div>
  <div class="typing-indicator" id="typingIndicator"></div>
  <div class="upload-progress" id="dmUploadProgress"><div></div></div>
  <div class="md-preview" id="mdPreviewDm"></div>
  <div class="reply-bar" id="replyBar" style="display:none"><span id="replyBarText"></span><button class="bsm" onclick="cancelReply()">&#x2715;</button></div>
  <div class="cia"><textarea id="dmMessageInput" oninput="autoResize(this);onDmInput();onMdInput('mdPreviewDm','dmMessageInput','mdCheckDm')" placeholder="<?php echo t('label_type_message');?>" maxlength="1000" rows="1" style="resize:none;overflow-y:auto;line-height:1.4;max-height:20em"></textarea><input type="file" id="dmMediaFile" style="display:none" onchange="previewAttachment(this, sendDmMessage, 'dmSendBtn')"><button class="bsm" onclick="toggleEmojiPicker(event,'dmMessageInput')" title="Emoji">😊</button><button class="bsm" onclick="toggleFlashMenu(event,this)" title="Attach">📁</button><input type="file" id="flashMediaFileDm" style="display:none" onchange="flashFileChosen(this,'dm')"><label class="md-check"><input type="checkbox" id="mdCheckDm" onchange="onMdInput('mdPreviewDm','dmMessageInput','mdCheckDm')"> Markdown</label><button class="bs" id="dmSendBtn" onclick="sendDmMessage()"><?php echo t('btn_send');?></button></div>
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
  <div class="music-frame"><iframe id="playerFrame" src="../apps/music/index.html"></iframe></div>
 </div>

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
   <div class="er" id="errorMsg"></div><div class="su" id="successMsg"></div>
   <div class="ss"><h3><?php echo t('title_preferred_language');?></h3><div class="fg"><select id="languageSelect" style="width:100%;max-width:300px;padding:8px 12px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.85em;font-family:inherit;outline:none"><option value="en"<?php echo ($currentUser['preferred_language']??'en')==='en'?' selected':'';?>><?php echo t('lang_en');?></option><option value="zh"<?php echo ($currentUser['preferred_language']??'en')==='zh'?' selected':'';?>><?php echo t('lang_zh');?></option><option value="zh_egg"<?php echo ($currentUser['preferred_language']??'en')==='zh_egg'?' selected':'';?>><?php echo t('lang_zh_egg');?></option><option value="wyw"<?php echo ($currentUser['preferred_language']??'en')==='wyw'?' selected':'';?>><?php echo t('lang_wyw');?></option><option value="raw"<?php echo ($currentUser['preferred_language']??'en')==='raw'?' selected':'';?>><?php echo t('lang_raw');?></option></select></div><button class="bsm" onclick="changeLanguage()"><?php echo t('btn_save');?></button></div>
   <div class="ss"><h3><?php echo t('title_display_name');?></h3><div class="fg"><label><?php echo t('msg_display_name_hint');?></label><input type="text" id="displayNameInput" maxlength="256" placeholder="<?php echo t('msg_leave_empty');?>" value="<?php echo htmlspecialchars($currentUser['display_name'] ?? '');?>"></div><button class="bsm" onclick="changeDisplayName()"><?php echo t('btn_save');?></button></div>
   <div class="ss"><h3><?php echo t('title_custom_title');?></h3><div class="fg" style="display:flex;align-items:center;gap:12px"><button class="bsm" id="customTitleBtn" onclick="toggleCustomTitle()" style="min-width:80px"><?php echo $customTitle ? t('btn_disable') : t('btn_enable');?></button><span id="customTitleStatus" style="color:#888;font-size:.78em"><?php echo $customTitle ? t('msg_custom_title_on') : t('msg_custom_title_off');?></span></div><div class="fg" id="customTitleField"<?php echo $customTitle ? '' : ' style="display:none"';?>><input type="text" id="customTitleInput" maxlength="100" placeholder="<?php echo t('label_custom_title_placeholder');?>" value="<?php echo htmlspecialchars($customTitle);?>" style="width:100%;max-width:300px"><button class="bsm" onclick="saveCustomTitle()" style="margin-top:6px"><?php echo t('btn_save');?></button></div></div>
   <div class="ss"><h3><?php echo t('title_privacy_settings');?></h3><div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="privacySearchable" <?php echo ($currentUser['searchable']??1)?'checked':'';?> style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_searchable_label');?></label></div><div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="privacySearchableByUid" <?php echo ($currentUser['searchable_by_uid']??1)?'checked':'';?> style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_searchable_uid_label');?></label></div><button class="bsm" onclick="savePrivacySettings()"><?php echo t('btn_save');?></button></div>
   <div class="ss"><h3><?php echo t('title_data_saver');?></h3><div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="dataSaver" <?php echo ($currentUser['data_saver']??0)?'checked':'';?> onchange="toggleDataSaver()" style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_data_saver_label');?></label></div></div>
    <div class="ss"><h3><?php echo t('title_local_cache');?></h3>
     <div class="fg"><label style="display:flex;align-items:center;gap:10px;cursor:pointer"><input type="checkbox" id="localCacheToggle" <?php echo ($currentUser['local_cache_enabled']??0)?'checked':'';?> onchange="toggleLocalCache()" style="accent-color:#888;width:18px;height:18px"> <?php echo t('msg_local_cache_label');?></label></div>
     <div class="fg"><button class="bsm" onclick="clearLocalCache()" style="color:#e0a040"><?php echo t('btn_clear_local_cache');?></button></div>
    </div>
   <div class="ss"><h3>Emoji Settings</h3>
    <div class="fg"><label>Emoji panel display:</label><select id="emojiPanelMode" style="width:100%;max-width:300px;padding:8px 12px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.85em;font-family:inherit;outline:none"><option value="dynamic">Dynamic (always animated)</option><option value="hover">Dynamic on hover (static preview)</option><option value="static">Static only</option></select></div>
    <div class="fg"><label>Chat emoji display:</label><select id="emojiChatMode" style="width:100%;max-width:300px;padding:8px 12px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.85em;font-family:inherit;outline:none"><option value="dynamic">Dynamic (animated)</option><option value="static">Static only</option></select></div>
    <button class="bsm" onclick="saveEmojiSettings()"><?php echo t('btn_save');?></button></div>
   <div class="ss"><h3><?php echo t('title_profile_photo');?></h3><div style="text-align:center;margin-bottom:10px"><div class="sa" id="moreAvatar" style="margin:0 auto;width:80px;height:80px"><?php if($currentUser['avatar']):?><img src="<?php echo htmlspecialchars($currentUser['avatar']);?>"><?php endif;?></div></div><input type="file" id="avatarFile" accept="image/*" style="color:#aaa;font-size:.8em;margin-bottom:8px"><button class="bsm" onclick="uploadAvatar()"><?php echo t('btn_upload_photo');?></button></div>
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
     <input type="range" id="bgOpacity" min="20" max="100" value="100" oninput="onBgOpacity(this.value)" style="width:100%;max-width:300px;accent-color:#6a9fd8">
    </div>
   </div>
  </div>
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
<div class="modal-overlay" id="duressModal"><div class="modal-box"><h3><?php echo t('title_duress');?></h3><p style="color:#e06060;font-size:.85em;margin-bottom:10px"><?php echo t('duress_desc');?></p><p style="color:#ff8080;font-size:.9em;font-weight:bold;margin-bottom:10px"><?php echo t('duress_warning');?></p><div class="fg" style="text-align:left"><label><?php echo t('label_current_password');?></label><input type="password" id="duressCurrent" style="width:100%"></div><div class="fg" style="text-align:left"><label><?php echo t('label_duress_new');?></label><input type="password" id="duressNew" style="width:100%"></div><div class="fg" style="text-align:left"><label><?php echo t('label_duress_confirm');?></label><input type="password" id="duressNew2" style="width:100%"></div><button class="bsm" onclick="clearDuress()" style="background:#3a3a3a;color:#aaa;margin-top:4px"><?php echo t('btn_clear_duress');?></button><div class="modal-actions"><button class="bsm" onclick="closeDuressModal()"><?php echo t('btn_cancel');?></button><button class="bsm" onclick="saveDuress()" style="background:#4a2020;border-color:#5c2a2a;color:#e06060"><?php echo t('btn_save_duress');?></button></div></div></div>
<div class="modal-overlay" id="deleteModal"><div class="modal-box"><h3><?php echo t('title_delete_account');?></h3><p><?php echo t('msg_delete_warning');?></p><div class="fg" style="text-align:left"><label><input type="radio" name="delMode" value="delete" checked> <?php echo t('del_mode_delete','Directly delete account');?></label></div><div class="fg" style="text-align:left"><label><input type="radio" name="delMode" value="revoke"> <?php echo t('del_mode_revoke','Revoke all chat records (account stays)');?></label></div><div class="fg" style="text-align:left"><label><?php echo t('msg_enter_password_confirm');?></label><input type="password" id="deletePassword" style="width:100%"></div><div class="fg" style="text-align:left"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" id="deleteConfirm" style="width:18px;height:18px"> <?php echo t('del_confirm_label','I confirm I want to delete<div class="modd understand there is NO recovery.');?></label></div><div class="modal-actions"><button class="bsm" onclick="hideDeleteModal()"><?php echo t('btn_cancel');?></button><button class="bsm danger" onclick="confirmDeleteAccount()"><?php echo t('btn_confirm');?></button></div></div></div>
<div class="modal-overlay" id="friendReqModal"><div class="modal-box"><h3 id="friendReqTitle"><?php echo t('btn_add_friend');?></h3><div class="fg" style="text-align:left"><label><?php echo t('label_friend_message');?></label><input type="text" id="friendReqMsg" maxlength="200" style="width:100%;padding:8px 12px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit;outline:none"></div><div class="modal-actions"><button class="bsm" onclick="closeFriendReqModal()"><?php echo t('btn_cancel');?></button><button class="bsm" id="friendReqSendBtn" style="background:#2a4a2a;border-color:#3a6a3a"><?php echo t('btn_send');?></button></div></div></div>
<div class="modal-overlay" id="admModal"><div class="modal-box"><h3 id="admModalTitle"></h3><div class="modal-actions" id="admModalActions"></div><button class="bsm" onclick="closeAdmModal()" style="margin-top:12px"><?php echo t('btn_back');?></button></div></div>
<div class="modal-overlay" id="admPwdModal"><div class="modal-box"><h3><?php echo t('admin_change_password');?></h3><div style="margin-bottom:12px"><input type="text" id="admNewPwd" placeholder="<?php echo t('admin_new_password');?>" style="width:100%;padding:8px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></div><div class="modal-actions"><button class="bsm" onclick="doAdmChangePassword()"><?php echo t('btn_save');?></button><button class="bsm" onclick="closeAdmPwdModal()"><?php echo t('btn_cancel');?></button></div></div></div>
<div class="modal-overlay" id="attachModal"><div class="modal-box"><h3 id="attachTitle"><?php echo t('title_send_attachment');?></h3><p id="attachTo" style="color:#aaa;font-size:.8em"></p><div id="attachPreview"></div><div id="attachInfo" class="att-info"></div><div class="modal-actions"><button class="bsm" onclick="cancelAttachment()"><?php echo t('btn_cancel');?></button><button class="bsm" id="attachSendBtn" style="background:#2a4a2a;border-color:#3a6a3a"><?php echo t('btn_send_attachment');?></button></div></div></div>
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
var TZ='<?php echo $currentUser['timezone'] ?? '+08:00';?>';
var DND=<?php echo (int)($currentUser['dnd'] ?? 0);?>;
var RSTR=<?php echo (int)($currentUser['restricted'] ?? 0);?>;
var DS=<?php echo (int)($currentUser['data_saver'] ?? 0);?>;
var ADMIN=<?php echo $isAdmin ? 'true' : 'false';?>;
var CACHE_KEY='<?php echo htmlspecialchars($currentUser['cache_key'] ?? '', ENT_QUOTES);?>';
var LOCAL_CACHE=<?php echo (int)($currentUser['local_cache_enabled'] ?? 0);?>;
var MYUID=<?php echo (int)($currentUser['user_id'] ?? 0);?>;
var EMOJI_PANEL='<?php echo $currentUser['emoji_panel_mode'] ?? 'dynamic';?>';
var EMOJI_CHAT='<?php echo $currentUser['emoji_chat_mode'] ?? 'dynamic';?>';
var MYLV=<?php echo (int)($currentUser['level'] ?? 1);?>;
var MYEXP=<?php echo (int)($currentUser['exp'] ?? 0);?>;
</script>
<script src="chat.js?v=<?php echo time();?>"></script>
<div class="modal-overlay" id="addDonModal"><div class="modal-box"><h3>Add Donation</h3><table style="width:100%;border-collapse:collapse;font-size:.82em"><tr><td style="padding:6px 12px">DateTime</td><td style="padding:6px"><input type="text" id="addDonDateTime" placeholder="YYYY-MM-DD HH:MM:SS" style="width:100%;padding:6px 10px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></td></tr><tr><td style="padding:6px 12px">User</td><td style="padding:6px"><input type="text" id="addDonUserSearch" placeholder="Search username or UID..." autocomplete="off" oninput="searchDonUser()" style="width:100%;padding:6px 10px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"><input type="hidden" id="addDonUserId"><div id="donUserSearchResults" style="max-height:120px;overflow-y:auto;border:1px solid #333;background:#1a1a1a;display:none"></div></td></tr><tr><td style="padding:6px 12px">WeixinID</td><td style="padding:6px"><input type="text" id="addDonWeixin" placeholder="Optional" style="width:100%;padding:6px 10px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></td></tr><tr><td style="padding:6px 12px">QQ</td><td style="padding:6px"><input type="text" id="addDonQQ" placeholder="Optional" style="width:100%;padding:6px 10px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit"></td></tr></table><div class="modal-actions" style="margin-top:10px"><button class="bsm" onclick="closeAddDonModal()">Cancel</button><button class="bsm" onclick="doAddDonation()" style="background:#2a4a2a;border-color:#3a6a3a">Save</button></div></div></div>
<input type="file" id="customEmojiFile" accept="image/*" multiple style="display:none" onchange="uploadCustomEmoji()"><div class="emoji-popup" id="emojiPopup" style="display:none"><div class="emoji-sidebar"><button class="active" id="emojiTabBuiltin" onclick="switchEmojiTab('builtin')">内置表情</button><button id="emojiTabCustom" onclick="switchEmojiTab('custom')">自定义表情</button></div><div class="emoji-grid" id="emojiGrid"></div></div>

<!-- Flash transfer menu (shared by announcement + DM composer) -->
<div class="flash-menu" id="flashMenu" style="display:none">
 <div onclick="flashMenuUpload()"><img src="../data/res/svg/folder_16.svg" width="14" alt=""><span><?php echo t('flash_upload', '上传');?></span></div>
 <div onclick="flashMenuFlash()"><img src="../data/res/svg/fast_folder_16.svg" width="14" alt=""><span><?php echo t('flash_flash', '闪传（临时）');?></span></div>
 <div onclick="flashMenuMy()"><img src="../data/res/svg/folder_16.svg" width="14" alt=""><span><?php echo t('flash_my', '我的闪传文件');?></span></div>
</div>

<!-- My flash files modal -->
<div class="modal-overlay" id="flashMyModal"><div class="modal-box"><h3><?php echo t('flash_my', '我的闪传文件');?></h3><div id="flashMyList" style="max-height:360px;overflow-y:auto;text-align:left;margin-bottom:14px"></div><div class="modal-actions"><button class="bsm" onclick="closeFlashMyModal()"><?php echo t('btn_cancel');?></button></div></div></div>
<div class="modal-overlay" id="customDialog"><div class="modal-box"><h3 id="cdTitle"></h3><p id="cdMsg" style="color:#aaa;font-size:.82em;margin-bottom:16px"></p><input type="text" id="cdInput" style="width:100%;padding:8px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-family:inherit;margin-bottom:12px;outline:none;display:none"><div class="modal-actions"><button class="bsm" id="cdCancel" onclick="cdResolve(false)">Cancel</button><button class="bsm" id="cdOk" onclick="cdResolve(true)" style="background:#2a4a2a;border-color:#3a6a3a">OK</button></div></div></div>

<!-- EXP toast container (bottom-right, in-page) -->
<div id="expToasts"></div>

<!-- Mobile sidebar drawer: overlay + toggle button -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeMobileSidebar()"></div>
<button id="sidebarToggleBtn" class="hidden" onclick="openMobileSidebar()" title="菜单">&#x276E;</button>
</body></html>
