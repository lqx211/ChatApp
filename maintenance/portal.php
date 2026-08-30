<?php
/**
 * ChatApp · Maintenance Management Portal — 维护模式管理门户
 *
 * 复刻 chat.php 的深色界面风格（global.css + modern/style/chat.css），
 * 仅替换侧边栏为门户导航。自包含，不依赖 chat.js。
 *
 * 鉴权：维护门户凭据签发的 1 小时 token（MT_TOKEN cookie / ?token=），
 * 与 maintenance.php 闸门一致 —— 即使处于维护模式、DB 挂掉也能访问。
 *
 * 能力：
 *   - 仪表盘：维护开关、当前状态、服务器信息（PHP/MySQL/git/磁盘）
 *   - 维护设置：返回码 / 维护页面 / 允许维护登录 / 使用 MySQL 凭据
 *   - 门户凭据：改维护门户用户名密码（须验证当前管理员密码）
 *   - 快捷链接：ChatApp 管理 / 工厂重置 / 升级 / 降级 / 卸载
 *
 * 存储：主写 data/maintenance_status.php（Web 可写，权威），尽力镜像到
 * 根 status.php（服务器上通常可写）。读取统一走 maintenance/status_loader.php。
 */

// 1) 引导（config.php 内含维护闸门：维护中且无有效 token 会被拦截——正确的安全行为）
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/creds.php';
require_once __DIR__ . '/status_loader.php';

// 2) 鉴权：维护门户 token（MT_TOKEN / ?token=）或已登录的 ChatApp 管理员（uid 10000）
$__authed = false;
$__creds = chatapp_maint_creds();
$__secret = (string)$__creds['secret'];
$__hour_window = floor(time() / 3600);
$__tok = $_COOKIE['MT_TOKEN'] ?? ($_GET['token'] ?? '');
if ($__secret !== '' && $__tok !== ''
    && hash_equals(hash_hmac('sha256', 'mt:' . $__hour_window, $__secret), (string)$__tok)) {
    $__authed = true;
    // ?token= 场景：提升为 cookie 并同步到 $_COOKIE（让本次请求内维护闸门也能读到）
    if (($_GET['token'] ?? '') !== '' && ($_COOKIE['MT_TOKEN'] ?? '') === '') {
        setcookie('MT_TOKEN', $__tok, 0, '/', '', false, true);
        $_COOKIE['MT_TOKEN'] = $__tok;
    }
}
if (!$__authed) {
    // 备选：已登录管理员（DB 正常时）可直接进入；DB 挂掉则此路不通 → 走维护登录
    try {
        chatapp_session_start();
        $__me = chatapp_get_user();
        if (is_array($__me) && (int)($__me['user_id'] ?? 0) === 10000) $__authed = true;
    } catch (\Throwable $e) { $__authed = false; }
}
if (!$__authed) {
    header('Location: /maintenance/index.php');
    exit;
}

/** MySQL 是否可达（尽力探测，DB 挂掉时门户其余功能仍可用） */
function chatapp_portal_mysql_ok(): bool {
    try { db(); return true; } catch (\Throwable $e) { return false; }
}

/** 写维护状态：MySQL（权威）+ 文件镜像（应急回退 / 上次已知状态） */
function chatapp_portal_write_status(array $st): bool {
    $st['override_mysql_maint_settings'] = false;
    $dbOk = false;
    try {
        chatapp_maint_ensure_table();
        $stmt = db()->prepare("INSERT INTO maintenance_settings (id, is_maintenance, mt_return_code, maintenance_page, allow_mt_login, mt_login_use_mysql_creds, updated_at) VALUES (1, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE is_maintenance=VALUES(is_maintenance), mt_return_code=VALUES(mt_return_code), maintenance_page=VALUES(maintenance_page), allow_mt_login=VALUES(allow_mt_login), mt_login_use_mysql_creds=VALUES(mt_login_use_mysql_creds), updated_at=NOW()");
        $stmt->execute([
            (int)$st['is_maintenance'],
            (int)$st['mt_return_code'],
            (string)$st['maintenance_page'],
            (int)$st['allow_mt_login'],
            (int)$st['mt_login_use_mysql_creds'],
        ]);
        $dbOk = true;
    } catch (\Throwable $e) { $dbOk = false; }
    // 文件镜像（保持上次已知状态 + override=false）
    $body = "<?php\n/**\n * ChatApp — Maintenance status (written by Maintenance Portal).\n * 手动改这里也行；门户会优先读 data/maintenance_status.php。\n */\nreturn " . var_export($st, true) . ";\n";
    $dataDir = dirname(__DIR__) . '/data';
    @mkdir($dataDir, 0775, true);
    $fileOk = @file_put_contents($dataDir . '/maintenance_status.php', $body);
    if ($fileOk !== false) {
        @file_put_contents(dirname(__DIR__) . '/status.php', $body); // best effort
    } else {
        $fileOk = @file_put_contents(dirname(__DIR__) . '/status.php', $body);
    }
    return $dbOk || $fileOk !== false;
}

$__maintPages = [
    '/errors/unavailable_erepair.html' => 'Emergency Repair',
    '/errors/unavailable_offline.html'  => 'Offline',
    '/errors/unavailable_upgrade.html'  => 'Upgrade',
    '/errors/unavailable_breakdb.html'  => 'DB Broken',
    '/errors/unavailable_limit.html'    => 'Limit Reached',
    '/errors/unavailable_spam.html'     => 'Spam Filter',
];
$__codes = [200, 401, 403, 429, 500, 503];

