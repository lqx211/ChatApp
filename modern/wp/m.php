<?php
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();

$currentUser = chatapp_get_user();
$meAvatar = $currentUser['avatar'] ?? '';
if (!empty($meAvatar) && strpos($meAvatar, 'data:') !== 0 && preg_match('/^[0-9a-zA-Z_]+\.(png|jpg|jpeg|gif|webp)$/i', $meAvatar)) {
    $meAvatar = '../../api/avatar.php?u=' . urlencode($currentUser['username'] ?? '');
}
?><!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<title>ChatApp</title>
<link rel="stylesheet" href="../style/m.css?v=<?php echo time();?>">
</head>
<body>

<!-- ================= 聊天窗口（全屏覆盖底部导航） ================= -->
<section class="chat-screen" id="chatScreen" style="display:none">
  <header class="m-header chat-header">
    <button class="m-icon-btn" id="chatBackBtn">‹</button>
    <img class="chat-hdr-avatar" id="chatHdrAvatar" alt="" style="display:none">
    <div class="chat-hdr-info">
      <div class="chat-hdr-name" id="chatTitle">…</div>
      <div class="chat-hdr-status" id="chatHdrStatus"></div>
    </div>
    <button class="m-icon-btn" id="chatMoreBtn">⋯</button>
  </header>
  <div class="chat-body" id="chatBody"></div>
  <div class="emoji-panel" id="emojiPanel" style="display:none">
    <div class="emoji-tabs">
      <button class="emoji-tab active" id="emojiTabBuiltin"><img src="../../data/res/cil/cil-smile.svg" style="width:16px;height:16px;vertical-align:-3px;filter:brightness(0) invert(1)"></button>
      <button class="emoji-tab" id="emojiTabCustom"><img src="../../data/res/cil/cil-plus.svg" style="width:15px;height:15px;vertical-align:-3px;filter:brightness(0) invert(1)"></button>
    </div>
    <div class="emoji-grid" id="emojiGridBuiltin"></div>
    <div class="emoji-grid" id="emojiGridCustom" style="display:none"></div>
  </div>
  <div class="chat-inputbar">
    <input type="file" id="chatMediaFile" multiple style="display:none">
    <button class="input-icon" id="chatAttachBtn" title="Attach"><img src="../../data/res/cil/cil-paperclip.svg" style="width:18px;height:18px;vertical-align:-3px;filter:brightness(0) invert(1)"></button>
    <button class="input-icon" id="chatEmojiBtn"><img src="../../data/res/cil/cil-smile.svg" style="width:18px;height:18px;vertical-align:-3px;filter:brightness(0) invert(1)"></button>
    <input id="chatInput" placeholder="<?php echo t('m_enter_message');?>" autocomplete="off" maxlength="32767">
    <button id="chatSendBtn"><?php echo t('btn_send');?></button>
  </div>
</section>

<!-- 聊天更多菜单 -->
<div class="sheet" id="chatMenuSheet" style="display:none">
  <button class="sheet-item" id="menuViewProfile"><?php echo t('m_view_profile');?></button>
  <button class="sheet-item" id="menuClearHistory"><?php echo t('m_clear_history');?></button>
  <button class="sheet-item" id="menuSheetCancel"><?php echo t('btn_cancel');?></button>
</div>

<!-- 「+」快捷入口 -->
<div class="sheet" id="quickSheet" style="display:none">
  <div class="sheet-grid">
    <button class="sheet-icon" id="quickAddFriend"><span>＋</span><?php echo t('m_add_friend');?></button>
    <button class="sheet-icon" id="quickNewGroup"><span>群</span><?php echo t('m_new_group');?></button>
    <button class="sheet-icon" id="quickScan"><span>◈</span><?php echo t('m_scan');?></button>
    <button class="sheet-icon" id="quickQr"><span>▦</span><?php echo t('m_qr');?></button>
  </div>
  <button class="sheet-item" id="quickCancel"><?php echo t('btn_cancel');?></button>
</div>

<!-- 添加好友弹窗 -->
<div class="modal-overlay" id="afOverlay" style="display:none">
  <div class="modal-box">
    <h3><?php echo t('m_add_friend');?></h3>
    <input type="text" id="afSearchInput" placeholder="<?php echo t('m_search_user');?>" autocomplete="off">
    <div id="afSearchResult" class="af-result"></div>
    <div class="modal-actions">
      <button class="bsm" id="afClose"><?php echo t('btn_cancel');?></button>
    </div>
  </div>
</div>

<!-- 文本输入弹窗（群名等，移动端不支持 prompt()） -->
<div class="modal-overlay" id="inputOverlay" style="display:none">
  <div class="modal-box">
    <h3 id="inputTitle">Input</h3>
    <input type="text" id="inputField" maxlength="40" autocomplete="off">
    <div class="modal-actions">
      <button class="bsm" id="inputCancel"><?php echo t('btn_cancel');?></button>
      <button class="bsm" id="inputOk"><?php echo t('btn_ok');?></button>
    </div>
  </div>
</div>

<!-- 轻提示 -->
<div class="m-toast" id="mToast"></div>

