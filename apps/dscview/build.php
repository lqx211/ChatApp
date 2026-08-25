<?php
/**
 * dscview indexer
 *
 * Streams c.json token-by-token (never loads the whole 162MB file into memory)
 * and produces:
 *   - index.json          : chat list + metadata (for the list/search API)
 *   - chats/{id}.json     : one file per chat, messages flattened & ordered
 *
 * Usage:
 *   php build.php              # uses ./c.json
 *   php build.php path/to.json # custom source
 */

declare(strict_types=1);

$src    = $argv[1] ?? __DIR__ . '/c.json';
$outDir = __DIR__ . '/chats';

if (!is_file($src)) {
    fwrite(STDERR, "source not found: $src\n");
    exit(1);
}
if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "cannot create $outDir\n");
    exit(1);
}

/**
 * Yields the raw JSON text of each top-level object of a JSON array,
 * without ever holding more than one object in memory.
 * Correctly skips braces/brackets that appear inside strings.
 */
function eachTopLevelObject(string $file, callable $cb): void
{
    $fh = fopen($file, 'rb');
    if ($fh === false) {
        throw new RuntimeException("cannot open $file");
    }

    $depth     = 0;   // combined {} + [] nesting depth
    $inString  = false;
    $escaped   = false;
    $capturing = false;
    $raw       = '';
    $count     = 0;

    while (!feof($fh)) {
        $chunk = fread($fh, 1 << 20); // 1 MiB
        if ($chunk === false) {
            break;
        }
        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            $c = $chunk[$i];

            if ($inString) {
                if ($capturing) {
                    $raw .= $c;
                }
                if ($escaped) {
                    $escaped = false;
                } elseif ($c === '\\') {
                    $escaped = true;
                } elseif ($c === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($c === '"') {
                $inString = true;
                if ($capturing) {
                    $raw .= $c;
                }
                continue;
            }

            if ($c === '{') {
                if ($depth === 1 && !$capturing) {
                    // start of a top-level element
                    $capturing = true;
                    $raw       = '{';
                } elseif ($capturing) {
                    $raw .= $c;
                }
                $depth++;
                continue;
            }

            if ($c === '}') {
                if ($capturing) {
                    $raw .= $c;
                }
                $depth--;
                if ($depth === 1 && $capturing) {
                    $capturing = false;
                    $cb($raw, $count++);
                    $raw = '';
                }
                continue;
            }

            if ($c === '[') {
                if ($capturing) {
                    $raw .= $c;
                }
                $depth++;
                continue;
            }

            if ($c === ']') {
                if ($capturing) {
                    $raw .= $c;
                }
                $depth--;
                continue;
            }

            // commas, colons, whitespace, numbers, literals...
            if ($capturing) {
                $raw .= $c;
            }
        }
    }

    fclose($fh);
}

function mbCut(string $s, int $len): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $len);
    }
    return strlen($s) > $len ? substr($s, 0, $len) : $s;
}

$index  = [];
$errors = 0;
$start  = microtime(true);

eachTopLevelObject($src, function (string $raw, int $i) use (&$index, &$errors, $outDir): void {
    $chat = json_decode($raw, true);
    if (!is_array($chat) || !isset($chat['id'])) {
        $errors++;
        return;
    }

    $id       = (string) $chat['id'];
    $title    = trim((string) ($chat['title'] ?? '')) !== '' ? (string) $chat['title'] : '未命名对话';
    $inserted = (string) ($chat['inserted_at'] ?? '');
    $updated  = (string) ($chat['updated_at'] ?? '');

    // Flatten the mapping tree into an ordered message list (DFS from "root").
    $mapping  = is_array($chat['mapping'] ?? null) ? $chat['mapping'] : [];
    $messages = [];

    $walk = function (string $nodeId) use (&$walk, $mapping, &$messages): void {
        if (!isset($mapping[$nodeId]) || !is_array($mapping[$nodeId])) {
            return;
        }
        $node = $mapping[$nodeId];
        $msg  = $node['message'] ?? null;
        if (is_array($msg)) {
            foreach ((array) ($msg['fragments'] ?? []) as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $messages[] = [
                    't' => (string) ($f['type'] ?? 'RESPONSE'),
                    'c' => (string) ($f['content'] ?? ''),
                    'm' => (string) ($msg['model'] ?? ''),
                    'd' => (string) ($msg['inserted_at'] ?? ''),
                ];
            }
        }
        foreach ((array) ($node['children'] ?? []) as $childId) {
            $walk((string) $childId);
        }
    };
    $walk('root');

    $nReq  = 0;
    $nResp = 0;
    $chars = 0;
    foreach ($messages as $m) {
        if ($m['t'] === 'REQUEST') {
            $nReq++;
        } elseif ($m['t'] === 'RESPONSE') {
            $nResp++;
        }
        $chars += function_exists('mb_strlen') ? mb_strlen($m['c']) : strlen($m['c']);
    }

    $preview = '';
    foreach ($messages as $m) {
        if ($m['t'] === 'REQUEST' && $m['c'] !== '') {
            $preview = $m['c'];
            break;
        }
    }

    $outFile = $outDir . '/' . $id . '.json';
    file_put_contents(
        $outFile,
        json_encode(
            [
                'id'          => $id,
                'title'       => $title,
                'inserted_at' => $inserted,
                'updated_at'  => $updated,
                'messages'    => $messages,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )
    );

    $index[] = [
        'id'         => $id,
        'title'      => $title,
        'updated_at' => $updated,
        'nReq'       => $nReq,
        'nResp'      => $nResp,
        'chars'      => $chars,
        'preview'    => mbCut($preview, 140),
    ];
});

usort($index, static fn ($a, $b): int => strcmp((string) $b['updated_at'], (string) $a['updated_at']));

file_put_contents(
    __DIR__ . '/index.json',
    json_encode(
        [
            'built_at' => date('c'),
            'source'   => basename($src),
            'total'    => count($index),
            'items'    => $index,
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    )
);

$secs = round(microtime(true) - $start, 2);
printf("done: %d chats indexed, %d errors, %.2fs\n", count($index), $errors, $secs);
