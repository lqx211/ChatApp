<?php
/**
 * ChatApp · Rescue Panel i18n（English / 简体中文）
 *
 * 救援面板自带语言包（不依赖 maintenance/ 或 api/，被降级/升级砸坏时也要能用）。
 *   - 语言存 cookie `rescue_lang`（en / zh），默认 zh
 *   - PHP 侧 rt('key')；JS 侧 rescue_lang_js() 注入 JSON 后用 RT.key 取
 */

if (!function_exists('rescue_lang')) {
    function rescue_lang(): string {
        $v = (string)($_COOKIE['rescue_lang'] ?? 'zh');
        return in_array($v, ['en', 'zh'], true) ? $v : 'zh';
    }
}

$GLOBALS['RESCUE_LANG'] = rescue_lang();

if (!function_exists('rt')) {
    function rt(string $k): string {
        global $RESCUE_MT;
        $lang = $GLOBALS['RESCUE_LANG'] ?? 'zh';
        return (string)($RESCUE_MT[$lang][$k] ?? $RESCUE_MT['en'][$k] ?? $k);
    }
}

if (!function_exists('rescue_lang_js')) {
    function rescue_lang_js(): string {
        global $RESCUE_MT;
        $lang = $GLOBALS['RESCUE_LANG'] ?? 'zh';
        return json_encode($RESCUE_MT[$lang] ?? $RESCUE_MT['en'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('rescue_lang_select')) {
    function rescue_lang_select(string $attrs = ''): string {
        $lang = $GLOBALS['RESCUE_LANG'] ?? 'zh';
        $sel = function (string $v) use ($lang) { return $v === $lang ? ' selected' : ''; };
        return '<select id="rescueLangSel" onchange="rescueSetLang(this.value)" ' . $attrs . '>'
            . '<option value="en"' . $sel('en') . '>English</option>'
            . '<option value="zh"' . $sel('zh') . '>简体中文</option>'
            . '</select>';
    }
}

if (!function_exists('rescue_setlang_js')) {
    function rescue_setlang_js(): string {
        return 'function rescueSetLang(v){ document.cookie = "rescue_lang=" + (v === "en" ? "en" : "zh") + ";path=/;max-age=31536000"; location.reload(); }';
    }
}

$RESCUE_MT = [
    'en' => [
        'rescue_name'   => 'Rescue Panel',
        'lang_label'    => 'Language',
        'logout'        => 'Logout',
        'badge_rescue'  => 'Emergency Mode',
        'net_err'       => 'Network error / no response',
        'all_fields_required' => 'All fields are required',
        'git_hash_mismatch'   => 'Git hash mismatch',
        'unknown_action'      => 'Unknown action',
        /* 侧栏 */
        'nav_dash'      => 'Dashboard',
        'nav_upgrade'   => 'Upgrade',
        'nav_downgrade' => 'Downgrade',
        'nav_repair'    => 'Repair',
        /* 仪表盘 */
        'card_state'    => 'System State',
        'k_app_git'     => 'App Git HEAD',
        'k_branch'      => 'Branch',
        'k_db'          => 'Database',
        'reachable'     => 'Reachable',
        'down'          => 'Down',
        'k_maint_cfg'   => 'Maintenance credentials file',
        'k_present'     => 'Present',
        'k_missing'     => 'Missing',
        'k_rescue_ver'  => 'Rescue version',
        'k_build'       => 'Build time',
        'k_disk'        => 'Free Disk',
        'card_update'   => 'Updating the Rescue Panel',
        'update_note'   => 'This panel is deliberately NOT updated by any web upgrade — it must stay usable even when an upgrade goes wrong. To update it, run over SSH:',
        'card_boot'     => 'First-run bootstrap',
        'boot_created'  => 'maintenance/config.php was missing. A random maintenance credential file has been generated and written to maintenance/config.php. Read it over SSH (cat maintenance/config.php) to log in.',
        /* 升级 */
        'up_card'       => 'Upgrade ChatApp',
        'up_note'       => 'Pulls from github.com/lqx211/ChatApp and overwrites code. config/ data/ and maintenance credentials are kept; the rescue panel itself is never touched.',
        'k_current'     => 'Current',
        'k_remote'      => 'Remote',
        'btn_check'     => 'Check for updates',
        'btn_checking'  => 'Checking...',
        'btn_upgrade_now' => 'Upgrade now',
        'chk_risk'      => 'I understand and accept the risk',
        'up_to_date'    => 'Already up to date',
        'up_available'  => 'Update available → ',
        'up_started'    => 'Upgrade started (rescue panel)',
        'up_done'       => 'Upgrade complete',
        'up_failed_t'   => 'Upgrade failed',
        'up_release'    => 'Upgrade failed — nothing else was changed',
        'up_complete'   => 'Upgrade complete — service restored',
        /* 降级 */
        'dg_card'       => 'Downgrade System',
        'dg_note'       => 'EXTREMELY DANGEROUS: reverts the entire codebase to an older version. Database schema and code may become incompatible. Effectively one-way. The rescue panel itself is kept.',
        'lbl_target'    => 'Select target version',
        'dg_search_ph'  => 'Search subject / hash...',
        'dg_none'       => 'No matching versions',
        'dg_picked'     => 'Selected',
        'dg_pick_first' => 'Pick a target version first',
        'lbl_target_optional' => 'Target version (optional — empty = latest main)',
        'btn_latest'    => 'Latest',
        'btn_next_ver'  => 'Next version',
        'btn_prev_ver'  => 'Previous version',
        'up_no_next'    => 'Already at the newest locally-fetched commit',
        'up_none'       => 'No upgrade targets — already at the latest version',
        'up_target_old' => 'That version is the current one or older — use the Downgrade panel instead (or Repair for the current version).',
        'dg_no_prev'    => 'Already at the very first commit',
        /* 修复 */
        'repair_card'   => 'Repair Internal Files',
        'repair_note'   => 'Re-checkout every tracked file at the current version (HEAD) — fixes modified / deleted / corrupted core files without changing the version. config, data, backups, maintenance credentials and the rescue panel itself are never touched.',
        'btn_scan'      => 'Scan for problems',
        'btn_repair_now' => 'Repair now',
        'chk_repair_risk' => 'I understand files will be restored from the current version',
        'repair_none'   => 'No problems found — all tracked files match the current version.',
        'repair_found'  => '%s problem file(s):',
        'repair_skipped' => '%s untracked / index-residue entries ignored — they are not problems and Repair will not touch them.',
        'repair_started' => 'Repair started',
        'repair_done'   => 'Repair complete',
        'repair_failed' => 'Repair failed',
        'st_m' => 'modified',
        'st_d' => 'deleted',
        'st_r' => 'renamed',
        'st_c' => 'copied',
        'st_a' => 'added',
        'st_u' => 'conflict',
        'st_n' => 'untracked',
        'dg_ph_loading' => 'Loading versions…',
        'dg_load_failed' => 'Failed to load versions',
        'btn_downgrade_now' => 'Downgrade now',
        'chk_dg_risk'   => 'I understand this is extremely dangerous',
        'dg_confirm_t'  => 'Please confirm before downgrading',
        'dg_started'    => 'Downgrade started (rescue panel)',
        'dg_done_t'     => 'Downgrade complete',
        'dg_failed_t'   => 'Downgrade failed',
        'dg_complete'   => 'Downgrade complete',
        /* 表单（与维护面板一致的三重验证） */
        'lbl_admin_pwd' => 'Administrator Password (10000)',
        'admin_db_down_note' => 'Database unreachable — the administrator password cannot be verified; this operation will rely on the maintenance credentials only.',
        'admin_missing_note' => 'No administrator (uid 10000) password record found — the administrator password cannot be verified; this operation will rely on the maintenance credentials only.',
        'lbl_m_user'    => 'Maintenance Username',
        'lbl_m_pass'    => 'Maintenance Passphrase',
        'lbl_git1'      => 'Current git hash',
        'lbl_git2'      => 'Re-enter git hash',
        'err_admin_required' => 'Administrator password is required',
        'err_admin_wrong'    => 'Administrator password incorrect',
        'err_maint_wrong'    => 'Maintenance credentials incorrect',
        'err_spawn'     => 'Could not start background worker',
        'err_busy'      => 'Another rescue operation is already running',
        /* 进度 */
        'step_done'     => 'Done',
        'step_failed'   => 'Failed',
        /* 登录页 */
        'login_h1'      => 'Rescue Panel',
        'login_sub'     => 'Emergency access when the main app is broken',
        'login_user'    => 'Maintenance Username',
        'login_pass'    => 'Maintenance Passphrase',
        'login_btn'     => 'Log In',
        'login_err'     => 'Invalid username or passphrase',
        'login_foot'    => 'Credentials come from maintenance/config.php (or data/maint_config.php).<br>This panel works even when the database is down.',
    ],
    'zh' => [
        'rescue_name'   => '救援面板',
        'lang_label'    => '语言',
        'logout'        => '退出登录',
        'badge_rescue'  => '应急模式',
        'net_err'       => '网络错误 / 无响应',
        'all_fields_required' => '所有字段都要填',
        'git_hash_mismatch'   => '两次 git 哈希不一致',
        'unknown_action'      => '未知操作',
        /* 侧栏 */
        'nav_dash'      => '仪表盘',
        'nav_upgrade'   => '升级',
        'nav_downgrade' => '降级',
        'nav_repair'    => '修复',
        /* 仪表盘 */
        'card_state'    => '系统状态',
        'k_app_git'     => '应用 Git 版本',
        'k_branch'      => '分支',
        'k_db'          => '数据库',
        'reachable'     => '可达',
        'down'          => '不可达',
        'k_maint_cfg'   => '维护凭据文件',
        'k_present'     => '存在',
        'k_missing'     => '缺失',
        'k_rescue_ver'  => '救援面板版本',
        'k_build'       => '构建时间',
        'k_disk'        => '剩余磁盘',
        'card_update'   => '更新救援面板',
        'update_note'   => '本面板被有意设计成「不会被任何网页升级更新」—— 升级翻车时它必须还能用。想更新它，SSH 执行：',
        'card_boot'     => '首次初始化',
        'boot_created'  => 'maintenance/config.php 不存在，已自动生成随机维护凭据并写入该文件。请 SSH 执行 cat maintenance/config.php 查看后再登录。',
        /* 升级 */
        'up_card'       => '升级 ChatApp',
        'up_note'       => '从 github.com/lqx211/ChatApp 拉取并覆盖代码；config/、data/ 与维护凭据保留；救援面板自身永远不动。',
        'k_current'     => '当前',
        'k_remote'      => '远程',
        'btn_check'     => '检查更新',
        'btn_checking'  => '检查中…',
        'btn_upgrade_now' => '立即升级',
        'chk_risk'      => '我已了解并接受风险',
        'up_to_date'    => '已是最新版本',
        'up_available'  => '发现新版本 → ',
        'up_started'    => '升级已开始（救援面板）',
        'up_done'       => '升级完成',
        'up_failed_t'   => '升级失败',
        'up_release'    => '升级失败 —— 其余未做任何改动',
        'up_complete'   => '升级完成 —— 服务已恢复',
        /* 降级 */
        'dg_card'       => '系统降级',
        'dg_note'       => '极度危险：把整个代码库回退到旧版本。数据库结构与代码可能不兼容，基本不可逆。救援面板自身会保留。',
        'lbl_target'    => '选择目标版本',
        'dg_search_ph'  => '搜索标题 / 哈希…',
        'dg_none'       => '没有匹配的版本',
        'dg_picked'     => '已选',
        'dg_pick_first' => '请先选择目标版本',
        'lbl_target_optional' => '目标版本（可选 —— 留空 = 拉最新 main）',
        'btn_latest'    => '最新版',
        'btn_next_ver'  => '升一级',
        'btn_prev_ver'  => '降一级',
        'up_no_next'    => '已经是最新的了（本地已取到的最前沿）',
        'up_none'       => '没有可升级的目标 —— 已经是最新版本',
        'up_target_old' => '该版本不比当前新 —— 回到旧版请用降级面板（想修复当前版本用修复面板）。',
        'dg_no_prev'    => '已经是最早的提交了',
        /* 修复 */
        'repair_card'   => '修复内部文件',
        'repair_note'   => '把全部跟踪文件恢复到当前版本（HEAD）的原样 —— 修复被改坏 / 误删 / 损坏的核心文件，版本不变。config、data、备份、维护凭据与救援面板自身都不会被动。',
        'btn_scan'      => '检查问题文件',
        'btn_repair_now' => '立即修复',
        'chk_repair_risk' => '我明白文件将按当前版本还原',
        'repair_none'   => '没有发现问题 —— 所有跟踪文件都与当前版本一致。',
        'repair_found'  => '发现 %s 个问题文件：',
        'repair_skipped' => '另有 %s 个未跟踪 / 索引残留条目已忽略 —— 不算问题，修复不会碰它们。',
        'repair_started' => '修复已开始',
        'repair_done'   => '修复完成',
        'repair_failed' => '修复失败',
        'st_m' => '已修改',
        'st_d' => '已删除',
        'st_r' => '已重命名',
        'st_c' => '已复制',
        'st_a' => '已新增',
        'st_u' => '冲突',
        'st_n' => '未跟踪',
        'dg_ph_loading' => '正在加载版本…',
        'dg_load_failed' => '版本列表加载失败',
        'btn_downgrade_now' => '立即降级',
        'chk_dg_risk'   => '我明白这极度危险',
        'dg_confirm_t'  => '降级前请先确认',
        'dg_started'    => '降级已开始（救援面板）',
        'dg_done_t'     => '降级完成',
        'dg_failed_t'   => '降级失败',
        'dg_complete'   => '降级完成',
        /* 表单 */
        'lbl_admin_pwd' => '管理员密码（UID 10000）',
        'admin_db_down_note' => '数据库不可达 —— 无法校验管理员密码，本次操作仅凭维护凭据确认。',
        'admin_missing_note' => '查不到管理员（UID 10000）密码记录 —— 无法校验管理员密码，本次操作仅凭维护凭据确认。',
        'lbl_m_user'    => '维护用户名',
        'lbl_m_pass'    => '维护口令',
        'lbl_git1'      => '当前 git 哈希',
        'lbl_git2'      => '再次输入 git 哈希',
        'err_admin_required' => '请填写管理员密码',
        'err_admin_wrong'    => '管理员密码不正确',
        'err_maint_wrong'    => '维护凭据不正确',
        'err_spawn'     => '后台任务启动失败',
        'err_busy'      => '已有救援任务正在执行',
        /* 进度 */
        'step_done'     => '完成',
        'step_failed'   => '失败',
        /* 登录页 */
        'login_h1'      => '救援面板',
        'login_sub'     => '主程序坏掉时的紧急入口',
        'login_user'    => '维护用户名',
        'login_pass'    => '维护口令',
        'login_btn'     => '登录',
        'login_err'     => '用户名或口令错误',
        'login_foot'    => '凭据来自 maintenance/config.php（或 data/maint_config.php）。<br>即使数据库整个挂掉，本面板也能用。',
    ],
];
