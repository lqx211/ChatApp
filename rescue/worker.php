<?php
/**
 * ChatApp · Rescue Panel 后台 worker（自包含，不依赖 api/ 与 maintenance/ 代码）
 *
 * 用法: php worker.php upgrade [commit]   （不带 commit = 拉最新 origin/main）
 *       php worker.php downgrade <commit>
 *       php worker.php repair             （把跟踪文件恢复到当前 HEAD，版本不变）
 *
 * 由 rescue/index.php spawn（nohup php worker.php ... &）。
 * 步骤：git fetch（流式进度）→ checkout（排除 config/data/bkup/maintenance/config.php
 * 与 rescue —— 救援面板自身永远不被改动）→ reset --soft → 尽力重启 WSS。
 * 进度写 data/rescue_progress.json；完成或失败都清除 data/rescue.lock。
 */
error_reporting(0);
require_once __DIR__ . '/lib.php';

$root = rescue_root();
$progress = rescue_progress_path();
$lock = rescue_lock_path();
$mode = (string)($argv[1] ?? '');
$target = (string)($argv[2] ?? '');

function rc_progress(string $status, string $step, int $pct, string $msg = '', ?string $from = null, ?string $to = null): void {
    global $progress;
    @file_put_contents($progress, json_encode([
        'status' => $status, 'step' => $step, 'pct' => $pct, 'msg' => $msg,
        'from' => $from, 'to' => $to, 'updated_at' => time(),
    ]));
}
function rc_shell(string $cmd): array {
    global $root;
    $out = []; $ret = -1;
    @exec('cd ' . escapeshellarg($root) . ' && ' . $cmd . ' 2>&1', $out, $ret);
    return [implode("\n", $out), $ret];
}
function rc_fail(string $step, string $detail, ?string $from, ?string $to = null): void {
    rc_progress('error', $step, 100, substr($detail, 0, 300), $from, $to); // 不用 mb_*：极简环境可能没 mbstring
    @unlink(rescue_lock_path());
    echo "ERROR\n" . $detail . "\n";
    exit;
}

if (!in_array($mode, ['upgrade', 'downgrade', 'repair'], true)) {
    rc_fail('Bad mode', 'usage: worker.php upgrade [commit]|downgrade <commit>|repair', null);
}

[$head0] = rc_shell('git rev-parse HEAD');
$head0 = trim($head0);
if ($head0 === '') rc_fail('Git unavailable', 'git rev-parse failed (not a git repo?)', null);

// 排除项：config/data/bkup 保留；maintenance 只护 config.php；rescue 永不改动
$EXCLUDES = "':!config' ':!data' ':!bkup' ':!maintenance/config.php' ':!rescue'";

// ---- repair：把跟踪文件恢复到当前 HEAD（不 fetch、不换版本） ----
if ($mode === 'repair') {
    rc_progress('running', 'Repairing files…', 30, '', $head0);
    [$co, $rc] = rc_shell("git checkout --force HEAD -- . $EXCLUDES");
    if ($rc !== 0) rc_fail('Repair failed', substr($co, 0, 300), $head0);
    rc_progress('done', 'Repair complete', 100, '', $head0, $head0);
    @unlink($lock);
    echo "DONE repair\n";
    exit;
}

if ($mode === 'upgrade') {
    rc_progress('running', 'Fetching update…', 3, '', $head0);

    // ---- fetch（流式解析 "Receiving objects: xx%"）----
    $cmd = 'cd ' . escapeshellarg($root) . ' && git fetch --progress origin main 2>&1';
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $desc, $pipes);
    if (is_resource($proc)) {
        $lastPct = 3;
        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if ($line === false) break;
            if (preg_match('/Receiving objects:\s*(\d+)%/i', $line, $m)) {
                $pct = 3 + (int)round(((int)$m[1]) * 0.80);
                if ($pct > $lastPct) { $lastPct = $pct; rc_progress('running', 'Downloading…', $pct, trim($line), $head0); }
            }
        }
        proc_close($proc);
    } else {
        rc_shell('git fetch --progress origin main');
    }

    rc_progress('running', 'Applying update…', 88, '', $head0);
    // 升级目标：默认 origin/main（最新）；也可指定具体 commit（界面“指定目标版本”）
    $coRef = ($target !== '') ? escapeshellarg($target) : 'origin/main';
    [$co, $rc] = rc_shell("git checkout --force $coRef -- . $EXCLUDES");
    if ($rc !== 0) rc_fail('Upgrade failed', substr($co, 0, 300), $head0);
    rc_shell('git reset --soft ' . $coRef);
    [$head1] = rc_shell('git rev-parse HEAD');
    $head1 = trim($head1);

    $wssMsg = rescue_wss_restart();
    rc_progress('done', 'Upgrade complete', 100, $wssMsg, $head0, $head1);
    @unlink($lock);
    echo "DONE " . $head0 . " -> " . $head1 . "\n";
    exit;
}

// ---- downgrade ----
rc_progress('running', 'Checking target…', 5, '', $head0);
if ($target === '') rc_fail('Bad target', 'no target commit given', $head0);

[$t] = rc_shell('git cat-file -t ' . escapeshellarg($target));
if (trim($t) !== 'commit') rc_fail('Downgrade failed', 'Target commit not found: ' . $target, $head0);

rc_progress('running', 'Applying downgrade…', 60, '', $head0, trim($target));
[$co, $rc] = rc_shell("git checkout --force " . escapeshellarg($target) . " -- . $EXCLUDES");
if ($rc !== 0) rc_fail('Downgrade failed', substr($co, 0, 300), $head0, trim($target));
rc_shell('git reset --soft ' . escapeshellarg($target));
[$head1] = rc_shell('git rev-parse HEAD');
$head1 = trim($head1);

$wssMsg = rescue_wss_restart();
rc_progress('done', 'Downgrade complete', 100, $wssMsg, $head0, $head1);
@unlink($lock);
echo "DONE " . $head0 . " -> " . $head1 . "\n";
