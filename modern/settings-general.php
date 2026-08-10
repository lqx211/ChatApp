<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$u = chatapp_get_user();

$customTitle = htmlspecialchars($u['custom_title'] ?? '');
$tz          = htmlspecialchars($u['timezone'] ?? '+08:00');
$dataSaver   = (int)($u['data_saver'] ?? 0);
$localCache  = (int)($u['local_cache_enabled'] ?? 0);
$restricted  = (int)($u['restricted'] ?? 0);

$langMap = [
    'en' => 'English（英语）',
    'zh' => '中文',
    'zh_egg' => '中文·彩蛋',
    'wyw' => '文言文',
    'raw' => 'Raw（原始）',
];
$curLang = $u['preferred_language'] ?? 'en';

$tzPresets = [
    '+00:00', '+01:00', '+02:00', '+03:00', '+03:30', '+04:00', '+05:00', '+05:30',
    '+06:00', '+07:00', '+08:00', '+09:00', '+09:30', '+10:00', '+11:00', '+12:00',
    '-01:00', '-02:00', '-03:00', '-03:30', '-04:00', '-05:00', '-06:00', '-07:00',
    '-08:00', '-09:00', '-10:00',
];
$tzPresetNames = [
    '+00:00' => 'UTC / 伦敦',
    '+08:00' => '北京时间',
    '+09:00' => '东京 / 首尔',
    '+01:00' => '柏林 / 巴黎',
    '+05:30' => '新德里',
    '-05:00' => '纽约（美东）',
    '-08:00' => '洛杉矶（美西）',
];
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('set_general');?></title>
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('set_general', 'General');?></span>
    <span style="width:28px"></span>
  </div>

  <div class="set-group"><?php echo t('set_language', 'Language');?></div>
  <div class="set-row" onclick="openLangPicker()">
    <span class="row-label"><?php echo t('set_language', 'Language');?></span>
    <span class="row-value" id="langVal"><?php echo htmlspecialchars($langMap[$curLang] ?? $curLang);?></span>
    <span class="row-arrow">›</span>
  </div>
  <div class="set-row" onclick="openTitleDialog()">
    <span class="row-label"><?php echo t('set_custom_title', 'Custom Title');?></span>
    <span class="row-value" id="titleVal"><?php echo $customTitle ?: t('msg_custom_title_off', 'Custom title is OFF');?></span>
    <span class="row-arrow">›</span>
  </div>
  <div class="set-row" onclick="openTzPicker()">
    <span class="row-label"><?php echo t('title_timezone', 'Timezone');?></span>
    <span class="row-value" id="tzVal"><?php echo $tz;?></span>
    <span class="row-arrow">›</span>
  </div>

  <div class="set-group"><?php echo t('set_emoji', 'Emoji');?></div>
  <div class="set-row" onclick="navTo('settings-emoji.php')">
    <span class="row-label"><?php echo t('set_emoji_settings', 'Emoji Settings');?></span>
    <span class="row-arrow">›</span>
  </div>

  <div class="set-group"><?php echo t('set_network', 'Network');?></div>
  <div class="set-row" style="cursor:default">
    <span class="row-label"><?php echo t('set_data_saver', 'Data Saver');?></span>
    <label class="set-switch">
      <input type="checkbox" id="dataSaverSw" <?php echo $dataSaver ? 'checked' : '';?> <?php echo $restricted ? 'disabled' : '';?> onchange="toggleDataSaver(this)">
      <span class="track"></span>
    </label>
  </div>
  <div class="set-row" style="cursor:default">
    <span class="row-label"><?php echo t('set_local_cache', 'Local Cache');?></span>
    <label class="set-switch">
      <input type="checkbox" id="localCacheSw" <?php echo $localCache ? 'checked' : '';?> onchange="toggleLocalCache(this)">
      <span class="track"></span>
    </label>
  </div>
  <div class="set-row" onclick="clearLocalCache()">
    <span class="row-label"><?php echo t('set_clear_local_cache', 'Clear Local Cache');?></span>
    <span class="row-arrow">›</span>
  </div>

</div>

<!-- ================= 语言选择（底部弹层） ================= -->
<div class="picker-overlay" id="langOverlay" onclick="closeLangPicker()"></div>
<div class="picker-panel" id="langPanel">
  <div class="picker-header">
    <button class="picker-cancel" onclick="closeLangPicker()"><?php echo t('btn_cancel', 'Cancel');?></button>
    <span class="picker-title"><?php echo t('set_language', 'Language');?></span>
    <span style="width:28px"></span>
  </div>
  <div class="picker-option" data-lang="en">English（英语）</div>
  <div class="picker-option" data-lang="zh">中文</div>
  <div class="picker-option" data-lang="zh_egg">中文·彩蛋</div>
  <div class="picker-option" data-lang="wyw">文言文</div>
  <div class="picker-option" data-lang="raw">Raw（原始）</div>
</div>

<!-- ================= 时区选择（底部弹层） ================= -->
<div class="picker-overlay" id="tzOverlay" onclick="closeTzPicker()"></div>
<div class="picker-panel" id="tzPanel">
  <div class="picker-header">
    <button class="picker-cancel" onclick="closeTzPicker()"><?php echo t('btn_cancel', 'Cancel');?></button>
    <span class="picker-title"><?php echo t('title_timezone', 'Timezone');?></span>
    <span style="width:28px"></span>
  </div>
  <?php foreach ($tzPresets as $tp): $tpName = $tzPresetNames[$tp] ?? '';?>
  <div class="picker-option" data-tz="<?php echo $tp;?>">UTC<?php echo $tp;?><?php echo $tpName ? '（'.$tpName.'）' : '';?></div>
  <?php endforeach;?>
