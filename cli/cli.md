# chatapp.py 使用说明（旧命令式 CLI）

> 传统 REPL 交互式命令行客户端，纯 Python 标准库实现（3.6+），**无第三方依赖**。
> 适合在无图形界面 / SSH / 脚本管道场景下使用；若想要全屏交互界面用 TUI（`tui.py`），
> 若想给 AI/自动化调用用 AI CLI（`ai_cli.py`），见 `../chatapp.md` 与 `../cli/README.md`。

---

## 1. 启动

```bash
# 方式一：直接用 Python 运行
python3 cli/chatapp.py

# 方式二：统一入口（--cli 模式）
./cli/chatapp.sh --cli

# 指定服务器地址
CHATAPP_SERVER=http://your-server:port python3 cli/chatapp.py

# 调整消息轮询间隔（默认 2 秒）
CHATAPP_POLL=1 python3 cli/chatapp.py
```

启动后进入交互式提示符 `chatapp>`，输入 `help` 查看全部命令，`quit` / `exit` 退出，`Ctrl+C` 也可退出。

---

## 2. 快速上手

```text
chatapp> register                 # 注册新账号（交互输入用户名/密码，密码安全输入）
chatapp> login alice             # 登录（提示输入密码，getpass 不回显）
chatapp> whoami                   # 确认已登录
chatapp> find bob                 # 搜索用户
chatapp> add bob                  # 发送好友请求
chatapp> send bob 你好            # 发私聊（需互为好友）
chatapp> msgs                     # 查看最近消息
chatapp> his bob                  # 查看与 bob 的聊天记录
chatapp> unread                   # 查看未读
chatapp> quit
```

登录成功后**自动启动后台消息轮询**，新消息实时弹出提醒并自动标记已读。

---

## 3. 认证

| 命令 | 说明 |
|------|------|
| `register` | 注册新账号（交互输入用户名/密码） |
| `login <用户名>` | 登录（密码 getpass 安全输入；PoW 挑战已内置自动处理） |
| `logout` | 登出 |
| `whoami` | 查看当前登录状态 |

## 4. 消息

| 命令 | 说明 |
|------|------|
| `send <用户名> <内容>` | 发送私聊消息（需互为好友） |
| `msgs [用户名]` | 查看最近 50 条消息（可指定私聊对象） |
| `his <用户名> [数量]` | 查看与某人的聊天历史（默认 50，最大 100） |
| `unread` | 查看未读消息 |
| `revoke <消息ID>` | 撤回消息（仅发送后 2 分钟内本人可撤） |
| `search <关键词>` | 搜索消息 |

消息显示格式：`[#ID 时间] 发送者: 内容`，支持图片/语音/文件/闪传/Markdown 附件的标识与链接，回复引用会展示原消息。

## 5. 联系人

| 命令 | 说明 |
|------|------|
| `contacts` | 列出联系人 |
| `find <用户名/UID>` | 搜索用户 |
| `discover [关键词]` | 发现用户（翻页浏览） |
| `add <用户名> [附言]` | 发送好友请求 |
| `pending` | 查看待处理的好友请求 |
| `accept <用户名>` | 接受好友请求 |
| `reject <用户名>` | 拒绝好友请求 |
| `nickname <用户名> <备注>` | 设置好友备注 |
| `unfriend <用户名>` | 删除好友 |

## 6. 群组

| 命令 | 说明 |
|------|------|
| `gcreate <名称>` | 创建群组 |
| `groups` | 我的群组列表 |
| `gsearch [关键词]` | 搜索群组 |
| `gjoin <群组ID>` | 加入群组（公开直加/私有申请） |
| `ginfo <群组ID>` | 查看群组信息 |
| `gmembers <群组ID>` | 查看群组成员 |
| `gsend <群组ID> <内容>` | 发送群消息 |
| `ghis <群组ID> [数量]` | 查看群消息历史 |
| `gpending <群组ID>` | 查看入群申请（管理员） |
| `gapprove <请求ID>` | 批准入群申请 |
| `greject <请求ID>` | 拒绝入群申请 |
| `gkick <群组ID> <用户ID>` | 踢出成员（管理员） |
| `gadmin <群组ID> <用户ID>` | 设为管理员（群主） |
| `gunadmin <群组ID> <用户ID>` | 取消管理员（群主） |
| `grename <群组ID> <名称>` | 重命名群组（群主） |
| `gvis <群组ID> <public>` | 设置群组可见性（群主） |
| `gmute <群组ID>` | 开关群组静音（静音群不推送轮询通知） |
| `gleave <群组ID>` | 退出群组 |
| `gdis <群组ID>` | 解散群组（群主） |

## 7. 设置

| 命令 | 说明 |
|------|------|
| `passwd` | 修改密码（需当前密码） |
| `setname <名称>` | 修改显示名称 |
| `lang <语言>` | 切换语言：`en` / `zh` / `zh_egg` / `wyw` / `raw` |
| `dnd` | 切换免打扰模式 |
| `saver` | 切换省流量模式 |

## 8. 服务器

| 命令 | 说明 |
|------|------|
| `server [URL]` | 查看/设置服务器地址（设置后自动保存到配置） |

---

## 9. 高级特性

### 消息轮询

登录后自动开启后台线程轮询（间隔默认 2s，`CHATAPP_POLL` 可调）：
- 私聊新消息实时弹出 `[用户名]: 内容`，并**自动标记已读**
- 群组消息实时弹出 `[群名] 用户名: 内容`，被静音的群不推送
- 首次登录会建立基线，历史消息不会刷屏

### 管道（类 shell）

```text
chatapp> his bob | grep 关键词      # 过滤输出
chatapp> msgs | head 5              # 只看前 5 行
chatapp> contacts | tail 3          # 只看后 3 行
chatapp> discover | wc              # 统计行数
chatapp> discover | sort            # 排序
chatapp> discover | cat             # 原样打印
chatapp> echo 测试                  # 输出文本到管道
```

### 逻辑组合

```text
chatapp> send bob hi && send bob 你好   # 前一个成功才执行后一个
chatapp> find nobody || echo 未找到      # 前一个失败才执行
```

### 命令别名（持久化）

```text
chatapp> alias f=find                # 设置别名
chatapp> alias                       # 查看所有别名
chatapp> f bob                       # 等价于 find bob
chatapp> unalias f                   # 删除别名
```

别名保存在 `~/.chatapp/config.json`，下次启动自动加载。

### Tab 补全

支持命令名 Tab 自动补全，以及 bracketed paste。

---

## 10. 配置文件与环境变量

| 项 | 路径/变量 | 说明 |
|----|-----------|------|
| 配置文件 | `~/.chatapp/config.json` | 服务器地址、命令别名 |
| 服务器 | `CHATAPP_SERVER`（默认 `http://127.0.0.1:8080`） | 或 `server` 命令设置 |
| 轮询间隔 | `CHATAPP_POLL`（默认 `2` 秒） | 后台消息轮询频率 |

---

## 11. 注意事项

- `send` 要求与对方**互为好友**，否则返回 `not_friends`
- `revoke` 仅能撤回**发送后 2 分钟内**的消息；闪传文件已撤回会标注
- 登录接口已内置 PoW 挑战求解（`api/pow.php`），无需手动处理
- 密码输入使用 `getpass`，不回显、不进终端历史
- 本 CLI 无第三方依赖，任意 Python 3.6+ 均可运行
