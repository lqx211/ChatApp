<?php
/**
 * ChatApp · 升级后台 worker
 * 由 api/upgrade.php perform 触发（nohup php 后台运行）。
 * 执行 git fetch（流式解析下载进度）→ checkout（排除 config/data/bkup/maintenance）→ reset。
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

// ---- checkout：覆盖代码，排除 config/data/bkup/maintenance ----
upw_progress('running', 'Applying update…', 88);
$coCmd = "git checkout --force origin/main -- . ':!config' ':!data' ':!bkup' ':!maintenance'";
[$co, $rc] = upw_git($coCmd, $root);
if ($rc !== 0) {
    upw_progress('error', 'Upgrade failed', 100, mb_substr($co, 0, 300), trim($head0), null);
    @unlink($lock);
    echo "ERROR\n" . $co . "\n";
    exit;
}
upw_git('git reset --soft origin/main', $root);
[$head1] = upw_git('git rev-parse HEAD', $root);

upw_progress('done', 'Upgrade complete', 100, '', trim($head0), trim($head1));
@unlink($lock); // 解除维护，全员恢复
echo "DONE " . trim($head0) . " -> " . trim($head1) . "\n";
