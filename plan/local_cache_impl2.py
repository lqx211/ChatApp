#!/usr/bin/env python3
"""Fix auth.php register + continue implementation."""

# ---- Fix auth.php register execute ----
p = '/Volumes/Server/ChatApp/api/auth.php'
s = open(p, encoding='utf-8').read()
old = "VALUES (?, ?, ?, ?, NOW())')->execute([$username, $hash, $lang]);"
new = "VALUES (?, ?, ?, ?, NOW())')->execute([$username, $hash, $lang, bin2hex(random_bytes(32))]);"
assert old in s, "register execute not found"
s = s.replace(old, new, 1)
# Also add cache_key to register response
old2 = "        echo json_encode(['success' => true]);\n        break;\n\n    case 'login':"
new2 = "        $newUser = db()->prepare('SELECT cache_key FROM users WHERE username = ?');\n        $newUser->execute([$username]);\n        echo json_encode(['success' => true, 'cache_key' => $newUser->fetchColumn() ?: bin2hex(random_bytes(32)), 'local_cache_enabled' => 0]);\n        break;\n\n    case 'login':"
assert old2 in s, "register response not found"
s = s.replace(old2, new2, 1)
open(p, 'w', encoding='utf-8').write(s)
print('OK auth.php')

# ---- settings.php: add toggle_local_cache ----
p = '/Volumes/Server/ChatApp/api/settings.php'
s = open(p, encoding='utf-8').read()
old_anchor = "    // ================= Wallpaper (custom background) ================="
new_action = """    case 'toggle_local_cache':
        $pdo = db();
        $enabled = (int)(($_POST['enabled'] ?? 0) ? 1 : 0);
        $stmt = $pdo->prepare('SELECT user_id, cache_key FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username']]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['success' => false]); exit; }
        if (empty($row['cache_key'])) {
            $key = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE users SET cache_key = ? WHERE username = ?')->execute([$key, $_SESSION['username']]);
            $row['cache_key'] = $key;
        }
        $pdo->prepare('UPDATE users SET local_cache_enabled = ? WHERE username = ?')->execute([$enabled, $_SESSION['username']]);
        echo json_encode(['success' => true, 'cache_key' => $row['cache_key'], 'local_cache_enabled' => $enabled]);
        break;

    // ================= Wallpaper (custom background) ================="""
assert old_anchor in s, "settings anchor not found"
s = s.replace(old_anchor, new_action, 1)
open(p, 'w', encoding='utf-8').write(s)
print('OK settings.php')

# ---- schema.sql ----
p = '/Volumes/Server/ChatApp/schema.sql'
s = open(p, encoding='utf-8').read()
old_cols = "    timezone VARCHAR(8) NOT NULL DEFAULT '+08:00',\n    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
new_cols = "    timezone VARCHAR(8) NOT NULL DEFAULT '+08:00',\n    cache_key VARCHAR(88) DEFAULT NULL,\n    local_cache_enabled TINYINT(1) NOT NULL DEFAULT 0,\n    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
assert old_cols in s, "schema cols not found"
s = s.replace(old_cols, new_cols, 1)
open(p, 'w', encoding='utf-8').write(s)
print('OK schema.sql')

# ---- Language keys ----
langs = {
    '/Volumes/Server/ChatApp/lang/zh.php': ("'menu_report' => '举报',", "'title_local_cache' => '本地缓存聊天记录',\n    'msg_local_cache_label' => '开启后聊天记录将加密保存在本机浏览器',\n    'btn_clear_local_cache' => '清除本地缓存',\n    'msg_local_cache_cleared' => '本地缓存已清除',\n    'menu_report' => '举报',"),
    '/Volumes/Server/ChatApp/lang/en.php': ("'menu_report' => 'Report',", "'title_local_cache' => 'Local chat cache',\n    'msg_local_cache_label' => 'Encrypt and store chat history on this device',\n    'btn_clear_local_cache' => 'Clear local cache',\n    'msg_local_cache_cleared' => 'Local cache cleared',\n    'menu_report' => 'Report',"),
    '/Volumes/Server/ChatApp/lang/zh_egg.php': ("'menu_report' => '举报',", "'title_local_cache' => '本地快取聊天记录',\n    'msg_local_cache_label' => '开启後聊天记录将加密保存在本机浏览器',\n    'btn_clear_local_cache' => '清除本地快取',\n    'msg_local_cache_cleared' => '本地快取已清除',\n    'menu_report' => '举报',"),
    '/Volumes/Server/ChatApp/lang/wyw.php': ("'menu_report' => '禀告',", "'title_local_cache' => '本地存档聊天录',\n    'msg_local_cache_label' => '啟用後聊録將加密存於本機瀏覽器',\n    'btn_clear_local_cache' => '清除本地存',\n    'msg_local_cache_cleared' => '本地存已清',\n    'menu_report' => '禀告',"),
}
for path, (anchor, repl) in langs.items():
    s = open(path, encoding='utf-8').read()
    assert anchor in s, f"lang anchor not found in {path}"
    s = s.replace(anchor, repl, 1)
    open(path, 'w', encoding='utf-8').write(s)
    print(f'OK {path}')

print('ALL DONE')