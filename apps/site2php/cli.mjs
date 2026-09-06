#!/usr/bin/env node
/**
 * site2php —— 网页 → 本地 PHP 模板骨架（分析 + 脚手架工具）
 *
 * 用法:  node cli.mjs <url> [--out <目录>] [--name <站点名>]
 *
 * 做的事:
 *   1. 抓 HTML；把外链 CSS/JS/图片/字体下载到 assets/ 并改写引用（含 css 内 url()/@import）
 *   2. <style> 内联样式外置成独立 css
 *   3. 分析：区块结构树 / 配色 / 字体 / class 清单 → _report/
 *   4. 把 <body> 按 header + 各区块 + footer 切成 partials/*.php，生成可预览 index.php
 *
 * 注意：仅用于「你有权处理 / 自有 / 已授权」的站点。
 */
import * as cheerio from 'cheerio';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const UA = 'Mozilla/5.0 (compatible; site2php/1.0; local-dev)';

/* ---------- 参数 ---------- */
const positional = process.argv.slice(2).filter(a => !a.startsWith('--'));
function opt(name, fb) { const i = process.argv.indexOf(name); return i >= 0 ? process.argv[i + 1] : fb; }
const targetUrl = positional[0] || '';
if (!targetUrl) { console.log('用法: node cli.mjs <url> [--out 目录] [--name 站点名]'); process.exit(1); }
function hostOf(u) { try { return new URL(u).hostname.replace(/[^a-zA-Z0-9.-]/g, '_'); } catch { return 'site'; } }
const siteName = opt('--name', hostOf(targetUrl));
const outDir = path.resolve(opt('--out', path.join(__dirname, 'out', siteName)));
const ensure = d => fs.mkdirSync(d, { recursive: true });

const usedNames = new Set();
const allCss = [];
const assetLog = [];

