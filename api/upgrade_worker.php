<?php
/**
 * ChatApp · 升级后台 worker
 * 由 api/upgrade.php perform 触发（nohup php 后台运行）。
 * 执行 git fetch（流式解析下载进度）→ checkout（排除 config/data/bkup 与机器相关的 maintenance/config.php）→ reset → 尽力重启 WSS 服务。
 * 进度写 data/upgrade_progress.json；完成或失败都清除 data/upgrade.lock（避免卡维护）。
 * ⚠️ 本文件不 require config.php（否则会被自己的维护锁拦截），只操作文件 + git。
 */
error_reporting(0);
$root = dirname(__DIR__);
$lock = $root . '/data/upgrade.lock';
$progress = $root . '/data/upgrade_progress.json';

function upw_progress(string $status, string $step, int $pct, string $msg = '', ?string $from = null, ?string $to = null): void {
    global $progress;
    @file_put_contents($progress, json_encode([
        'status' => $status, 'step' => $step, 'pct' => $pct, 'msg' => $msg,
        'from' => $from, 'to' => $to, 'updated_at' => time(),
    ]));
}
function upw_git(string $cmd, string $root): array {
    $out = []; $ret = -1;
    exec('cd ' . escapeshellarg($root) . ' && ' . $cmd . ' 2>&1', $out, $ret);
    return [implode("\n", $out), $ret];
}

/**
 * 尽力重启 WSS 常驻进程（systemd → start.sh）。
 * 升级只更新磁盘文件；已在跑的 WSS 进程仍执行内存里的旧代码（常驻进程不热更新），
 * 不重启就会出现「网页是新版、WSS 行为是旧版」的诡异问题（如发消息失败）。
 * 没有权限就跳过，由进度消息提醒手工重启。
 */
function upw_wss_restart(string $root): string {
    foreach (['sudo -n systemctl restart chatapp-wss', 'systemctl restart chatapp-wss'] as $c) {
        [$o, $rc] = upw_git($c, $root);
        if ($rc === 0) return 'WSS restarted';
    }
    [$o, $rc] = upw_git('bash ' . escapeshellarg($root . '/wss/start.sh') . ' restart', $root);
    if ($rc === 0) return 'WSS restarted (start.sh)';
    return 'WSS not restarted - run: sudo systemctl restart chatapp-wss';
}

[$head0] = upw_git('git rev-parse HEAD', $root);
upw_progress('running', 'Fetching update…', 3, '', trim($head0));

// ---- fetch（流式读 stderr 解析 "Receiving objects: xx%"）----
$cmd = 'cd ' . escapeshellarg($root) . ' && git fetch --progress origin main 2>&1';
$desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($cmd, $desc, $pipes);
if (is_resource($proc)) {
    $lastPct = 3;
    while (!feof($pipes[1])) {
        $line = fgets($pipes[1]);
        if ($line === false) break;
        if (preg_match('/Receiving objects:\s*(\d+)%/i', $line, $m)) {
            $pct = 3 + (int)round(((int)$m[1]) * 0.80); // fetch 进度映射到 3-83%
            if ($pct > $lastPct) { $lastPct = $pct; upw_progress('running', 'Downloading…', $pct, trim($line)); }
        }
    }
    proc_close($proc);
} else {
    [$f] = upw_git('git fetch --progress origin main', $root);
}

// ---- checkout：覆盖代码；config/data/bkup 跳过；maintenance/ 照常更新 ----
// （maintenance/config.php 是机器相关的凭据文件，显式排除；creds.php 是通用
//   加载器，会随代码更新）
upw_progress('running', 'Applying update…', 88);
$coCmd = "git checkout --force origin/main -- . ':!config' ':!data' ':!bkup' ':!maintenance/config.php'";
[$co, $rc] = upw_git($coCmd, $root);
if ($rc !== 0) {
    upw_progress('error', 'Upgrade failed', 100, mb_substr($co, 0, 300), trim($head0), null);
    @unlink($lock);
    echo "ERROR\n" . $co . "\n";
    exit;
}
upw_git('git reset --soft origin/main', $root);
[$head1] = upw_git('git rev-parse HEAD', $root);

// ---- 重启 WSS（尽力而为），并把它作为完成消息带上 ----
$wssMsg = upw_wss_restart($root);

upw_progress('done', 'Upgrade complete', 100, $wssMsg, trim($head0), trim($head1));
@unlink($lock); // 解除维护，全员恢复
echo "DONE " . trim($head0) . " -> " . trim($head1) . "\n";