// ---------- POST 后端 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $st = chatapp_maint_status();

    if ($action === 'get') {
        $git = trim((string)@shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse --short HEAD 2>&1'));
        $df = disk_free_space(dirname(__DIR__));
        echo json_encode([
            'success' => true,
            'status'  => $st,
            'server'  => [
                'php'         => PHP_VERSION,
                'mysql'       => chatapp_portal_mysql_ok(),
                'git_head'    => $git,
                'disk_free'   => ($df === false ? null : (int)$df),
                'time'        => date('Y-m-d H:i:s'),
                'cfg_writable'=> is_writable(dirname(__DIR__) . '/data'),
            ],
        ]);
        exit;
    }

    if ($action === 'set') {
        $st['is_maintenance'] = (($_POST['is_maintenance'] ?? '') === '1');
        $code = (int)($_POST['mt_return_code'] ?? $st['mt_return_code']);
        if (!in_array($code, $__codes, true)) $code = 500;
        $st['mt_return_code'] = $code;
        $page = trim((string)($_POST['maintenance_page'] ?? $st['maintenance_page']));
        if (!isset($__maintPages[$page])) $page = $st['maintenance_page'];
        $st['maintenance_page'] = $page;
        $st['allow_mt_login'] = (($_POST['allow_mt_login'] ?? '') === '1');
        $st['mt_login_use_mysql_creds'] = (($_POST['mt_login_use_mysql_creds'] ?? '') === '1');
        if (!chatapp_portal_write_status($st)) {
            echo json_encode(['success' => false, 'error' => 'Could not write status config (data/ not writable?).']); exit;
        }
        echo json_encode(['success' => true, 'status' => $st]);
        exit;
    }

    if ($action === 'set_creds') {
        $cur = (string)($_POST['current_password'] ?? '');
        $mu  = trim($_POST['maint_user'] ?? '');
        $mp  = (string)($_POST['maint_pass'] ?? '');
        if ($cur === '') { echo json_encode(['success' => false, 'error' => 'Current admin password is required.']); exit; }
        $adm = db()->query('SELECT password FROM users WHERE user_id=10000')->fetch();
        if (!$adm || !password_verify($cur, (string)($adm['password'] ?? ''))) {
            echo json_encode(['success' => false, 'error' => 'Current admin password incorrect.']); exit;
        }
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $mu)) { echo json_encode(['success' => false, 'error' => 'Invalid maintenance username.']); exit; }
        if (strlen($mp) < 8) { echo json_encode(['success' => false, 'error' => 'Maintenance password min 8.']); exit; }
        $body = "<?php\n/**\n * ChatApp — Maintenance admin credentials\n *\n * AUTO-GENERATED during OOBE / Maintenance Portal.\n * Override via MAINT_USER / MAINT_PASS / MAINT_SECRET env vars if needed.\n */\n"
            . "\$MAINT_USER   = getenv('MAINT_USER') ?: " . var_export($mu, true) . ";\n"
            . "\$MAINT_PASS   = getenv('MAINT_PASS') ?: " . var_export($mp, true) . ";\n"
            . "\$MAINT_SECRET = getenv('MAINT_SECRET') ?: " . var_export(bin2hex(random_bytes(32)), true) . ";\n";
        $dataDir = dirname(__DIR__) . '/data';
        @mkdir($dataDir, 0775, true);
        $ok = @file_put_contents($dataDir . '/maint_config.php', $body);
        if ($ok !== false) {
            @file_put_contents(__DIR__ . '/config.php', $body); // best effort
        } else {
            $ok = @file_put_contents(__DIR__ . '/config.php', $body);
        }
        if ($ok === false) { echo json_encode(['success' => false, 'error' => 'Could not write maintenance config.']); exit; }
        // 凭据变更 → 使旧 MT_TOKEN 失效（强制重新登录）
        setcookie('MT_TOKEN', '', time() - 42000, '/', '', false, true);
        echo json_encode(['success' => true, 'relogin' => true]);
        exit;
    }

    if ($action === 'logout') {
        setcookie('MT_TOKEN', '', time() - 42000, '/', '', false, true);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'unknown action']); exit;
}

