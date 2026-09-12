/**
 * ChatApp - Proof-of-Work client.
 *
 * SHA-256 (FIPS 180-4), bit-for-bit identical to chatapp_pow_hash() in
 * api/pow.php (PHP: hash('sha256', $challenge . ':' . $nonce)).
 * A compact SYNCHRONOUS implementation is used on purpose: the solver runs tens
 * of thousands of hashes per challenge, and crypto.subtle.digest() (async, one
 * promise per call) is far too slow in a tight nonce loop.
 * Input is ASCII: challenge (hex) + ':' + nonce (decimal).
 */
(function (global) {
    'use strict';

    // SHA-256 round constants (FIPS 180-4)
    var K = [
        0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
        0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
        0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
        0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
        0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
        0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
        0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
        0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ];

    function nowMs() {
        return (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
    }

    function rotr(x, n) { return (x >>> n) | (x << (32 - n)); }

    // Reused message-schedule buffer (single-threaded, sha256Hex is not re-entrant).
    var W = new Array(64);

    /** UTF-8 bytes of a JS string (surrogate-pair safe). */
    function utf8Bytes(s) {
        var out = new Array(s.length), j = 0, i, c;
        for (i = 0; i < s.length; i++) {
            c = s.charCodeAt(i);
            if (c < 0x80) { out[j++] = c; }
            else if (c < 0x800) { out[j++] = 0xC0 | (c >> 6); out[j++] = 0x80 | (c & 63); }
            else if (c < 0xD800 || c >= 0xE000) { out[j++] = 0xE0 | (c >> 12); out[j++] = 0x80 | ((c >> 6) & 63); out[j++] = 0x80 | (c & 63); }
            else {
                c = 0x10000 + (((c & 0x3FF) << 10) | (s.charCodeAt(++i) & 0x3FF));
                out[j++] = 0xF0 | (c >> 18); out[j++] = 0x80 | ((c >> 12) & 63); out[j++] = 0x80 | ((c >> 6) & 63); out[j++] = 0x80 | (c & 63);
            }
        }
        out.length = j;
        return out;
    }

    /** SHA-256 → 64-char lowercase hex. Mirrors PHP hash('sha256', $str). */
    function sha256Hex(str) {
        var msg = utf8Bytes(str);
        var l = msg.length;
        var H = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
        msg.push(0x80);
        while (msg.length % 64 !== 56) { msg.push(0); }
        var bits = l * 8;
        var hi = Math.floor(bits / 4294967296), lo = bits >>> 0;
        msg.push((hi >>> 24) & 255, (hi >>> 16) & 255, (hi >>> 8) & 255, hi & 255,
                 (lo >>> 24) & 255, (lo >>> 16) & 255, (lo >>> 8) & 255, lo & 255);
        var w = W;
        for (var off = 0; off < msg.length; off += 64) {
            var i;
            for (i = 0; i < 16; i++) {
                w[i] = (msg[off + i * 4] << 24) | (msg[off + i * 4 + 1] << 16) | (msg[off + i * 4 + 2] << 8) | msg[off + i * 4 + 3];
            }
            for (i = 16; i < 64; i++) {
                var s0 = rotr(w[i - 15], 7) ^ rotr(w[i - 15], 18) ^ (w[i - 15] >>> 3);
                var s1 = rotr(w[i - 2], 17) ^ rotr(w[i - 2], 19) ^ (w[i - 2] >>> 10);
                w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
            }
            var a = H[0], b = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7];
            for (i = 0; i < 64; i++) {
                var S1 = rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25);
                var ch = (e & f) ^ (~e & g);
                var t1 = (h + S1 + ch + K[i] + w[i]) | 0;
                var S0 = rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22);
                var maj = (a & b) ^ (a & c) ^ (b & c);
                var t2 = (S0 + maj) | 0;
                h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
            }
            H[0] = (H[0] + a) | 0; H[1] = (H[1] + b) | 0; H[2] = (H[2] + c) | 0; H[3] = (H[3] + d) | 0;
            H[4] = (H[4] + e) | 0; H[5] = (H[5] + f) | 0; H[6] = (H[6] + g) | 0; H[7] = (H[7] + h) | 0;
        }
        var hex = '';
        for (i = 0; i < 8; i++) {
            hex += ('00000000' + (H[i] >>> 0).toString(16)).slice(-8);
        }
        return hex;
    }

    /** 64-char lowercase hex of the PoW input hash. */
    function powHashHex(input) {
        return sha256Hex(input);
    }

    /**
     * Solve a PoW challenge: find a nonce (sequential counter) such that
     * powHashHex(challenge + ':' + nonce) < target.
     * Both strings are 64 lowercase hex chars, so JS `<` on strings equals the
     * numeric comparison. Yields to the event loop every 500 tries so the UI can
     * repaint. onProgress(kHps) is called at each yield.
     * Resolves with { nonce, kHps } or null on failure (nonce space exhausted).
     */
    function solvePow(challenge, target, onProgress) {
        return new Promise(function (resolve) {
            var nonce = 0;
            var attempts = 0;
            var start = nowMs();
            var MAX_NONCE = 9999999999;

            function rate() {
                return attempts / Math.max(0.001, (nowMs() - start) / 1000) / 1000;
            }

            (function loop() {
                var localEnd = Math.min(nonce + 500, MAX_NONCE);
                while (nonce <= localEnd) {
                    attempts++;
                    if (powHashHex(challenge + ':' + nonce) < target) {
                        resolve({ nonce: String(nonce), kHps: rate() });
                        return;
                    }
                    nonce++;
                }
                if (onProgress) { onProgress(rate()); }
                if (nonce > MAX_NONCE) { resolve(null); return; }
                setTimeout(loop, 0);
            })();
        });
    }

    global.ChatAppPow = {
        hashHex: powHashHex,
        solve: solvePow
    };
})(typeof window !== 'undefined' ? window : this);