</div>

<!-- ================= 自定义头衔弹窗 ================= -->
<div class="set-dialog-overlay" id="titleOverlay" onclick="closeTitleDialog()"></div>
<div class="set-dialog" id="titleDialog">
  <h3><?php echo t('set_custom_title', 'Custom Title');?></h3>
  <p><?php echo t('msg_custom_title_hint', 'Shown at the top of the chat page, up to 100 characters.');?></p>
  <input type="text" id="titleInput" maxlength="100" placeholder="<?php echo t('label_custom_title_placeholder', 'Enter custom title name');?>" value="<?php echo $customTitle;?>">
  <div class="set-dialog-actions">
    <button class="cancel" onclick="closeTitleDialog()"><?php echo t('btn_cancel', 'Cancel');?></button>
    <button class="ok" onclick="saveTitle()"><?php echo t('btn_save', 'Save');?></button>
  </div>
</div>

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
var CUR_LANG = <?php echo json_encode($curLang);?>;

function goBack() {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
        window.parent.document.getElementById('profileFrame').src = 'settings.php';
    } else { history.back(); }
}
function navTo(src) {
    var card = document.querySelector('.card');
    if (!card) { if (window.parent) window.parent.document.getElementById('profileFrame').src = src; return; }
    card.classList.add('slide-out-left');
    setTimeout(function() {
        if (window.parent && window.parent.document.getElementById('profileFrame')) {
            window.parent.document.getElementById('profileFrame').src = src;
        } else { location.href = src; }
    }, 250);
}
function showToast() {
    var t = document.getElementById('saveToast');
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
function api(action, data) {
    var f = new URLSearchParams();
    f.append('action', action);
    for (var k in (data || {})) f.append(k, data[k]);
    return fetch('../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); });
}

/* 语言 */
function openLangPicker() {
    document.querySelectorAll('#langPanel .picker-option').forEach(function(o) {
        o.classList.toggle('selected', o.getAttribute('data-lang') === CUR_LANG);
    });
    document.getElementById('langOverlay').classList.add('active');
    document.getElementById('langPanel').classList.add('active');
}
function closeLangPicker() {
    document.getElementById('langOverlay').classList.remove('active');
    document.getElementById('langPanel').classList.remove('active');
}
function selectLang(lang) {
    CUR_LANG = lang;
    var names = { 'en':'English（英语）', 'zh':'中文', 'zh_egg':'中文·彩蛋', 'wyw':'文言文', 'raw':'Raw（原始）' };
    document.getElementById('langVal').textContent = names[lang] || lang;
    api('change_language', { language: lang }).then(function(d) {
        if (d.success) {
            if (window.parent && window.parent.location) window.parent.location.reload();
            else location.reload();
        }
    });
    closeLangPicker();
}

/* 时区 */
function openTzPicker() {
    var cur = document.getElementById('tzVal').textContent;
    document.querySelectorAll('#tzPanel .picker-option').forEach(function(o) {
        o.classList.toggle('selected', o.getAttribute('data-tz') === cur);
    });
    document.getElementById('tzOverlay').classList.add('active');
    document.getElementById('tzPanel').classList.add('active');
}
function closeTzPicker() {
    document.getElementById('tzOverlay').classList.remove('active');
    document.getElementById('tzPanel').classList.remove('active');
}
function selectTz(tz) {
    document.getElementById('tzVal').textContent = tz;
    api('change_timezone', { timezone: tz }).then(function(d) { if (d.success) showToast(); });
    closeTzPicker();
}

/* 自定义头衔 */
function openTitleDialog() {
    document.getElementById('titleOverlay').classList.add('active');
    document.getElementById('titleDialog').classList.add('active');
    setTimeout(function() { document.getElementById('titleInput').focus(); }, 120);
}
function closeTitleDialog() {
    document.getElementById('titleOverlay').classList.remove('active');
    document.getElementById('titleDialog').classList.remove('active');
}
function saveTitle() {
    var v = document.getElementById('titleInput').value.trim();
    api('change_custom_title', { custom_title: v }).then(function(d) {
        if (d.success) {
            document.getElementById('titleVal').textContent = v || '未开启';
            showToast();
            closeTitleDialog();
        }
    });
}

/* 省流量 */
function toggleDataSaver(el) {
    api('toggle_data_saver').then(function(d) {
        el.checked = !!d.data_saver;
        if (d.success) {
            if (window.parent) window.parent.DS = d.data_saver;
            showToast();
        }
    });
}

/* 本地缓存 */
function toggleLocalCache(el) {
    api('toggle_local_cache', { enabled: el.checked ? 1 : 0 }).then(function(d) {
        if (d.success) {
            if (window.parent) window.parent.LOCAL_CACHE = d.local_cache_enabled;
            showToast();
        }
    });
}
function clearLocalCache() {
    if (!confirm('<?php echo t('set_clear_cache_confirm', 'Are you sure you want to clear the local cache?');?>')) return;
    if (window.parent && window.parent.lcClearAll) {
        window.parent.lcClearAll().then(function() { showToast(); });
    } else {
        showToast();
    }
}

/* 弹层选项绑定 */
document.querySelectorAll('#langPanel .picker-option').forEach(function(o) {
    o.addEventListener('click', function() { selectLang(o.getAttribute('data-lang')); });
});
document.querySelectorAll('#tzPanel .picker-option').forEach(function(o) {
    o.addEventListener('click', function() { selectTz(o.getAttribute('data-tz')); });
});
document.getElementById('titleInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') saveTitle();
});
</script>

</body>
</html>
