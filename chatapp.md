# ChatApp 项目说明档案

> 生成时间：2026-08-31 ｜ 覆盖：架构 / 后端 API / 终端客户端（TUI·AI·旧CLI）/ WebSocket / 维护部署 / 数据

ChatApp —— 一个自托管的开源网络聊天应用（Network Chatting Application）。
服务端 PHP 8.1/8.3 + MySQL，提供网页端（现代版 + IE8 兼容版）、WebSocket 实时服务、
以及三套终端客户端（交互式 TUI、无头 AI CLI、旧命令式 CLI）。

---

## 1. 技术栈

| 层 | 技术 |
|----|------|
| 服务端 | PHP 8.1+（本机 brew Apache mod_php，`127.0.0.1:8080`） |
| 数据库 | MySQL 8.0（本机 `root`/无密码/库 `chatapp`，TCP） |
| 实时通道 | WebSocket 自研服务（`wss/wss_server.php`，9090 端口） |
| 网页前端 | `modern/` 原生 PHP + 自研 JS/CSS（含 SVG 图标、深灰主题、iOS 风格开关） |
| 终端客户端 | Python 3.6+ 纯标准库（TUI 额外依赖 Textual 8.x，装在全局 venv `/Users/jadenlau/.venv`） |

---

## 2. 目录结构

```
ChatApp/
├── index.php / status.php / maintenance.php   入口 / 维护状态 / 全局维护闸门
├── setup.sh / gh_container_setup.sh           一键初始化脚本（目录/权限/DB）
├── getdb.sh                                   MySQL 快捷查询（messages / users）
├── schema.sql                                 数据库表结构
├── api/                                       后端 API（REST/表单，JSON）
├── modern/                                    现代版网页端（登录/聊天/空间/设置）
├── wss/                                       WebSocket 服务器（PHP 常驻进程）
├── cli/                                       Python 终端客户端（三模式）
├── data/                                      用户数据（头像/背景/说说图/壁纸等）
├── maintenance/                               维护门户（管理员工具）
├── ticket/                                    工单系统
└── chatapp.md                                 本文档
```

### 主要 API 端点（`api/`）

| 文件 | 职责 |
|------|------|
| `auth.php` | 登录/注册/登出/check，PoW 挑战（`pow.php`）防机器人，账号级失败锁定 |
| `chat.php` | 私聊消息：send/all/fetch/conversations/unread_counts/mark_read/revoke/search_messages/my_content |
| `group.php` | 群组全生命周期：create/join/request/approve/members/kick/admin/rename/announce/transfer/mute 系列/leave/dissolve |
| `contacts.php` | 联系人：search/send_request/respond/force_add/pending/change_nickname/toggle_pin/delete |
| `settings.php` | 设置全量：资料/开关/隐私/黑名单/壁纸/空间封面/签名隐私/删号/胁迫密码 |
| `space.php` | 个人空间朋友圈：说说 post/list/delete/like、评论、留言板、日志 |
| `status.php` | 在线/输入中/勿扰状态，ping |
| `e2ee.php` | 端到端加密密钥（register/prekeys/bundle/status） |
| `emoji.php` | 自定义 emoji 上传/列表/删除 |
| `file.php` / `avatar.php` / `temp.php` | 文件/头像服务、临时闪传 |
| `level.php` | 等级/经验：info/rank/leaderboard/sign/upgrade/history |
| `like.php` | 点赞 |
| `report.php` / `incident.php` | 举报、故障事件 |
| `donation.php` | 赞助列表 |
| `ws_token.php` | WebSocket 令牌签发/校验 |
| `admin.php` | 后台管理：用户/角色/日志/DB 查询/登录为/WSS 状态/数据库导出 |
| `upgrade.php` / `downgrade.php` / `factory_reset.php` / `uninstall.php` | 升级/降级/工厂重置/卸载（维护门户） |

> 约定：只读 action 允许 GET，状态变更一律 POST（防 CSRF）；登录会话经 `config.php` 的 `chatapp_get_user()` 读取全量设置字段。

---

## 3. 终端客户端（`cli/`）— 三模式

统一入口：`./cli/chatapp.sh`

```bash
./cli/chatapp.sh            # ① TUI 交互式界面（默认，需 Textual）
./cli/chatapp.sh --cli      # ② 旧命令式 REPL（纯标准库）
./cli/chatapp.sh --ai ...   # ③ 无头 AI CLI（JSON 输出，供 AI/脚本）
```

### ① TUI（`cli/tui.py`）

基于 Textual 8 的全屏界面：登录/注册弹窗 + 私聊/群组/联系人/设置四面板切换，
消息 2.5s 轮询增量刷新，未读角标，导航 `q` 退出 / `r`、`F5` 刷新。

运行：`/Users/jadenlau/.venv/bin/python cli/tui.py`

### ② 旧命令式 CLI（`cli/chatapp.py`）

传统 REPL：`login/send/his/unread/revoke/search/contacts/groups/...`，
后台线程轮询推送新消息，支持管道与命令组合。

### ③ AI CLI（`cli/ai_cli.py`）— 供 AI / 自动化

