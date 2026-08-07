<?php
/**
 * ChatApp — WebSocket 鉴权 Token 接口
 *
 * 用途:
 *   WebSocket 服务（wss/wss_server.php）无法读取 Apache PHP 的 session,
 *   因此通过本接口为已登录用户签发一次性 token, WS 连接时携带校验。
 *
 * 流程:
 *   1. 前端已登录(有 session) -> GET api/ws_token.php?action=issue
 *      返回 {success:true, token, expires_in}
 *   2. WS 连接: wss://wss.lqx211.com/?token=xxx
 *      wss_server.php 校验通过后接受连接
 *
 * Token 存储策略:
 *   - 有数据库的表 ws_tokens (自动创建)
 *   - 单用户同时只保留 1 个有效 token (旧 token 自动失效)
 *
 * 安全:
 *   - token 长度 64 hex, 一次性使用(校验后即删/换新)
 *   - 有效期默认 5 分钟, WS 长连接建立后不再依赖 token
 *   - HTTP-only session 仍然是主要认证, token 只是 WS 握手凭据
 */

require_once __DIR__ . '/config.php';

chatapp_session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$action = $_GET['action'] ?? 'issue';

// 确保表存在
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS ws_tokens (
    token CHAR(64) PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    INDEX idx_ws_tokens_user (user_id),
    INDEX idx_ws_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function ws_get_uid(PDO $pdo, string $username): int {
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ? AND deleted_at IS NULL');
    $stmt->execute([$username]);
    return (int)($stmt->fetchColumn() ?: 0);
}

switch ($action) {

    case 'issue':
        $username = $_SESSION['username'];
        $uid = ws_get_uid($pdo, $username);
        if ($uid <= 0) {
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }

        // 让该用户旧的 token 全部失效（单设备 token 策略，可随时调整）
        $pdo->prepare("DELETE FROM ws_tokens WHERE user_id = ?")->execute([$uid]);

        // 生成一次性 token
        $token = bin2hex(random_bytes(32)); // 64 hex
        $expiresInSeconds = 300; // 5 分钟
        $pdo->prepare("INSERT INTO ws_tokens (token, user_id, username, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL ? SECOND))")
            ->execute([$token, $uid, $username, $expiresInSeconds]);

        // 清理过期 token（顺手做）
        $pdo->exec("DELETE FROM ws_tokens WHERE expires_at < NOW()");

        echo json_encode([
            'success' => true,
            'token'   => $token,
            'expires_in' => $expiresInSeconds,
        ]);
        break;

    case 'validate':
        // 供调试/测试用：校验 token 是否有效
        $token = trim($_GET['token'] ?? '');
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            echo json_encode(['success' => false, 'error' => 'Invalid token format']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT user_id, username, expires_at FROM ws_tokens WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Token expired or not found']);
            exit;
        }
        echo json_encode([
            'success'  => true,
            'user_id'  => (int)$row['user_id'],
            'username' => $row['username'],
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}