<!-- ================= 消息 ================= -->
<section class="screen" id="screenMsg">
  <header class="m-header msg-header">
    <button class="hdr-avatar" id="hdrAvatarBtn" aria-label="account">
      <?php if ($meAvatar): ?><img src="<?php echo htmlspecialchars($meAvatar);?>" alt=""><?php else: ?><?php echo htmlspecialchars(mb_substr($currentUser['display_name'] ?: $currentUser['username'], 0, 1));?><?php endif; ?>
    </button>
    <div class="hdr-info">
      <div class="hdr-name"><?php echo htmlspecialchars($currentUser['display_name'] ?: $currentUser['username']);?></div>
      <div class="hdr-status"><?php echo t('msg_online_status');?></div>
    </div>
    <button class="hdr-plus" id="hdrPlusBtn">+</button>
  </header>
  <div class="m-searchbar"><input id="msgSearchInput" placeholder="<?php echo t('btn_search');?>" autocomplete="off"></div>
  <div class="list" id="conversationList"><div class="empty"><?php echo t('msg_loading');?></div></div>
</section>

<!-- ================= 联系人 ================= -->
<section class="screen" id="screenContacts" style="display:none">
  <header class="m-header"><span><?php echo t('title_contacts');?></span></header>
  <div class="contacts-scroll">
    <div class="m-section-hdr">My Groups 我的群聊</div>
    <div class="list" id="groupList"><div class="empty"><?php echo t('msg_loading');?></div></div>
    <div class="m-section-hdr"><?php echo t('title_contacts');?></div>
    <div class="list" id="contactList"><div class="empty"><?php echo t('msg_loading');?></div></div>
  </div>
</section>

<!-- ================= 动态 ================= -->
<section class="screen" id="screenDiscover" style="display:none">
  <header class="m-header"><span><?php echo t('m_tab_discover');?></span></header>
  <div class="list" id="discoverList"><div class="empty"><?php echo t('msg_loading');?></div></div>
</section>

<!-- ================= 我的 ================= -->
<section class="screen" id="screenMe" style="display:none">
  <header class="m-header"><span><?php echo t('m_tab_me');?></span></header>
  <div class="me-card">
    <?php if ($meAvatar): ?><img class="me-avatar" src="<?php echo htmlspecialchars($meAvatar);?>" alt=""><?php else: ?><div class="me-avatar me-avatar-empty"><?php echo htmlspecialchars(mb_substr($currentUser['display_name'] ?: $currentUser['username'], 0, 1));?></div><?php endif; ?>
    <div class="me-name"><?php echo htmlspecialchars($currentUser['display_name'] ?: $currentUser['username']);?><span class="me-uid">(<?php echo (int)$currentUser['user_id'];?>)</span></div>
    <div class="me-sub"><?php echo htmlspecialchars($currentUser['username']);?></div>
  </div>
  <div class="m-group">
    <button class="m-cell" id="meSettingsBtn"><span><?php echo t('title_settings');?></span><i>›</i></button>
    <button class="m-cell" id="meEditProfileBtn"><span><?php echo t('set_edit_profile');?></span><i>›</i></button>
    <a class="m-cell" href="chat.php"><span><?php echo t('m_desktop_version');?></span><i>›</i></a>
  </div>
  <div class="m-group">
    <button class="m-cell m-danger" id="meLogoutBtn"><span><?php echo t('btn_log_out');?></span></button>
  </div>
</section>

<!-- 全屏 iframe 页面（资料/设置/编辑资料，避免整页跳转导致返回黑屏） -->
<div class="frame-overlay" id="frameOverlay" style="display:none">
  <header class="m-header frame-header">
    <button class="m-icon-btn" id="frameBackBtn">‹</button>
    <div class="frame-title" id="frameTitle"></div>
    <div style="width:36px"></div>
  </header>
  <iframe id="frameContent" src="about:blank"></iframe>
</div>

<!-- ================= 底部导航 ================= -->
<nav class="tabbar" id="tabbar">
  <button class="tab active" data-tab="msg">
    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8.5 8.5 0 0 1-8.5 8.5c-1.4 0-2.7-.3-3.9-.9L3 21l1.4-5.6A8.5 8.5 0 1 1 21 12z"/></svg>
    <span><?php echo t('m_tab_messages');?></span>
  </button>
  <button class="tab" data-tab="contacts">
    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.6-3 2.9-4.5 5.5-4.5s4.9 1.5 5.5 4.5"/><circle cx="17" cy="9" r="2.4"/><path d="M16.5 14.6c2.2.3 3.9 1.6 4.4 4"/></svg>
    <span><?php echo t('title_contacts');?></span>
  </button>
  <button class="tab" data-tab="discover">
    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M15.5 8.5 14 14l-5.5 1.5L10 10z"/></svg>
    <span><?php echo t('m_tab_discover');?></span>
  </button>
  <button class="tab" data-tab="me">
    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.7-3.4 3.4-5 7-5s6.3 1.6 7 5"/></svg>
    <span><?php echo t('m_tab_me');?></span>
  </button>
</nav>

<script>
var LANG=<?php echo json_encode(lang_load());?>;
var M_USER=<?php echo json_encode([
    'username' => $currentUser['username'] ?? '',
    'uid' => (int)($currentUser['user_id'] ?? 0),
    'display_name' => $currentUser['display_name'] ?: ($currentUser['username'] ?? ''),
    'avatar' => $meAvatar,
]);?>;
</script>
<script src="../scripts/markdown.js?v=<?php echo time();?>"></script>
<script src="../scripts/m.js?v=<?php echo time();?>"></script>
</body>
</html>
