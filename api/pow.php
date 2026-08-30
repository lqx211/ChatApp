<?php
/**
 * ChatApp — Proof-of-Work challenge（auth 登录 与 维护门户登录 共用）
 *
 * 自包含：不依赖 config.php / DB，可独立 require。
 * 用法：
 *   $pow = chatapp_pow_issue('pow');              // 签发（默认键 pow）
 *   chatapp_verify_pow($ch, $nonce, 'pow');       // 校验（单次，成功后清除）
 * 前端 pow.js 与 PHP 端位级一致，无 32 位溢出/编码坑。
 */

if (!defined('POW_TARGET_BITS'))    define('POW_TARGET_BITS', 15);      // sub-second difficulty (~2^15 tries)
if (!defined('POW_MAX_NONCE_LEN'))  define('POW_MAX_NONCE_LEN', 10);    // nonce is decimal, <= 9999999999
if (!defined('POW_CHALLENGE_TTL'))  define('POW_CHALLENGE_TTL', 300);   // seconds before a challenge expires

/** Custom PoW hash → 64 lowercase hex chars. Only 0-255 arithmetic (add/xor/
 *  shift), so the PHP and JS implementations are bit-for-bit identical with no
 *  32-bit signed-overflow or encoding pitfalls. Input is ASCII. */
function chatapp_pow_hash(string $input): string {
    $seed = [0x24, 0x5a, 0x10, 0x9f, 0x3d, 0x77, 0x81, 0xc2, 0x4b, 0x0e, 0x96, 0x55,
             0x1a, 0x68, 0xdc, 0x03, 0x7e, 0x92, 0x40, 0xcf, 0x11, 0x5d, 0xaa, 0x38,
             0x66, 0xf1, 0x0b, 0x9c, 0x27, 0x74, 0xdb, 0x32];
    $state = $seed;
    $bytes = array_values(unpack('C*', $input));
    $n = count($bytes);
    for ($round = 0; $round < 32; $round++) {
        $state[0] = ($state[0] ^ ($round + 1)) & 0xff;
        for ($i = 0; $i < 32; $i++) {
            $ib = $n > 0 ? $bytes[($i + $round) % $n] : 0;
            $a = $state[$i];
            $b = $state[($i + 7) % 32];
            $c = $state[($i + 13) % 32];
            $x = ((($a << 3) | ($a >> 5)) & 0xff);
            $x = ($x + $b) & 0xff;
            $x = ($x ^ $c) & 0xff;
            $x = ($x ^ $ib) & 0xff;
            $k = (($round * 31 + $i * 7 + 11) & 0xff);
            $state[$i] = ($x + $k) & 0xff;
        }
        $t = $state[0]; $state[0] = $state[31]; $state[31] = $t;
        $t = $state[5]; $state[5] = $state[21]; $state[21] = $t;
    }
    $out = '';
    foreach ($state as $b) { $out .= sprintf('%02x', $b); }
    return $out;
}

/** Target = 2^(256 - bits), a 64-char lowercase hex string (no gmp needed). */
function chatapp_pow_target(int $bits): string {
    $shift = 256 - $bits;
    $idx = intdiv($shift, 4);
    $digit = 1 << ($shift % 4);
    return str_pad(dechex($digit) . str_repeat('0', $idx), 64, '0', STR_PAD_LEFT);
}

/** Issue a fresh challenge bound to this session under the given key. */
function chatapp_pow_issue(string $key = 'pow'): array {
    $pow = [
        'challenge' => bin2hex(random_bytes(16)),
        'target_bits' => POW_TARGET_BITS,
        'expires' => time() + POW_CHALLENGE_TTL,
    ];
    $_SESSION[$key] = $pow;
    return $pow;
}

/** Verify a client PoW solution. Single-use (unset on success). Difficulty is
 *  always taken from the server-side session — never trusted from the client. */
function chatapp_verify_pow(string $challenge, string $nonce, string $key = 'pow'): bool {
    $pow = $_SESSION[$key] ?? null;
    if (!$pow || !isset($pow['challenge'], $pow['target_bits'], $pow['expires'])) return false;
    if (time() > (int)$pow['expires']) return false;
    if (!hash_equals($pow['challenge'], $challenge)) return false;
    if ($nonce === '' || strlen($nonce) > POW_MAX_NONCE_LEN || !ctype_digit($nonce)) return false;
    if ((float)$nonce > 9999999999.0) return false;
    $target = chatapp_pow_target((int)$pow['target_bits']);
    if (strcmp(chatapp_pow_hash($pow['challenge'] . ':' . $nonce), $target) >= 0) return false;
    unset($_SESSION[$key]);
    return true;
}
