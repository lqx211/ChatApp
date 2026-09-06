<?php
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();
$birthday = $currentUser['birthday'] ?? '';

// Parse birthday to compute age & zodiac
$birthTs = $birthday ? strtotime($birthday) : 0;
$age = '';
$zodiac = '';
$monthDay = '';
if ($birthTs > 0) {
    // age
    $by = (int)date('Y', $birthTs);
    $cy = (int)date('Y');
    $age = ($cy - $by) . t('bd_age_unit', '岁');
    // month-day display
    $monthDay = sprintf(t('bd_month_day', '%s月%s日'), date('n', $birthTs), date('j', $birthTs));
    // simple zodiac
    $bd = (int)date('j', $birthTs);
    $bm = (int)date('n', $birthTs);
    $zodiacMap = [
        [20, t('p_zodiac_aquarius')],[19, t('p_zodiac_pisces')],[21, t('p_zodiac_aries')],[20, t('p_zodiac_taurus')],
        [21, t('p_zodiac_gemini')],[22, t('p_zodiac_cancer')],[23, t('p_zodiac_leo')],[23, t('p_zodiac_virgo')],
        [23, t('p_zodiac_libra')],[24, t('p_zodiac_scorpio')],[23, t('p_zodiac_sagittarius')],[22, t('p_zodiac_capricorn')]
    ];
    $z = $zodiacMap[$bm - 1];
    $zodiac = ($bd >= $z[0]) ? $z[1] : $zodiacMap[($bm - 2 + 12) % 12][1];
}
$displayBirthday = $birthday ? htmlspecialchars($birthday) : '';
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title><?php echo t('bd_title', '选择出生日期');?></title>
<link rel="stylesheet" href="/plan/editinfo.css?v=20260809">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title"><?php echo t('bd_title', '选择出生日期');?></span>
    <span style="width:28px"></span>
  </div>

  <div class="hint-text"><?php echo t('bd_hint', '你的生日日期不在资料中公开显示');?></div>

  <!-- 年龄 -->
  <div class="form-row">
    <span class="row-label"><?php echo t('bd_age', '年龄');?></span>
    <span class="row-value" id="ageVal"><?php echo $age ?: t('bd_unknown', '未知');?></span>
    <span class="row-arrow" style="visibility:hidden">›</span>
  </div>

  <!-- 生日（点击弹出选择器） -->
  <div class="form-row" onclick="openBirthdayPicker()">
    <span class="row-label"><?php echo t('bd_birthday', '生日');?></span>
    <span class="row-value<?php echo $monthDay ? '' : ' placeholder';?>" id="birthdayBD"><?php echo $monthDay ?: t('bd_please_select', '请选择');?></span>
    <span class="row-arrow">›</span>
  </div>

  <!-- 星座 -->
  <div class="form-row">
    <span class="row-label"><?php echo t('bd_zodiac', '星座');?></span>
    <span class="row-value" id="zodiacVal"><?php echo $zodiac ?: t('bd_unknown', '未知');?></span>
    <span class="row-arrow" style="visibility:hidden">›</span>
  </div>

</div>

<!-- 日期选择器遮罩 + 面板 -->
<div class="picker-overlay" id="birthdayOverlay" onclick="closeBirthdayPicker()"></div>
<div class="picker-panel" id="birthdayPanel">
  <div class="picker-header">
    <button class="picker-cancel" onclick="closeBirthdayPicker()"><?php echo t('bd_cancel', '取消');?></button>
    <span class="picker-title"><?php echo t('bd_pick_title', '选择生日');?></span>
    <button class="picker-confirm" onclick="confirmBirthday()"><?php echo t('bd_confirm', '确定');?></button>
  </div>
  <div class="picker-body">
    <div class="picker-highlight"></div>
    <div class="picker-col">
      <div class="picker-scroll" id="yearScroll"></div>
    </div>
    <div class="picker-col">
      <div class="picker-scroll" id="monthScroll"></div>
    </div>
    <div class="picker-col">
      <div class="picker-scroll" id="dayScroll"></div>
    </div>
  </div>
</div>

<div class="save-toast" id="saveToast">✓ <?php echo t('bd_saved', '已保存');?></div>

<script>
var EBD_T = { ageUnit: <?php echo json_encode(t('bd_age_unit', '岁'));?>, mdFmt: <?php echo json_encode(t('bd_month_day', '%s月%s日'));?>, yUnit: <?php echo json_encode(t('bd_year_unit', '年'));?>, mUnit: <?php echo json_encode(t('bd_month_unit', '月'));?>, dUnit: <?php echo json_encode(t('bd_day_unit', '日'));?>, zodiacs: [<?php echo implode(',', array_map(function ($v) { return json_encode(t('p_zodiac_' . $v)); }, ['aquarius','pisces','aries','taurus','gemini','cancer','leo','virgo','libra','scorpio','sagittarius','capricorn']));?>] };
var _bdYear = <?php echo $birthTs ? (int)date('Y', $birthTs) : 2005;?>;
var _bdMonth = <?php echo $birthTs ? (int)date('n', $birthTs) : 1;?>;
var _bdDay = <?php echo $birthTs ? (int)date('j', $birthTs) : 1;?>;

