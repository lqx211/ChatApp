<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
// 版本 / 构建日期 / 简介来自 config/info.php
$info = include __DIR__ . '/../config/info.php';
if (!is_array($info)) $info = [];
$version   = (string)($info['version'] ?? '');
$buildDate = (string)($info['build_date'] ?? '');
$intro     = (string)($info['introduction'] ?? '');
$siteUrl   = 'https://lqx211.com';
$repoUrl   = 'https://github.com/lqx211/ChatApp';
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('set_about_chatapp');?></title>
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('set_about', 'About');?></span>
    <span style="width:28px"></span>
  </div>

  <div class="set-about-logo">
    <div class="logo">C</div>
    <div class="name">ChatApp</div>
    <?php if ($version !== ''):?>
    <div class="ver"><?php echo sprintf(t('set_version', 'Version %s'), htmlspecialchars($version));?></div>
    <?php endif;?>
    <?php if ($buildDate !== ''):?>
    <div class="ver"><?php echo sprintf(t('set_build_date', 'Built on %s'), htmlspecialchars($buildDate));?></div>
    <?php endif;?>
  </div>

  <?php if ($intro !== ''):?>
  <div class="set-group"><?php echo t('set_about_intro', 'About');?></div>
  <div class="set-about-article"><?php echo nl2br(htmlspecialchars($intro));?></div>
  <?php endif;?>

  <div class="set-group"><?php echo t('set_privacy', 'Privacy');?></div>
  <div class="set-about-links">
    <a class="set-row" href="<?php echo $siteUrl;?>" target="_blank" rel="noopener">
      <span class="row-label"><?php echo t('set_official_site', 'Official Website');?></span>
      <span class="row-arrow">›</span>
    </a>
    <a class="set-row" href="<?php echo $repoUrl;?>" target="_blank" rel="noopener">
      <span class="row-label"><?php echo t('set_source_repo', 'Source Code');?></span>
      <span class="row-arrow">›</span>
    </a>
  </div>

  <p style="text-align:center;color:#4a5260;font-size:12px;padding:28px 16px 40px"><?php echo t('set_copyright', '© ChatApp · All rights reserved');?></p>

</div>

<script>
function goBack() {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
        window.parent.document.getElementById('profileFrame').src = 'settings.php';
    } else { history.back(); }
}
</script>

</body>
</html>
