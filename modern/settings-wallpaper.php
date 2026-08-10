<?php
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$tab = $_GET['tab'] ?? 'chat';
if ($tab !== 'profile') $tab = 'chat';
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=428, initial-scale=1.0, user-scalable=no">
<title>个性装扮</title>
<link rel="stylesheet" href="../plan/editinfo.css?v=20260809">
<link rel="stylesheet" href="settings.css?v=20260810">
</head>
<body>

<div class="card">

  <div class="nav-bar">
    <button class="nav-btn" onclick="goBack()">‹</button>
    <span class="nav-title">个性装扮</span>
    <span style="width:28px"></span>
  </div>

  <div class="set-tabs">
    <button id="tabChat" onclick="switchTab('chat')">聊天壁纸</button>
    <button id="tabProfile" onclick="switchTab('profile')">个人主页封面</button>
  </div>

  <!-- ============ 聊天壁纸 ============ -->
  <div id="panelChat" <?php echo $tab === 'chat' ? '' : 'style="display:none"';?>>
    <div class="set-group">聊天壁纸</div>
    <div class="set-wall-preview" id="chatPreview">
      <div class="ph">无背景</div>
    </div>
    <div class="set-btn-row">
      <input type="file" id="chatBgFile" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="onChatUpload(this)">
      <button class="set-btn" onclick="document.getElementById('chatBgFile').click()">上传背景</button>
      <button class="set-btn ghost" onclick="removeChatBg()">移除背景</button>
    </div>
    <div class="set-group">系统预设</div>
    <div class="set-wall-presets" id="chatPresets"><div class="ph" style="padding:16px;color:#5a6270;font-size:13px">加载中…</div></div>
    <div class="set-group">模糊度</div>
    <div class="set-slider-row">
      <div class="lab"><span>模糊</span><span class="val" id="blurVal">0px</span></div>
      <input type="range" class="set-slider" id="blurRange" min="0" max="40" value="0" oninput="onBlur(this.value)">
    </div>
    <div class="set-group">透明度</div>
    <div class="set-slider-row">
      <div class="lab"><span>透明度</span><span class="val" id="opacityVal">100%</span></div>
      <input type="range" class="set-slider" id="opacityRange" min="20" max="100" value="100" oninput="onOpacity(this.value)">
    </div>
  </div>

  <!-- ============ 个人主页封面 ============ -->
  <div id="panelProfile" <?php echo $tab === 'profile' ? '' : 'style="display:none"';?>>
    <div class="set-group">个人主页封面</div>
    <p class="set-hint">上传的封面会显示在你的个人主页顶部，支持图片或视频（mp4 / webm）。</p>
    <div class="set-wall-preview" id="profilePreview">
      <div class="ph">无封面</div>
    </div>
    <div class="set-btn-row">
      <input type="file" id="profileBgFile" accept="image/*,video/mp4,video/webm" style="display:none" onchange="onProfileUpload(this)">
      <button class="set-btn" onclick="document.getElementById('profileBgFile').click()">上传封面</button>
      <button class="set-btn ghost" onclick="removeProfileBg()">移除封面</button>
    </div>
  </div>

</div>

<div class="save-toast" id="saveToast">✓ 已保存</div>

<script>
var BG_CACHE_KEY = 'chatapp_bg_v1';

function goBack() {
    if (window.parent && window.parent.document.getElementById('profileFrame')) {
        window.parent.document.getElementById('profileFrame').src = 'settings.php';
    } else { history.back(); }
}
function showToast() {
    var t = document.getElementById('saveToast');
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 2000);
}
function switchTab(tab) {
    document.getElementById('tabChat').classList.toggle('active', tab === 'chat');
    document.getElementById('tabProfile').classList.toggle('active', tab === 'profile');
    document.getElementById('panelChat').style.display = tab === 'chat' ? '' : 'none';
    document.getElementById('panelProfile').style.display = tab === 'profile' ? '' : 'none';
}
function setPreview(el, url) {
    el.style.backgroundImage = url ? 'url("' + url + '")' : '';
    var ph = el.querySelector('.ph');
    if (ph) ph.style.display = url ? 'none' : 'flex';
}

