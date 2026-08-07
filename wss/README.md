# ChatApp WebSocket 实时推送服务

纯 PHP、零依赖的 WebSocket 服务器，为 ChatApp 提供消息实时推送、在线状态广播、打字指示器转发，替代原有的 1.5s HTTP 轮询。

## 架构

```
浏览器 ChatApp 页面
    │  wss://wss.lqx211.com/?token=xxx   ← Cloudflare Tunnel 透传
    ▼
wss/wss_server.php  (PHP 常驻进程, 监听 0.0.0.0:9090)
    │  每 500ms 查一次 DB 增量消息
    │  连接/断开时通知好友上下线
    │  收到心跳时更新 users.last_ping
    ▼
MySQL (chatapp)
```

## 文件说明

| 文件 | 用途 |
|------|------|
| `wss_server.php` | 主服务（WebSocket 协议 + stream_select 事件循环） |
| `wss_config.php` | 配置（端口、轮询间隔、心跳超时、DB 凭据） |
| `start.sh` | 启动/停止/重启/状态脚本（macOS/Linux） |
| `chatapp-wss.service` | Linux systemd 单元（生产部署） |
| `api/ws_token.php` | 前端获取 WS 连接 token 的 HTTP 接口 |

## 快速启动（macOS 开发本）

```bash
chmod +x wss/start.sh
./wss/start.sh -d        # 后台启动
./wss/start.sh status    # 查看状态
./wss/start.sh logs      # 跟踪日志
./wss/start.sh stop      # 停止
```

## 生产部署（Linux 服务器，配合 systemd）

```bash
# 1. 修改 chatapp-wss.service 中的路径为服务器实际路径
# 2. 安装
sudo cp wss/chatapp-wss.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now chatapp-wss

# 3. 检查
sudo systemctl status chatapp-wss
sudo journalctl -u chatapp-wss -f
```

## 数据库

WS 服务通过 `api/ws_token.php` 自动创建 `ws_tokens` 表（token 签发/校验）。
`users.last_ping` 由 WS 心跳更新（替代原 HTTP ping），在线判定逻辑不变。
如果你已有 `schema.sql` 中的表结构，无需额外迁移。

## Cloudflare Tunnel 要求

Tunnel 需将 `wss.lqx211.com` 指向 `http://localhost:9090`。
本仓库测试已确认 Cloudflare Tunnel 完整支持 WebSocket 透传（101 握手成功）。

## 协议约定（前端 wss_client.js 配套）

### 客户端 → 服务端（JSON text 帧）

```json
{"type":"ping","l":123,"glast":45,"groups":[1,2,3]}
{"type":"typing","to":"someuser"}
{"type":"fetch_group","group_id":5}
```

- `l`: 已收公告/私聊最大消息 id
- `glast`: 已收群消息最大 id
- `groups`: 当前用户已加入的群组 id 列表（心跳时同步）

### 服务端 → 客户端（JSON text 帧）

```json
{"type":"pong"}
{"type":"msg","messages":[...],"latest_id":123}
{"type":"group_msg","messages":[...],"glast":45}
{"type":"presence","online":{"user":1},"dnd":{},"offline":["user2"]}
{"type":"typing","from":"username"}
```

## 故障排查

| 现象 | 原因 | 解决 |
|------|------|------|
| 启动报"无法监听 9090" | 端口被占用（如测试回显服务） | `lsof -i :9090` 找 PID，kill 后再启动 |
| 握手后立刻断开 | token 无效/过期 | 重新登录页面（自动续 token）；`api/ws_token.php?action=issue` 验证 |
| 连接被 Cloudflare 掐断 | 空闲超时 | 客户端每 60s ping 一次（wss_client.js 已实现） |
| 收不到消息 | 游标未同步 | 心跳时带最新 `l`/`glast` |
| Web 前端无法连接 | 页面在 HTTP 下 | 确保页面走 `https://chat.lqx211.com` |

## 与 HTTP 轮询的关系（降级策略）

WS 连接成功后将轮询大幅降频（30-60s 兜底）；WS 断线时自动恢复原高频轮询，保证消息不丢。这是双保险设计。