function goBack() {
    var card = document.querySelector('.card');
    if (!card) { _doBack(); return; }
    card.classList.add('slide-out-right');
    setTimeout(function() {
        _doBack();
    }, 260);
}

function _doBack() {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
        window.parent.document.getElementById('profileFrame').src = 'editinfo.php';
    } else {
        history.back();
    }
}

function showToast() {
    var t = document.getElementById('saveToast');
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}

function computeZodiac(m, d) {
    var cut = [20, 19, 21, 20, 21, 22, 23, 23, 23, 24, 23, 22];
    var z = cut[m - 1];
    var i = (d >= z) ? m - 1 : (m - 2 + 12) % 12;
    return EBD_T.zodiacs[i];
}

function computeAge(y, m, d) {
    var now = new Date();
    var cy = now.getFullYear(), cm = now.getMonth() + 1, cd = now.getDate();
    var age = cy - y;
    if (cm < m || (cm === m && cd < d)) age--;
    return age + EBD_T.ageUnit;
}

// ---- Birthday picker ----
function initBirthdayPicker() {
    buildYearScroll();
    buildMonthScroll();
    buildDayScroll();
    setTimeout(function() {
        scrollToSelected('yearScroll', _bdYear - 1900);
        scrollToSelected('monthScroll', _bdMonth - 1);
        scrollToSelected('dayScroll', _bdDay - 1);
    }, 100);
}

function buildYearScroll() {
    var h = '';
    for (var y = 1900; y <= 2026; y++) {
        h += '<div class="picker-item' + (y === _bdYear ? ' selected' : '') + '" data-val="' + y + '">' + y + EBD_T.yUnit + '</div>';
    }
    document.getElementById('yearScroll').innerHTML = h;
}

function buildMonthScroll() {
    var h = '';
    for (var m = 1; m <= 12; m++) {
        h += '<div class="picker-item' + (m === _bdMonth ? ' selected' : '') + '" data-val="' + m + '">' + m + EBD_T.mUnit + '</div>';
    }
    document.getElementById('monthScroll').innerHTML = h;
}

function buildDayScroll() {
    var days = new Date(_bdYear, _bdMonth, 0).getDate();
    var h = '';
    for (var d = 1; d <= days; d++) {
        h += '<div class="picker-item' + (d === _bdDay ? ' selected' : '') + '" data-val="' + d + '">' + d + EBD_T.dUnit + '</div>';
    }
    document.getElementById('dayScroll').innerHTML = h;
}

function scrollToSelected(id, idx) {
    var el = document.getElementById(id);
    if (!el) return;
    el.scrollTop = idx * 40;
}

function openBirthdayPicker() {
    document.getElementById('birthdayOverlay').classList.add('active');
    document.getElementById('birthdayPanel').classList.add('active');
    initBirthdayPicker();
}

function closeBirthdayPicker() {
    document.getElementById('birthdayOverlay').classList.remove('active');
    document.getElementById('birthdayPanel').classList.remove('active');
}

function confirmBirthday() {
    var ys = document.getElementById('yearScroll');
    var ms = document.getElementById('monthScroll');
    var ds = document.getElementById('dayScroll');
    var itemH = 40;
    var yi = Math.round(ys.scrollTop / itemH);
    var mi = Math.round(ms.scrollTop / itemH);
    var di = Math.round(ds.scrollTop / itemH);

    var yItems = ys.querySelectorAll('.picker-item');
    var mItems = ms.querySelectorAll('.picker-item');
    var dItems = ds.querySelectorAll('.picker-item');

    var yv = parseInt((yItems[Math.min(yi, yItems.length-1)] || {}).getAttribute('data-val') || _bdYear);
    var mv = parseInt((mItems[Math.min(mi, mItems.length-1)] || {}).getAttribute('data-val') || _bdMonth);
    var dv = parseInt((dItems[Math.min(di, dItems.length-1)] || {}).getAttribute('data-val') || _bdDay);

    _bdYear = yv; _bdMonth = mv; _bdDay = dv;

    document.getElementById('birthdayBD').textContent = EBD_T.mdFmt.replace('%s', mv).replace('%s', dv);
    document.getElementById('birthdayBD').classList.remove('placeholder');
    document.getElementById('ageVal').textContent = computeAge(yv, mv, dv);
    document.getElementById('zodiacVal').textContent = computeZodiac(mv, dv);

    // Save to server
    var mm = ('0' + mv).slice(-2);
    var dd = ('0' + dv).slice(-2);
    var fullDate = yv + '-' + mm + '-' + dd;
    var f = new URLSearchParams();
    f.append('action', 'save_birthday');
    f.append('birthday', fullDate);
    fetch('../../api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: f.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) showToast();
    });

    closeBirthdayPicker();
}
</script>

</body>
</html>