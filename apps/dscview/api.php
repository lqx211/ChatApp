<?php
/**
 * dscview API
 *
 * Endpoints (all GET, all JSON):
 *   api.php?action=list&q=&page=&per=   — paginated chat list with optional search
 *   api.php?action=chat&id=<uuid>       — full chat (messages)
 *   api.php?action=stats                — index metadata
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

$action    = $_GET['action'] ?? 'list';
$indexFile = __DIR__ . '/index.json';

function readIndex(): array
{
    global $indexFile;
    static $cache = null;
    if ($cache === null) {
        $raw   = @file_get_contents($indexFile);
        $cache = $raw !== false ? (json_decode($raw, true) ?: []) : [];
    }
    return $cache;
}

function fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Case-insensitive substring test that works without mbstring. */
function containsFold(string $haystack, string $needle): bool
{
    return strpos(strtolower($haystack), strtolower($needle)) !== false;
}

switch ($action) {
    case 'list':
        if (!is_file($indexFile)) {
            fail('尚未建立索引，请先运行: php build.php', 404);
        }
        $data  = readIndex();
        $items = $data['items'] ?? [];

        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $items = array_values(array_filter(
                $items,
                static fn ($it): bool =>
                    containsFold((string) ($it['title'] ?? ''), $q)
                    || containsFold((string) ($it['preview'] ?? ''), $q)
            ));
        }

        $total = count($items);
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $per   = min(200, max(1, (int) ($_GET['per'] ?? 30)));
        $slice = array_slice($items, ($page - 1) * $per, $per);

        echo json_encode(
            [
                'total' => $total,
                'page'  => $page,
                'per'   => $per,
                'items' => $slice,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        break;

    case 'chat':
        $id = (string) ($_GET['id'] ?? '');
        if (!preg_match('/^[0-9a-fA-F-]{8,64}$/', $id)) {
            fail('非法的对话 id', 400);
        }
        $file = __DIR__ . '/chats/' . $id . '.json';
        if (!is_file($file)) {
            fail('对话不存在，可能需要重新建立索引', 404);
        }
        readfile($file);
        break;

    case 'stats':
        $data = readIndex();
        echo json_encode(
            [
                'total'    => $data['total'] ?? count($data['items'] ?? []),
                'built_at' => $data['built_at'] ?? null,
                'source'   => $data['source'] ?? null,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        break;

    default:
        fail('未知的 action: ' . $action, 400);
}