一条命令做一件事，结果输出 JSON（utf-8）。登录态持久化在 `~/.chatapp/ai.cookies`，
先 `login` 一次，之后所有命令复用会话、免密。

```bash
./cli/chatapp.sh --ai login --user mobtest2 --pass password
./cli/chatapp.sh --ai settings get          # 读取全部设置
./cli/chatapp.sh --ai dm send alice "hello" # 发私聊
./cli/chatapp.sh --ai me --pretty           # 当前用户+全部设置（缩进）
```

覆盖范围（全部对应后端 action）：

- **Settings 全部项目**：资料（昵称/头衔/性别/生日/时区/语言/密码/胁迫密码/空间耳朵/头像）、
  全部开关（dnd/data_saver/auto_focus/searchable/notif×2/typing/stranger×2/anyone_add_friend）、
  隐私、本地缓存、emoji、黑名单、聊天壁纸全套、空间封面全套、签名隐私全套、删号（需 `--yes`）
- **聊天全部项目**：私聊（发/历史/会话/未读/已读/撤回/搜索/附件/回复/Markdown）、我的内容、
  群组全套、联系人全套（加好友/强制/备注/置顶/待处理）
- **朋友圈**：说说/评论/留言板/日志

退出码：`0` 成功；`1` 后端 `success:false`（读 `error` 字段）；`2` 参数/网络/未登录。

完整命令表见 `cli/README.md`。

---

## 4. 网页端（`modern/`）

- `login.php` 登录（含维护态倒计时、ChatApp 运行时长展示）
- `chat.php` 聊天主界面（私聊/群聊、附件、Markdown、回复、emoji、自定义壁纸、深灰主题、SVG 图标）
- `space.php` 个人空间（Qzone 风格：封面编辑、随机壁纸、朋友圈、留言板、日志、访客块、UID）
- `editinfo.php` 编辑资料（昵称/生日/性别/签名/头像，含 iOS 风格开关）
- `users.php` 用户/发现、`settings.php` 设置面板、`profile.php` 个人页
- 前端脚本 `modern/scripts/`：`chat.js`、`ears.js`（全局兔子耳朵挂件，默认关、仅圆形头像）
- 老版本兼容：`index.php` 提供 Modern（PHP 8.3）与 IE8 双入口

---

## 5. WebSocket（`wss/`）

- `wss_server.php` — PHP 常驻 WebSocket 服务器（9090），私聊/群聊实时推送、在线/输入状态
- `chatapp-wss.service` — systemd 服务单元；`start.sh` 启动脚本（含 pid/log 管理）
- `ws_token.php` — 令牌签发/校验，浏览器经 token 鉴权后长连

---

## 6. 维护 / 部署

- **维护闸门**：`maintenance.php` 读 `status.php`；开启维护时仅持 1 小时 admin token（`MT_TOKEN`）可绕过
- **维护门户**：`maintenance/`，含升级/降级/工厂重置/卸载、DB 备份导出（`ca-bkup-*.sql`）
- **一键初始化**：`setup.sh` / `gh_container_setup.sh` 创建目录结构、设置权限、初始化 DB
- **降级/回滚**：`api/downgrade.php`；**工厂重置**：`api/factory_reset.php`（模拟/真实模式）
- 根目录 `README.md` 提示：部署后务必修改 `maintenance/config.php` 的维护密码

---

## 7. 数据存储（`data/`）

| 目录 | 内容 |
|------|------|
| `data/pp/` | 用户头像（`<uid>.png` 等） |
| `data/bgi/` | 空间封面/私密背景（png/mp4/webm） |
| `data/user/<uid>/` | 用户文件、聊天壁纸（`bg.*`） |
| `data/res/wallpaper/` | 内置壁纸预设 |
| `data/res/space-widget/` | 空间挂件（兔耳朵 APNG 等） |
| `data/ce/` `cep/` `sc/` `donation/` | 自定义 emoji、表情、说说图、赞助图 |

---

## 8. 本地开发环境

```bash
# 网页：PHP 8.1 brew Apache，http://127.0.0.1:8080
# DB：  MySQL 8.0，库名 chatapp（root 无密码）
# WSS： wss/start.sh（9090）
# CLI： /Users/jadenlau/.venv/bin/python cli/...   （Textual 在全局 venv）

# 快捷查库
./getdb.sh messages
./getdb.sh users
```

测试账号（本地）：`mobtest2`(10172) / `mobtest`(10171) / `zjq`(10001) / `admin`(10000)，
密码多为 `password`；`assistant`(admin) 见 `ASSISTANT_ChatApp_README.md`。

---

## 9. 常见维护操作

| 操作 | 命令/路径 |
|------|-----------|
| 开启/关闭维护 | 维护门户 或 改 `status.php` |
| 备份数据库 | `ca-bkup-*.sql`（getdb/门户导出） |
| 查看工单 | `ticket/`（zsh 工具 + 网页 Support & Bug Report） |
| 升级 | 维护门户 → 拉取/执行 `upgrade.php` |
| 重置 | `factory_reset.php`（需管理员+维护凭据+git hash 三因素验证） |
