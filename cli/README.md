# ChatApp CLI

ChatApp 终端客户端，包含**三种模式**：

| 模式 | 文件 | 说明 |
|------|------|------|
| **TUI**（默认） | `tui.py` | 交互式全屏界面（Textual），登录/私聊/群组/联系人/设置四面板 |
| **AI CLI**（无头） | `ai_cli.py` | 一条命令做一件事、输出 JSON，专供 AI / 脚本 / 自动化调用 |
| **命令式 CLI**（旧） | `chatapp.py` | 传统 REPL 命令行交互 |

## 快速开始

```bash
# TUI（需全局 venv，内含 textual 8.x）
/Users/jadenlau/.venv/bin/python cli/tui.py

# 或统一入口
./cli/chatapp.sh                 # TUI
./cli/chatapp.sh --cli           # 旧命令式 CLI
./cli/chatapp.sh --ai ...        # AI CLI（见下）

# 指定服务器地址
CHATAPP_SERVER=http://your-server:port ./cli/chatapp.sh
```

---

## AI CLI（供 AI / 自动化调用）

**设计原则**：每条命令做一件事、独立进程、结果输出 JSON（utf-8）。登录态
持久化在 `~/.chatapp/ai.cookies`，先 `login` 一次，之后所有命令复用会话，
无需每次带密码。

```bash
# 登录（PoW 挑战已自动处理）
./cli/chatapp.sh --ai login --user _mobtest2 --pass password

# 之后直接执行任意命令
./cli/chatapp.sh --ai settings get
./cli/chatapp.sh --ai dm send alice "hello"
./cli/chatapp.sh --ai me --pretty
```

退出码：`0` 成功；`1` 后端返回 `success:false`（看 `error` 字段）；`2` 参数/网络/未登录错误。

### 认证
| 命令 | 说明 |
|------|------|
| `login --user U --pass P` | 登录并保存会话 |
| `register --user U --pass P [--lang L]` | 注册 |
| `logout` | 退出 |
| `me` | 当前用户 + 全部设置 |

### Settings（覆盖 settings.php 全部项目）
| 命令 | 说明 |
|------|------|
| `settings get` | 读取全部设置 |
| `settings toggle <dnd\|data_saver\|auto_focus\|searchable\|searchable_by_uid\|notif_system\|notif_banner\|typing_visible\|stranger_invite_group\|stranger_like\|anyone_add_friend>` | 翻转开关 |
| `settings privacy <s> <uid>` | 可被搜索 / 可按 UID 搜索 |
| `settings local-cache <0\|1>` | 本地缓存 |
| `settings emoji <panel> <chat>` | emoji 面板/聊天模式 |
| `settings timezone <±HH:MM>` | 时区 |
| `settings language <zh\|en\|zh_egg\|wyw\|raw>` | 语言 |
| `settings name <名>` / `settings title <头衔>` | 昵称 / 自定义头衔 |
| `settings gender <0女\|1男\|空>` / `settings gender-privacy <0\|1\|2>` | 性别及其隐私 |
| `settings birthday <YYYY-MM-DD\|空>` | 生日 |
| `settings space-ears <0\|1>` | 空间耳朵开关 |
| `settings password <当前> <新>` | 修改密码 |
| `settings duress <当前> [胁迫密码]` | 设置/清除胁迫密码 |
| `settings avatar <file>` | 上传头像 |
| `block list` / `block add <uid>` / `block remove <uid>` | 黑名单 |
| `bg get\|upload\|preset\|remove\|privacy\|blacklist\|whitelist\|no-friend\|private\|private-set` | 聊天壁纸全套 |
| `profile-bg get\|upload\|remove\|frame <x> <y> <zoom> <flip>` | 空间封面全套 |
| `sig get\|privacy\|blacklist\|whitelist\|no-friend\|hidden-text` | 签名隐私全套 |
| `discover [q] [--page N]` | 发现用户 |
| `account delete <password> [--mode delete\|revoke\|delete_all] [--yes]` | 删号（危险，需 --yes） |

### 聊天（覆盖 chat.php / group.php / contacts.php 全部 action）
| 命令 | 说明 |
|------|------|
| `dm send <user> <msg> [--file F] [--reply-to ID] [--md]` | 发私聊（可附件/回复/Markdown） |
| `dm history <user> [--limit N] [--before ID]` | 私聊历史 |
| `dm conversations` / `dm unread` / `dm fetch [--after ID] [--dm U]` | 会话 / 未读 / 增量拉取 |
| `dm mark-read <user>` / `dm revoke <id>` / `dm revoke-own <id>` | 已读 / 撤回 |
| `dm search <q> [--dm U] [--group-id N]` | 搜索消息 |
| `content [--type all\|photo\|video\|file\|audio] [--limit N]` | 我的内容 |
| `group list\|create\|info\|members\|send\|history\|search\|join\|request` | 群组基本操作 |
| `group pending\|approve\|reject\|invite\|kick\|admin\|unadmin` | 群组管理 |
| `group rename\|announce\|visibility\|transfer\|pin\|avatar` | 群组设置 |
| `group mute\|unmute\|mute-member\|unmute-member\|mute-all\|unmute-all` | 群组禁言 |
| `group leave\|dissolve` | 退群 / 解散 |
| `contact list\|search\|add\|force-add\|pending` | 联系人 |
| `contact accept\|reject\|remove\|nickname\|pin\|pin-self` | 联系人管理 |

