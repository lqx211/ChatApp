<?php
/**
 * ChatApp · Rescue Panel — 紧急救援面板
 *
 * 存在的意义：任何网页升级/降级都可能把 maintenance/ 或整站砸坏（比如降级回
 * 远古版本）。rescue/ 自包含、零依赖（不 require api/、maintenance/ 任何代码），
 * 且被所有网页升级/降级的 checkout 显式排除（':!rescue'）—— 它永远还在。
 *
 * 鉴权：维护凭据（maintenance/config.php → data/maint_config.php → env），
 * 文件级校验，数据库挂了也能登录；两个文件都不存在时自动生成随机凭据
 * （见 lib.php rescue_bootstrap）。数据库可达时，危险操作另须管理员密码。
 *
 * 功能：仪表盘（系统状态）/ 升级 / 降级 —— 三重验证同维护面板：
 * 管理员密码（DB 可用时）+ 维护凭据 + 当前 git hash（两次输入）。
 *
 * ⚠️ 本面板不会被网页升级更新（故意的）。要更新救援面板，SSH 执行：
 *    cd <项目目录> && git fetch origin main && git checkout --force origin/main -- rescue
 */
@ini_set('display_errors', '0');
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lang.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// 自举：确保 maintenance/ 目录 + maintenance/config.php 存在（缺则生成随机凭据）
$__boot = rescue_bootstrap(rescue_root());
$__authed = !empty($_SESSION['rescue_ok']);

