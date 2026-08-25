<?php
/**
 * ChatApp - IE8 Chat Page (Server-rendered fallback, MySQL)
 */
require_once __DIR__ . '/../api/config.php';
chatapp_require_login();
$currentUser = chatapp_get_user();
$pdo = db();

// Handle message sending via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty(trim($_POST['message'] ?? ''))) {
    $message = trim($_POST['message'] ?? '');
    $recipient = trim($_POST['recipient'] ?? '');
    
    if (mb_strlen($message) <= 1000) {
        $msg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $time = date('H:i:s');
        
        // Non-admin can only send DMs
        if ($currentUser['username'] !== 'admin') {
            if (!empty($recipient) && $recipient !== $currentUser['username']) {
                $pdo->prepare('INSERT INTO messages (username, recipient, message, time, datetime) VALUES (?, ?, ?, ?, NOW())')
                    ->execute([$currentUser['username'], $recipient, $msg, $time]);
            }
        } else {
            if (!empty($recipient) && $recipient !== $currentUser['username']) {
                $pdo->prepare('INSERT INTO messages (username, recipient, message, time, datetime) VALUES (?, ?, ?, ?, NOW())')
                    ->execute([$currentUser['username'], $recipient, $msg, $time]);
            } else {
                $pdo->prepare('INSERT INTO messages (username, message, time, datetime) VALUES (?, ?, ?, NOW())')
                    ->execute([$currentUser['username'], $msg, $time]);
            }
        }
        $pdo->exec("DELETE FROM messages WHERE id <= (SELECT id FROM (SELECT id FROM messages ORDER BY id DESC LIMIT 1 OFFSET 500) AS t)");
    }
    header('Location: chat.php' . (!empty($recipient) ? '?dm=' . urlencode($recipient) : ''));
    exit;
}