$__st = chatapp_maint_status();
$__git = trim((string)@shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' rev-parse --short HEAD 2>&1'));
$__df = disk_free_space(dirname(__DIR__));
$__dfTxt = ($__df === false) ? '?' : number_format($__df / 1073741824, 2) . ' GB';
$__mysqlOk = chatapp_portal_mysql_ok();
?>
<!DOCTYPE html>
<html lang="zh-Hans">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Maintenance Portal</title>
<link rel="stylesheet" href="../css/global.css">
<link rel="stylesheet" href="../modern/style/chat.css?v=<?php echo time();?>">
<style>
  html,body{height:100%;margin:0;background:#222}
  /* 加载动画（与 chat.php 一致） */
  #loader-wrapper{position:fixed;top:0;left:0;width:100%;height:100%;z-index:999;overflow:hidden;background:#333}
  #loader-wrapper .loader{width:100%;height:100%;position:absolute;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff;font-size:22px}
  #loader-wrapper .loader .circle{width:110px;height:110px;border-radius:50%;border:3px solid transparent;border-top-color:#fff;animation:spin 1.4s linear infinite}
  #loader-wrapper.loaded{visibility:hidden;pointer-events:none;transform:translateY(-100%);transition:transform .4s .4s ease-out,visibility .4s .4s ease-out}
  @keyframes spin{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}
  /* 门户卡片与表单（沿用深色主题） */
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
  .pcheck{display:flex;align-items:center;gap:8px;color:#bbb;font-size:.84em;padding:6px 0;cursor:pointer}
  .pcheck input{width:16px;height:16px;accent-color:#4a8a6a}
  .stat-big{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
  .stat-big .pill{font-size:1.05em;font-weight:700;padding:8px 20px;border-radius:0}
  .pill.on{background:#2e5d43;color:#7ddb9a;border:1px solid #3a704f}
  .pill.off{background:#6e2d2d;color:#ff9a9a;border:1px solid #8a3a3a}
  .grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
  .ok-dot{display:inline-block;width:9px;height:9px;border-radius:0;margin-right:6px;vertical-align:1px}
  .ok-dot.g{background:#5ec87a}.ok-dot.r{background:#e06666}
  .linkbtn{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;margin-bottom:8px;background:rgba(42,42,42,.8);border:1px solid #3a3a3a;border-radius:0;color:#c8c8c8;text-decoration:none;font-size:.85em}
  .linkbtn:hover{border-color:#4a6a8e;color:#fff}
  .note{color:#666;font-size:.72em;line-height:1.6;margin-top:6px}
  .flash{position:fixed;top:16px;right:16px;z-index:1000;padding:10px 16px;border-radius:0;font-size:.84em;display:none}
  .flash.ok{background:#2e5d43;color:#c8f5d8;border:1px solid #3a704f}
  .flash.err{background:#6e2d2d;color:#ffd0d0;border:1px solid #8a3a3a}
</style>
</head>
<body>
 <!-- 加载动画 -->
 <div id="loader-wrapper"><div class="loader"><div class="circle"></div><div style="margin-top:26px">Maintenance Portal</div></div></div>

 <!-- ============ 侧边栏（与 chat.php 完全一致的类与结构，无 emoji） ============ -->
 <div class="sidebar">
   <div class="sidebar-profile">
    <div class="sa"></div>
    <div class="sun">Maintenance Portal</div>
    <div class="sdnd <?php echo $__st['is_maintenance'] ? 'rstr' : 'on'; ?>" id="maintStatusBadge"><?php echo $__st['is_maintenance'] ? 'Maintenance' : 'Online'; ?></div>
   </div>
   <div class="sidebar-nav">
    <div class="ng"><div class="ngh" onclick="showPanel('dash')" style="cursor:pointer"><span>Dashboard</span></div></div>
    <div class="ng"><div class="ngh" onclick="showPanel('settings')" style="cursor:pointer"><span>Settings</span></div></div>
    <div class="ng"><div class="ngh" onclick="showPanel('creds')" style="cursor:pointer"><span>Credentials</span></div></div>
    <div class="ng"><div class="ngh" onclick="showPanel('upgrade')" style="cursor:pointer"><span>Upgrade</span></div></div>
    <div class="ng"><div class="ngh" onclick="showPanel('downgrade')" style="cursor:pointer"><span>Downgrade</span></div></div>
    <div class="ng"><div class="ngh" onclick="showPanel('factory')" style="cursor:pointer"><span>Factory Reset</span></div></div>
    <div class="ng"><div class="ngh" onclick="showPanel('uninstall')" style="cursor:pointer"><span>Uninstall</span></div></div>
    <div class="ng"><div class="ngh" onclick="showPanel('links')" style="cursor:pointer"><span>Quick Links</span></div></div>
   </div>
   <div class="sidebar-footer">
    <div class="ngh" onclick="doLogout()" style="cursor:pointer"><span>Logout</span></div>
   </div>
  </div>

  <!-- ============ 主区域 ============ -->
  <div class="main-content">

   <!-- 仪表盘 -->
   <div class="panel active" id="panel-dash">
    <div class="ch"><h2>Dashboard</h2><span style="color:#666;font-size:.75em">Maintenance Portal</span></div>
    <div class="portal">
     <div class="pcard">
      <h3>Maintenance Mode</h3>
      <div class="stat-big">
       <span class="pill <?php echo $__st['is_maintenance'] ? 'on' : 'off'; ?>" id="dashPill"><?php echo $__st['is_maintenance'] ? 'Maintenance' : 'Online'; ?></span>
       <button class="pbtn <?php echo $__st['is_maintenance'] ? 'green' : 'red'; ?>" id="dashToggle" onclick="toggleMaint()"><?php echo $__st['is_maintenance'] ? 'Disable Maintenance' : 'Enable Maintenance'; ?></button>
       <span class="note" style="margin-left:6px">All visitors see the maintenance page. Portal and admin credentials can still log in.</span>
      </div>
     </div>
     <div class="grid2">
      <div class="pcard"><h3>Current Settings</h3>
       <div class="prow"><span class="k">Return Code</span><span class="v" id="dashCode"><?php echo (int)$__st['mt_return_code']; ?></span></div>
       <div class="prow"><span class="k">Maintenance Page</span><span class="v" id="dashPage"><?php echo htmlspecialchars($__st['maintenance_page']); ?></span></div>
       <div class="prow"><span class="k">Allow Maintenance Login</span><span class="v" id="dashAllowLogin"><?php echo $__st['allow_mt_login'] ? 'Yes' : 'No'; ?></span></div>
       <div class="prow"><span class="k">Use MySQL Credentials</span><span class="v" id="dashMysqlCreds"><?php echo $__st['mt_login_use_mysql_creds'] ? 'Yes' : 'No'; ?></span></div>
      </div>
      <div class="pcard"><h3>Server Info</h3>
       <div class="prow"><span class="k">PHP</span><span class="v"><?php echo htmlspecialchars(PHP_VERSION); ?></span></div>
       <div class="prow"><span class="k">MySQL</span><span class="v"><span class="ok-dot <?php echo $__mysqlOk ? 'g' : 'r'; ?>"></span><?php echo $__mysqlOk ? 'Reachable' : 'Down'; ?></span></div>
       <div class="prow"><span class="k">Git HEAD</span><span class="v"><?php echo htmlspecialchars($__git ?: '?'); ?></span></div>
       <div class="prow"><span class="k">Free Disk</span><span class="v"><?php echo htmlspecialchars($__dfTxt); ?></span></div>
       <div class="prow"><span class="k">Server Time</span><span class="v"><?php echo date('Y-m-d H:i:s'); ?></span></div>
      </div>
     </div>
     <div class="pcard"><h3>Quick Actions</h3>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
       <button class="pbtn" onclick="showPanel('upgrade')">Upgrade</button>
       <button class="pbtn" onclick="showPanel('downgrade')">Downgrade</button>
       <button class="pbtn red" onclick="showPanel('factory')">Factory Reset</button>
       <button class="pbtn red" onclick="showPanel('uninstall')">Uninstall</button>
       <a class="pbtn gray" href="index.php">Maintenance Login</a>
      </div>
     </div>
    </div>
   </div>

   <!-- 维护设置 -->
   <div class="panel" id="panel-settings">
    <div class="ch"><h2>Settings</h2></div>
    <div class="portal">
     <div class="pcard" style="max-width:520px">
      <h3>Maintenance Mode Settings</h3>
      <div class="pfield"><label>Maintenance Mode</label>
       <select id="setIsMaint">
        <option value="0" <?php echo $__st['is_maintenance'] ? '' : 'selected'; ?>>Running (disable maintenance)</option>
        <option value="1" <?php echo $__st['is_maintenance'] ? 'selected' : ''; ?>>Maintenance (enable maintenance)</option>
       </select>
      </div>
      <div class="pfield"><label>Return Code</label>
       <select id="setCode">
        <?php foreach ($__codes as $__c): ?>
        <option value="<?php echo $__c; ?>" <?php echo (int)$__st['mt_return_code'] === $__c ? 'selected' : ''; ?>><?php echo $__c; ?> — <?php echo ['200'=>'OK','401'=>'Unauthorized','403'=>'Forbidden','429'=>'Too Many Requests','500'=>'Internal Server Error','503'=>'Service Unavailable'][$__c]; ?></option>
        <?php endforeach; ?>
       </select>
      </div>
      <div class="pfield"><label>Maintenance Page</label>
       <select id="setPage">
        <?php foreach ($__maintPages as $__p => $__pl): ?>
        <option value="<?php echo $__p; ?>" <?php echo $__st['maintenance_page'] === $__p ? 'selected' : ''; ?>><?php echo $__pl; ?></option>
        <?php endforeach; ?>
       </select>
      </div>
      <label class="pcheck"><input type="checkbox" id="setAllowLogin" <?php echo $__st['allow_mt_login'] ? 'checked' : ''; ?>> Allow maintenance login (shows the Admin Login link on the maintenance page)</label>
      <label class="pcheck"><input type="checkbox" id="setMysqlCreds" <?php echo $__st['mt_login_use_mysql_creds'] ? 'checked' : ''; ?>> Use MySQL credentials for maintenance login (validate account in DB)</label>
      <div style="margin-top:16px;display:flex;gap:10px;align-items:center">
       <button class="pbtn green" onclick="saveSettings()">Save</button>
       <button class="pbtn gray" onclick="previewPage()">Preview Page</button>
       <span class="note">Changes take effect immediately. Preview opens the selected page in a new tab.</span>
      </div>
     </div>
    </div>
   </div>

   <!-- 门户凭据 -->
   <div class="panel" id="panel-creds">
    <div class="ch"><h2>Credentials</h2></div>
    <div class="portal">
     <div class="pcard" style="max-width:520px">
      <h3>Change Maintenance Portal Username / Password</h3>
      <p class="note" style="margin-top:0">You must verify the current administrator password (uid 10000). Saving invalidates old maintenance tokens and you will need to log in again.</p>
      <div class="pfield"><label>Current Admin Password (required)</label><input type="password" id="cCur" autocomplete="current-password"></div>
      <div class="pfield"><label>Maintenance Username (3-20)</label><input type="text" id="cUser" autocomplete="off" placeholder="admin"></div>
      <div class="pfield"><label>Maintenance Password (≥8)</label><input type="password" id="cPass" autocomplete="new-password"></div>
      <button class="pbtn green" onclick="saveCreds()">Save &amp; Re-login</button>
     </div>
    </div>
   </div>

   <!-- 快捷链接 -->
   <div class="panel" id="panel-links">
    <div class="ch"><h2>Quick Links</h2></div>
    <div class="portal">
     <div class="pcard">
      <h3>Shortcuts</h3>
      <a class="linkbtn" href="index.php"><span>Maintenance Login (re-login)</span><span>→</span></a>
      <p class="note">Danger operations (Upgrade / Downgrade / Factory Reset / Uninstall) are handled directly in this portal via the sidebar.</p>
     </div>
    </div>
   </div>

   <!-- Upgrade -->
   <div class="panel" id="panel-upgrade">
    <div class="ch"><h2>Upgrade</h2></div>
    <div class="portal">
     <div class="pcard" style="max-width:560px">
      <h3>Upgrade ChatApp</h3>
      <p class="note" style="margin-top:0">Pulls from github.com/lqx211/ChatApp and overwrites code. config/ data/ maintenance/ are kept. Uncommitted changes will be overwritten.</p>
      <div class="prow"><span class="k">Branch</span><span class="v" id="upBranch">…</span></div>
      <div class="prow"><span class="k">Current</span><span class="v" id="upLocal">…</span></div>
      <div class="prow"><span class="k">Remote</span><span class="v" id="upRemote">…</span></div>
      <div class="prow"><span class="k">Uncommitted</span><span class="v" id="upDirty">…</span></div>
      <div style="margin-top:12px"><button class="pbtn" id="upCheckBtn" onclick="upgradeCheck()">Check for updates</button></div>
      <div id="upForm" style="display:none;margin-top:16px">
       <div class="pfield"><label>Administrator Password (10000)</label><input type="password" id="upPwd" autocomplete="current-password"></div>
       <div class="pfield"><label>Maintenance Username</label><input type="text" id="upMUser" autocomplete="off"></div>
       <div class="pfield"><label>Maintenance Passphrase</label><input type="password" id="upMSecret" autocomplete="off"></div>
       <div class="pfield"><label>Current git hash</label><input type="text" id="upHash1" spellcheck="false" placeholder="git log -1 --format=%H"></div>
       <div class="pfield"><label>Re-enter git hash</label><input type="text" id="upHash2" spellcheck="false"></div>
       <label class="pcheck"><input type="checkbox" id="upConfirm"> I understand and accept the risk</label>
       <div style="margin-top:12px"><button class="pbtn red" onclick="upgradeRun()">Upgrade now</button></div>
      </div>
      <div id="upProgress" style="display:none;margin-top:16px">
       <div id="upStep" style="color:#6fa8dc;font-weight:700">Starting…</div>
       <div style="height:14px;border:1px solid #3a6a8a;margin:10px 0"><div id="upBar" style="height:100%;width:0%;background:#4a9dd8"></div></div>
       <div id="upPct" style="color:#888;font-size:.8em">0%</div>
      </div>
     </div>
    </div>
   </div>

   <!-- Downgrade -->
   <div class="panel" id="panel-downgrade">
    <div class="ch"><h2>Downgrade</h2></div>
    <div class="portal">
     <div class="pcard" style="max-width:560px">
      <h3>Downgrade System</h3>
      <p class="note" style="margin-top:0;color:#ff9a9a">EXTREMELY DANGEROUS: reverts the entire codebase to an older version. Database schema and code may become incompatible. Effectively one-way.</p>
      <div class="pfield"><label>Current version</label><input type="text" id="dgHead" readonly placeholder="Loading versions…"></div>
      <div class="pfield"><label>Select target version</label><select id="dgTarget" style="width:100%;padding:8px 12px;background:#1e1e1e;border:1px solid #444;color:#e0e0e0;font-size:.85em;font-family:inherit;outline:none"></select></div>
      <div class="pfield"><label>Administrator Password (10000)</label><input type="password" id="dgPwd" autocomplete="current-password"></div>
      <div class="pfield"><label>Maintenance Username</label><input type="text" id="dgMUser" autocomplete="off"></div>
      <div class="pfield"><label>Maintenance Passphrase</label><input type="password" id="dgMSecret" autocomplete="off"></div>
      <div class="pfield"><label>Current git hash</label><input type="text" id="dgHash1" spellcheck="false" placeholder="git log -1 --format=%H"></div>
      <div class="pfield"><label>Re-enter git hash</label><input type="text" id="dgHash2" spellcheck="false"></div>
      <label class="pcheck"><input type="checkbox" id="dgConfirm"> I understand this is extremely dangerous</label>
      <div style="margin-top:12px"><button class="pbtn red" onclick="downgradeRun()">Downgrade now</button></div>
      <div id="dgResult" style="display:none;margin-top:10px;color:#7ddb9a"></div>
     </div>
    </div>
   </div>

   <!-- Factory Reset -->
   <div class="panel" id="panel-factory">
    <div class="ch"><h2>Factory Reset</h2></div>
    <div class="portal">
     <div class="pcard" style="max-width:560px">
      <h3>Factory Reset ChatApp</h3>
      <p class="note" style="margin-top:0;color:#ff9a9a">Drops and rebuilds the database, creates a new administrator, and wipes all users/data. A mysqldump backup is taken automatically unless skipped.</p>
      <div class="pfield"><label>Administrator Password (10000)</label><input type="password" id="frPwd" autocomplete="current-password"></div>
      <div class="pfield"><label>Maintenance Username</label><input type="text" id="frMUser" autocomplete="off"></div>
      <div class="pfield"><label>Maintenance Passphrase</label><input type="password" id="frMSecret" autocomplete="off"></div>
      <div class="pfield"><label>Current git hash</label><input type="text" id="frHash" spellcheck="false" placeholder="git log -1 --format=%H"></div>
      <div class="pfield"><label>New Admin Username (3-20)</label><input type="text" id="frNewUser" autocomplete="off"></div>
      <div class="pfield"><label>New Admin Password (min 8)</label><input type="password" id="frNewPass" autocomplete="new-password"></div>
      <div class="pfield"><label>New Maintenance Username (optional)</label><input type="text" id="frNewMUser" autocomplete="off"></div>
      <div class="pfield"><label>New Maintenance Password (optional)</label><input type="password" id="frNewMPass" autocomplete="new-password"></div>
      <label class="pcheck"><input type="checkbox" id="frSkipDump"> Skip automatic database backup</label>
      <label class="pcheck"><input type="checkbox" id="frConfirm"> I understand all data will be wiped</label>
      <div style="margin-top:12px"><button class="pbtn red" onclick="factoryRun()">Factory Reset now</button></div>
      <div id="frProgress" style="display:none;margin-top:12px;color:#6fa8dc;font-weight:700"></div>
     </div>
    </div>
   </div>

   <!-- Uninstall -->
   <div class="panel" id="panel-uninstall">
    <div class="ch"><h2>Uninstall</h2></div>
    <div class="portal">
     <div class="pcard" style="max-width:560px">
      <h3>Uninstall ChatApp</h3>
      <p class="note" style="margin-top:0;color:#ff9a9a">Permanently removes ChatApp from this server: deployed files, database (unless unchecked), and the WebSocket service. This cannot be undone.</p>
      <div class="pfield"><label>Administrator Password (10000)</label><input type="password" id="unPwd" autocomplete="current-password"></div>
      <div class="pfield"><label>Maintenance Username</label><input type="text" id="unMUser" autocomplete="off"></div>
      <div class="pfield"><label>Maintenance Passphrase</label><input type="password" id="unMSecret" autocomplete="off"></div>
      <div class="pfield"><label>Current git hash</label><input type="text" id="unHash1" spellcheck="false" placeholder="git log -1 --format=%H"></div>
      <div class="pfield"><label>Re-enter git hash</label><input type="text" id="unHash2" spellcheck="false"></div>
      <label class="pcheck"><input type="checkbox" id="unDbDel" checked> Delete database chatapp (uncheck to keep data)</label>
      <label class="pcheck"><input type="checkbox" id="unConfirm"> I understand: everything will be deleted</label>
      <div style="margin-top:12px"><button class="pbtn red" onclick="uninstallRun()">Uninstall ChatApp</button></div>
      <div id="unDone" style="display:none;margin-top:14px;color:#7ddb9a;font-weight:700;text-align:center">ChatApp has been uninstalled.<br><span style="color:#bbb;font-weight:400;font-size:.8em">Remaining files are being removed in the background. You can close this page now.</span></div>
     </div>
    </div>
   </div>

 </div>

 <div class="flash" id="flash"></div>

<script>
var STATUS = <?php echo json_encode($__st); ?>;
var PAGES  = <?php echo json_encode($__maintPages); ?>;
function showPanel(id){
  document.querySelectorAll('.panel').forEach(function(p){ p.classList.remove('active'); });
  var el = document.getElementById('panel-' + id);
  if (el) el.classList.add('active');
  if (id === 'downgrade') downgradeLoad();
}
function flash(msg, ok){
  var f = document.getElementById('flash');
  f.textContent = msg;
  f.className = 'flash ' + (ok ? 'ok' : 'err');
  f.style.display = 'block';
  clearTimeout(flash._t);
  flash._t = setTimeout(function(){ f.style.display = 'none'; }, 2600);
}
function api(action, extra, cb){
  var fd = new URLSearchParams(); fd.append('action', action);
  (extra || []).forEach(function(kv){ fd.append(kv[0], kv[1]); });
  fetch('portal.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:fd.toString(), credentials:'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(d){ cb(d); })
    .catch(function(){ flash('Network error / no response', false); });
}
function applyStatus(d){
  STATUS = d.status || STATUS;
  var mt = !!STATUS.is_maintenance;
  var pill = document.getElementById('dashPill');
  pill.textContent = mt ? 'Maintenance' : 'Online';
  pill.className = 'pill ' + (mt ? 'on' : 'off');
  document.getElementById('dashToggle').textContent = mt ? 'Disable Maintenance' : 'Enable Maintenance';
  document.getElementById('dashToggle').className = 'pbtn ' + (mt ? 'green' : 'red');
  var b = document.getElementById('maintStatusBadge');
  b.textContent = mt ? 'Maintenance' : 'Online';
  b.className = 'sdnd ' + (mt ? 'rstr' : 'on');
  document.getElementById('dashCode').textContent = STATUS.mt_return_code;
  document.getElementById('dashPage').textContent = STATUS.maintenance_page;
  document.getElementById('dashAllowLogin').textContent = STATUS.allow_mt_login ? 'Yes' : 'No';
  document.getElementById('dashMysqlCreds').textContent = STATUS.mt_login_use_mysql_creds ? 'Yes' : 'No';
}
function toggleMaint(){
  var next = !STATUS.is_maintenance;
  api('set', [['is_maintenance', next ? '1' : '0']], function(d){
    if (d.success){ applyStatus(d); flash(next ? 'Maintenance mode enabled' : 'Maintenance mode disabled', true); }
    else flash(d.error || 'Failed', false);
  });
}
function saveSettings(){
  api('set', [
    ['is_maintenance', document.getElementById('setIsMaint').value === '1' ? '1' : '0'],
    ['mt_return_code', document.getElementById('setCode').value],
    ['maintenance_page', document.getElementById('setPage').value],
    ['allow_mt_login', document.getElementById('setAllowLogin').checked ? '1' : '0'],
    ['mt_login_use_mysql_creds', document.getElementById('setMysqlCreds').checked ? '1' : '0'],
  ], function(d){
    if (d.success){ applyStatus(d); flash('Settings saved', true); }
    else flash(d.error || 'Save failed', false);
  });
}
function previewPage(){
  var p = document.getElementById('setPage').value;
  window.open(p, '_blank');
}
function saveCreds(){
  var cur = document.getElementById('cCur').value;
  var mu  = document.getElementById('cUser').value.trim();
  var mp  = document.getElementById('cPass').value;
  if (!cur){ flash('Please enter the current admin password', false); return; }
  if (!/^[a-zA-Z0-9_]{3,20}$/.test(mu)){ flash('Maintenance username must be 3-20 letters/numbers/underscore', false); return; }
  if (mp.length < 8){ flash('Maintenance password must be at least 8 chars', false); return; }
  api('set_creds', [['current_password', cur], ['maint_user', mu], ['maint_pass', mp]], function(d){
    if (d.success && d.relogin){ flash('Credentials updated, re-logging in...', true); setTimeout(function(){ location.href = 'index.php'; }, 900); }
    else flash(d.error || 'Failed', false);
  });
}
function doLogout(){
  api('logout', [], function(){ location.href = 'index.php'; });
}

/* ================= 危险操作（门户内直接执行，三重验证） ================= */
function $(id){ return document.getElementById(id); }
function dangerApi(ep, action, extra){
  var fd = new URLSearchParams();
  fd.append('action', action);
  (extra || []).forEach(function(kv){ fd.append(kv[0], kv[1]); });
  return fetch('/api/' + ep + '.php', {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString(), credentials: 'same-origin'
  }).then(function(r){ return r.json(); });
}

/* ---- Upgrade ---- */
function upgradeCheck(){
  var btn = $('upCheckBtn');
  btn.disabled = true; btn.textContent = 'Checking...';
  dangerApi('upgrade', 'check').then(function(d){
    btn.disabled = false; btn.textContent = 'Check for updates';
    if (!d.success){ flash(d.error || 'Check failed', false); return; }
    $('upBranch').textContent = d.branch || 'main';
    $('upLocal').textContent = (d.local || '').slice(0, 12);
    $('upRemote').textContent = d.remote ? d.remote.slice(0, 12) : '?';
    $('upDirty').textContent = d.dirty_count;
    if (d.has_update){ $('upForm').style.display = 'block'; flash('Update available → ' + (d.remote || '').slice(0, 12), true); }
    else { $('upForm').style.display = 'none'; flash('Already up to date', true); }
  }).catch(function(){ btn.disabled = false; btn.textContent = 'Check for updates'; flash('Network error', false); });
}
function upgradeRun(){
  var pwd = $('upPwd').value, mu = $('upMUser').value.trim(), ms = $('upMSecret').value;
  var h1 = $('upHash1').value.trim().toUpperCase(), h2 = $('upHash2').value.trim().toUpperCase();
  if (!pwd || !mu || !ms || !h1 || !h2){ flash('All fields are required', false); return; }
  if (h1 !== h2){ flash('Git hash mismatch', false); return; }
  if (!$('upConfirm').checked){ flash('Please accept the risk', false); return; }
  dangerApi('upgrade', 'perform', [['password', pwd], ['maint_user', mu], ['maint_secret', ms], ['git_hash', h1], ['git_hash2', h2]]).then(function(d){
    if (d.success){ $('upForm').style.display = 'none'; $('upCheckBtn').style.display = 'none'; $('upProgress').style.display = 'block'; flash('Upgrade started — maintenance armed', true); upgradePoll(); }
    else flash(d.error || 'Upgrade failed', false);
  }).catch(function(){ flash('Network error', false); });
}
function upgradePoll(){
  dangerApi('upgrade', 'progress').then(function(d){
    if (!d.success){ setTimeout(upgradePoll, 1500); return; }
    if (d.step) $('upStep').textContent = d.step;
    if (typeof d.pct === 'number'){ $('upBar').style.width = d.pct + '%'; $('upPct').textContent = d.pct + '%'; }
    if (d.status === 'done'){ $('upStep').textContent = 'Upgrade complete'; $('upBar').style.width = '100%'; $('upPct').textContent = '100%'; flash('Upgrade complete — service restored', true); setTimeout(function(){ location.reload(); }, 2500); return; }
    if (d.status === 'error'){ $('upStep').textContent = 'Upgrade failed'; $('upCheckBtn').style.display = 'block'; flash('Upgrade failed — maintenance released', false); return; }
    setTimeout(upgradePoll, 1000);
  }).catch(function(){ setTimeout(upgradePoll, 2000); });
}

/* ---- Downgrade ---- */
function downgradeLoad(){
  dangerApi('downgrade', 'list').then(function(d){
    if (!d.success){ flash(d.error || 'Failed to load versions', false); return; }
    $('dgHead').value = d.head ? d.head.slice(0, 12) : '';
    var sel = $('dgTarget');
    sel.innerHTML = '';
    (d.commits || []).forEach(function(c){
      var o = document.createElement('option');
      o.value = c.hash;
      o.textContent = (c.current ? '★ ' : '') + c.short + '  ' + c.subject + '  (' + c.date + ')';
      sel.appendChild(o);
    });
  }).catch(function(){ flash('Failed to load versions', false); });
}
function downgradeRun(){
  var pwd = $('dgPwd').value, mu = $('dgMUser').value.trim(), ms = $('dgMSecret').value;
  var h1 = $('dgHash1').value.trim().toUpperCase(), h2 = $('dgHash2').value.trim().toUpperCase();
  var target = $('dgTarget').value;
  if (!pwd || !mu || !ms || !h1 || !h2 || !target){ flash('All fields are required', false); return; }
  if (h1 !== h2){ flash('Git hash mismatch', false); return; }
  if (!$('dgConfirm').checked){ flash('Please confirm before downgrading', false); return; }
  dangerApi('downgrade', 'perform', [['password', pwd], ['maint_user', mu], ['maint_secret', ms], ['git_hash', h1], ['git_hash2', h2], ['target', target]]).then(function(d){
    if (d.success){ $('dgResult').style.display = 'block'; $('dgResult').textContent = 'Downgrade done: ' + (d.from || '').slice(0, 8) + ' → ' + (d.to || '').slice(0, 8); flash('Downgrade complete', true); setTimeout(function(){ location.reload(); }, 1800); }
    else flash(d.error || 'Downgrade failed', false);
  }).catch(function(){ flash('Network error', false); });
}

/* ---- Factory Reset ---- */
function factoryRun(){
  var pwd = $('frPwd').value, mu = $('frMUser').value.trim(), ms = $('frMSecret').value, h = $('frHash').value.trim().toUpperCase();
  var nu = $('frNewUser').value.trim(), np = $('frNewPass').value;
  if (!pwd || !mu || !ms || !h){ flash('All fields are required', false); return; }
  if (!/^[a-zA-Z0-9_]{3,20}$/.test(nu)){ flash('New admin username 3-20 letters/numbers/underscore', false); return; }
  if (np.length < 8){ flash('New admin password min 8', false); return; }
  if (!$('frConfirm').checked){ flash('Please confirm before factory reset', false); return; }
  var st = $('frProgress'); st.style.display = 'block'; st.textContent = 'Step 1/4: verifying credentials...';
  dangerApi('factory_reset', 'start', [['password', pwd], ['maint_user', mu], ['maint_secret', ms], ['git_hash', h]]).then(function(d){
    if (!d.success){ st.style.display = 'none'; flash(d.error || 'Verify failed', false); return; }
    st.textContent = 'Step 2/4: expiring all session tokens...';
    return dangerApi('factory_reset', 'expire_tokens').then(function(d2){
      if (!d2.success){ st.style.display = 'none'; flash(d2.error || 'Step 2 failed', false); throw 'stop'; }
      st.textContent = 'Step 3/4: setting new administrator...';
      return dangerApi('factory_reset', 'setup_creds', [['username', nu], ['password', np], ['skip_dump', $('frSkipDump').checked ? '1' : '0'], ['maint_user', $('frNewMUser').value.trim()], ['maint_pass', $('frNewMPass').value]]);
    }).then(function(d3){
      if (!d3.success){ st.style.display = 'none'; flash(d3.error || 'Step 3 failed', false); throw 'stop'; }
      st.textContent = 'Step 4/4: rebuilding database...';
      return dangerApi('factory_reset', 'rebuild');
    }).then(function(d4){
      if (!d4.success){ st.style.display = 'none'; flash(d4.error || 'Rebuild failed', false); return; }
      st.textContent = 'Factory reset complete ✓';
      flash('Factory reset complete', true);
      setTimeout(function(){ location.reload(); }, 2000);
    });
  }).catch(function(e){ if (e !== 'stop'){ st.style.display = 'none'; flash('Network error', false); } });
}

/* ---- Uninstall ---- */
function uninstallRun(){
  var pwd = $('unPwd').value, mu = $('unMUser').value.trim(), ms = $('unMSecret').value;
  var h1 = $('unHash1').value.trim().toUpperCase(), h2 = $('unHash2').value.trim().toUpperCase();
  if (!pwd || !mu || !ms || !h1 || !h2){ flash('All fields are required', false); return; }
  if (h1 !== h2){ flash('Git hash mismatch', false); return; }
  if (!$('unConfirm').checked){ flash('Please confirm before uninstalling', false); return; }
  if (!confirm('Are you absolutely sure? This permanently deletes ChatApp and (by default) its database. This cannot be undone.')) return;
  dangerApi('uninstall', 'perform', [['password', pwd], ['maint_user', mu], ['maint_secret', ms], ['git_hash', h1], ['git_hash2', h2], ['db_delete', $('unDbDel').checked ? '1' : '0']]).then(function(d){
    if (d.success){ $('unDone').style.display = 'block'; flash('ChatApp has been uninstalled', true); }
    else flash(d.error || 'Uninstall failed', false);
  }).catch(function(){ flash('Network error', false); });
}
window.addEventListener('load', function(){
  var w = document.getElementById('loader-wrapper');
  if (!w) return;
  setTimeout(function(){
    w.classList.add('loaded');
    w.style.pointerEvents = 'none';
    setTimeout(function(){ w.style.display = 'none'; }, 900); // 彻底移除，杜绝遮罩挡点击
  }, 350);
});
</script>
</body>
</html>
