<?php
/**
 * ChatApp · OOBE — 首次配置引导（服主首登向导）
 *
 * 幂等 + 非破坏：
 *   - 只允许：改 admin 密码 / 改 preferred_language / 写 maintenance/config.php
 *   - 绝不：建库/导 schema、DROP/ALTER、删数据、重置会话、重复插 admin
 *   - 可重复触发（api/admin.php oobe_rerun 清 data/oobe.done 后重登或直接访问）
 */
require_once __DIR__ . '/../../api/config.php';
chatapp_require_login();

$me = chatapp_get_user();
if (!is_array($me) || (int)($me['user_id'] ?? 0) !== 10000) {
    header('Location: chat.php'); exit;   // 仅服主（uid 10000）
}

// 与 login.php 共用壁纸（会话内一致）
if (empty($_SESSION['wallpaper']) || (int)$_SESSION['wallpaper'] < 1 || (int)$_SESSION['wallpaper'] > 10) {
    $_SESSION['wallpaper'] = rand(1, 10);
}
$bgWallpaper = (int)$_SESSION['wallpaper'];
$currentLang = $_SESSION['preferred_language'] ?? ($me['preferred_language'] ?? 'en');

// ---------- POST 后端（全部幂等） ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'set_language') {
        $lang = trim($_POST['lang'] ?? '');
        if (!in_array($lang, ['en', 'zh', 'zh_egg', 'wyw', 'raw'], true)) { echo json_encode(['success' => false, 'error' => 'bad lang']); exit; }
        db()->prepare('UPDATE users SET preferred_language=? WHERE user_id=10000')->execute([$lang]);
        $_SESSION['preferred_language'] = $lang;
        echo json_encode(['success' => true, 'lang' => $lang]); exit;
    }

    if ($action === 'set_creds') {
        $pwd = (string)($_POST['password'] ?? '');
        $mu  = trim($_POST['maint_user'] ?? '');
        $mp  = (string)($_POST['maint_pass'] ?? '');
        if ($pwd !== '') {
            if (strlen($pwd) < 8) { echo json_encode(['success' => false, 'error' => 'Password min 8.']); exit; }
            db()->prepare('UPDATE users SET password=? WHERE user_id=10000')->execute([password_hash($pwd, PASSWORD_BCRYPT)]);
        }
        if ($mu !== '' && $mp !== '') {
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $mu)) { echo json_encode(['success' => false, 'error' => 'Invalid maintenance username.']); exit; }
            if (strlen($mp) < 8) { echo json_encode(['success' => false, 'error' => 'Maintenance password min 8.']); exit; }
            $maintFile = __DIR__ . '/../../maintenance/config.php';
            $body = "<?php\n/**\n * ChatApp — Maintenance admin credentials\n *\n * AUTO-GENERATED during OOBE.\n * Override via MAINT_USER / MAINT_PASS / MAINT_SECRET env vars if needed.\n */\n"
                . "\$MAINT_USER   = getenv('MAINT_USER') ?: " . var_export($mu, true) . ";\n"
                . "\$MAINT_PASS   = getenv('MAINT_PASS') ?: " . var_export($mp, true) . ";\n"
                . "\$MAINT_SECRET = getenv('MAINT_SECRET') ?: " . var_export(bin2hex(random_bytes(32)), true) . ";\n";
            if (@file_put_contents($maintFile, $body) === false) { echo json_encode(['success' => false, 'error' => 'Could not write maintenance config.']); exit; }
        } elseif (($mu === '') !== ($mp === '')) {
            echo json_encode(['success' => false, 'error' => 'Maintenance username and password must both be set (or both empty).']); exit;
        }
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'finish') {
        @file_put_contents(__DIR__ . '/../../data/oobe.done', json_encode(['done' => time()]));
        echo json_encode(['success' => true]); exit;
    }

    echo json_encode(['success' => false, 'error' => 'unknown action']); exit;
}
?>
<!DOCTYPE html>
<html lang="zh-Hans">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>OOBE · 首次引导</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  html,body { height:100%; }
  body {
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',sans-serif;
    color:#e0e0e0;
    display:flex; justify-content:center; align-items:center; min-height:100vh;
    background-color:#1a1a1a;
    background-image:
      radial-gradient(rgba(0,0,0,0) 0%, rgba(0,0,0,0.5) 100%),
      radial-gradient(rgba(0,0,0,0) 33%, rgba(0,0,0,0.3) 166%),
      url('../bg/background<?php echo $bgWallpaper; ?>.jpg');
    background-size:cover; background-position:center; background-repeat:no-repeat; background-attachment:fixed;
  }
  .card {
    width:440px; max-width:calc(100vw - 32px); padding:30px 32px 22px;
    background:rgba(42,42,42,0.9);
    -webkit-backdrop-filter:blur(10px); backdrop-filter:blur(10px);
    border:1px solid rgba(90,90,90,0.5); border-radius:0;
    box-shadow:0 10px 40px rgba(0,0,0,0.55);
    animation:cardIn .5s cubic-bezier(.2,.8,.25,1);
  }
  @keyframes cardIn { from { opacity:0; transform:translateY(22px) scale(.98); } to { opacity:1; transform:none; } }
  .hTitle { text-align:center; font-size:1.35em; color:#c8c8c8; font-weight:600; min-height:1.4em; }
  .cardBody { min-height:330px; margin-top:18px; }
  .cardBody.anim { animation:stepIn .38s cubic-bezier(.4,0,.2,1); }
  @keyframes stepIn { from { opacity:0; transform:translateX(26px); } to { opacity:1; transform:none; } }
  .dots { display:flex; gap:8px; justify-content:center; margin-top:20px; }
  .dot { width:10px; height:10px; border-radius:0; background:#333; transition:all .3s; }
  .dot.active { background:#4a9dd8; transform:scale(1.3); }
  .dot.done { background:#5a7a5a; }
  .btn {
    flex:1; padding:11px 14px; border:1px solid #555; background:#4a4a4a; color:#e0e0e0;
    font-size:.92em; font-weight:600; cursor:pointer; font-family:inherit; border-radius:0; transition:all .18s;
  }
  .btn:hover { background:#5a5a5a; }
  .btn.primary { background:#4a6a9a; border-color:#3a5a8a; }
  .btn.primary:hover { background:#5a7aaa; }
  .btn.ghost { background:transparent; border-color:transparent; color:#888; }
  .btn.ghost:hover { color:#bbb; background:rgba(255,255,255,.04); }
  .actions { display:flex; gap:10px; margin-top:20px; }
  .actions.center { justify-content:center; }
  .lang-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .lang-btn {
    padding:14px 10px; background:#1e1e1e; border:1px solid #3a3a3a; color:#bbb;
    font-size:.92em; cursor:pointer; font-family:inherit; border-radius:0; transition:all .18s;
  }
  .lang-btn:hover { border-color:#666; color:#fff; }
  .lang-btn.active { border-color:#4a9dd8; color:#fff; background:#2a3a4a; }
  .slide { text-align:center; padding:6px 4px; }
  .slide .ic { font-size:3.2em; display:block; margin-bottom:16px; animation:pop .4s ease; }
  @keyframes pop { 0% { transform:scale(.4); opacity:0; } 70% { transform:scale(1.15); } 100% { transform:scale(1); opacity:1; } }
  .slide h2 { font-size:1.15em; color:#ddd; margin-bottom:12px; min-height:1.4em; }
  .slide p { color:#999; font-size:.86em; line-height:1.7; min-height:4.2em; }
  .slide .cnt { margin-top:14px; color:#666; font-size:.75em; letter-spacing:2px; }
  .fg { margin-bottom:14px; }
  .fg label { display:block; margin-bottom:6px; color:#aaa; font-size:.82em; }
  .fg input {
    width:100%; padding:10px 12px; background:#1e1e1e; border:1px solid #444;
    color:#e0e0e0; font-size:.92em; font-family:inherit; outline:none; border-radius:0; transition:border-color .2s;
  }
  .fg input:focus { border-color:#888; }
  .hint { color:#888; font-size:.74em; line-height:1.55; margin:8px 0 2px; }
  .check { display:flex; align-items:flex-start; gap:8px; margin:12px 0; font-size:.78em; color:#c88; line-height:1.45; }
  .check input { margin-top:2px; }
  .doneWrap { text-align:center; padding:8px 0; }
  .checkmark { width:76px; height:76px; stroke:#7ddb9a; stroke-width:6; fill:none; stroke-linecap:round; stroke-linejoin:round; animation:pop .5s ease; }
  .checkmark path { stroke-dasharray:90; stroke-dashoffset:90; animation:draw .5s ease forwards .25s; }
  @keyframes draw { to { stroke-dashoffset:0; } }
  .doneWrap h2 { margin:14px 0 8px; color:#ddd; font-size:1.15em; }
  .doneWrap p { color:#999; font-size:.84em; line-height:1.6; }
  .splash { text-align:center; padding:40px 0 20px; }
  .splash .logo {
    width:84px; height:84px; margin:0 auto 18px; display:flex; align-items:center; justify-content:center;
    background:#2a3a4a; border:1px solid #4a6a9a; color:#9fc2e8; font-size:2em; font-weight:700; border-radius:0;
    animation:pop .5s ease;
  }
  .splash h1 { color:#ddd; font-size:1.6em; margin-bottom:10px; letter-spacing:4px; }
  .splash p { color:#888; font-size:.84em; animation:blink 1.2s ease infinite; }
  @keyframes blink { 50% { opacity:.35; } }
</style>
</head>
<body>
<div class="card">
  <div class="hTitle" id="hTitle">OOBE</div>
  <div class="cardBody" id="cardBody"></div>
  <div class="dots" id="dots"></div>
</div>
<?php include __DIR__ . '/../partials/footer.php'; ?>

<script>
var LANG = <?php echo json_encode($currentLang); ?>;
function L(e, z) { return LANG === 'en' ? e : z; }

var STEP = -1;         // -1 splash, 0 lang, 1 tour, 2 security, 3 done
var SLIDE = 0;
var SLIDES = [
  { ic:'💬', en:['Chat & DMs','Real-time DMs, groups, attachments, stickers, doodles and flash transfers — all synced instantly.'], zh:['聊天与私信','实时私聊、群组、附件、表情、涂鸦与闪传，全部即时同步。'] },
  { ic:'👥', en:['Groups','Create groups, invite members, assign admins, pin, mute-all and dissolve when done.'], zh:['群组','建群、邀请成员、设置管理员、置顶、全员禁言，随时解散。'] },
  { ic:'🏅', en:['Levels & EXP','Chat, sign in daily and give likes to earn EXP. Unlock level titles and per-level limits.'], zh:['等级与经验','聊天、每日签到、点赞赚经验，解锁等级头衔与各级上限。'] },
  { ic:'🔒', en:['Security','End-to-end encryption for DMs, plus a duress password that silently wipes the account under coercion.'], zh:['安全','私聊端到端加密；还有胁迫密码，被胁迫时静默销毁账号自保。'] },
  { ic:'⚡', en:['Realtime','WebSocket pushes messages, typing indicators, presence and read receipts in real time.'], zh:['实时','WebSocket 实时推送消息、正在输入、在线状态与已读回执。'] },
  { ic:'🛠️', en:['Admin tools','User & role management, audit logs, maintenance mode, DB admin and Factory Reset.'], zh:['管理工具','用户与角色管理、审计日志、维护模式、数据库管理与工厂重置。'] },
  { ic:'💾', en:['Backups','Data lives in MySQL + data/. Back up regularly. Next step sets your maintenance portal password.'], zh:['备份','数据在 MySQL + data/ 目录。请定期备份。下一步设置维护门户密码。'] }
];

function $(id){ return document.getElementById(id); }
function body(el){
  var b = $('cardBody');
  b.classList.remove('anim'); void b.offsetWidth;
  b.innerHTML = el;
  b.classList.add('anim');
}
function headTitle(t){ $('hTitle').textContent = t; }
function dots(){
  var steps = ['lang','tour','security','done'];
  var labels = [L('Language','语言'), L('Tour','导览'), L('Security','安全'), L('Done','完成')];
  var h = '';
  for (var i=0;i<steps.length;i++){
    var st = i < STEP ? 'done' : (i === STEP ? 'active' : '');
    h += '<div class="dot '+st+'" title="'+labels[i]+'"></div>';
  }
  $('dots').innerHTML = h;
}
function animIn(root){
  var els = root.querySelectorAll('.fade');
  els.forEach ? els.forEach(function(e,i){ e.style.animation = 'stepIn .5s ease '+(i*0.09)+'s both'; }) : null;
}

/* ---------- Splash ---------- */
function stepSplash(){
  body('<div class="splash"><div class="logo">CA</div><h1>ChatApp</h1><p>'+L('Initializing first-run setup…','正在初始化首次配置…')+'</p></div>');
  dots();
  setTimeout(stepLang, 1300);
}

/* ---------- Step 0: Language ---------- */
function stepLang(){
  STEP = 0; dots();
  headTitle(L('Choose your language','选择语言'));
  var opts = [['en','English (US)'],['zh','中文（简体）'],['zh_egg','中文（微软式）'],['wyw','文言文'],['raw','Raw']];
  var h = '<div class="lang-grid">';
  for (var i=0;i<opts.length;i++){
    h += '<button class="lang-btn'+(opts[i][0]===LANG?' active':'')+'" data-v="'+opts[i][0]+'" onclick="pickLang(this)">'+opts[i][1]+'</button>';
  }
  h += '</div><div class="actions"><button class="btn primary" onclick="next()">'+L('Continue','继续')+' →</button></div>';
  body(h);
  animIn($('cardBody'));
}
function pickLang(btn){
  var v = btn.getAttribute('data-v');
  LANG = v;
  document.documentElement.lang = (v==='en'?'en':'zh-Hans');
  var qs = new URLSearchParams(); qs.append('action','set_language'); qs.append('lang',v);
  fetch('oobe.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:qs.toString() }).catch(function(){});
  var bts = document.querySelectorAll('.lang-btn');
  for (var i=0;i<bts.length;i++) bts[i].classList.toggle('active', bts[i].getAttribute('data-v')===v);
}

/* ---------- Step 1: Tour ---------- */
function stepTour(){
  STEP = 1; dots();
  headTitle(L('A quick tour','功能导览'));
  drawSlide();
}
function drawSlide(){
  var s = SLIDES[SLIDE];
  body('<div class="slide">'+
       '<span class="ic">'+s.ic+'</span>'+
       '<h2 id="slideTitle"></h2>'+
       '<p id="slideDesc"></p>'+
       '<div class="cnt">'+(SLIDE+1)+' / '+SLIDES.length+'</div>'+
       '</div>'+
       '<div class="actions">'+
       '<button class="btn ghost" onclick="skipTour()">'+L('Skip tour','跳过导览')+'</button>'+
       (SLIDE>0?'<button class="btn" onclick="tourPrev()">← '+L('Prev','上一页')+'</button>':'')+
       (SLIDE<SLIDES.length-1?'<button class="btn primary" onclick="tourNext()">'+L('Next','下一页')+' →</button>':'<button class="btn primary" onclick="stepSecurity()">'+L('Next','下一步')+' →</button>')+
       '</div>');
  typewrite($('slideTitle'), L(s.en[0], s.zh[0]), function(){
    $('slideDesc').textContent = L(s.en[1], s.zh[1]);
  });
}
function typewrite(el, text, done){
  el.textContent = '';
  var i = 0;
  var t = setInterval(function(){
    el.textContent += text.charAt(i++);
    if (i > text.length){ clearInterval(t); if (done) done(); }
  }, 22);
}
function tourNext(){ SLIDE++; drawSlide(); }
function tourPrev(){ SLIDE--; drawSlide(); }
function skipTour(){ stepSecurity(); }

/* ---------- Step 2: Security init ---------- */
function stepSecurity(){
  STEP = 2; dots();
  headTitle(L('Security setup','安全初始化'));
  body('<div class="fg"><label>'+L('New admin password (optional)','新管理员密码（可选）')+'</label>'+
       '<input type="password" id="pw" autocomplete="new-password" placeholder="'+L('leave blank to keep current','留空保持现状')+'"></div>'+
       '<div class="fg"><label>'+L('Maintenance portal username (optional)','维护门户用户名（可选）')+'</label>'+
       '<input type="text" id="mu" autocomplete="off" placeholder="'+L('leave blank to keep current','留空保持现状')+'"></div>'+
       '<div class="fg"><label>'+L('Maintenance portal password (optional)','维护门户密码（可选）')+'</label>'+
       '<input type="password" id="mp" autocomplete="new-password" placeholder="'+L('leave blank to keep current','留空保持现状')+'"></div>'+
       '<div class="hint">'+L('Maintenance portal is the emergency login used when the site is in maintenance mode.','维护门户是站点处于维护模式时的紧急登录入口。')+'</div>'+
       '<div class="actions"><button class="btn ghost" onclick="finishNow()">'+L('Skip','跳过')+'</button><button class="btn primary" onclick="submitSecurity()">'+L('Save & Finish','保存并完成')+'</button></div>');
}
function submitSecurity(){
  var pw = $('pw').value;
  var mu = $('mu').value.trim();
  var mp = $('mp').value;
  if (pw && pw.length < 8){ alert(L('Password min 8 chars.','密码至少 8 位。')); return; }
  if ((!!mu) !== (!!mp)){ alert(L('Maintenance username & password must both be set (or both empty).','维护门户用户名和密码需同时填写（或都留空）。')); return; }
  var qs = new URLSearchParams();
  qs.append('action','set_creds'); qs.append('password',pw); qs.append('maint_user',mu); qs.append('maint_pass',mp);
  fetch('oobe.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:qs.toString() })
    .then(function(r){ return r.json(); }).then(function(d){
      if (d.success) finishNow();
      else alert(d.error || 'Failed');
    }).catch(function(){ finishNow(); });
}
function finishNow(){
  var qs = new URLSearchParams(); qs.append('action','finish');
  fetch('oobe.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:qs.toString() }).catch(function(){});
  stepDone();
}

/* ---------- Step 3: Done ---------- */
function stepDone(){
  STEP = 3; dots();
  headTitle(L('All set!','一切就绪！'));
  body('<div class="doneWrap">'+
       '<svg class="checkmark" viewBox="0 0 52 52"><path d="M14 27l8 8 16-16"/></svg>'+
       '<h2>'+L('Setup complete','设置完成')+'</h2>'+
       '<p>'+L('Welcome to ChatApp. You can re-open this guide anytime from Admin → OOBE.','欢迎使用 ChatApp。以后可在 管理 → OOBE 引导 随时重新打开本指南。')+'</p>'+
       '<div class="actions"><button class="btn primary" onclick="location.href=\'chat.php\'">'+L('Enter ChatApp','进入 ChatApp')+' →</button></div>'+
       '</div>');
}

function next(){
  if (STEP === 0) stepTour();
  else if (STEP === 1) stepSecurity();
  else if (STEP === 2) finishNow();
}

document.addEventListener('keydown', function(e){
  if (STEP === 1){
    if (e.key === 'ArrowRight') tourNext();
    else if (e.key === 'ArrowLeft' && SLIDE > 0) tourPrev();
  }
});

stepSplash();
</script>
</body>
</html>
