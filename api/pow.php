<?php
/**
 * ChatApp — Proof-of-Work challenge（auth 登录 与 维护门户登录 共用）
 *
 * 自包含：不依赖 config.php / DB，可独立 require。
 * 用法：
 *   $pow = chatapp_pow_issue('pow');              // 签发（默认键 pow）
 *   chatapp_verify_pow($ch, $nonce, 'pow');       // 校验（单次，成功后清除）
 *  hash = SHA-256：PHP 用 hash('sha256', ...)，前端 pow.js 为同步 SHA-256
 *  实现（FIPS 180-4），两侧可用标准测试向量互相校验，无自定义密码学。
 */

if (!defined('POW_TARGET_BITS'))    define('POW_TARGET_BITS', 15);      // sub-second difficulty (~2^15 tries)
if (!defined('POW_MAX_NONCE_LEN'))  define('POW_MAX_NONCE_LEN', 10);    // nonce is decimal, <= 9999999999
if (!defined('POW_CHALLENGE_TTL'))  define('POW_CHALLENGE_TTL', 300);   // seconds before a challenge expires

/** PoW hash → 64 lowercase hex chars. Standard SHA-256 (FIPS 180-4):
 *  the JS side (modern/scripts/pow.js) runs a synchronous SHA-256 that mirrors
 *  this call exactly, so both sides are bit-for-bit identical and verifiable
 *  against public test vectors. Input is ASCII (challenge + ':' + nonce). */
function chatapp_pow_hash(string $input): string {
    return hash('sha256', $input);
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
