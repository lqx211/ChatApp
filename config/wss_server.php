<?php
/**
 * ChatApp · WebSocket 服务器地址
 * 可在聊天界面「Database Admin → WebSocket Settings」由 root(10000) 修改。
 * 填 host:port（如 localhost:9090）或完整 ws:// / wss:// URL。
 * 留空字符串 = 回退到按访问 Host 自动推断（localhost 直连 9090 / 公网走 Tunnel）。
 */
return 'localhost:9090';
