<?php
/**
 * ChatApp - Level System API
 * info / sign / upgrade / leaderboard / rank / history / limits
 */
require_once __DIR__ . '/config.php';
// import  config from config/lvconfig.php
require_once __DIR__ . '/../config/lvconfig.php';

chatapp_session_start();
if (!isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}
header('Content-Type: application/json');

$me = $_SESSION['username'];
$pdo = db();
$myUid = 0;
$myUidStmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
$myUidStmt->execute([$me]);
$myUid = (int)($myUidStmt->fetchColumn() ?: 0);
if (!$myUid) {
    echo json_encode(['success' => false, 'error' => 'Invalid user']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * UTC+8 today string.
 */
function _lvl_today(): string {
    return gmdate('Y-m-d', time() + 8 * 3600);
}

/**
 * Build my level data payload.
 *
 * level = manual display level (users.level, user presses Upgrade)
 * max_level = the highest display level the cumulative exp can afford
 * can_upgrade = exp has reached the gate for manual level+1
 */

function _get_lvconfig_value(string $lvconfig_id): string {
    $lvconfig = include __DIR__ . '/../config/lvconfig.php';
    return $lvconfig[$lvconfig_id] ?? '';
}

function _lvl_my_payload(PDO $pdo, int $uid): array {
    $row = $pdo->prepare("SELECT exp, level, last_sign_date, sign_streak FROM users WHERE user_id = ?");
    $row->execute([$uid]);
    $u = $row->fetch();
    $exp = (int)($u['exp'] ?? 0);
    $manual = max(1, min(100, (int)($u['level'] ?? 1)));
    $maxLv = level_info($exp)['level'];          // exp-affordable highest
    if ($maxLv > 100) $maxLv = 100;

    // Progress within the MANUAL level segment toward manual+1
    $cur = $manual == 1 ? 0 : level_cumulative($manual - 2);
    $end = level_cumulative($manual - 1);
    $need = $end - $cur;
    $progress = max(0, $exp - $cur);             // can exceed $need (exp keeps accumulating)
    // get max streak exp from lvconfig: exp_max_streak
    $maxStreakExp = (int)_get_lvconfig_value('exp_max_streak');
    $streakBonusExp = (int)_get_lvconfig_value('exp_streak_bonus');
    $baseExp = (int)_get_lvconfig_value('exp_streak_base');
    $pct = $need > 0 ? min(100, round(100 * $progress / $need, 1)) : 100;

    $today = _lvl_today();
    $lastSign = $u['last_sign_date'] ?? null;
    $streak = (int)($u['sign_streak'] ?? 0);
    $signedToday = ($lastSign === $today);
    $nextStreak = $signedToday ? $streak : $streak + 1;
    $tomorrowExp = min($maxStreakExp, $baseExp + ($nextStreak - 1) * $streakBonusExp);
    return [
        'success' => true,
        'level' => $manual,
        'max_level' => $maxLv,
        'can_upgrade' => $manual < $maxLv,
        'exp' => $exp,
        'cur' => $progress,
        'need' => $need,
        'progress' => $pct,
        'signed_today' => $signedToday,
        'sign_streak' => $streak,
        'next_sign_exp' => $signedToday ? 0 : $tomorrowExp,
        'limits' => level_limits($manual),
        'username' => $_SESSION['username'] ?? '',
    ];
}

switch ($action) {

    case 'info':
        echo json_encode(_lvl_my_payload($pdo, $myUid));
        exit;

    case 'upgrade':
        // Option B: exp keeps accumulating; user manually clicks Upgrade.
        $row = $pdo->prepare("SELECT exp, level FROM users WHERE user_id = ?");
        $row->execute([$myUid]);
        $u = $row->fetch();
        $exp = (int)($u['exp'] ?? 0);
        $manual = max(1, min(100, (int)($u['level'] ?? 1)));
        $maxLv = level_info($exp)['level'];
        if ($maxLv > 100) $maxLv = 100;
        if ($manual >= $maxLv) {
            echo json_encode(['success' => false, 'error' => 'Not enough EXP']);
            exit;
        }
        $newLevel = min(100, $manual + 1);
        $pdo->prepare("UPDATE users SET level = ? WHERE user_id = ?")->execute([$newLevel, $myUid]);
        echo json_encode([
            'success' => true,
            'level' => $newLevel,
            'payload' => _lvl_my_payload($pdo, $myUid),
        ]);
        exit;

    case 'sign':
        // Daily sign in (UTC+8). Base +n, streak bonus +n/day, max total n.
        // Make the n's configurable in the future, but for now hardcode them.
        $today = _lvl_today();
        $row = $pdo->prepare("SELECT last_sign_date, sign_streak FROM users WHERE user_id = ?");
        $row->execute([$myUid]);
        $u = $row->fetch();
        $lastSign = $u['last_sign_date'] ?? null;
        $streak = (int)($u['sign_streak'] ?? 0);

        if ($lastSign === $today) {
            echo json_encode(['success' => false, 'error' => 'Already signed today']);
            exit;
        }
        $yesterday = gmdate('Y-m-d', time() + 8 * 3600 - 86400);
        if ($lastSign === $yesterday) {
            $streak += 1;
        } else {
            $streak = 1;
        }
        $maxStreakExp = (int)_get_lvconfig_value('exp_max_streak');
        $streakBonusExp = (int)_get_lvconfig_value('exp_streak_bonus');
        $baseExp = (int)_get_lvconfig_value('exp_streak_base');
        $exp = min($maxStreakExp, $baseExp + ($streak - 1) * $streakBonusExp);
        // 原子化：条件更新防止并发 double-sign（TOCTOU）重复领 EXP
        $upd = $pdo->prepare("UPDATE users SET last_sign_date = ?, sign_streak = ? WHERE user_id = ? AND (last_sign_date IS NULL OR last_sign_date <> ?)");
        $upd->execute([$today, $streak, $myUid, $today]);
        if ($upd->rowCount() === 0) {
            echo json_encode(['success' => false, 'error' => 'Already signed today']);
            exit;
        }
        exp_add($myUid, $exp, 'sign', true, 'streak:' . $streak);
        echo json_encode([
            'success' => true,
            'exp' => $exp,
            'streak' => $streak,
            'signed_today' => true,
            'payload' => _lvl_my_payload($pdo, $myUid),
        ]);
        exit;

    case 'leaderboard':
        // Manual level is displayed (users.level), exp for tiebreak.
        $rows = $pdo->query("SELECT user_id, username, display_name, exp, level FROM users WHERE deleted_at IS NULL AND placeholder = 0 ORDER BY level DESC, exp DESC, user_id ASC LIMIT 50")->fetchAll();
        $list = [];
        $rank = 1;
        foreach ($rows as $r) {
            $list[] = [
                'rank' => $rank++,
                'user_id' => (int)$r['user_id'],
                'username' => $r['username'],
                'display_name' => $r['display_name'],
                'level' => max(1, min(100, (int)($r['level'] ?? 1))),
                'max_level' => min(100, level_info((int)$r['exp'])['level']),
                'exp' => (int)$r['exp'],
            ];
        }
        echo json_encode(['success' => true, 'list' => $list]);
        exit;

    case 'rank':
        $stmt = $pdo->prepare("SELECT level, exp FROM users WHERE user_id = ?");
        $stmt->execute([$myUid]);
        $row = $stmt->fetch();
        $myLevel = max(1, (int)($row['level'] ?? 1));
        $myExp = (int)($row['exp'] ?? 0);
        $higher = $pdo->prepare("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND placeholder = 0 AND (level > ? OR (level = ? AND exp > ?))");
        $higher->execute([$myLevel, $myLevel, $myExp]);
        $rank = (int)$higher->fetchColumn() + 1;
        $total = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND placeholder = 0")->fetchColumn();
        echo json_encode(['success' => true, 'rank' => $rank, 'total' => $total]);
        exit;

    case 'history':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = 30;
        $off = ($page - 1) * $per;
        $stmt = $pdo->prepare("SELECT id, exp, type, detail, created_at FROM exp_log WHERE user_id = ? ORDER BY id DESC LIMIT $per OFFSET $off");
        $stmt->execute([$myUid]);
        $items = $stmt->fetchAll();
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM exp_log WHERE user_id = ?");
        $cnt->execute([$myUid]);
        $total = (int)$cnt->fetchColumn();
        echo json_encode(['success' => true, 'items' => $items, 'total' => $total, 'page' => $page]);
        exit;

    case 'limits':
        $row = $pdo->prepare("SELECT level FROM users WHERE user_id = ?");
        $row->execute([$myUid]);
        $manual = max(1, (int)($row->fetchColumn() ?: 1));
        echo json_encode(['success' => true, 'level' => $manual, 'limits' => level_limits($manual)]);
        exit;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
}