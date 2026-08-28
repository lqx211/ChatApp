<?php
/**
 * ChatApp · 设置主页面
 * 在个人主页抽屉 iframe 中打开：chat.js openSettings()
 * 结构按 plan/s.md pseudocode：搜索栏 + 账号与安全 + 功能(消息通知/通用) + 隐私 + 其余
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
$u = chatapp_get_user();

$displayName = htmlspecialchars($u['display_name'] ?? $u['username'] ?? '');
$avatar      = chatapp_avatar_url($u['avatar'] ?? '', $u['username'] ?? '');
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('title_settings');?></title>
<link rel="stylesheet" href="/plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="/modern/style/settings.css?v=20260828">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('title_settings', 'Settings');?></span>
    <span style="width:28px"></span>
  </div>

  <!-- ============ 搜索栏（过滤设置项） ============ -->
  <div class="set-searchbar">
    <div class="set-search-inner">
      <svg class="set-search-ico" viewBox="0 0 24 24" width="15" height="15"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27a6.5 6.5 0 1 0-.7.7l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0A4.5 4.5 0 1 1 14 9.5 4.5 4.5 0 0 1 9.5 14z"/></svg>
      <input type="text" id="setSearch" placeholder="<?php echo t('set_search_placeholder', 'Search');?>" autocomplete="off" oninput="filterSettings(this.value)">
    </div>
  </div>

  <!-- ============ 账号与安全 ============ -->
  <div class="set-group" data-search="账号与安全"><?php echo t('set_account_safety', 'Account & Safety');?></div>
  <div class="set-row" data-search="账号与安全" onclick="navTo('settings-account.php')">
    <span class="row-label"><?php echo t('set_account_safety', 'Account & Safety');?></span>
    <span class="row-arrow">›</span>
  </div>

  <!-- ============ 功能 ============ -->
  <div class="set-group" data-search="功能 消息通知 通用"><?php echo t('set_features', 'Features');?></div>
  <div class="set-row" data-search="消息通知" onclick="navTo('settings-notif.php')">
    <span class="row-label"><?php echo t('set_notifications', 'Notifications');?></span>
    <span class="row-arrow">›</span>
  </div>
  <div class="set-row" data-search="通用" onclick="navTo('settings-general.php')">
    <span class="row-label"><?php echo t('set_general', 'General');?></span>
    <span class="row-arrow">›</span>
  </div>

  <!-- ============ 隐私 ============ -->
  <div class="set-group" data-search="隐私"><?php echo t('set_privacy', 'Privacy');?></div>
  <div class="set-row" data-search="隐私" onclick="navTo('settings-privacy.php')">
    <span class="row-label"><?php echo t('title_privacy_settings', 'Privacy Settings');?></span>
    <span class="row-arrow">›</span>
  </div>

  <!-- ============ 个人资料 ============ -->
  <div class="set-group" data-search="个人资料"><?php echo t('set_profile', 'Profile');?></div>
  <div class="set-row" data-search="个人资料" onclick="navTo('editinfo.php?from=settings')">
    <span class="row-label"><?php echo t('set_edit_profile', 'Edit Profile');?></span>
    <span class="row-value" style="display:flex;align-items:center;justify-content:flex-end;gap:6px">
      <span><?php echo $displayName;?></span>
      <?php if ($avatar):?>
      <img class="set-avatar" src="<?php echo htmlspecialchars($avatar);?>" alt="">
      <?php endif;?>
    </span>
    <span class="row-arrow">›</span>
  </div>

  <!-- ============ 个性装扮 ============ -->
  <div class="set-group" data-search="个性装扮"><?php echo t('set_appearance', 'Appearance');?></div>
  <div class="set-row" data-search="聊天壁纸 壁纸" onclick="navTo('settings-wallpaper.php')">
    <span class="row-label"><?php echo t('set_chat_wallpaper', 'Chat Wallpaper');?></span>
    <span class="row-arrow">›</span>
  </div>
  <div class="set-row" data-search="个人主页封面 封面" onclick="navTo('settings-wallpaper.php?tab=profile')">
    <span class="row-label"><?php echo t('set_profile_cover', 'Profile Cover');?></span>
    <span class="row-arrow">›</span>
  </div>

  <!-- ============ 关于 ============ -->
  <div class="set-group" data-search="关于"><?php echo t('set_about', 'About');?></div>
  <div class="set-row" data-search="关于" onclick="navTo('settings-about.php')">
    <span class="row-label"><?php echo t('set_about_chatapp', 'About ChatApp');?></span>
    <span class="row-arrow">›</span>
  </div>

  <!-- ============ 危险操作 ============ -->
  <div class="set-group" data-search="危险 重置 恢复出厂 factory reset">Danger Zone</div>
  <div class="set-row" data-search="危险 重置 恢复出厂 factory reset" onclick="navTo('settings-factory.php')">
    <span class="row-label set-danger-text">Factory Reset</span>
    <span class="row-arrow">›</span>
  </div>
  <div class="set-row" data-search="危险 升级 更新 upgrade system" onclick="navTo('settings-upgrade.php')">
    <span class="row-label set-danger-text">Upgrade System</span>
    <span class="row-arrow">›</span>
  </div>

  <!-- 退出当前账号（红色） -->
  <button class="set-logout" data-search="退出 注销 登出" onclick="doLogout()"><?php echo t('btn_log_out', 'Log out');?></button>

</div>

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
function goBack() {
    if (window.parent && window.parent.closeMyProfile) window.parent.closeMyProfile();
    else history.back();
}

/* 子页跳转：卡片左滑后切换 iframe src */
function navTo(src) {
    var card = document.querySelector('.card');
    if (!card) { if (window.parent) window.parent.document.getElementById('profileFrame').src = src; return; }
    card.classList.add('slide-out-left');
    setTimeout(function() {
        if (window.parent && window.parent.document.getElementById('profileFrame')) {
            window.parent.document.getElementById('profileFrame').src = src;
        } else {
            location.href = src;
        }
    }, 250);
}

/* ---------------- 搜索过滤 ---------------- */
function filterSettings(q) {
    q = (q || '').trim().toLowerCase();
    var groups = document.querySelectorAll('.card .set-group');
    groups.forEach(function(g) {
        var kw = (g.getAttribute('data-search') || g.textContent || '').toLowerCase();
        g.style.display = (!q || kw.indexOf(q) >= 0) ? '' : 'none';
    });
    var rows = document.querySelectorAll('.card .set-row, .card .set-logout');
    rows.forEach(function(r) {
        var kw = (r.getAttribute('data-search') || r.textContent || '').toLowerCase();
        r.style.display = (!q || kw.indexOf(q) >= 0) ? '' : 'none';
    });
    // 隐藏没有可见行/紧跟的分组：简单起见，分组的可见性由分组自身关键字决定
}

/* ---------------- 退出登录 ---------------- */
function doLogout() {
    if (!confirm('<?php echo t('set_logout_confirm', 'Are you sure you want to log out?');?>')) return;
    if (window.parent && window.parent.logout) { window.parent.logout(); return; }
    var f = new URLSearchParams();
    f.append('action', 'logout');
    fetch('../../api/auth.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString() })
        .then(function() { window.location.href = 'login.php'; });
}
</script>

</body>
</html>
