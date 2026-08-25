<?php
/**
 * ChatApp 版本信息
 * version 自动从 git 读取当前 commit 短哈希（如 7b81d7）；
 * 非 git 部署或无权读取时保持空字符串。
 */
$__infoVersion = '';
$__infoBuild   = '';
$__headFile = __DIR__ . '/../.git/HEAD';
if (is_file($__headFile)) {
    $__head = @file_get_contents($__headFile);
    if (is_string($__head) && preg_match('/^ref:\s*(.+)$/m', $__head, $__m)) {
        $__refFile = __DIR__ . '/../.git/' . trim($__m[1]);
        if (is_file($__refFile)) {
            $__hash = @file_get_contents($__refFile);
            if (is_string($__hash) && preg_match('/^[0-9a-f]{40}$/i', trim($__hash))) {
                $__infoVersion = substr(trim($__hash), 0, 7);
                $__infoBuild   = date('Y-m-d', (int)@filemtime($__refFile));
            }
        }
    }
}
return [
    "version"      => $__infoVersion,
    "build_date"   => $__infoBuild,
    "introduction" => "",
]
?>