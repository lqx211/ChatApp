<?php
/**
 * Simple file serving endpoint
 * Supports: ?u=$userId&f=$filename (user uploads: data/user/$uid/$f)
 *           ?f=path/rel (ticket images: data/ticket/...)
 */
require_once __DIR__ . '/config.php';

$userId = (int)($_GET['u'] ?? 0);
$originalFile = $_GET['f'] ?? '';
$file = str_replace('\\', '/', $originalFile);
$file = preg_replace('/\.\./', '', $file);

// Reject if any ".." remains after stripping (e.g. "...." -> "..")
if (strpos($file, '..') !== false) {
    chatapp_log('security_logs', [
        'event_type' => 'path_traversal',
        'target_path' => mb_substr($originalFile, 0, 500),
        'details' => 'detected_by: dotdot_check',
    ]);
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid path']);
    exit;
}
$file = ltrim($file, '/');

if ($userId > 0) {
    $baseDir = __DIR__ . '/../data/user/' . $userId;
    $path = $baseDir . '/' . $file;
} else {
    $baseDir = __DIR__ . '/../data/';
    $path = $baseDir . $file;
}

// Resolve symlinks and normalize, then verify the file stays inside the allowed base directory
$real = realpath($path);
$realBase = realpath($baseDir);
if ($real === false || $realBase === false || strpos($real . '/', $realBase . '/') !== 0) {
    chatapp_log('security_logs', [
        'event_type' => 'path_traversal',
        'target_path' => mb_substr($originalFile, 0, 500),
        'details' => 'detected_by: realpath_check, base=' . mb_substr($baseDir, 0, 200) . ', real=' . mb_substr((string)$real, 0, 200),
    ]);
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File not found']);
    exit;
}
$path = $real;

if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File not found']);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'gif' => 'image/gif', 'webp' => 'image/webp',
    'mp4' => 'video/mp4', 'webm' => 'video/webm',
    'mov' => 'video/quicktime', 'ogg' => 'video/ogg',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'm4b' => 'audio/mp4',
    'wav' => 'audio/wav', 'aac' => 'audio/aac', 'opus' => 'audio/ogg',
    'flac' => 'audio/flac',
    'oga' => 'audio/ogg',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

// Sanitize download filename: strip control characters (CR/LF etc.), quotes and backslashes
// to prevent HTTP response header injection
$dlName = isset($_GET['name']) ? $_GET['name'] : basename($file);
$dlName = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $dlName);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
// Force download for generic files or when dl=1 is requested
if (!preg_match('/^(image|video)\//', $mime) || (isset($_GET['dl']) && $_GET['dl'] === '1')) {
    header('Content-Disposition: attachment; filename="' . $dlName . '"');
}
readfile($path);