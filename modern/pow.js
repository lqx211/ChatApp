/**
 * ChatApp - Proof-of-Work client.
 *
 * Custom byte-level hash, bit-for-bit identical to chatapp_pow_hash() in
 * api/auth.php (no public algorithm code). Only 0-255 arithmetic (add/xor/shift),
 * so there are no 32-bit signed-overflow or encoding pitfalls between PHP and JS.
 * Input is ASCII: challenge + ':' + nonce.
 */
(function (global) {
    'use strict';

    var POW_SEED = [0x24, 0x5a, 0x10, 0x9f, 0x3d, 0x77, 0x81, 0xc2, 0x4b, 0x0e, 0x96, 0x55,
                    0x1a, 0x68, 0xdc, 0x03, 0x7e, 0x92, 0x40, 0xcf, 0x11, 0x5d, 0xaa, 0x38,
                    0x66, 0xf1, 0x0b, 0x9c, 0x27, 0x74, 0xdb, 0x32];

    function nowMs() {
        return (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
    }

    /** 32 bytes of the custom hash (mirror of chatapp_pow_hash). */
    function powHashBytes(input) {
        var state = POW_SEED.slice();
        var bytes = [];
        var i, j, round;
        for (i = 0; i < input.length; i++) { bytes.push(input.charCodeAt(i) & 0xff); }
        var n = bytes.length;
        for (round = 0; round < 32; round++) {
            state[0] = (state[0] ^ (round + 1)) & 0xff;
            for (j = 0; j < 32; j++) {
                var ib = n > 0 ? bytes[(j + round) % n] : 0;
                var a = state[j];
                var b = state[(j + 7) % 32];
                var c = state[(j + 13) % 32];
                var x = ((a << 3) | (a >> 5)) & 0xff;
                x = (x + b) & 0xff;
                x = (x ^ c) & 0xff;
                x = (x ^ ib) & 0xff;
                var k = ((round * 31 + j * 7 + 11) & 0xff);
                state[j] = (x + k) & 0xff;
            }
            var t = state[0]; state[0] = state[31]; state[31] = t;
            t = state[5]; state[5] = state[21]; state[21] = t;
        }
        return state;
    }

    /** 64-char lowercase hex of the custom hash. */
    function powHashHex(input) {
        var st = powHashBytes(input);
        var hex = '';
        for (var i = 0; i < st.length; i++) {
            var h = st[i].toString(16);
            if (h.length === 1) { h = '0' + h; }
            hex += h;
        }
        return hex;
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
