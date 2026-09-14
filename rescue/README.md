# ChatApp · Rescue Panel（救援面板）

紧急救援入口：当**升级/降级把整站砸坏**（比如不小心降级回远古版本、`maintenance/` 被覆盖、数据库整个挂掉）时，用它把系统救回来。

访问：`https://<你的域名>/rescue/`

## 为什么它不会坏

- **零依赖**：不 require `api/config.php`、不 require `maintenance/` 或 `data/` 里任何代码；样式/字体/背景全部在 `rescue/` 内部（`css/`、`fonts/`、`bg.jpg`）——**只把 rescue 文件夹拷出来，整个面板依然完整可用**。
- **永不随网页升级/降级更新**：所有网页升级/降级的 checkout 都显式排除 `rescue/`（`':!rescue'`）。这就是「在网页上是定死的」——它是最后防线，故意不自动更新。
- **数据库炸了也能登录**：登录校验走文件凭据（`maintenance/config.php` → `data/maint_config.php`），与 MySQL 无关。
- **凭据文件全没了也能进**：两个凭据文件都不存在时，自动创建 `maintenance/` 目录并生成随机维护凭据写入 `maintenance/config.php`（绝不覆盖已有文件）。
  生成后请 SSH 查看：`cat maintenance/config.php`，用里面的用户名/密码登录。
- **维护模式下永远可达**：全局闸门（`maintenance.php`）对 `/rescue/` 路径直接放行。

## 功能

| 面板 | 说明 |
|---|---|
| 仪表盘 | 应用 git hash / 分支 / 数据库可达性 / 凭据文件位置 / rescue 版本与构建时间 / 磁盘 |
| 升级 | 拉取 `origin/main` 覆盖代码；也可**指定目标版本**（可搜索下拉，含「最新版 / 升一级」快捷按钮）。config/data/bkup/维护凭据保留，rescue 自身不动 |
| 降级 | 选任意历史 commit 回退（可搜索下拉 + 「降一级」快捷按钮）；同样的排除项 |
| 修复 Repair | 把全部跟踪文件**恢复到当前版本（HEAD）** 的原样——修复被改坏/误删/损坏的核心文件，版本不变；先「检查」会逐条列出问题文件（已修改/已删除/未跟踪…） |

升级/降级/修复完成后都会尽力重启 WSS。

危险操作（升级/降级/修复）沿用维护面板的三重验证：

1. **管理员密码**（uid 10000；数据库可达时校验，不可达时提示并跳过——救援场景必须放行）
2. **维护用户名 + 口令**（文件凭据，`maintenance/config.php` / `data/maint_config.php` 任一匹配即可）
3. **当前 git hash**（两次输入；画面左下角常驻显示 rescue 版本、构建时间与应用 git HEAD 方便复制）

支持 English / 简体中文（左下角切换，cookie `rescue_lang`，默认中文）。

## 如何更新救援面板（只能用命令行）

网页不会更新它。想更新，SSH 执行：

```bash
cd /var/www/html            # 换成你的项目目录
git fetch origin main
git checkout --force origin/main -- rescue
```

（顺手重启一下守卫也无妨——rescue 是纯 PHP 页面，没有常驻进程。）

## 文件

- `index.php` — 登录页 + 仪表盘/升级/降级/修复四面板（自包含 CSS/JS）
- `lang.php` — English / 简体中文语言包
- `lib.php` — 凭据加载、自举（生成维护凭据）、DB 探测、git、WSS 重启等工具函数
- `worker.php` — 后台升级/降级/修复执行器（nohup 运行，进度写 `data/rescue_progress.json`）
- `css/`、`fonts/`、`bg.jpg` — 全部样式与字体（独立副本，不依赖项目其它目录）

## 安全说明

- 登录失败有轻微节流（0.4s），建议不要把 `/rescue/` 暴露给搜索引擎（可加 robots/CF 规则）。
- 升级/降级期间会短暂中断访客访问（不自动切维护模式——数据库挂了也切不了，交给救援者判断）。