### 朋友圈（space.php）
| 命令 | 说明 |
|------|------|
| `moments post <内容> [--visibility 0-4] [--to uids]` | 发说说 |
| `moments list [--user U\|--uid N]` / `moments delete <id>` / `moments like <id>` | 说说操作 |
| `moments comment <feed_id> <内容> [--parent N]` / `comments` / `comment-del` | 评论 |
| `moments message <to_uid> <内容>` / `messages [--to-uid N]` / `message-del` | 留言板 |
| `moments blog <标题> <内容> [--visibility] [--to]` / `blogs` / `blog-get` / `blog-del` | 日志 |

> 全局参数：`--server URL`（默认 $CHATAPP_SERVER 或 127.0.0.1:8080）、`--cookie PATH`、
> `--user/--pass`（未登录时自动登录）、`--pretty`（缩进 JSON）。

---

## 旧命令式 CLI（chatapp.py）

### 认证
| 命令 | 说明 |
|------|------|
| `login <用户名>` | 登录 (密码安全输入) |
| `register` | 注册新账号 |
| `logout` | 登出 |
| `whoami` | 查看当前登录状态 |

### 消息
| 命令 | 说明 |
|------|------|
| `send <用户名> <内容>` | 发送私聊消息 |
| `msgs [用户名]` | 查看最近消息 |
| `his <用户名> [数量]` | 查看聊天历史 |
| `unread` | 查看未读消息 |
| `revoke <消息ID>` | 撤回2分钟内的消息 |
| `search <关键词>` | 搜索消息 |

登录后自动开启**消息轮询**，新消息实时提醒。

### 联系人
| 命令 | 说明 |
|------|------|
| `contacts` | 列出联系人 |
| `find <用户名/UID>` | 搜索用户 |
| `discover [关键词]` | 发现用户 |
| `add <用户名> [附言]` | 发送好友请求 |
| `pending` | 查看待处理请求 |
| `accept <用户名>` | 接受好友请求 |
| `reject <用户名>` | 拒绝好友请求 |
| `nickname <用户名> <备注>` | 设置好友备注 |
| `unfriend <用户名>` | 删除好友 |

### 群组
| 命令 | 说明 |
|------|------|
| `gcreate <名称>` | 创建群组 |
| `groups` | 我的群组列表 |
| `gsearch [关键词]` | 搜索群组 |
| `gjoin <群组ID>` | 加入群组 |
| `ginfo <群组ID>` | 群组信息 |
| `gmembers <群组ID>` | 群组成员 |
| `gsend <群组ID> <内容>` | 发送群消息 |
| `ghis <群组ID> [数量]` | 群消息历史 |
| `gpending <群组ID>` | 入群申请 (管理员) |
| `gapprove <请求ID>` | 批准入群申请 |
| `greject <请求ID>` | 拒绝入群申请 |
| `gkick <群组ID> <用户ID>` | 踢出成员 (管理员) |
| `gadmin <群组ID> <用户ID>` | 设为管理员 (群主) |
| `gunadmin <群组ID> <用户ID>` | 取消管理员 (群主) |
| `grename <群组ID> <名称>` | 重命名群组 (群主) |
| `gvis <群组ID> <0/1>` | 设置可见性 (群主) |
| `gmute <群组ID>` | 开关静音 |
| `gleave <群组ID>` | 退出群组 |
| `gdis <群组ID>` | 解散群组 (群主) |

### 设置
| 命令 | 说明 |
|------|------|
| `passwd` | 修改密码 |
| `setname <名称>` | 修改显示名称 |
| `lang <语言>` | 切换语言 (en/zh/zh_egg/wyw/raw) |
| `dnd` | 切换免打扰模式 |
| `saver` | 切换省流量模式 |

### 别名 & 管道
| 命令 | 说明 |
|------|------|
| `alias [名称=命令]` | 查看/设置命令别名 (持久化到 `~/.chatapp/config.json`) |
| `unalias <名称>` | 删除命令别名 |
| `cmd1 \| grep <关键词>` | 管道过滤输出 |
| `cmd1 \| head [N]` | 管道前N行 (默认10) |
| `cmd1 \| tail [N]` | 管道后N行 (默认10) |
| `cmd1 \| wc` | 统计管道行数 |
| `cmd1 \| sort` | 排序管道输出 |
| `cmd1 \| cat` | 打印管道输出 |
| `echo <文本>` | 输出文本 |
| `cmd1 && cmd2` | 前一个成功才执行 cmd2 |
| `cmd1 \|\| cmd2` | 前一个失败才执行 cmd2 |

### 其他
| 命令 | 说明 |
|------|------|
| `server [URL]` | 查看/设置服务器地址 |
| `clear` | 清屏 |
| `help` | 显示帮助 |
| `quit` / `exit` | 退出 |

## 特性

- 🎨 **彩色输出** - 消息、通知、状态一目了然
- ⚡ **实时消息推送** - 后台线程轮询，新消息即时提醒
- 🔐 **安全输入** - 密码使用 `getpass` 输入
- 📁 **配置持久化** - 服务器地址、命令别名保存在 `~/.chatapp/config.json`
- 🔤 **Tab 补全** - 命令自动补全
- 📱 **附件支持** - 照片/文件/语音/闪传消息显示
- 🔗 **管道支持** - `grep` / `head` / `tail` / `wc` / `sort` / `cat` / `echo`
- 🧱 **命令组合** - `&&` / `||` 逻辑连接
- 🏷️ **命令别名** - 支持 `alias 名称=命令` 和 `alias 名称 命令` 两种语法

## 环境变量

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `CHATAPP_SERVER` | `http://127.0.0.1:8080` | 服务器地址 |
| `CHATAPP_POLL` | `2` | 消息轮询间隔 (秒) |