function rc_json($arr): void {
    header('Content-Type: application/json');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================== POST 后端 ====================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'login') {
        $u = trim((string)($_POST['login'] ?? ''));
        $p = (string)($_POST['password'] ?? '');
        if (rescue_verify_creds($u, $p)) {
            $_SESSION['rescue_ok'] = true;
            rc_json(['success' => true]);
        }
        usleep(400000); // 轻微节流
        rc_json(['success' => false, 'error' => rt('login_err')]);
    }

    if ($action === 'logout') {
        unset($_SESSION['rescue_ok']);
        rc_json(['success' => true]);
    }

    if (!$__authed) rc_json(['success' => false, 'error' => rt('unknown_action')]);

    switch ($action) {
        case 'get': {
            $disk = @disk_free_space(rescue_root());
            $cfgFiles = rescue_cred_files();
            $present = null;
            foreach ($cfgFiles as $f) { if (is_file($f)) { $present = $f; break; } }
            $db = rescue_db_status();
            rc_json([
                'success'    => true,
                'app_git'    => rescue_git('rev-parse HEAD'),
                'branch'     => rescue_git('rev-parse --abbrev-ref HEAD'),
                'db_ok'      => $db['ok'],
                'maint_cfg'  => $present ? basename(dirname($present)) . '/' . basename($present) : '',
                'php'        => PHP_VERSION,
                'disk_free'  => ($disk === false ? null : (int)$disk),
            ]);
        }

        case 'check_update': {
            $remote = rescue_git('ls-remote origin main');
            $remoteSha = '';
            if (preg_match('/^([0-9a-f]{40})\s+/im', $remote, $m)) $remoteSha = strtolower($m[1]);
            $localSha = strtolower(trim(rescue_git('rev-parse HEAD')));
            rc_json(['success' => true, 'current' => $localSha, 'remote' => $remoteSha, 'has_update' => ($remoteSha !== '' && $remoteSha !== $localSha)]);
        }

        case 'dg_list': {
            $q = trim((string)($_POST['q'] ?? ''));
            if ($q === '') {
                // 默认列表：至少 250 条
                $out = rescue_git('log --format=%H%x09%ci%x09%s -n 250');
            } else {
                // 搜索：不限条数（--grep 只返回命中；-F 纯文本、-i 忽略大小写）
                $out = rescue_git('log --format=%H%x09%ci%x09%s -i -F --grep=' . escapeshellarg($q));
            }
            $list = [];
            $seen = [];
            foreach (explode("\n", $out) as $line) {
                $parts = explode("\t", $line, 3);
                if (count($parts) < 3) continue;
                $h = trim($parts[0]);
                $seen[$h] = true;
                $list[] = ['h' => $h, 'date' => trim($parts[1]), 'subj' => trim($parts[2])];
            }
            // 搜索词像 hash 前缀 → 把该 commit 本身插到最前（--grep 搜不到 hash）
            if ($q !== '' && preg_match('/^[0-9a-f]{4,40}$/i', $q)) {
                $full = strtolower($q);
                $t = trim(rescue_git('cat-file -t ' . escapeshellarg($full)));
                if ($t === 'commit') {
                    [$one] = [rescue_git('log --format=%H%x09%ci%x09%s -n 1 ' . escapeshellarg($full))];
                    $parts = explode("\t", $one, 3);
                    if (count($parts) >= 3 && !isset($seen[trim($parts[0])])) {
                        array_unshift($list, ['h' => trim($parts[0]), 'date' => trim($parts[1]), 'subj' => trim($parts[2])]);
                    }
                }
            }
            rc_json(['success' => true, 'versions' => $list]);
        }

        case 'up_list': {
            // 升级目标列表：只列「比当前新」的提交（origin/main 上 HEAD 之后的）。
            // 先 quiet fetch 拉新引用（带低速超时，断网/卡网 15s 内放弃）——失败就
            // 退回本地记录的 origin/main。
            $q = trim((string)($_POST['q'] ?? ''));
            rescue_git('-c http.lowSpeedLimit=1000 -c http.lowSpeedTime=15 fetch --quiet origin main');
            if ($q === '') {
                $out = rescue_git('log --first-parent --format=%H%x09%ci%x09%s -n 250 origin/main ^HEAD');
            } else {
                $out = rescue_git('log --first-parent --format=%H%x09%ci%x09%s -i -F --grep=' . escapeshellarg($q) . ' origin/main ^HEAD');
            }
            $list = [];
            foreach (explode("\n", $out) as $line) {
                $parts = explode("\t", $line, 3);
                if (count($parts) < 3) continue;
                $list[] = ['h' => trim($parts[0]), 'date' => trim($parts[1]), 'subj' => trim($parts[2])];
            }
            rc_json(['success' => true, 'versions' => $list]);
        }

        case 'adjacent': {
            // prev = HEAD 的父提交（降一级）；next = main 线上紧贴 HEAD 的下一提交（升一级）
            $prev = trim(rescue_git('rev-parse --verify HEAD^'));
            if (!preg_match('/^[0-9a-f]{40}$/i', $prev)) $prev = '';
            $next = '';
            $rev = rescue_git('rev-list --first-parent --reverse HEAD..origin/main');
            if ($rev !== '' && strpos($rev, 'fatal') === false) {
                $lines = preg_split('/\r?\n/', trim($rev));
                if ($lines && preg_match('/^[0-9a-f]{40}$/i', $lines[0])) $next = $lines[0];
            }
            rc_json(['success' => true, 'prev' => $prev, 'next' => $next]);
        }

        case 'progress': {
            $p = [];
            $pf = rescue_progress_path();
            if (is_file($pf)) { $p = json_decode((string)@file_get_contents($pf), true) ?: []; }
            $p['locked'] = is_file(rescue_lock_path());
            rc_json(['success' => true] + $p);
        }

        case 'repair_scan': {
            // 只列出「当前版本（HEAD）里有、但工作区被改坏/误删」的跟踪文件。
            // 过滤噪音：?? 未跟踪（用户自己的文件/备份/.gitkeep 占位）与 X='A' 的
            // 索引残留（旧版目录结构大迁移后软重置留下的条目，HEAD 里根本没有
            // 这些文件）——它们不是损坏，修复也不会/不该碰它们。
            $out = rescue_git("-c core.quotepath=false status --porcelain -- . ':!config' ':!data' ':!bkup' ':!maintenance/config.php' ':!rescue'");
            if (strpos($out, 'fatal:') !== false) rc_json(['success' => false, 'error' => substr($out, 0, 200)]);
            $files = [];
            $skipped = 0;
            foreach (preg_split('/\r?\n/', $out) as $line) {
                if ($line === '') continue;
                $x = $line[0];
                $y = $line[1] ?? ' ';
                $path = ltrim(substr($line, 2));
                if ($path === '') continue;
                if ($path[0] === '"') $path = trim($path, '"'); // quotepath 关闭后仍兜底
                if ($path === '') continue;
                if ($x === '?' || $x === '!' || $x === 'A') { $skipped++; continue; }
                $files[] = ['code' => ($x !== ' ') ? $x : $y, 'path' => $path];
            }
            rc_json(['success' => true, 'files' => $files, 'skipped' => $skipped]);
        }

        case 'perform_upgrade':
        case 'perform_downgrade':
        case 'perform_repair': {
            $isUp = ($action === 'perform_upgrade');
            $isRepair = ($action === 'perform_repair');
            $pwd  = (string)($_POST['password'] ?? '');
            $mu   = trim((string)($_POST['maint_user'] ?? ''));
            $mp   = (string)($_POST['maint_pass'] ?? '');
            $h1   = strtoupper(trim((string)($_POST['git_hash'] ?? '')));
            $h2   = strtoupper(trim((string)($_POST['git_hash2'] ?? '')));
            $tgt  = trim((string)($_POST['target'] ?? ''));

            if (is_file(rescue_lock_path())) rc_json(['success' => false, 'error' => rt('err_busy')]);
            if ($mu === '' || $mp === '' || $h1 === '' || $h2 === '') rc_json(['success' => false, 'error' => rt('all_fields_required')]);
            if ($h1 !== $h2) rc_json(['success' => false, 'error' => rt('git_hash_mismatch')]);

            // 1) git hash 必须等于当前 HEAD
            $head = strtoupper(trim(rescue_git('rev-parse HEAD')));
            if ($head === '' || $head !== $h1) rc_json(['success' => false, 'error' => rt('git_hash_mismatch') . ' (HEAD: ' . substr($head, 0, 10) . ')']);

            // 2) 管理员密码：DB 可达且 uid 10000 有密码才校验；数据库炸了 /
            //    管理员记录或密码不存在 → 跳过（救援场景仅凭维护凭据放行）
            $gate = rescue_admin_verifiable();
            if ($gate['ok']) {
                if ($pwd === '') rc_json(['success' => false, 'error' => rt('err_admin_required')]);
                $okAdmin = false;
                try {
                    $pdo = rescue_db_pdo();
                    if ($pdo) {
                        $stmt = $pdo->prepare('SELECT password FROM users WHERE user_id = 10000');
                        $stmt->execute();
                        $row = $stmt->fetch();
                        $okAdmin = $row && password_verify($pwd, $row['password']);
                    }
                } catch (\Throwable $e) { $okAdmin = false; }
                if (!$okAdmin) rc_json(['success' => false, 'error' => rt('err_admin_wrong')]);
            }

            // 3) 维护凭据（文件级）
            if (!rescue_verify_creds($mu, $mp)) rc_json(['success' => false, 'error' => rt('err_maint_wrong')]);

            // 4) 目标 commit 校验：repair 无目标；降级必填；升级可选（空 = 最新 main）
            if ($isRepair) {
                // 修复：目标固定为当前 HEAD
            } elseif (!$isUp) {
                if ($tgt === '') rc_json(['success' => false, 'error' => rt('all_fields_required')]);
                $t = trim(rescue_git('cat-file -t ' . escapeshellarg($tgt)));
                if ($t !== 'commit') rc_json(['success' => false, 'error' => rt('dg_load_failed') . ' (invalid target)']);
            } elseif ($tgt !== '') {
                $t = trim(rescue_git('cat-file -t ' . escapeshellarg($tgt)));
                if ($t !== 'commit') {
                    // 本地还没有这个对象？可能是远端最新——允许它，worker fetch 后即可用
                    $remote = rescue_git('ls-remote origin main');
                    $remoteSha = '';
                    if (preg_match('/^([0-9a-f]{40})\s+/im', $remote, $m)) $remoteSha = strtolower($m[1]);
                    if ($remoteSha === '' || $remoteSha !== strtolower($tgt)) {
                        rc_json(['success' => false, 'error' => rt('dg_load_failed') . ' (invalid target)']);
                    }
                } else {
                    // 升级目标必须「比当前新」：是当前或其祖先 → 那是降级/修复的活
                    $outA = []; $rcA = -1;
                    @exec('git -C ' . escapeshellarg(rescue_root()) . ' merge-base --is-ancestor ' . escapeshellarg($tgt) . ' HEAD 2>&1', $outA, $rcA);
                    if ($rcA === 0) rc_json(['success' => false, 'error' => rt('up_target_old')]);
                }
            }

            // 5) 置锁 + 启动后台 worker
            $modetype = $isRepair ? 'repair' : ($isUp ? 'upgrade' : 'downgrade');
            $workerArgs = $modetype;
            if ($isUp && $tgt !== '') $workerArgs .= ' ' . escapeshellarg($tgt);
            if (!$isUp && !$isRepair) $workerArgs .= ' ' . escapeshellarg($tgt);
            @file_put_contents(rescue_lock_path(), json_encode(['type' => $modetype, 'started' => time()]));
            @file_put_contents(rescue_progress_path(), json_encode(['status' => 'pending', 'type' => $modetype, 'step' => 'Starting…', 'pct' => 0, 'from' => trim($head)]));
            $workerLog = rescue_root() . '/data/rescue_worker.log';
            @file_put_contents($workerLog, '');
            $cmd = 'cd ' . escapeshellarg(rescue_root())
                 . ' && nohup ' . escapeshellarg(rescue_php_binary()) . ' ' . escapeshellarg(__DIR__ . '/worker.php')
                 . ' ' . $workerArgs
                 . ' >> ' . escapeshellarg($workerLog) . ' 2>&1 &';
            @exec($cmd);
            if (!is_file(rescue_progress_path())) rc_json(['success' => false, 'error' => rt('err_spawn')]);
            rc_json(['success' => true, 'started' => true]);
        }

        default:
            rc_json(['success' => false, 'error' => rt('unknown_action')]);
    }
}