// Handle revoke via GET
if (isset($_GET['revoke'])) {
    $msgId = (int)$_GET['revoke'];
    $stmt = $pdo->prepare("SELECT id, username, datetime FROM messages WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch();
    if ($msg && $msg['username'] === $currentUser['username'] && (time() - strtotime($msg['datetime'])) <= 120) {
        $pdo->prepare("UPDATE messages SET deleted_at = NOW() WHERE id = ?")->execute([$msgId]);
    }
    $redirect = 'Location: chat.php';
    if (!empty($_GET['dm'])) $redirect .= '?dm=' . urlencode($_GET['dm']);
    header($redirect);
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Determine view mode
$dmContact = trim($_GET['dm'] ?? '');
$viewMode = !empty($dmContact) ? 'dm' : 'announce';

// Load messages
if ($viewMode === 'dm') {
    $stmt = $pdo->prepare("SELECT m.id, m.username, COALESCE(u.display_name, m.username) AS display_name, 
        m.recipient, m.message, m.time, m.datetime, m.deleted_at 
        FROM messages m LEFT JOIN users u ON u.username = m.username
        WHERE m.recipient IS NOT NULL AND ((m.username = ? AND m.recipient = ?) OR (m.username = ? AND m.recipient = ?))
        ORDER BY m.id ASC LIMIT 50");
    $stmt->execute([$currentUser['username'], $dmContact, $dmContact, $currentUser['username']]);
} else {
    $stmt = $pdo->query("SELECT m.id, m.username, COALESCE(u.display_name, m.username) AS display_name,
        m.recipient, m.message, m.time, m.datetime, m.deleted_at
        FROM messages m LEFT JOIN users u ON u.username = m.username
        WHERE m.recipient IS NULL ORDER BY m.id DESC LIMIT 50");
}
$allMessages = array_reverse($stmt->fetchAll());

// Load contacts
$contactsStmt = $pdo->prepare("
    SELECT u.username, COALESCE(u.display_name, u.username) AS display_name, u.avatar
    FROM users u INNER JOIN contacts c ON (c.user_from = u.username OR c.user_to = u.username)
    WHERE (c.user_from = ? OR c.user_to = ?) AND c.status = 'accepted' AND u.username != ?
");
$contactsStmt->execute([$currentUser['username'], $currentUser['username'], $currentUser['username']]);
$contacts = $contactsStmt->fetchAll();

$autoRefresh = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST') $autoRefresh = false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=8">
    <?php if ($autoRefresh): ?>
    <meta http-equiv="refresh" content="3">
    <?php endif; ?>
    <title>ChatApp - Chat (IE8)</title>
    <style type="text/css">
        body { margin:0; padding:0; background-color:#1a1a1a; color:#e0e0e0; font-family:Arial,Helvetica,sans-serif; font-size:14px; }
        #chat-wrap { width:100%; max-width:700px; margin:0 auto; background-color:#222; border-left:1px solid #3a3a3a; border-right:1px solid #3a3a3a; min-height:100vh; }
        #chat-header { background-color:#2a2a2a; padding:12px 16px; border-bottom:1px solid #3a3a3a; overflow:hidden; line-height:30px; }
        #chat-header h2 { display:inline; font-size:14px; color:#c0c0c0; margin:0; padding:0; font-weight:bold; }
        #user-info { float:right; color:#888; font-size:12px; line-height:30px; }
        #user-info strong { color:#ccc; }
        .btn-settings, .btn-logout { margin-left:10px; background-color:#3a3a3a; border:1px solid #555; color:#ccc; padding:4px 12px; cursor:pointer; font-size:12px; font-family:Arial,sans-serif; text-decoration:none; }
        .btn-settings:hover, .btn-logout:hover { background-color:#4a4a4a; }
        #tab-bar { overflow:hidden; background-color:#262626; border-bottom:1px solid #3a3a3a; }
        .tab-btn { float:left; width:50%; height:30px; line-height:30px; text-align:center; cursor:pointer; color:#888; font-size:12px; text-decoration:none; border-bottom:2px solid transparent; box-sizing:border-box; }
        .tab-btn.active { color:#ccc; border-bottom-color:#888; }
        #messages-area { padding:12px 16px; padding-bottom:80px; }
        #contacts-area { padding:8px 12px; padding-bottom:80px; display:none; }
        .message-row { margin-bottom:4px; }
        .message-row.own { text-align:right; }
        .message-bubble { display:inline-block; background-color:#2e2e2e; border:1px solid #3a3a3a; padding:7px 12px; max-width:65%; text-align:left; word-wrap:break-word; }
        .message-row.own .message-bubble { background-color:#383838; border-color:#4a4a4a; }
        .msg-username { font-size:10px; color:#999; margin-bottom:2px; font-weight:bold; }
        .msg-text { font-size:12px; color:#ddd; line-height:1.4; }
        .msg-text.deleted { color:#666; font-style:italic; }
        .msg-time { font-size:9px; color:#666; margin-top:2px; text-align:right; }
        .revoke-link { font-size:9px; color:#888; }
        .revoke-link a { color:#888; }
        .revoke-link a:hover { color:#e06060; }
        .contact-item { padding:6px 8px; color:#888; font-size:12px; border-bottom:1px solid #2e2e2e; text-decoration:none; display:block; }
        .contact-item:hover { background-color:#2a2a2a; color:#ccc; }
        .empty-state { text-align:center; color:#555; margin-top:30px; font-size:13px; }
        #chat-input-area { position:fixed; bottom:0; left:50%; margin-left:-350px; width:700px; background-color:#2a2a2a; border-top:1px solid #3a3a3a; padding:10px 16px; overflow:hidden; }
        #chat-input-area input { width:78%; padding:8px 10px; background-color:#1e1e1e; border:1px solid #444; color:#e0e0e0; font-size:13px; font-family:Arial,sans-serif; box-sizing:border-box; float:left; }
        #chat-input-area input[type="submit"] { width:18%; padding:8px 0; background-color:#4a4a4a; border:1px solid #555; color:#e0e0e0; font-size:13px; font-weight:bold; cursor:pointer; font-family:Arial,sans-serif; float:right; box-sizing:border-box; }
        #chat-input-area input[type="submit"]:hover { background-color:#5a5a5a; }
        .clear { clear:both; }
        .readonly-msg { text-align:center; color:#666; font-size:12px; padding:14px 16px; }
    </style>
</head>
<body>
    <div id="chat-wrap">
        <div id="chat-header">
            <h2><?php echo $viewMode === 'dm' ? 'Chat with ' . htmlspecialchars($dmContact) : 'Global Announcements'; ?></h2>
            <span id="user-info">
                <?php echo htmlspecialchars($currentUser['display_name'] ?: $currentUser['username']); ?>
                <a href="settings.html" class="btn-settings">Settings</a>
                <a href="?logout=1" class="btn-logout">Logout</a>
            </span>
        </div>

        <div id="tab-bar">
            <a href="chat.php" class="tab-btn<?php echo $viewMode !== 'dm' ? ' active' : ''; ?>">Announcements</a>
            <a href="chat.php#contacts" class="tab-btn<?php echo $viewMode === 'dm' ? ' active' : ''; ?>" onclick="document.getElementById('messages-area').style.display='none';document.getElementById('contacts-area').style.display='block';return true;">Contacts</a>
        </div>

        <!-- Messages -->
        <div id="messages-area"<?php echo $viewMode === 'dm' ? '' : ' style="display:block;"'; ?>>
            <?php if (empty($allMessages)): ?>
            <div class="empty-state"><p><?php echo $viewMode === 'dm' ? 'Start chatting with ' . htmlspecialchars($dmContact) . '.' : 'No announcements yet.'; ?></p></div>
            <?php else: ?>
                <?php foreach ($allMessages as $msg): ?>
                    <?php
                        $isOwn = ($msg['username'] === $currentUser['username']);
                        $isDeleted = ($msg['deleted_at'] !== null);
                        $displayText = $isDeleted ? '[This message has been revoked]' : $msg['message'];
                        $deletedClass = $isDeleted ? ' deleted' : '';
                        $canRevoke = $isOwn && !$isDeleted && (time() - strtotime($msg['datetime'])) <= 120;
                    ?>
                    <div class="message-row<?php echo $isOwn ? ' own' : ''; ?>">
                        <div class="message-bubble">
                            <div class="msg-username"><?php echo htmlspecialchars($msg['display_name']); ?></div>
                            <div class="msg-text<?php echo $deletedClass; ?>"><?php echo $displayText; ?></div>
                            <div class="msg-time"><?php echo htmlspecialchars($msg['time']); ?></div>
                            <?php if ($canRevoke): ?>
                            <div class="revoke-link"><a href="?revoke=<?php echo $msg['id']; ?><?php echo $viewMode === 'dm' ? '&dm=' . urlencode($dmContact) : ''; ?>" onclick="return confirm('Revoke this message?');">Revoke</a></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Contacts -->
        <div id="contacts-area">
            <div class="contact-item" style="color:#ccc;"><?php echo htmlspecialchars($currentUser['display_name'] ?: $currentUser['username']); ?> (me)</div>
            <?php foreach ($contacts as $c): ?>
            <a href="chat.php?dm=<?php echo urlencode($c['username']); ?>" class="contact-item"><?php echo htmlspecialchars($c['display_name']); ?></a>
            <?php endforeach; ?>
            <?php if (empty($contacts)): ?>
            <div class="contact-item">No contacts yet. Use + Add Contact.</div>
            <?php endif; ?>
        </div>

        <div style="height:60px;"></div>

        <div id="chat-input-area">
            <?php if ($viewMode === 'dm'): ?>
            <form method="post" action="chat.php" style="margin:0; padding:0;">
                <input type="hidden" name="recipient" value="<?php echo htmlspecialchars($dmContact); ?>">
                <input type="text" name="message" placeholder="Type a message..." maxlength="1000" autocomplete="off">
                <input type="submit" value="Send">
                <div class="clear"></div>
            </form>
            <?php elseif ($currentUser['username'] === 'admin'): ?>
            <form method="post" action="chat.php" style="margin:0; padding:0;">
                <input type="text" name="message" placeholder="Type announcement..." maxlength="1000" autocomplete="off">
                <input type="submit" value="Send">
                <div class="clear"></div>
            </form>
            <?php else: ?>
            <div class="readonly-msg">Announcements are read-only. Only admin can post.</div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>