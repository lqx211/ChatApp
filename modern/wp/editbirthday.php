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
    $age = ($cy - $by) . '岁';
    // month-day display
    $monthDay = date('n月j日', $birthTs);
    // simple zodiac
    $bd = (int)date('j', $birthTs);
    $bm = (int)date('n', $birthTs);
    $zodiacMap = [
        [20, '水瓶座'],[19, '双鱼座'],[21, '白羊座'],[20, '金牛座'],
        [21, '双子座'],[22, '巨蟹座'],[23, '狮子座'],[23, '处女座'],
        [23, '天秤座'],[24, '天蝎座'],[23, '射手座'],[22, '摩羯座']
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
<title>选择出生日期</title>
<link rel="stylesheet" href="../../plan/editinfo.css?v=20260809">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title">选择出生日期</span>
    <span style="width:28px"></span>
  </div>

  <div class="hint-text">你的生日日期不在资料中公开显示</div>

  <!-- 年龄 -->
  <div class="form-row">
    <span class="row-label">年龄</span>
    <span class="row-value" id="ageVal"><?php echo $age ?: '未知';?></span>
    <span class="row-arrow" style="visibility:hidden">›</span>
  </div>

  <!-- 生日（点击弹出选择器） -->
  <div class="form-row" onclick="openBirthdayPicker()">
    <span class="row-label">生日</span>
    <span class="row-value<?php echo $monthDay ? '' : ' placeholder';?>" id="birthdayBD"><?php echo $monthDay ?: '请选择';?></span>
    <span class="row-arrow">›</span>
  </div>

  <!-- 星座 -->
  <div class="form-row">
    <span class="row-label">星座</span>
    <span class="row-value" id="zodiacVal"><?php echo $zodiac ?: '未知';?></span>
    <span class="row-arrow" style="visibility:hidden">›</span>
  </div>

</div>

<!-- 日期选择器遮罩 + 面板 -->
<div class="picker-overlay" id="birthdayOverlay" onclick="closeBirthdayPicker()"></div>
<div class="picker-panel" id="birthdayPanel">
  <div class="picker-header">
    <button class="picker-cancel" onclick="closeBirthdayPicker()">取消</button>
    <span class="picker-title">选择生日</span>
    <button class="picker-confirm" onclick="confirmBirthday()">确定</button>
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

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
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
    var map = [
        [20, '水瓶座'],[19, '双鱼座'],[21, '白羊座'],[20, '金牛座'],
        [21, '双子座'],[22, '巨蟹座'],[23, '狮子座'],[23, '处女座'],
        [23, '天秤座'],[24, '天蝎座'],[23, '射手座'],[22, '摩羯座']
    ];
    var z = map[m - 1];
    return (d >= z[0]) ? z[1] : map[(m - 2 + 12) % 12][1];
}

function computeAge(y, m, d) {
    var now = new Date();
    var cy = now.getFullYear(), cm = now.getMonth() + 1, cd = now.getDate();
    var age = cy - y;
    if (cm < m || (cm === m && cd < d)) age--;
    return age + '岁';
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
        h += '<div class="picker-item' + (y === _bdYear ? ' selected' : '') + '" data-val="' + y + '">' + y + '年</div>';
    }
    document.getElementById('yearScroll').innerHTML = h;
}

function buildMonthScroll() {
    var h = '';
    for (var m = 1; m <= 12; m++) {
        h += '<div class="picker-item' + (m === _bdMonth ? ' selected' : '') + '" data-val="' + m + '">' + m + '月</div>';
    }
    document.getElementById('monthScroll').innerHTML = h;
}

function buildDayScroll() {
    var days = new Date(_bdYear, _bdMonth, 0).getDate();
    var h = '';
    for (var d = 1; d <= days; d++) {
        h += '<div class="picker-item' + (d === _bdDay ? ' selected' : '') + '" data-val="' + d + '">' + d + '日</div>';
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

    document.getElementById('birthdayBD').textContent = mv + '月' + dv + '日';
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