<?php
/**
 * ChatApp — WebSocket 服务配置（独立于 Apache PHP）
 *
 * 说明:
 *   - WS 常驻进程不依赖 Apache session, 不 require api/config.php
 *   - 数据库凭据与 api/config.php 保持一致
 *   - 修改端口后需同步 Cloudflare Tunnel 的 ingress 规则
 */

// 与 api/config.php 保持一致的时区（MySQL NOW() 与 PHP 时间对齐）
date_default_timezone_set('Asia/Hong_Kong');

define('WSS_DB_HOST', '127.0.0.1');
define('WSS_DB_NAME', 'chatapp');
define('WSS_DB_USER', 'root');
define('WSS_DB_PASS', '');
define('WSS_DB_CHARSET', 'utf8mb4');

// 监听端口（Tunnel: wss.lqx211.com -> localhost:9090）
define('WSS_PORT', 9090);

// 消息轮询间隔（毫秒）。越小延迟越低，DB 压力越大。
define('WSS_POLL_MS', 500);

// 心跳超时（秒）。客户端 60s 发一次 ping，90s 没收到则断开。
define('WSS_HEARTBEAT_TIMEOUT_S', 90);

// 日志文件（相对本文件目录）
define('WSS_LOG_FILE', __DIR__ . '/wss.log');