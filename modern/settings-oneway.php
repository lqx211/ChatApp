<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$u = chatapp_get_user();
$uid = (int)($u['user_id'] ?? 0);
// 单向好友：对方加了但自己还没加回 —— 即 contacts 中自己为 user_to 且 status=pending 的申请
$oneway = [];
if ($uid > 0) {
    $stmt = db()->prepare("SELECT u.username, u.display_name, u.avatar, c.created_at
        FROM contacts c JOIN users u ON u.user_id = c.user_from
        WHERE c.user_to = ? AND c.status = 'pending'
        ORDER BY c.created_at DESC LIMIT 100");
    $stmt->execute([$uid]);
    $oneway = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('set_oneway');?></title>
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('set_oneway', 'One-way friends');?></span>
    <span style="width:28px"></span>
  </div>

  <p class="set-hint"><?php echo t('set_oneway_hint', 'One-way friends are friend requests that added you but you have not yet accepted. After accepting they become two-way friends.');?></p>

  <div class="set-group"><?php echo sprintf(t('set_oneway_count', 'One-way friends (%s)'), count($oneway));?></div>
  <?php if (empty($oneway)):?>
  <div class="set-row" style="cursor:default">
    <span class="row-value" style="text-align:left;color:#5a6270"><?php echo t('set_oneway_empty', 'No one-way friends');?></span>
  </div>
  <?php else:?>
  <?php foreach ($oneway as $ow): $owName = $ow['display_name'] ?: $ow['username'];?>
  <div class="set-row">
    <span class="row-label" style="display:flex;align-items:center;gap:8px;min-width:0">
      <?php if (!empty($ow['avatar'])):?><img class="set-avatar" src="<?php echo htmlspecialchars($ow['avatar']);?>" alt=""><?php endif;?>
      <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($owName);?></span>
    </span>
    <span class="row-value" style="font-size:12px"><?php echo htmlspecialchars(mb_substr($ow['created_at'] ?? '', 0, 10));?></span>
  </div>
  <?php endforeach;?>
  <?php endif;?>

</div>

<script>
function goBack() {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
        window.parent.document.getElementById('profileFrame').src = 'settings-privacy.php';
    } else { history.back(); }
}
</script>

</body>
</html>
