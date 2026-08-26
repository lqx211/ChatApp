<?php
/**
 * apps/filemgr/api.php — 文件管理器后端（root 专用，安全重写）
 *
 * 设计原则：
 *  - 鉴权：仅 root 可访问（chatapp_session + chatapp_get_role），前端不可信
 *  - 路径安全：所有路径经 fm_resolve() 解析，必须真实落在 ChatApp 根目录内
 *    （拒绝 ../ 穿越、拒绝符号链接逃逸、拒绝 null 字节）
 *  - 文件名净化：fm_name() 只取 basename，拒绝 / \ .. 等
 */
require __DIR__ . '/../../api/config.php';
chatapp_session_start();

$uid = null;
if (!empty($_SESSION['username'])) {
    $u = chatapp_get_user();
    $uid = $u['user_id'] ?? null;
}
if (!$uid || chatapp_get_role((int)$uid) !== 'root') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

$ROOT = realpath(__DIR__ . '/../..');
if ($ROOT === false) { fm_err('root_not_found'); }

function fm_err(string $msg): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function fm_json($d): void {
    header('Content-Type: application/json');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 安全路径解析：返回绝对路径，且真实落在 $ROOT 内（防 ../ 与符号链接逃逸）。
 *  $allowMissing=true 时允许目标不存在（新建用），但父目录必须在根内。 */
function fm_resolve(string $p, bool $allowMissing = false): ?string {
    global $ROOT;
    if (strpos($p, "\0") !== false) return null;
    $p = str_replace('\\', '/', $p);
    if ($p === '' || $p === '/') return $ROOT;
    $abs = $ROOT . '/' . ltrim($p, '/');
    $real = realpath($abs);
    if ($real !== false) {
        if ($real !== $ROOT && strpos($real . '/', $ROOT . '/') !== 0) return null;
        return $real;
    }
    if ($allowMissing) {
        $parent = realpath(dirname($abs));
        if ($parent === false) return null;
        if ($parent !== $ROOT && strpos($parent . '/', $ROOT . '/') !== 0) return null;
        return $abs;
    }
    return null;
}

/** 文件名净化：只接受纯文件名；拒绝分隔符/.. /空/null 字节（不再用 basename 静默剥离） */
function fm_name(string $n): ?string {
    if ($n === '' || $n === '.' || $n === '..' || strpos($n, "\0") !== false) return null;
    if (strpos($n, '/') !== false || strpos($n, '\\') !== false) return null;
    return $n;
}

/** 递归删除目录 */
function fm_rrmdir(string $dir): bool {
    foreach (scandir($dir) as $i) {
        if ($i === '.' || $i === '..') continue;
        $p = $dir . '/' . $i;
        if (is_dir($p) && !is_link($p)) {
            if (!fm_rrmdir($p)) return false;
        } else {
            if (!unlink($p)) return false;
        }
    }
    return rmdir($dir);
}

/** 递归复制（文件或目录） */
function fm_copydir(string $src, string $dst): bool {
    if (!is_dir($src)) return copy($src, $dst);
    if (!is_dir($dst) && !mkdir($dst, 0777, true)) return false;
    foreach (scandir($src) as $i) {
        if ($i === '.' || $i === '..') continue;
        $s = $src . '/' . $i; $d = $dst . '/' . $i;
        if (is_dir($s) && !is_link($s)) { if (!fm_copydir($s, $d)) return false; }
        else { if (!@copy($s, $d)) return false; }
    }
    return true;
}

/** 解析 names 参数：支持 names[] 数组或逗号分隔；逐个 fm_name 净化 */
function fm_names_param($v): array {
    if (is_array($v)) $list = $v;
    else $list = array_map('trim', explode(',', (string)$v));
    $out = [];
    foreach ($list as $n) { $nn = fm_name((string)$n); if ($nn !== null) $out[] = $nn; }
    return $out;
}

/** 复制时若目标已存在，自动生成 "name (1).ext" 之类的名称 */
function fm_copy_name(string $dst): string {
    if (!file_exists($dst)) return $dst;
    $dir  = dirname($dst);
    $base = basename($dst);
    $pi   = pathinfo($base);
    $name = $pi['filename'] ?? $base;
    $ext  = isset($pi['extension']) && $pi['extension'] !== '' ? '.' . $pi['extension'] : '';
    for ($i = 1; $i < 10000; $i++) {
        $cand = $dir . '/' . $name . ' (' . $i . ')' . $ext;
        if (!file_exists($cand)) return $cand;
    }
    return $dir . '/' . $name . '-' . time() . $ext;
}

/** 递归把目录加入 zip */
function fm_zipadddir(ZipArchive $za, string $dir, string $prefix): void {
    $za->addEmptyDir($prefix);
    foreach (scandir($dir) as $i) {
        if ($i === '.' || $i === '..') continue;
        $full = $dir . '/' . $i;
        if (is_dir($full) && !is_link($full)) fm_zipadddir($za, $full, $prefix . '/' . $i);
        else $za->addFile($full, $prefix . '/' . $i);
    }
}

/** 安全解压：拒绝绝对路径/.. 穿越/null 字节，防止 zip-slip */
function fm_extract(string $zipfile, string $dir): bool {
    $za = new ZipArchive();
    if ($za->open($zipfile) !== true) return false;
    for ($i = 0; $i < $za->numFiles; $i++) {
        $entry = $za->getNameIndex($i);
        if ($entry === '' || $entry[0] === '/' || strpos($entry, "\0") !== false) { $za->close(); return false; }
        if (strpos(str_replace('\\', '/', $entry), '../') !== false) { $za->close(); return false; }
        $dest = $dir . '/' . $entry;
        if (substr($entry, -1) === '/') {
            if (!is_dir($dest) && !@mkdir($dest, 0777, true)) { $za->close(); return false; }
        } else {
            $pd = dirname($dest);
            if (!is_dir($pd) && !@mkdir($pd, 0777, true)) { $za->close(); return false; }
            if ($za->extractTo($pd, $entry) === false) { $za->close(); return false; }
        }
    }
    $za->close();
    return true;
}

$action = $_REQUEST['action'] ?? 'list';
$path   = (string)($_REQUEST['path'] ?? '/');

switch ($action) {

    case 'list': {
        $dir = fm_resolve($path);
        if (!$dir || !is_dir($dir)) fm_err('not_found');
        $items = [];
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $dir . '/' . $name;
            $rp = realpath($full);
            if ($rp === false) continue;
            if ($rp !== $ROOT && strpos($rp . '/', $ROOT . '/') !== 0) continue; // 逃逸项跳过
            $isDir = is_dir($full);
            $items[] = [
                'name'  => $name,
                'dir'   => $isDir,
                'link'  => is_link($full),
                'size'  => $isDir ? null : filesize($full),
                'mtime' => filemtime($full),
                'ext'   => $isDir ? '' : (pathinfo($name, PATHINFO_EXTENSION) ?: '')
            ];
        }
        usort($items, function ($a, $b) {
            if ($a['dir'] !== $b['dir']) return $a['dir'] ? -1 : 1;
            return strcmp(strtolower($a['name']), strtolower($b['name']));
        });
        fm_json(['success' => true, 'path' => $path, 'cwd' => $dir, 'items' => $items]);
    }

    case 'tree': { // 文件夹树：返回 path 的直接子目录（懒加载）
        $dir = fm_resolve($path);
        if (!$dir || !is_dir($dir)) fm_err('not_found');
        $dirs = [];
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $dir . '/' . $name;
            if (!is_dir($full)) continue;
            $rp = realpath($full);
            if ($rp === false || ($rp !== $ROOT && strpos($rp . '/', $ROOT . '/') !== 0)) continue;
            $dirs[] = ['name' => $name, 'path' => $path === '/' ? $name : rtrim($path, '/') . '/' . $name];
        }
        usort($dirs, function ($a, $b) { return strcmp(strtolower($a['name']), strtolower($b['name'])); });
        fm_json(['success' => true, 'dirs' => $dirs]);
    }

    case 'mkdir':
    case 'mkfile': {
        $dir  = fm_resolve($path);
        $name = fm_name((string)($_REQUEST['name'] ?? ''));
        if (!$dir || !is_dir($dir) || $name === null) fm_err('bad_request');
        $target = $dir . '/' . $name;
        if (file_exists($target)) fm_err('exists');
        $ok = ($action === 'mkdir')
            ? mkdir($target)
            : (file_put_contents($target, '') !== false);
        fm_json(['success' => $ok]);
    }

    case 'rename': {
        $dir = fm_resolve($path);
        $old = fm_name((string)($_REQUEST['old'] ?? ''));
        $new = fm_name((string)($_REQUEST['new'] ?? ''));
        if (!$dir || !is_dir($dir) || $old === null || $new === null) fm_err('bad_request');
        $src = $dir . '/' . $old;
        $dst = $dir . '/' . $new;
        if (!file_exists($src)) fm_err('not_found');
        if (file_exists($dst)) fm_err('exists');
        $rp = realpath($src);
        if ($rp === false || ($rp !== $ROOT && strpos($rp . '/', $ROOT . '/') !== 0)) fm_err('denied');
        fm_json(['success' => rename($src, $dst)]);
    }

    case 'delete': {
        $dir  = fm_resolve($path);
        $name = fm_name((string)($_REQUEST['name'] ?? ''));
        if (!$dir || !is_dir($dir) || $name === null) fm_err('bad_request');
        $target = $dir . '/' . $name;
        $rp = realpath($target);
        if ($rp === false || ($rp !== $ROOT && strpos($rp . '/', $ROOT . '/') !== 0)) fm_err('denied');
        $ok = (is_dir($target) && !is_link($target)) ? fm_rrmdir($target) : unlink($target);
        fm_json(['success' => $ok]);
    }

    case 'upload': {
        $dir = fm_resolve($path);
        if (!$dir || !is_dir($dir)) fm_err('bad_request');
        if (empty($_FILES['file'])) fm_err('no_file');
        $name = fm_name((string)$_FILES['file']['name']);
        if ($name === null) fm_err('bad_name');
        $dest = fm_copy_name($dir . '/' . $name);   // 同名自动改名，避免覆盖
        $saved = basename($dest);
        if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            fm_json(['success' => true, 'name' => $saved]);
        }
        fm_err('upload_failed');
    }

    case 'download': {
        $file = fm_resolve($path);
        if (!$file || !is_file($file)) { http_response_code(404); exit('not found'); }
        $rp = realpath($file);
        if ($rp === false || ($rp !== $ROOT && strpos($rp . '/', $ROOT . '/') !== 0)) { http_response_code(403); exit('denied'); }
        $name = basename($file);
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($fi, $file) ?: $mime;
            finfo_close($fi);
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    case 'copy':
    case 'move': {
        $dir   = fm_resolve($path);
        $dest  = fm_resolve((string)($_REQUEST['dest'] ?? ''));
        $names = fm_names_param($_REQUEST['names'] ?? '');
        if (!$dir || !is_dir($dir) || !$dest || !is_dir($dest) || !$names) fm_err('bad_request');
        if ($action === 'move' && $dest === $dir) fm_err('same_dir');
        $ok = true; $count = 0; $renamed = [];
        foreach ($names as $n) {
            $src = realpath($dir . '/' . $n);
            if ($src === false || ($src !== $ROOT && strpos($src . '/', $ROOT . '/') !== 0)) { $ok = false; break; }
            if (strpos($dest . '/', $src . '/') === 0) { $ok = false; break; } // 目标在源内部 → 循环
            if ($action === 'copy') {
                $dst = fm_copy_name($dest . '/' . $n);   // 同名自动改名
                $r = fm_copydir($src, $dst);
                $renamed[] = basename($dst);
            } else {
                $dst = $dest . '/' . $n;
                if (file_exists($dst)) { $ok = false; break; }
                $r = rename($src, $dst);
            }
            if (!$r) { $ok = false; break; }
            $count++;
        }
        fm_json(['success' => $ok, 'count' => $count, 'renamed' => $renamed]);
    }

    case 'zip': {
        $dir   = fm_resolve($path);
        $names = fm_names_param($_REQUEST['names'] ?? '');
        $zname = fm_name((string)($_REQUEST['zname'] ?? 'archive.zip'));
        if (!$dir || !is_dir($dir) || !$names || $zname === null || !class_exists('ZipArchive')) fm_err('bad_request');
        if (!preg_match('/\.zip$/i', $zname)) $zname .= '.zip';
        $tmp = tempnam(sys_get_temp_dir(), 'fmz');
        if ($tmp === false) fm_err('zip_failed');
        $za = new ZipArchive();
        if ($za->open($tmp, ZipArchive::OVERWRITE) !== true) { @unlink($tmp); fm_err('zip_failed'); }
        foreach ($names as $n) {
            $src = realpath($dir . '/' . $n);
            if ($src === false || ($src !== $ROOT && strpos($src . '/', $ROOT . '/') !== 0)) continue;
            if (is_dir($src) && !is_link($src)) fm_zipadddir($za, $src, $n);
            else $za->addFile($src, $n);
        }
        $za->close();
        $dest = $dir . '/' . $zname;
        if (file_exists($dest)) { @unlink($tmp); fm_err('exists'); }
        if (!@rename($tmp, $dest)) { @unlink($tmp); fm_err('zip_failed'); }
        fm_json(['success' => true, 'name' => $zname]);
    }

    case 'unzip': {
        $dir  = fm_resolve($path);
        $name = fm_name((string)($_REQUEST['name'] ?? ''));
        if (!$dir || !is_dir($dir) || $name === null) fm_err('bad_request');
        $rp = realpath($dir . '/' . $name);
        if ($rp === false || !is_file($rp) || ($rp !== $ROOT && strpos($rp . '/', $ROOT . '/') !== 0)) fm_err('denied');
        fm_json(['success' => fm_extract($rp, $dir)]);
    }

    case 'view': { // 内联预览（图片/音视频/文本），不强制下载
        $file = fm_resolve($path);
        if (!$file || !is_file($file)) { http_response_code(404); exit('not found'); }
        $rp = realpath($file);
        if ($rp === false || ($rp !== $ROOT && strpos($rp . '/', $ROOT . '/') !== 0)) { http_response_code(403); exit('denied'); }
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $file) ?: $mime; finfo_close($fi); }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    case 'search': { // 递归搜索文件名（跳过隐藏/巨型目录，限量）
        $dir = fm_resolve($path);
        $q   = trim((string)($_REQUEST['q'] ?? ''));
        if (!$dir || !is_dir($dir) || $q === '') fm_err('bad_request');
        $max = min(1000, max(1, (int)($_REQUEST['max'] ?? 300)));
        $results = [];
        $it = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            function ($current, $key, $iterator) {
                if (!$iterator->hasChildren()) return true;
                $n = strtolower($current->getFilename());
                if (strpos($n, '.') === 0) return false;                    // 隐藏目录剪枝
                if (in_array($n, ['node_modules', 'vendor'])) return false;  // 巨型目录剪枝
                return true;
            }
        );
        $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::SELF_FIRST);
        foreach ($ri as $f) {
            if (count($results) >= $max) break;
            if ($ri->getDepth() > 8) { $ri->next(); continue; }
            $name = $f->getFilename();
            if (strpos($name, '.') === 0) continue;
            if (stripos($name, $q) === false) continue;
            $rp = realpath($f->getPathname());
            if ($rp === false || ($rp !== $ROOT && strpos($rp . '/', $ROOT . '/') !== 0)) continue;
            $rel = ltrim(str_replace('\\', '/', substr($rp, strlen($dir))), '/');
            $isDir = $f->isDir() && !$f->isLink();
            $results[] = [
                'name'  => $name,
                'path'  => $path === '/' ? $rel : rtrim($path, '/') . '/' . $rel,
                'dir'   => $isDir,
                'size'  => $isDir ? null : $f->getSize(),
                'mtime' => $f->getMTime(),
                'ext'   => $isDir ? '' : (pathinfo($name, PATHINFO_EXTENSION) ?: '')
            ];
        }
        usort($results, function ($a, $b) {
            if ($a['dir'] !== $b['dir']) return $a['dir'] ? -1 : 1;
            return strcmp(strtolower($a['name']), strtolower($b['name']));
        });
        fm_json(['success' => true, 'total' => count($results), 'items' => $results]);
    }

    case 'info': {
        $f = fm_resolve($path);
        if (!$f) fm_err('not_found');
        $rp = realpath($f);
        if ($rp === false || ($rp !== $ROOT && strpos($rp . '/', $ROOT . '/') !== 0)) fm_err('denied');
        $isDir = is_dir($f);
        $cnt = null;
        if ($isDir) { $cnt = 0; foreach (scandir($f) as $n) { if ($n === '.' || $n === '..') continue; $cnt++; } }
        fm_json(['success' => true, 'info' => [
            'name' => basename($f), 'path' => $path, 'dir' => $isDir,
            'size' => $isDir ? null : filesize($f),
            'mtime' => filemtime($f), 'ctime' => filectime($f),
            'items' => $cnt, 'link' => is_link($f),
            'readable' => is_readable($f), 'writable' => is_writable($f)
        ]]);
    }

    default:
        fm_err('unknown_action');
}