/* ================= 聊天壁纸 ================= */
function loadChatBg() {
    fetch('../api/settings.php?action=get_background').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) return;
        setPreview(document.getElementById('chatPreview'), d.url);
        // 预设
        var ph = document.getElementById('chatPresets');
        if (d.presets && d.presets.length) {
            var h = '';
            for (var i = 0; i < d.presets.length; i++) {
                var p = d.presets[i];
                var isCur = d.url && d.url.indexOf(p.url) === 0;
                h += '<div class="preset' + (isCur ? ' active' : '') + '" style="background-image:url(\'' + p.url + '\')" onclick="setPreset(\'' + p.name + '\', this)"><span class="nm">' + p.name + '</span></div>';
            }
            ph.innerHTML = h;
        } else ph.innerHTML = '<div style="padding:16px;color:#5a6270;font-size:13px;grid-column:1/-1">无预设</div>';
        // 模糊/透明度
        var c = {};
        try { c = JSON.parse(localStorage.getItem(BG_CACHE_KEY) || '{}'); } catch(e) {}
        document.getElementById('blurRange').value = c.blur || 0;
        document.getElementById('opacityRange').value = c.opacity || 100;
        document.getElementById('blurVal').textContent = (c.blur || 0) + 'px';
        document.getElementById('opacityVal').textContent = (c.opacity || 100) + '%';
    });
}
function onChatUpload(input) {
    var f = input.files[0];
    if (!f) return;
    if (f.size > 32 * 1024 * 1024) { alert('文件过大（最大 32MB）'); return; }
    var reader = new FileReader();
    reader.onload = function(ev) {
        var frm = new URLSearchParams();
        frm.append('action', 'upload_background');
        frm.append('image', ev.target.result);
        fetch('../api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: frm.toString()
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.success) {
                setPreview(document.getElementById('chatPreview'), d.url);
                if (window.parent && window.parent.bgEnable) window.parent.bgEnable(d.url, 'force-' + Date.now());
                input.value = '';
                loadChatBg();
                showToast();
            } else alert(d.error || '上传失败');
        });
    };
    reader.readAsDataURL(f);
}
function removeChatBg() {
    if (!confirm('确定要移除聊天壁纸吗？')) return;
    var frm = new URLSearchParams();
    frm.append('action', 'remove_background');
    fetch('../api/settings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: frm.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            setPreview(document.getElementById('chatPreview'), null);
            if (window.parent && window.parent.bgEnable) window.parent.bgEnable(null);
            localStorage.removeItem(BG_CACHE_KEY);
            loadChatBg();
            showToast();
        }
    });
}
function setPreset(name, el) {
    var frm = new URLSearchParams();
    frm.append('action', 'set_preset_background');
    frm.append('name', name);
    fetch('../api/settings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: frm.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            document.querySelectorAll('#chatPresets .preset').forEach(function(x) { x.classList.remove('active'); });
            el.classList.add('active');
            setPreview(document.getElementById('chatPreview'), d.url);
            if (window.parent && window.parent.bgEnable) window.parent.bgEnable(d.url, 'preset-' + Date.now());
            showToast();
        } else alert(d.error || '设置失败');
    });
}
function saveBgPrefs() {
    var c = {};
    try { c = JSON.parse(localStorage.getItem(BG_CACHE_KEY) || '{}'); } catch(e) {}
    c.blur = document.getElementById('blurRange').value;
    c.opacity = document.getElementById('opacityRange').value;
    try { localStorage.setItem(BG_CACHE_KEY, JSON.stringify(c)); } catch(e) {}
    if (window.parent && window.parent.bgApply) window.parent.bgApply(c.blur, c.opacity);
}
function onBlur(v) {
    document.getElementById('blurVal').textContent = v + 'px';
    saveBgPrefs();
}
function onOpacity(v) {
    document.getElementById('opacityVal').textContent = v + '%';
    saveBgPrefs();
}

/* ================= 个人主页封面 ================= */
function loadProfileBg() {
    fetch('../api/settings.php?action=get_profile_bg').then(function(r) { return r.json(); }).then(function(d) {
        if (!d.success) return;
        setPreview(document.getElementById('profilePreview'), d.url);
    });
}
function onProfileUpload(input) {
    var f = input.files[0];
    if (!f) return;
    if (f.size > 64 * 1024 * 1024) { alert('文件过大（最大 64MB）'); return; }
    var xhr = new XMLHttpRequest();
    var form = new FormData();
    form.append('action', 'upload_profile_bg');
    form.append('file', f);
    xhr.open('POST', '../api/settings.php');
    xhr.onload = function() {
        try {
            var d = JSON.parse(xhr.responseText);
            if (d.success) {
                setPreview(document.getElementById('profilePreview'), d.url);
                input.value = '';
                loadProfileBg();
                showToast();
            } else alert(d.error || '上传失败');
        } catch(e) { alert('上传失败'); }
    };
    xhr.onerror = function() { alert('上传失败'); };
    xhr.send(form);
}
function removeProfileBg() {
    if (!confirm('确定要移除个人主页封面吗？')) return;
    var frm = new URLSearchParams();
    frm.append('action', 'remove_profile_bg');
    fetch('../api/settings.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: frm.toString()
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.success) {
            setPreview(document.getElementById('profilePreview'), null);
            showToast();
        }
    });
}

document.getElementById('tabChat').classList.toggle('active', <?php echo $tab === 'chat' ? 'true' : 'false';?>);
document.getElementById('tabProfile').classList.toggle('active', <?php echo $tab === 'profile' ? 'true' : 'false';?>);
loadChatBg();
loadProfileBg();
</script>

</body>
</html>