// ==================== GET 渲染 ====================
$__resVer = rescue_git('log -1 --format=%h -- rescue');
if ($__resVer === '' || strpos($__resVer, 'fatal') !== false) $__resVer = '?';
$__resBuild = date('Y-m-d H:i:s', (int)(@filemtime(__FILE__) ?: time()));

if (!$__authed):
?><!DOCTYPE html>
<html lang="<?php echo $GLOBALS['RESCUE_LANG'] === 'zh' ? 'zh-Hans' : 'en'; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo rt('login_h1'); ?> — ChatApp</title><link rel="stylesheet" href="css/global.css">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
        color: #e0e0e0;
        display: flex; justify-content: center; align-items: center;
        min-height: 100vh;
        background-color: #1a1a1a;
        background-image:
            radial-gradient(rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.5) 100%),
            radial-gradient(rgba(0, 0, 0, 0) 33%, rgba(0, 0, 0, 0.3) 166%),
            url('/rescue/bg.jpg');
        background-size: cover; background-position: center; background-repeat: no-repeat; background-attachment: fixed;
    }
    .auth-container {
        background: rgba(42, 42, 42, 0.88);
        -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
        border: 1px solid rgba(90, 90, 90, 0.5);
        padding: 40px 38px; width: 400px; max-width: 92vw;
        box-shadow: 0 8px 32px rgba(0,0,0,0.5);
    }
    .auth-container h1 { text-align: center; font-size: 1.8em; color: #c0c0c0; margin-bottom: 6px; font-weight: 600; }
    .auth-container p.subtitle { text-align: center; color: #777; margin-bottom: 26px; font-size: 0.9em; }
    .maint-badge {
        display: inline-block; margin: 0 auto 16px; padding: 4px 14px;
        background: #4a2a1e; border: 1px solid #7a3a2a; color: #ff9a5a;
        font-size: 0.72em; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase;
        text-align: center;
    }
    .boot-note { background: #3d2f1e; border: 1px solid #6a522a; color: #e8c07a; padding: 10px 14px; margin-bottom: 16px; font-size: 0.8em; line-height: 1.6; }
    .error-msg { background: #3d2020; border: 1px solid #5c2a2a; color: #e06060; padding: 10px 14px; margin-bottom: 16px; font-size: 0.85em; display: none; }
    .error-msg.show { display: block; }
    .form-group { position: relative; margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 6px; color: #aaa; font-size: 0.85em; }
    .form-group input {
        width: 100%; padding: 11px 2px; background: transparent; border: none;
        border-bottom: 1px solid #555; border-radius: 0; color: #e0e0e0;
        font-size: 0.95em; outline: none; font-family: inherit;
    }
    .form-group input:focus { border-bottom-color: #4a9dd8; }
    .btn-primary {
        width: 100%; padding: 12px; background: #4a4a4a; border: 1px solid #555;
        color: #e0e0e0; font-size: 0.95em; font-weight: 600; cursor: pointer;
        transition: background 0.2s; font-family: inherit;
    }
    .btn-primary:hover { background: #5a5a5a; }
    .foot-note { text-align: center; color: #555; font-size: 0.72em; margin-top: 14px; line-height: 1.6; }
    .lang-pick { position: fixed; left: 16px; bottom: 16px; display: flex; align-items: center; gap: 8px;
        background: rgba(30, 30, 30, 0.85); border: 1px solid #3a3a3a; padding: 7px 10px;
        font-size: 0.8em; color: #999; }
    .lang-pick select { background: #1e1e1e; border: 1px solid #444; color: #e0e0e0; font-family: inherit;
        font-size: 1em; padding: 4px 8px; outline: none; cursor: pointer; }
    .ver-line { position: fixed; left: 16px; top: 16px; color: #8fa8c8; font-size: 0.74em; font-family: monospace;
        text-shadow: 0 0 8px rgba(120, 170, 255, 0.55), 0 0 2px rgba(160, 200, 255, 0.45); }
    .layout-copyright { position: fixed; left: 50%; transform: translateX(-50%); bottom: 26px; text-align: center;
        color: #777; font-size: 12px; max-width: 94vw; line-height: 1.6; }
    .layout-copyright a { color: #6a9fd8; text-decoration: none; }
    .layout-copyright a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="auth-container">
    <div style="text-align:center"><span class="maint-badge"><?php echo rt('badge_rescue'); ?></span></div>
    <h1><?php echo rt('login_h1'); ?></h1>
    <p class="subtitle"><?php echo rt('login_sub'); ?></p>

    <?php if (!empty($__boot['created'])): ?>
    <div class="boot-note">⚠ <?php echo rt('boot_created'); ?></div>
    <?php endif; ?>

    <div class="error-msg" id="errorMsg"></div>

    <form id="loginPanel" onsubmit="handleLogin(event)">
        <div class="form-group">
            <label for="loginUsername"><?php echo rt('login_user'); ?></label>
            <input type="text" id="loginUsername" maxlength="100" required autocomplete="username">
        </div>
        <div class="form-group">
            <label for="loginPassword"><?php echo rt('login_pass'); ?></label>
            <input type="password" id="loginPassword" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn-primary" id="loginBtn"><?php echo rt('login_btn'); ?></button>
    </form>

    <p class="foot-note"><?php echo rt('login_foot'); ?></p>
</div>
<div class="lang-pick"><span><?php echo rt('lang_label'); ?></span><?php echo rescue_lang_select(''); ?></div>
<div class="ver-line">rescue <?php echo htmlspecialchars($__resVer); ?> · build <?php echo htmlspecialchars($__resBuild); ?></div>
<div class="layout-copyright">© <a href="/index.php">ChatApp</a> 2026-<?php echo date('Y');?> by Jaden | <a href="//github.com/lqx211/ChatApp">Source Code</a> | All rights reserved</div>

<script>
function handleLogin(e){
    e.preventDefault();
    var el = document.getElementById('errorMsg');
    el.classList.remove('show');
    var btn = document.getElementById('loginBtn');
    btn.disabled = true;
    var f = new URLSearchParams();
    f.append('action', 'login');
    f.append('login', document.getElementById('loginUsername').value.trim());
    f.append('password', document.getElementById('loginPassword').value);
    fetch('index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString(), credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            btn.disabled = false;
            if (d.success) { location.reload(); }
            else { el.textContent = d.error || '?'; el.classList.add('show'); }
        })
        .catch(function(){ btn.disabled = false; el.textContent = 'Network error'; el.classList.add('show'); });
}
<?php echo rescue_setlang_js(); ?>
</script>
</body>
</html>
<?php
exit;
endif;

// ==================== 主界面（已登录） ====================
$__db = rescue_db_status();
// 管理员密码此刻能不能校验（DB 可达 + uid 10000 有密码）；不能 → 表单不要求输入
$__adminGate = rescue_admin_verifiable();
$__adminNoteKey = ($__adminGate['why'] === 'admin') ? 'admin_missing_note' : 'admin_db_down_note';
$__appGit = rescue_git('rev-parse HEAD');
$__branch = rescue_git('rev-parse --abbrev-ref HEAD');
$__cfgFiles = rescue_cred_files();
$__cfgPresent = '';
foreach ($__cfgFiles as $f) { if (is_file($f)) { $__cfgPresent = str_replace(rescue_root() . '/', '', $f); break; } }
$__disk = @disk_free_space(rescue_root());
$__diskTxt = ($__disk === false) ? '?' : number_format($__disk / 1073741824, 2) . ' GB';
?><!DOCTYPE html>
<html lang="<?php echo $GLOBALS['RESCUE_LANG'] === 'zh' ? 'zh-Hans' : 'en'; ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo rt('rescue_name'); ?> — ChatApp</title>
<link rel="stylesheet" href="css/global.css?v=<?php echo time();?>">
<link rel="stylesheet" href="css/chat.css?v=<?php echo time();?>">
<style>
  html,body{height:100%;margin:0;background:#222}
  .portal{padding:18px 22px;overflow-y:auto;flex:1}
  .pcard{background:rgba(42,42,42,.8);border:1px solid #3a3a3a;border-radius:0;padding:16px 18px;margin-bottom:14px}
  .pcard h3{margin:0 0 10px;font-size:.95em;color:#d0d0d0;font-weight:600;display:flex;align-items:center;gap:8px}
  .prow{display:flex;align-items:center;gap:12px;padding:7px 0;border-bottom:1px dashed #2f2f2f;font-size:.84em;color:#aaa}
  .prow:last-child{border-bottom:none}
  .prow .k{width:180px;color:#888;flex-shrink:0}
  .prow .v{color:#d8d8d8;word-break:break-all}
  .pbtn{display:inline-block;background:#2d4a6e;border:1px solid #3d5a7e;color:#e8f0fa;padding:8px 18px;border-radius:0;cursor:pointer;font-size:.85em;font-family:inherit;text-decoration:none}
  .pbtn:hover{background:#37608a}
  .pbtn.green{background:#2e5d43;border-color:#3a704f}
  .pbtn.green:hover{background:#3a704f}
  .pbtn.red{background:#6e2d2d;border-color:#8a3a3a}
  .pbtn.red:hover{background:#8a3a3a}
  .pbtn.gray{background:#3a3a3a;border-color:#4a4a4a;color:#bbb}
  .pbtn:disabled{opacity:.5;cursor:not-allowed}
  .pfield{margin-bottom:12px}
  .pfield label{display:block;color:#999;font-size:.76em;margin-bottom:5px}
  .pfield input[type=text],.pfield input[type=password],.pfield select{width:100%;max-width:360px;padding:8px 12px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.85em;font-family:inherit;outline:none;border-radius:0}
  .pfield input:focus,.pfield select:focus{border-color:#4a6a8e}
  /* 降级：可搜索版本下拉 */
  .dg-list{position:absolute;top:100%;left:0;width:100%;max-width:620px;max-height:60vh;overflow-y:auto;background:#161616;border:1px solid #444;z-index:50;font-size:.8em;font-family:monospace}
  .dg-item{padding:7px 12px;cursor:pointer;border-bottom:1px solid #242424;color:#bbb;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .dg-item:hover{background:#2a3a4e;color:#fff;white-space:normal;word-break:break-all}
  .dg-item.sel{background:#2e4a6e;color:#fff}
  .dg-empty{padding:10px 12px;color:#777}
  /* repair：问题文件列表 */
  .rp-row{display:flex;gap:10px;align-items:flex-start;padding:6px 0;border-bottom:1px dashed #2f2f2f;font-size:.78em;font-family:monospace}
  .rp-tag{flex-shrink:0;padding:1px 8px;border:1px solid #8a3a3a;color:#ff9a9a;min-width:56px;text-align:center}
  .rp-path{color:#c8c8c8;word-break:break-all}
  .pcheck{display:flex;align-items:center;gap:8px;color:#bbb;font-size:.84em;padding:6px 0;cursor:pointer}
  .pcheck input{width:16px;height:16px;accent-color:#4a8a6a}
  .ok-dot{display:inline-block;width:9px;height:9px;border-radius:0;margin-right:6px;vertical-align:1px}
  .ok-dot.g{background:#5ec87a}.ok-dot.r{background:#e06666}
  .note{color:#666;font-size:.72em;line-height:1.6;margin-top:6px}
  .dcard{background:#1e1e1e;border:1px dashed #444;padding:10px 14px;margin-top:8px;font-family:monospace;font-size:.78em;color:#9ecbff;word-break:break-all}
  .dnote{background:#3d2f1e;border:1px solid #6a522a;color:#e8c07a;padding:10px 14px;font-size:.78em;line-height:1.6;margin-bottom:12px}
  .flash{position:fixed;top:16px;right:16px;z-index:1000;padding:10px 16px;border-radius:0;font-size:.84em;display:none}
  .flash.ok{background:#2e5d43;color:#c8f5d8;border:1px solid #3a704f}
  .flash.err{background:#6e2d2d;color:#ffd0d0;border:1px solid #8a3a3a}
  .progwrap{margin-top:14px}
  .progbar{height:8px;background:#222;border:1px solid #3a3a3a}
  .progbar > div{height:100%;width:0;background:#3d6ea6;transition:width .3s}
  /* 左下角：语言选择器 + 版本信息（需求：git hash 与 build time 固定在救援画面左下角） */
  .lang-pick{display:flex;align-items:center;gap:8px;padding:8px 12px;margin:0 8px 6px;background:rgba(30,30,30,.6);border:1px solid #3a3a3a;font-size:.78em;color:#999}
  .lang-pick span{flex-shrink:0}
  .lang-pick select{flex:1;min-width:0;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:1em;font-family:inherit;padding:5px 8px;outline:none;border-radius:0;cursor:pointer}
  .lang-pick select:focus{border-color:#4a6a8e}
  .rescue-ver{padding:8px 12px;margin:0 8px 6px;border:1px solid #333;background:rgba(20,20,20,.6);color:#777;font-size:.7em;font-family:monospace;line-height:1.7;word-break:break-all}
  .rescue-ver b{color:#9ecbff;font-weight:600}
  .layout-copyright{position:fixed;left:50%;transform:translateX(-50%);bottom:26px;text-align:center;color:#777;font-size:12px;max-width:94vw;line-height:1.6;z-index:5}
  .layout-copyright a{color:#6a9fd8;text-decoration:none}
  .layout-copyright a:hover{text-decoration:underline}
</style>
</head>
<body>
<div class="sidebar">
  <div class="sidebar-profile">
    <div class="sa"></div>
    <div class="sun"><?php echo rt('rescue_name'); ?></div>
    <div class="sdnd rstr"><?php echo rt('badge_rescue'); ?></div>
  </div>
  <div class="sidebar-nav">
    <div class="ng"><div class="ngh" onclick="showPanel('dash')" style="cursor:pointer"><span><?php echo rt('nav_dash'); ?></span></div></div>
    <div class="ng"><div class="ngh" onclick="showPanel('upgrade')" style="cursor:pointer"><span><?php echo rt('nav_upgrade'); ?></span></div></div>
    <div class="ng"><div class="ngh" onclick="showPanel('downgrade')" style="cursor:pointer"><span><?php echo rt('nav_downgrade'); ?></span></div></div>
    <div class="ng"><div class="ngh" onclick="showPanel('repair')" style="cursor:pointer"><span><?php echo rt('nav_repair'); ?></span></div></div>
  </div>
  <div class="sidebar-footer">
    <div class="lang-pick"><span><?php echo rt('lang_label'); ?></span><?php echo rescue_lang_select(''); ?></div>
    <div class="rescue-ver" title="rescue <?php echo htmlspecialchars($__resVer); ?>">
      rescue <b><?php echo htmlspecialchars($__resVer); ?></b><br>
      <?php echo rt('k_build'); ?>: <?php echo htmlspecialchars($__resBuild); ?><br>
      git HEAD: <b><?php echo htmlspecialchars(substr($__appGit, 0, 10)); ?></b>
    </div>
    <div class="ngh" onclick="doLogout()" style="cursor:pointer"><span><?php echo rt('logout'); ?></span></div>
  </div>
</div>

<div class="main-content">
  <div class="panel active" id="panel-dash">
    <div class="ch"><h2><?php echo rt('nav_dash'); ?></h2><span style="color:#666;font-size:.75em"><?php echo rt('rescue_name'); ?></span></div>
    <div class="portal">
      <div class="pcard">
        <h3><?php echo rt('card_state'); ?></h3>
        <div class="prow"><span class="k"><?php echo rt('k_app_git'); ?></span><span class="v" id="stGit" style="user-select:all"><?php echo htmlspecialchars($__appGit ?: '?'); ?></span></div>
        <div class="prow"><span class="k"><?php echo rt('k_branch'); ?></span><span class="v" id="stBranch"><?php echo htmlspecialchars($__branch ?: '?'); ?></span></div>
        <div class="prow"><span class="k"><?php echo rt('k_db'); ?></span><span class="v" id="stDb"><span class="ok-dot <?php echo $__db['ok'] ? 'g' : 'r'; ?>"></span><?php echo $__db['ok'] ? rt('reachable') : rt('down'); ?></span></div>
        <div class="prow"><span class="k"><?php echo rt('k_maint_cfg'); ?></span><span class="v"><span class="ok-dot <?php echo $__cfgPresent ? 'g' : 'r'; ?>"></span><?php echo $__cfgPresent ? htmlspecialchars($__cfgPresent) : rt('k_missing'); ?></span></div>
        <div class="prow"><span class="k"><?php echo rt('k_rescue_ver'); ?></span><span class="v" style="user-select:all"><?php echo htmlspecialchars($__resVer); ?> · build <?php echo htmlspecialchars($__resBuild); ?></span></div>
        <div class="prow"><span class="k"><?php echo rt('k_disk'); ?></span><span class="v"><?php echo $__diskTxt; ?></span></div>
      </div>
      <div class="pcard">
        <h3><?php echo rt('card_update'); ?></h3>
        <div class="note" style="font-size:.78em;color:#999"><?php echo rt('update_note'); ?></div>
        <div class="dcard">cd <?php echo htmlspecialchars(rescue_root()); ?> &amp;&amp; git fetch origin main &amp;&amp; git checkout --force origin/main -- rescue</div>
      </div>
    </div>
  </div>

  <div class="panel" id="panel-upgrade">
    <div class="ch"><h2><?php echo rt('nav_upgrade'); ?></h2><span style="color:#e0a040;font-size:.75em;margin-left:12px"><?php echo rt('badge_rescue'); ?></span></div>
    <div class="portal">
      <div class="pcard">
        <h3><?php echo rt('up_card'); ?></h3>
        <div class="note" style="margin:0 0 10px"><?php echo rt('up_note'); ?></div>
        <div class="prow"><span class="k"><?php echo rt('k_current'); ?></span><span class="v" id="upCur" style="user-select:all"><?php echo htmlspecialchars(substr($__appGit, 0, 12) ?: '?'); ?></span></div>
        <div class="prow"><span class="k"><?php echo rt('k_remote'); ?></span><span class="v" id="upRem" style="user-select:all">—</span></div>
        <div style="margin-top:10px"><button class="pbtn gray" id="upCheckBtn" onclick="upCheck()"><?php echo rt('btn_check'); ?></button></div>
        <div class="pfield" style="margin-top:14px;position:relative">
          <label><?php echo rt('lbl_target_optional'); ?></label>
          <input type="text" id="upSearch" placeholder="<?php echo rt('dg_search_ph'); ?>" autocomplete="off" oninput="upOnInput()" onfocus="upOnFocus()">
          <div class="dg-list" id="upList" style="display:none"></div>
        </div>
        <div class="note" id="upSelInfo" style="display:none"></div>
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
          <button class="pbtn gray" onclick="upPickLatest()"><?php echo rt('btn_latest'); ?></button>
          <button class="pbtn gray" onclick="upPickNext()"><?php echo rt('btn_next_ver'); ?></button>
        </div>
      </div>
      <div class="pcard">
        <?php if (!$__adminGate['ok']): ?>
        <div class="dnote">⚠ <?php echo rt($__adminNoteKey); ?></div>
        <?php else: ?>
        <div class="pfield"><label><?php echo rt('lbl_admin_pwd'); ?></label><input type="password" id="upPwd" autocomplete="off"></div>
        <?php endif; ?>
        <div class="pfield"><label><?php echo rt('lbl_m_user'); ?></label><input type="text" id="upMUser" autocomplete="off"></div>
        <div class="pfield"><label><?php echo rt('lbl_m_pass'); ?></label><input type="password" id="upMPass" autocomplete="off"></div>
        <div class="pfield"><label><?php echo rt('lbl_git1'); ?></label><input type="text" id="upH1" spellcheck="false" placeholder="git rev-parse HEAD"></div>
        <div class="pfield"><label><?php echo rt('lbl_git2'); ?></label><input type="text" id="upH2" spellcheck="false"></div>
        <label class="pcheck"><input type="checkbox" id="upRisk"> <?php echo rt('chk_risk'); ?></label>
        <div style="margin-top:10px"><button class="pbtn green" id="upRunBtn" onclick="upRun()"><?php echo rt('btn_upgrade_now'); ?></button></div>
        <div class="progwrap" id="upProgWrap" style="display:none">
          <div id="upStep" style="color:#6fa8dc;font-weight:700;font-size:.84em;margin-bottom:6px"></div>
          <div class="progbar"><div id="upBar"></div></div>
          <div id="upPct" style="color:#888;font-size:.78em;margin-top:4px">0%</div>
        </div>
      </div>
    </div>
  </div>

  <div class="panel" id="panel-downgrade">
    <div class="ch"><h2><?php echo rt('nav_downgrade'); ?></h2><span style="color:#e0a040;font-size:.75em;margin-left:12px"><?php echo rt('badge_rescue'); ?></span></div>
    <div class="portal">
      <div class="pcard">
        <h3><?php echo rt('dg_card'); ?></h3>
        <div class="dnote">⚠ <?php echo rt('dg_note'); ?></div>
        <div class="prow"><span class="k"><?php echo rt('k_current'); ?></span><span class="v" style="user-select:all"><?php echo htmlspecialchars(substr($__appGit, 0, 12) ?: '?'); ?></span></div>
        <div class="pfield" style="margin-top:10px;position:relative">
          <label><?php echo rt('lbl_target'); ?></label>
          <input type="text" id="dgSearch" placeholder="<?php echo rt('dg_search_ph'); ?>" autocomplete="off" oninput="dgOnInput()" onfocus="dgOnFocus()">
          <div class="dg-list" id="dgList" style="display:none"></div>
        </div>
        <div class="note" id="dgSelInfo" style="display:none"></div>
        <div style="margin-top:8px"><button class="pbtn gray" onclick="dgPickPrev()"><?php echo rt('btn_prev_ver'); ?></button></div>
      </div>
      <div class="pcard">
        <?php if (!$__adminGate['ok']): ?>
        <div class="dnote">⚠ <?php echo rt($__adminNoteKey); ?></div>
        <?php else: ?>
        <div class="pfield"><label><?php echo rt('lbl_admin_pwd'); ?></label><input type="password" id="dgPwd" autocomplete="off"></div>
        <?php endif; ?>
        <div class="pfield"><label><?php echo rt('lbl_m_user'); ?></label><input type="text" id="dgMUser" autocomplete="off"></div>
        <div class="pfield"><label><?php echo rt('lbl_m_pass'); ?></label><input type="password" id="dgMPass" autocomplete="off"></div>
        <div class="pfield"><label><?php echo rt('lbl_git1'); ?></label><input type="text" id="dgH1" spellcheck="false" placeholder="git rev-parse HEAD"></div>
        <div class="pfield"><label><?php echo rt('lbl_git2'); ?></label><input type="text" id="dgH2" spellcheck="false"></div>
        <label class="pcheck"><input type="checkbox" id="dgRisk"> <?php echo rt('chk_dg_risk'); ?></label>
        <div style="margin-top:10px"><button class="pbtn red" id="dgRunBtn" onclick="dgRun()"><?php echo rt('btn_downgrade_now'); ?></button></div>
        <div class="progwrap" id="dgProgWrap" style="display:none">
          <div id="dgStep" style="color:#e08a80;font-weight:700;font-size:.84em;margin-bottom:6px"></div>
          <div class="progbar"><div id="dgBar"></div></div>
          <div id="dgPct" style="color:#888;font-size:.78em;margin-top:4px">0%</div>
        </div>
      </div>
    </div>
  </div>

  <div class="panel" id="panel-repair">
    <div class="ch"><h2><?php echo rt('nav_repair'); ?></h2><span style="color:#e0a040;font-size:.75em;margin-left:12px"><?php echo rt('badge_rescue'); ?></span></div>
    <div class="portal">
      <div class="pcard">
        <h3><?php echo rt('repair_card'); ?></h3>
        <div class="note" style="margin:0 0 10px"><?php echo rt('repair_note'); ?></div>
        <button class="pbtn gray" id="rpScanBtn" onclick="rpScan()"><?php echo rt('btn_scan'); ?></button>
        <div id="rpResult" style="margin-top:12px"></div>
      </div>
      <div class="pcard">
        <div class="prow"><span class="k"><?php echo rt('k_current'); ?></span><span class="v" style="user-select:all"><?php echo htmlspecialchars(substr($__appGit, 0, 12) ?: '?'); ?></span></div>
        <?php if (!$__adminGate['ok']): ?>
        <div class="dnote" style="margin-top:10px">⚠ <?php echo rt($__adminNoteKey); ?></div>
        <?php else: ?>
        <div class="pfield" style="margin-top:10px"><label><?php echo rt('lbl_admin_pwd'); ?></label><input type="password" id="rpPwd" autocomplete="off"></div>
        <?php endif; ?>
        <div class="pfield"><label><?php echo rt('lbl_m_user'); ?></label><input type="text" id="rpMUser" autocomplete="off"></div>
        <div class="pfield"><label><?php echo rt('lbl_m_pass'); ?></label><input type="password" id="rpMPass" autocomplete="off"></div>
        <div class="pfield"><label><?php echo rt('lbl_git1'); ?></label><input type="text" id="rpH1" spellcheck="false" placeholder="git rev-parse HEAD"></div>
        <div class="pfield"><label><?php echo rt('lbl_git2'); ?></label><input type="text" id="rpH2" spellcheck="false"></div>
        <label class="pcheck"><input type="checkbox" id="rpRisk"> <?php echo rt('chk_repair_risk'); ?></label>
        <div style="margin-top:10px"><button class="pbtn green" id="rpRunBtn" onclick="rpRun()"><?php echo rt('btn_repair_now'); ?></button></div>
        <div class="progwrap" id="rpProgWrap" style="display:none">
          <div id="rpStep" style="color:#6fa8dc;font-weight:700;font-size:.84em;margin-bottom:6px"></div>
          <div class="progbar"><div id="rpBar"></div></div>
          <div id="rpPct" style="color:#888;font-size:.78em;margin-top:4px">0%</div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="flash" id="flash"></div>
<div class="layout-copyright">© <a href="/index.php">ChatApp</a> 2026-<?php echo date('Y');?> by Jaden | <a href="//github.com/lqx211/ChatApp">Source Code</a> | All rights reserved</div>

<script>
var RT = <?php echo rescue_lang_js(); ?>;
<?php echo rescue_setlang_js(); ?>
function $(id){ return document.getElementById(id); }
function api(action, data, cb){
  var f = new URLSearchParams(); f.append('action', action);
  for (var k in (data || {})) { if (data[k] !== undefined && data[k] !== null) f.append(k, data[k]); }
  fetch('index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: f.toString(), credentials: 'same-origin' })
    .then(function(r){ return r.json(); }).then(cb)
    .catch(function(){ cb({ success: false, error: RT.net_err }); });
}
var _flashT = null;
function flash(msg, ok){
  var el = $('flash');
  el.textContent = msg;
  el.className = 'flash ' + (ok ? 'ok' : 'err');
  el.style.display = 'block';
  clearTimeout(_flashT);
  _flashT = setTimeout(function(){ el.style.display = 'none'; }, 6000);
}
function showPanel(name){
  var panels = document.querySelectorAll('.panel');
  for (var i = 0; i < panels.length; i++) panels[i].classList.remove('active');
  var p = $('panel-' + name);
  if (p) p.classList.add('active');
  if (name === 'dash') refreshStatus();
  if (name === 'upgrade' && !upk.loaded) upk.load();
  if (name === 'downgrade' && !dgk.loaded) dgk.load();
}
function doLogout(){ api('logout', [], function(){ location.reload(); }); }
function refreshStatus(){
  api('get', [], function(d){
    if (!d.success) return;
    if (d.app_git) { $('stGit').textContent = d.app_git; }
    if (d.branch) { $('stBranch').textContent = d.branch; }
  });
}

/* ---- 升级 ---- */
function upCheck(){
  var btn = $('upCheckBtn');
  btn.disabled = true; btn.textContent = RT.btn_checking;
  api('check_update', [], function(d){
    btn.disabled = false; btn.textContent = RT.btn_check;
    if (!d.success) return flash(d.error || RT.net_err, false);
    if (!d.remote) return flash(RT.net_err, false);
    $('upCur').textContent = d.current || '?';
    $('upRem').textContent = d.remote || '?';
    flash(d.has_update ? (RT.up_available + d.remote.slice(0, 12)) : RT.up_to_date, true);
  });
}
var _upPollT = null;
function upRun(){
  if (!$('upRisk').checked) return flash(RT.chk_risk, false);
  var data = {
    maint_user: $('upMUser').value.trim(),
    maint_pass: $('upMPass').value,
    git_hash: $('upH1').value.trim(),
    git_hash2: $('upH2').value.trim()
  };
  if (upk.target) data.target = upk.target;
  if ($('upPwd')) data.password = $('upPwd').value;
  $('upRunBtn').disabled = true;
  api('perform_upgrade', data, function(d){
    if (!d.success) { $('upRunBtn').disabled = false; return flash(d.error || RT.up_failed_t, false); }
    $('upProgWrap').style.display = 'block';
    $('upStep').textContent = RT.up_started;
    flash(RT.up_started, true);
    upPoll();
  });
}
function upPoll(){
  api('progress', [], function(d){
    if (d.step) $('upStep').textContent = d.step;
    if (typeof d.pct === 'number') { $('upBar').style.width = d.pct + '%'; $('upPct').textContent = d.pct + '%'; }
    if (d.status === 'done') {
      $('upStep').textContent = RT.up_done + (d.msg ? ' · ' + d.msg : '');
      $('upBar').style.width = '100%'; $('upPct').textContent = '100%';
      flash(RT.up_complete, true);
      setTimeout(function(){ location.reload(); }, d.msg ? 6000 : 2500);
      return;
    }
    if (d.status === 'error') {
      $('upStep').textContent = RT.up_failed_t;
      $('upRunBtn').disabled = false;
      flash(d.msg || RT.up_release, false);
      return;
    }
    _upPollT = setTimeout(upPoll, 1200);
  });
}

/* ---- 可搜索版本下拉（升级/降级共用工厂；默认 250 条，搜索不限条数） ---- */
function mkPicker(prefix, opts){
  opts = opts || {};
  var P = { loaded: false, target: '', timer: null,
            listAction: opts.listAction || 'dg_list',
            emptyText: opts.emptyText || RT.dg_none };
  P.fetch = function(q){
    api(P.listAction, q ? { q: q } : [], function(d){
      if (!d.success) { P.loaded = false; return flash(d.error || RT.dg_load_failed, false); }
      P.render(d.versions);
    });
  };
  P.load = function(){ P.loaded = true; P.fetch(''); };
  P.render = function(list){
    var el = $(prefix + 'List');
    el.innerHTML = '';
    if (!list.length) {
      var e = document.createElement('div');
      e.className = 'dg-empty';
      e.textContent = P.emptyText;
      el.appendChild(e);
    } else {
      for (var i = 0; i < list.length; i++) {
        (function(v){
          var it = document.createElement('div');
          it.className = 'dg-item' + (v.h === P.target ? ' sel' : '');
          it.textContent = v.h.slice(0, 10) + ' · ' + v.date.slice(0, 16) + ' · ' + v.subj;
          it.title = v.h + '\n' + v.date + '\n' + v.subj;
          it.addEventListener('mousedown', function(ev){ ev.preventDefault(); P.pick(v); });
          el.appendChild(it);
        })(list[i]);
      }
    }
    el.style.display = 'block';
  };
  P.pick = function(v){
    P.target = v.h;
    $(prefix + 'Search').value = v.h.slice(0, 10) + ' · ' + v.date.slice(0, 16) + ' · ' + v.subj;
    $(prefix + 'List').style.display = 'none';
    var info = $(prefix + 'SelInfo');
    if (info) {
      info.style.display = 'block';
      info.innerHTML = RT.dg_picked + ': <b style="color:#9ecbff;user-select:all">' + v.h + '</b>';
    }
  };
  P.onInput = function(){
    P.target = ''; // 改动了搜索词 → 必须重新选一项
    clearTimeout(P.timer);
    var q = $(prefix + 'Search').value.trim();
    P.timer = setTimeout(function(){ P.fetch(q); }, 250);
  };
  P.onFocus = function(){
    if (!P.loaded) { P.load(); return; }
    var el = $(prefix + 'List');
    if (el && !el.innerHTML) { P.fetch(''); return; }
    el.style.display = 'block';
  };
  P.pickByHash = function(h){
    if (!h) return;
    api(P.listAction, { q: h.slice(0, 12) }, function(d){
      if (!d.success || !d.versions || !d.versions.length) return flash(RT.net_err, false);
      var pick = d.versions[0];
      for (var i = 0; i < d.versions.length; i++) { if (d.versions[i].h === h) { pick = d.versions[i]; break; } }
      P.pick(pick);
    });
  };
  return P;
}
var upk = mkPicker('up', { listAction: 'up_list', emptyText: RT.up_none });
var dgk = mkPicker('dg');
function upOnInput(){ upk.onInput(); }
function upOnFocus(){ upk.onFocus(); }
function dgOnInput(){ dgk.onInput(); }
function dgOnFocus(){ dgk.onFocus(); }
document.addEventListener('mousedown', function(ev){
  var pairs = [['upSearch', 'upList'], ['dgSearch', 'dgList']];
  for (var i = 0; i < pairs.length; i++) {
    var el = $(pairs[i][1]);
    if (!el || el.style.display === 'none') continue;
    if (ev.target === $(pairs[i][0]) || el.contains(ev.target)) continue;
    el.style.display = 'none';
  }
});
var _dgPollT = null;
function dgRun(){
  if (!dgk.target) return flash(RT.dg_pick_first, false);
  if (!$('dgRisk').checked) return flash(RT.chk_dg_risk, false);
  var data = {
    target: dgk.target,
    maint_user: $('dgMUser').value.trim(),
    maint_pass: $('dgMPass').value,
    git_hash: $('dgH1').value.trim(),
    git_hash2: $('dgH2').value.trim()
  };
  if ($('dgPwd')) data.password = $('dgPwd').value;
  $('dgRunBtn').disabled = true;
  api('perform_downgrade', data, function(d){
    if (!d.success) { $('dgRunBtn').disabled = false; return flash(d.error || RT.dg_failed_t, false); }
    $('dgProgWrap').style.display = 'block';
    $('dgStep').textContent = RT.dg_started;
    flash(RT.dg_started, true);
    dgPoll();
  });
}
function dgPoll(){
  api('progress', [], function(d){
    if (d.step) $('dgStep').textContent = d.step;
    if (typeof d.pct === 'number') { $('dgBar').style.width = d.pct + '%'; $('dgPct').textContent = d.pct + '%'; }
    if (d.status === 'done') {
      $('dgStep').textContent = RT.dg_done_t + (d.msg ? ' · ' + d.msg : '');
      $('dgBar').style.width = '100%'; $('dgPct').textContent = '100%';
      flash(RT.dg_complete, true);
      setTimeout(function(){ location.reload(); }, d.msg ? 6000 : 2500);
      return;
    }
    if (d.status === 'error') {
      $('dgStep').textContent = RT.dg_failed_t;
      $('dgRunBtn').disabled = false;
      flash(d.msg || RT.dg_failed_t, false);
      return;
    }
    _dgPollT = setTimeout(dgPoll, 1200);
  });
}

/* ---- 升级快捷：最新版 / 升一级 ---- */
function upPickLatest(){
  api('check_update', [], function(d){
    if (!d.success || !d.remote) return flash(RT.net_err, false);
    $('upCur').textContent = d.current || '?';
    $('upRem').textContent = d.remote || '?';
    if (d.remote === d.current) return flash(RT.up_to_date, true);
    upk.pickByHash(d.remote);
  });
}
function upPickNext(){
  api('adjacent', [], function(d){
    if (!d.success) return flash(RT.net_err, false);
    if (!d.next) return flash(RT.up_no_next, true);
    upk.pickByHash(d.next);
  });
}
/* ---- 降级快捷：降一级 ---- */
function dgPickPrev(){
  api('adjacent', [], function(d){
    if (!d.success) return flash(RT.net_err, false);
    if (!d.prev) return flash(RT.dg_no_prev, true);
    dgk.pickByHash(d.prev);
  });
}

/* ---- 修复（Repair）：把跟踪文件恢复到当前 HEAD 版本 ---- */
var _rpPollT = null;
function rpScan(){
  var btn = $('rpScanBtn');
  btn.disabled = true; btn.textContent = RT.btn_checking;
  api('repair_scan', [], function(d){
    btn.disabled = false; btn.textContent = RT.btn_scan;
    if (!d.success) return flash(d.error || RT.net_err, false);
    var el = $('rpResult');
    el.innerHTML = '';
    if (!d.files.length) {
      var ok = document.createElement('div');
      ok.className = 'note';
      ok.style.color = '#7ddb9a';
      ok.textContent = RT.repair_none;
      el.appendChild(ok);
    } else {
      var head = document.createElement('div');
      head.className = 'note';
      head.textContent = RT.repair_found.replace('%s', d.files.length);
      el.appendChild(head);
      var stMap = { 'M': RT.st_m, 'D': RT.st_d, 'R': RT.st_r, 'C': RT.st_c, 'A': RT.st_a, 'U': RT.st_u };
      for (var i = 0; i < d.files.length; i++) {
        var f = d.files[i];
        var row = document.createElement('div');
        row.className = 'rp-row';
        var tag = document.createElement('span');
        tag.className = 'rp-tag';
        tag.textContent = stMap[f.code] || f.code;
        var pth = document.createElement('span');
        pth.className = 'rp-path';
        pth.textContent = f.path;
        row.appendChild(tag); row.appendChild(pth);
        el.appendChild(row);
      }
    }
    if (d.skipped > 0) {
      var sk = document.createElement('div');
      sk.className = 'note';
      sk.textContent = RT.repair_skipped.replace('%s', d.skipped);
      el.appendChild(sk);
    }
  });
}
function rpRun(){
  if (!$('rpRisk').checked) return flash(RT.chk_repair_risk, false);
  var data = {
    maint_user: $('rpMUser').value.trim(),
    maint_pass: $('rpMPass').value,
    git_hash: $('rpH1').value.trim(),
    git_hash2: $('rpH2').value.trim()
  };
  if ($('rpPwd')) data.password = $('rpPwd').value;
  $('rpRunBtn').disabled = true;
  api('perform_repair', data, function(d){
    if (!d.success) { $('rpRunBtn').disabled = false; return flash(d.error || RT.repair_failed, false); }
    $('rpProgWrap').style.display = 'block';
    $('rpStep').textContent = RT.repair_started;
    flash(RT.repair_started, true);
    rpPoll();
  });
}
function rpPoll(){
  api('progress', [], function(d){
    if (d.step) $('rpStep').textContent = d.step;
    if (typeof d.pct === 'number') { $('rpBar').style.width = d.pct + '%'; $('rpPct').textContent = d.pct + '%'; }
    if (d.status === 'done') {
      $('rpStep').textContent = RT.repair_done + (d.msg ? ' · ' + d.msg : '');
      $('rpBar').style.width = '100%'; $('rpPct').textContent = '100%';
      flash(RT.repair_done, true);
      $('rpRunBtn').disabled = false;
      setTimeout(rpScan, 800); // 修完自动再检查一遍
      return;
    }
    if (d.status === 'error') {
      $('rpStep').textContent = RT.repair_failed;
      $('rpRunBtn').disabled = false;
      flash(d.msg || RT.repair_failed, false);
      return;
    }
    _rpPollT = setTimeout(rpPoll, 1200);
  });
}

/* 打开时若有正在进行的任务 → 自动接上进度轮询 */
api('progress', [], function(d){
  if (!d.locked) return;
  var t = d.type || 'upgrade';
  if (t === 'downgrade') {
    showPanel('downgrade'); $('dgProgWrap').style.display = 'block'; dgPoll();
  } else if (t === 'repair') {
    showPanel('repair'); $('rpProgWrap').style.display = 'block'; rpPoll();
  } else {
    showPanel('upgrade'); $('upProgWrap').style.display = 'block'; upPoll();
  }
});
</script>
</body>
</html>