/* ---------- 网络 ---------- */
async function fetchBuf(url) {
  const r = await fetch(url, { headers: { 'user-agent': UA }, redirect: 'follow' });
  if (!r.ok) throw new Error(`HTTP ${r.status} (${url})`);
  return { buf: Buffer.from(await r.arrayBuffer()), ct: (r.headers.get('content-type') || '').split(';')[0].trim().toLowerCase() };
}
async function fetchText(url) {
  const r = await fetch(url, { headers: { 'user-agent': UA }, redirect: 'follow' });
  if (!r.ok) throw new Error(`HTTP ${r.status} (${url})`);
  const buf = Buffer.from(await r.arrayBuffer());
  let enc = (r.headers.get('content-type') || '').match(/charset=([\w-]+)/i)?.[1];
  if (!enc) enc = buf.toString('latin1').match(/charset=["']?([\w-]+)/i)?.[1];
  try { return { text: new TextDecoder(enc || 'utf-8').decode(buf), url: r.url }; }
  catch { return { text: buf.toString('utf8'), url: r.url }; }
}
const MIME_EXT = {
  'text/css': '.css', 'application/javascript': '.js', 'text/javascript': '.js',
  'image/png': '.png', 'image/jpeg': '.jpg', 'image/gif': '.gif', 'image/webp': '.webp',
  'image/svg+xml': '.svg', 'image/x-icon': '.ico', 'image/vnd.microsoft.icon': '.ico',
  'font/woff2': '.woff2', 'font/woff': '.woff', 'font/ttf': '.ttf',
  'video/mp4': '.mp4', 'audio/mpeg': '.mp3'
};

/* ---------- 落盘 ---------- */
function uniqName(url, hint) {
  let base = (path.basename(new URL(url).pathname) || 'file').split('?')[0].replace(/[^a-zA-Z0-9._-]/g, '_') || 'file';
  if (base === '.' || base === '..') base = 'file';
  if (!path.extname(base) && hint) base += hint;
  const ext = path.extname(base);
  let key = base, i = 1;
  while (usedNames.has(key)) key = ext ? base.replace(ext, `-${i++}${ext}`) : `${base}-${i++}`;
  usedNames.add(key);
  return key;
}
async function saveAsset(kind, url) {
  const sub = { css: 'css', js: 'js', img: 'img', font: 'fonts', media: 'media' }[kind] || 'img';
  const dir = path.join(outDir, 'assets', sub);
  ensure(dir);
  const { buf, ct } = await fetchBuf(url);
  const hint = kind === 'js' ? '.js' : kind === 'css' ? '.css' : (MIME_EXT[ct] || '');
  const name = uniqName(url, hint);
  fs.writeFileSync(path.join(dir, name), buf);
  assetLog.push({ kind, name, url });
  return { name, rel: `assets/${sub}/${name}`, sub };
}
function cssRefs(css) {
  const out = [];
  const re = /@import\s+(?:url\()?\s*['"]?([^'"\s);]+)['"]?|url\(\s*['"]?([^'")]+)['"]?\s*\)/g;
  let m;
  while ((m = re.exec(css))) { const v = (m[1] || m[2] || '').trim(); if (v && !v.startsWith('data:') && !v.startsWith('#')) out.push(v); }
  return out;
}
function kindFromExt(u) {
  const p = u.split('?')[0].split('#')[0].toLowerCase();
  if (/\.(woff2?|ttf|otf|eot)$/.test(p)) return 'font';
  if (/\.(mp4|webm|ogg|mp3|wav)$/.test(p)) return 'media';
  return 'img';
}

/** 下载一个外部 css：内部 url()/@import 递归本地化，整体存 assets/css/ */
async function saveCss(url, depth) {
  if (depth > 5) return null;
  let txt, real;
  try { ({ text: txt, url: real } = await fetchText(url)); } catch (e) { console.warn('  ⚠ css 失败: ' + url); return null; }
  for (const ref of cssRefs(txt)) {
    let abs;
    try { abs = new URL(ref, real || url).href; } catch { continue; }
    if (/\.css(\?|$)/i.test(abs.split('#')[0])) {
      const inner = await saveCss(abs, depth + 1);
      if (inner) txt = txt.split(ref).join(`url('./${inner.name}')`);
    } else {
      try {
        const d = await saveAsset(kindFromExt(abs), abs);
        txt = txt.split(ref).join(`url('../${d.sub}/${d.name}')`); // css 位于 assets/css/
      } catch { console.warn('  ⚠ css 子资源失败: ' + abs); }
    }
  }
  allCss.push(txt);
  const dir = path.join(outDir, 'assets', 'css');
  ensure(dir);
  const name = uniqName(url, '.css');
  fs.writeFileSync(path.join(dir, name), txt, 'utf8');
  assetLog.push({ kind: 'css', name, url });
  return { name, rel: `assets/css/${name}` };
}

/* ---------- 分析 ---------- */
function analyze($, baseUrl) {
  const reportDir = path.join(outDir, '_report');
  ensure(reportDir);
  const htmlText = $.html();
  const tree = structureTree($, $('body'), 0);

  const colorRe = /#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\)/g;
  const colors = {};
  const add = c => { c = c.trim().toLowerCase(); colors[c] = (colors[c] || 0) + 1; };
  for (const css of allCss) for (const c of css.match(colorRe) || []) add(c);
  for (const m of htmlText.match(/style="[^"]*"/g) || []) for (const c of m.match(colorRe) || []) add(c);
  const palette = Object.entries(colors).sort((a, b) => b[1] - a[1]).slice(0, 40).map(([c, n]) => ({ color: c, count: n }));

  const fonts = {};
  const fontRe = /font-family\s*:\s*([^;}]+)/g;
  for (const css of allCss) {
    let m;
    while ((m = fontRe.exec(css))) for (const part of m[1].split(',')) {
      const f = part.trim().replace(/^['"]|['"]$/g, '');
      if (f && f !== 'inherit' && f !== 'initial') fonts[f] = (fonts[f] || 0) + 1;
    }
  }
  const fontList = Object.entries(fonts).sort((a, b) => b[1] - a[1]).slice(0, 30).map(([f, n]) => ({ font: f, count: n }));

  const classes = {};
  for (const m of htmlText.match(/class="([^"]*)"/g) || []) for (const c of m.slice(7, -1).split(/\s+/)) if (c) classes[c] = (classes[c] || 0) + 1;
  const topClasses = Object.entries(classes).sort((a, b) => b[1] - a[1]).slice(0, 60).map(([c, n]) => ({ class: c, count: n }));

  fs.writeFileSync(path.join(reportDir, 'structure.txt'), tree, 'utf8');
  fs.writeFileSync(path.join(reportDir, 'palette.json'), JSON.stringify({ colors: palette }, null, 2), 'utf8');
  fs.writeFileSync(path.join(reportDir, 'fonts.json'), JSON.stringify({ fonts: fontList }, null, 2), 'utf8');
  fs.writeFileSync(path.join(reportDir, 'classes.json'), JSON.stringify({ classes: topClasses }, null, 2), 'utf8');
  fs.writeFileSync(path.join(reportDir, 'assets.json'), JSON.stringify(assetLog, null, 2), 'utf8');
  fs.writeFileSync(path.join(reportDir, 'manifest.json'),
    JSON.stringify({ url: baseUrl, site: siteName, fetchedAt: new Date().toISOString(), assets: assetLog.length, css: allCss.length }, null, 2), 'utf8');
  return { tree, palette, fonts: fontList, classes: topClasses };
}
function structureTree($, root, depth) {
  let out = '';
  if (depth > 6) return out;
  root.children().each(function () {
    const el = this;
    if (!el || typeof el.tagName !== 'string') return;
    if (['script', 'style', 'link', 'noscript'].includes(el.tagName)) return;
    const id = el.attribs && el.attribs.id ? '#' + el.attribs.id : '';
    const cls = el.attribs && el.attribs.class ? '.' + String(el.attribs.class).trim().split(/\s+/).slice(0, 3).join('.') : '';
    out += '  '.repeat(depth) + `<${el.tagName}${id}${cls}>\n`;
    if (depth < 6) out += structureTree($, $(el), depth + 1);
  });
  return out;
}

/* ---------- 生成 PHP 模板 ---------- */
function phpSafe(html) {
  return html.replace(/<\?(?!php|=|xml\b)/gi, '<?php echo "<?"; ?>');
}
function scaffold($, siteName) {
  const pdir = path.join(outDir, 'partials');
  ensure(pdir);

  const kids = $('body').children().toArray().filter(el => typeof el.tagName === 'string' && !['style', 'link', 'noscript'].includes(el.tagName));
  if (!kids.length) { console.log('  ⚠ body 无内容'); return; }

  const isHead = el => el.tagName === 'header' || el.tagName === 'nav' ||
    /header|banner|topbar|top-bar|navbar|nav-bar|masthead/i.test((el.attribs && el.attribs.class) || '');
  const isFoot = el => el.tagName === 'footer' || /footer/i.test((el.attribs && el.attribs.class) || '');

  // 定位 header（开头的 header/nav/顶栏 连续区）与 footer（结尾的 footer 连续区）
  const header = [];
  let i = 0;
  while (i < kids.length && isHead(kids[i])) header.push(kids[i++]);
  // 常见：第一块就是顶栏（无 <header> 标签）
  if (!header.length && i < kids.length && /div|section/.test(kids[i].tagName) && kids.length - i > 1 &&
      !isFoot(kids[i]) && !isFoot(kids[kids.length - 1])) header.push(kids[i++]);
  // footer：最后一个 footer-like 元素及其之后的所有尾巴（常含脚本）
  const footer = [];
  let fj = -1;
  for (let kk = kids.length - 1; kk >= i; kk--) { if (isFoot(kids[kk])) { fj = kk; break; } }
  let j;
  if (fj >= 0) {
    for (let kk = fj; kk < kids.length; kk++) footer.push(kids[kk]);
    j = fj - 1;
  } else {
    j = kids.length - 1;
  }

  // 中间：每个顶层元素一个 section 文件；独立 <script> 并入前一文件
  const buckets = [];
  if (header.length) buckets.push({ label: 'header', els: header });
  for (let k = i; k <= j; k++) {
    const el = kids[k];
    if (el.tagName === 'script' && buckets.length) buckets[buckets.length - 1].els.push(el);
    else buckets.push({ label: 'section', els: [el] });
  }
  if (footer.length) buckets.push({ label: 'footer', els: footer });

  const partFiles = [];
  let sec = 0;
  for (const b of buckets) {
    const frag = b.els.map(el => $.html(el)).join('\n').trim();
    if (!frag) continue;
    let fn;
    if (b.label === 'header') fn = 'header.php';
    else if (b.label === 'footer') fn = 'footer.php';
    else fn = 'section-' + String(++sec).padStart(2, '0') + '.php';
    fs.writeFileSync(path.join(pdir, fn), '<?php\n// partial: ' + b.label + '（site2php 生成，可自行改写）\n?>\n' + phpSafe(frag) + '\n', 'utf8');
    partFiles.push({ label: b.label, file: fn, els: b.els.length });
  }

  const headHtml = $('head').html() || '';
  const title = ($('title').text() || siteName).trim();
  const inc = partFiles.map(p => `include __DIR__.'/partials/${p.file}';\n`).join('');

  // 骨架只补 head 缺失的部分，避免与原 head 重复
  const needCharset = !/charset=/i.test(headHtml);
  const needTitle = !/<title[ >]/i.test(headHtml);
  const extraHead = (needCharset ? '<meta charset="UTF-8">\n' : '') +
    (needTitle ? `<title><?= htmlspecialchars($page_title) ?></title>\n` : '') +
    (headHtml ? `${headHtml}\n` : '');

  const php = `<?php
/**
 * site2php 生成的骨架 —— 把页面拆成可维护的 PHP include 模板。
 * 预览:  在本目录运行  php -S 0.0.0.0:8080  →  http://127.0.0.1:8080/
 * 提示:  请把内容/样式替换为你有权使用的模板数据；结构见 _report/。
 */
$page_title = ${JSON.stringify(title)};
?>
<!DOCTYPE html>
<html lang="zh">
<head>
${extraHead}
</head>
<body>
<?php
${inc}
?>
</body>
</html>
`;
  fs.writeFileSync(path.join(outDir, 'index.php'), php, 'utf8');
  console.log('  partials: ' + partFiles.map(p => p.file).join(', '));
}

/* ---------- 主流程 ---------- */
async function run() {
  console.log(`▶ 抓取 ${targetUrl}`);
  const { text: html, url: finalUrl } = await fetchText(targetUrl);
  console.log(`  下载成功 (${html.length} 字符)`);
  const $ = cheerio.load(html);

  // <base> 覆盖基准
  let baseUrl = finalUrl;
  const b = $('base[href]').first().attr('href');
  if (b) { try { baseUrl = new URL(b, finalUrl).href; } catch {} }

  // 1) <style> 外置
  let si = 0;
  $('style').each(function () {
    const dir = path.join(outDir, 'assets', 'css'); ensure(dir);
    const name = 'inline-' + (++si) + '.css';
    const css = $(this).html() || '';
    fs.writeFileSync(path.join(dir, name), css, 'utf8');
    allCss.push(css);
    assetLog.push({ kind: 'css', name, url: '(inline)' });
    $(this).replaceWith(`<link rel="stylesheet" href="assets/css/${name}" data-local="1">`);
  });

  // 2) 外链 css
  const cssLinks = [];
  $('link[rel~="stylesheet"][href]:not([data-local])').each(function () {
    const href = $(this).attr('href');
    try { cssLinks.push({ el: this, abs: new URL(href, baseUrl).href }); } catch {}
  });
  for (const c of cssLinks) {
    const d = await saveCss(c.abs, 0);
    if (d) $(c.el).attr('href', d.rel);
  }

  // 3) js / img / video / audio / icon
  const jobs = [];
  $('script[src], img[src], source[src], video[src], video[poster], audio[src], link[rel~="icon"][href]').each(function () {
    const el = this;
    for (const attr of ['src', 'href', 'poster']) {
      if (!el.attribs[attr]) continue;
      try { jobs.push({ el, attr, abs: new URL(el.attribs[attr], baseUrl).href }); } catch {}
    }
  });
  for (const j of jobs) {
    let kind = 'img';
    const tag = j.el.tagName.toLowerCase();
    if (tag === 'script') kind = 'js';
    else if (tag === 'video' || tag === 'audio' || tag === 'source') kind = kindFromExt(j.abs) === 'media' ? 'media' : 'img';
    try {
      const d = await saveAsset(kind, j.abs);
      $(j.el).attr(j.attr, d.rel);
    } catch (e) { console.warn('  ⚠ 资源失败: ' + j.abs + ' (' + e.message + ')'); }
  }

  // 4) srcset
  const srcs = [];
  $('img[srcset], source[srcset]').each(function () {
    const el = this;
    for (const seg of (el.attribs.srcset || '').split(',')) {
      const p = seg.trim().split(/\s+/);
      if (!p[0] || p[0].startsWith('data:')) continue;
      try { srcs.push({ el, seg, p, abs: new URL(p[0], baseUrl).href }); } catch {}
    }
  });
  for (const s of srcs) {
    try { const d = await saveAsset('img', s.abs); s.p[0] = d.rel; } catch {}
  }
  for (const el of $('img[srcset], source[srcset]').toArray()) {
    const out = (el.attribs.srcset || '').split(',').map(s => {
      const p = s.trim().split(/\s+/);
      const hit = srcs.find(x => x.el === el && x.seg.trim() === s.trim());
      return hit ? hit.p.join(' ') : s;
    });
    $(el).attr('srcset', out.join(', '));
  }

  console.log(`  静态资源本地化 ${assetLog.length} 个`);

  // 5) 分析
  console.log('▶ 分析页面…');
  analyze($, baseUrl);

  // 6) 脚手架
  console.log('▶ 生成 PHP 模板骨架…');
  scaffold($, siteName);

  console.log(`\n✔ 完成 → ${outDir}`);
  console.log('  预览: cd "' + outDir + '" && php -S 0.0.0.0:8080');
  console.log('  报告: ' + path.join(outDir, '_report'));
}

run().catch(e => { console.error('✖ 出错: ' + (e && e.message ? e.message : e)); process.exit(1); });
