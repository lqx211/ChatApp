# site2php — 网页 → 本地 PHP 模板骨架

输入一个 URL，自动把页面**本地化**（HTML/CSS/JS/图片/字体下载落盘并改写引用），
**分析**结构/配色/字体，并生成一套**可维护的 PHP include 模板骨架**。

> 仅用于你有权处理、自有或已授权的站点；不要整站复制他人受版权保护的内容用于再发布/商用。

## 安装

```bash
cd apps/site2php
npm install
```

## 用法

```bash
node cli.mjs https://example.com/            # 输出到 out/example.com/
node cli.mjs https://example.com/ --out ./mycopy --name example
```

## 产出

```
out/<站点名>/
  index.php              # 入口（head 保留原样 + include partials）
  assets/
    css/ js/ img/ fonts/ media/   # 本地化的静态资源（引用已改写）
  partials/
    header.php           # 页头（含导航，若原页有）
    section-01.php …     # 各内容区块（可独立维护）
    footer.php           # 页脚
  _report/
    manifest.json        # 抓取元信息
    structure.txt        # body 区块结构树
    palette.json         # 配色统计（从 css/style 提取）
    fonts.json           # 字体清单
    classes.json         # 高频 class（组件提示）
    assets.json          # 下载资源清单
```

## 预览

```bash
cd out/<站点名>
php -S 0.0.0.0:8080
# 打开 http://127.0.0.1:8080/
```

## 说明与限制

- CSS 内部的 `url()` / `@import` 会递归下载改写；`data:` 内联保持不变。
- JS 里运行时动态请求的远程资源无法静态本地化（由 JS 运行时自行决定）。
- 骨架拆分是启发式的（header/各 section/footer）；复杂页面可在 `_report/structure.txt`
  基础上手动调整 partials。
- 生成的 `<?` 已被转义，避免原文触发 PHP 解析。
