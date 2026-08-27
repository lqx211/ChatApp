/**
 * ChatApp - E2EE 客户端（X3DH + Double Ratchet，Signal 级）
 *
 * M1：密钥基建。
 *  - IndexedDB 密钥库（chatapp-e2ee）：私钥只存本地，服务器永远只有公钥。
 *  - 身份密钥 IK（X25519）+ 签名密钥 sigKey（Ed25519）+ 签名预密钥 SPK（X25519，由 sigKey 签名）
 *    + 一次性预密钥池 OPK。
 *  - 注册公钥包到 api/e2ee.php；开关状态读写。
 *
 * 依赖：window.nacl（tweetnacl）+ window.nacl.util（tweetnacl-util）。
 */
(function (global) {
    'use strict';

    var DB_NAME = 'chatapp-e2ee';
    var DB_VER = 2;
    var OPK_BATCH = 100;
    var _db = null;

    /* ---------------- IndexedDB 帮助 ---------------- */
    function openDb() {
        return new Promise(function (resolve, reject) {
            if (_db) return resolve(_db);
            var req = indexedDB.open(DB_NAME, DB_VER);
            req.onupgradeneeded = function (e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains('keys')) db.createObjectStore('keys');
                if (!db.objectStoreNames.contains('sessions')) db.createObjectStore('sessions');
                if (!db.objectStoreNames.contains('sentkeys')) db.createObjectStore('sentkeys');
            };
            req.onsuccess = function () { _db = req.result; resolve(_db); };
            req.onerror = function () { reject(req.error); };
        });
    }
    function idbTx(store, mode) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(store, mode);
                tx.oncomplete = function () { resolve(); };
                tx.onerror = function () { reject(tx.error); };
                resolve(tx.objectStore(store));
            });
        });
    }
    function idbGet(store, key) {
        return idbTx(store, 'readonly').then(function (os) {
            return new Promise(function (resolve, reject) {
                var r = os.get(key);
                r.onsuccess = function () { resolve(r.result); };
                r.onerror = function () { reject(r.error); };
            });
        });
    }
    function idbPut(store, key, val) {
        return idbTx(store, 'readwrite').then(function (os) {
            return new Promise(function (resolve, reject) {
                var r = os.put(val, key);
                r.onsuccess = function () { resolve(); };
                r.onerror = function () { reject(r.error); };
            });
        });
    }

    /* ---------------- 编码 ---------------- */
    function toB64(u8) { return nacl.util.encodeBase64(u8); }
    function fromB64(s) { return nacl.util.decodeBase64(s); }
    function randomId(len) {
        var s = '';
        var bytes = nacl.randomBytes(len); // tweetnacl: randomBytes(n) 返回 n 字节
        for (var i = 0; i < bytes.length; i++) s += bytes[i].toString(16).padStart(2, '0');
        return s;
    }

    /* ---------------- 密钥 ---------------- */
    function genOPKPool(n) {
        var list = [];
        for (var i = 0; i < n; i++) {
            var kp = nacl.box.keyPair();
            list.push({ id: 'opk-' + randomId(8), pub: toB64(kp.publicKey), secret: toB64(kp.secretKey) });
        }
        return list;
    }

    /* ================================================================
     *  X3DH + Double Ratchet（Signal 级，tweetnacl 原语）
     *  私钥只存 IndexedDB；服务器只接触公钥与密文。
     * ================================================================ */

    /* ---- 字节工具 ---- */
    function utf8(s) { return nacl.util.decodeUTF8(s); }
    function cat() {
        var parts = Array.prototype.slice.call(arguments), len = 0, i, j;
        for (i = 0; i < parts.length; i++) len += parts[i].length;
        var out = new Uint8Array(len), o = 0;
        for (i = 0; i < parts.length; i++) { out.set(parts[i], o); o += parts[i].length; }
        return out;
    }

    /* ---- HMAC-SHA512 / HKDF-SHA512（基于 nacl.hash）---- */
    function hmacSha512(key, msg) {
        var block = 128;
        var k = key.length > block ? nacl.hash(key) : key;
        var kk = new Uint8Array(block);
        kk.set(k);
        var ipad = new Uint8Array(block), opad = new Uint8Array(block), i;
        for (i = 0; i < block; i++) { ipad[i] = kk[i] ^ 0x36; opad[i] = kk[i] ^ 0x5c; }
        var inner = new Uint8Array(block + msg.length);
        inner.set(ipad, 0); inner.set(msg, block);
        var ih = nacl.hash(inner);
        var outer = new Uint8Array(block + ih.length);
        outer.set(opad, 0); outer.set(ih, block);
        return nacl.hash(outer);
    }
    function hkdf(ikm, salt, info, len) {
        var prk = hmacSha512(salt, ikm);
        var out = new Uint8Array(0), t = new Uint8Array(0), i = 1;
        while (out.length < len) {
            var input = cat(t, info, new Uint8Array([i]));
            t = hmacSha512(prk, input);
            out = cat(out, t);
            i++;
        }
        return out.slice(0, len);
    }
    var ZEROS32 = new Uint8Array(32);
    var INFO_RK = utf8('chatapp-ratchet-rk-v1');
    var INFO_CK = utf8('chatapp-ratchet-ck-v1');

    /** (RK, CK) = KDF_RK(RK, DH_out) */
    function kdfRk(rk, dhOut) {
        var o = hkdf(dhOut, rk, INFO_RK, 64);
        return [o.slice(0, 32), o.slice(32, 64)];
    }
    /** (CK', MK) = KDF_CK(CK) */
    function kdfCk(ck) {
        var o = hkdf(ck, ZEROS32, INFO_CK, 64);
        return [o.slice(0, 32), o.slice(32, 64)];
    }

    /* ---- X3DH ---- */
    function x3dhInfo(ikA, ikB) { return cat(utf8('chatapp-x3dh-v1'), fromB64(ikA), fromB64(ikB)); }

    /** 发起方：校验 SPK 签名 → 计算 SK。返回 {sk, ek_pub, spk_key_id, opk_id} */
    function x3dhInitiate(identity, bundle) {
        if (!nacl.sign.detached.verify(fromB64(bundle.spk_pub), fromB64(bundle.spk_sig), fromB64(bundle.sig_pub))) {
            throw new Error('e2ee_bad_spk_sig');
        }
        var ek = nacl.box.keyPair();
        var dh1 = nacl.scalarMult(fromB64(identity.ik_secret), fromB64(bundle.spk_pub));
        var dh2 = nacl.scalarMult(ek.secretKey, fromB64(bundle.ik_pub));
        var dh3 = nacl.scalarMult(ek.secretKey, fromB64(bundle.spk_pub));
        var dh4 = bundle.opk_pub ? nacl.scalarMult(ek.secretKey, fromB64(bundle.opk_pub)) : new Uint8Array(0);
        var sk = hkdf(cat(cat(cat(dh1, dh2), dh3), dh4), ZEROS32, x3dhInfo(identity.ik_pub, bundle.ik_pub), 32);
        return { sk: sk, ek_pub: toB64(ek.publicKey), spk_key_id: bundle.spk_key_id, opk_id: bundle.opk_id || null };
    }

    /** 接收方：用 init 里的 ek/ik + 自己的 SPK/OPK 私钥重算 SK。
     *  注意 DH 组件拼接顺序必须与发起方一致：
     *    发起方 [DH(IK_A,SPK_B), DH(EK_A,IK_B), DH(EK_A,SPK_B), DH(EK_A,OPK_B)]
     *    接收方 [DH(SPK_B,IK_A), DH(IK_B,EK_A), DH(SPK_B,EK_A), DH(OPK_B,EK_A)] */
    function x3dhRespond(identity, init, spkSecret, opkSecret) {
        var dh1 = nacl.scalarMult(fromB64(spkSecret), fromB64(init.ik));
        var dh2 = nacl.scalarMult(fromB64(identity.ik_secret), fromB64(init.ek));
        var dh3 = nacl.scalarMult(fromB64(spkSecret), fromB64(init.ek));
        var dh4 = opkSecret ? nacl.scalarMult(fromB64(opkSecret), fromB64(init.ek)) : new Uint8Array(0);
        return hkdf(cat(cat(cat(dh1, dh2), dh3), dh4), ZEROS32, x3dhInfo(init.ik, identity.ik_pub), 32);
    }

    /** 发起方会话：SK → 首次棘轮（DHs 对 SPK）→ CKs；首个消息附 init。 */
    function initiatorSession(peer, identity, xr, bundle) {
        var rk = nacl.box.keyPair();
        var o = kdfRk(xr.sk, nacl.scalarMult(rk.secretKey, fromB64(bundle.spk_pub)));
        return {
            peer: peer, initiator: true,
            myIK: identity.ik_pub, theirIK: bundle.ik_pub,
            theirSPKId: xr.spk_key_id, theirOPKId: xr.opk_id,
            rootKey: toB64(o[0]),
            sendingChainKey: toB64(o[1]),
            receivingChainKey: null,
            DHs: toB64(rk.publicKey), DHsPriv: toB64(rk.secretKey),
            DHr: null, N: 0, PN: 0, Nr: 0,
            skipped: {}, created: Date.now()
        };
    }

    /** 接收方会话：重算 SK → 首次棘轮（SPK 对 header.dh）→ CKr。 */
    function responderSession(peer, identity, env, spkSecret, opkSecret) {
        var sk = x3dhRespond(identity, env.init, spkSecret, opkSecret);
        var o = kdfRk(sk, nacl.scalarMult(fromB64(spkSecret), fromB64(env.h.dh)));
        return {
            peer: peer, initiator: false,
            myIK: identity.ik_pub, theirIK: env.init.ik,
            theirSPKId: env.init.spk_id, theirOPKId: env.init.opk_id || null,
            rootKey: toB64(o[0]),
            sendingChainKey: null,
            receivingChainKey: toB64(o[1]),
            DHs: null, DHsPriv: null,
            DHr: env.h.dh, N: 0, PN: 0, Nr: 0,
            skipped: {}, created: Date.now()
        };
    }

    /* ---- Double Ratchet ---- */
    function skipKeys(sess, from, until, chainDh) {
        if (!sess.receivingChainKey) return;
        var ck = fromB64(sess.receivingChainKey);
        var i;
        for (i = from; i < until; i++) {
            var r = kdfCk(ck);
            ck = r[0];
            if (chainDh) sess.skipped[chainDh + ':' + i] = toB64(r[1]);
            if (Object.keys(sess.skipped).length > 2000) sess.skipped = {};
        }
        sess.receivingChainKey = toB64(ck);
        if (until > sess.Nr) sess.Nr = until;
    }

    function ratchetEncrypt(sess, plaintext) {
        if (!sess.sendingChainKey) {
            if (!sess.DHr) throw new Error('e2ee_no_dhr');
            var rk = nacl.box.keyPair();
            var o = kdfRk(fromB64(sess.rootKey), nacl.scalarMult(rk.secretKey, fromB64(sess.DHr)));
            sess.rootKey = toB64(o[0]);
            sess.sendingChainKey = toB64(o[1]);
            sess.DHs = toB64(rk.publicKey);
            sess.DHsPriv = toB64(rk.secretKey);
            sess.DHr = null;
            sess.PN = sess.N;
            sess.N = 0;
        }
        var header = { dh: sess.DHs, pn: sess.PN, n: sess.N };
        var r = kdfCk(fromB64(sess.sendingChainKey));
        sess.sendingChainKey = toB64(r[0]);
        var mk = r[1];
        sess.N++;
        var nonce = nacl.randomBytes(24);
        var ct = nacl.secretbox(plaintext, nonce, mk);
        return { h: header, nonce: toB64(nonce), ct: toB64(ct), mk: mk, headerKey: header.dh + ':' + header.n };
    }

    function ratchetDecrypt(sess, env) {
        var header = env.h;
        if (header.dh !== sess.DHr) {
            skipKeys(sess, sess.Nr, header.pn, sess.DHr);
            if (!sess.DHsPriv) throw new Error('e2ee_no_dhspriv');
            // DHRatchet（规范版）：先派生新接收链，再立刻轮换自己的发送棘轮（PN=Ns, Ns=0）
            var o = kdfRk(fromB64(sess.rootKey), nacl.scalarMult(fromB64(sess.DHsPriv), fromB64(header.dh)));
            sess.rootKey = toB64(o[0]);
            sess.receivingChainKey = toB64(o[1]);
            sess.DHr = header.dh;
            sess.Nr = 0;
            var rk2 = nacl.box.keyPair();
            var o2 = kdfRk(fromB64(sess.rootKey), nacl.scalarMult(rk2.secretKey, fromB64(sess.DHr)));
            sess.rootKey = toB64(o2[0]);
            sess.sendingChainKey = toB64(o2[1]);
            sess.DHs = toB64(rk2.publicKey);
            sess.DHsPriv = toB64(rk2.secretKey);
            sess.PN = sess.N;
            sess.N = 0;
        }
        skipKeys(sess, sess.Nr, header.n, sess.DHr);
        var lookup = sess.DHr + ':' + header.n, key;
        if (sess.skipped[lookup]) {
            key = fromB64(sess.skipped[lookup]);
            delete sess.skipped[lookup];
        } else {
            var r = kdfCk(fromB64(sess.receivingChainKey));
            sess.receivingChainKey = toB64(r[0]);
            key = r[1];
            sess.Nr = header.n + 1;
        }
        var pt = nacl.secretbox.open(fromB64(env.ct), fromB64(env.nonce), key);
        if (!pt) throw new Error('e2ee_decrypt_fail');
        return pt;
    }

    /* ---- 会话 API ---- */
    /** 发起方：拉对方 bundle → X3DH → 建会话（未持久化，由 encrypt 落盘）。 */
    function ensureSession(peer, identity) {
        return apiGet({ action: 'get_bundle', username: peer }).then(function (b) {
            if (!b || !b.success) throw new Error('e2ee_no_bundle');
            var xr = x3dhInitiate(identity, b);
            var sess = initiatorSession(peer, identity, xr, b);
            sess._x3dhEk = xr.ek_pub;
            sess.theirSPKId = xr.spk_key_id;
            sess.theirOPKId = xr.opk_id;
            return sess;
        });
    }

    /** 接收方：按 init 找到 SPK/OPK 私钥 → 建会话（一次性 OPK 用过即焚）。 */
    function buildResponder(peer, identity, env) {
        return idbGet('keys', 'spks').then(function (spks) {
            spks = spks || {};
            var spkSecret = (spks[env.init.spk_id] && spks[env.init.spk_id].secret) || identity.spk_secret;
            if (!spkSecret) throw new Error('e2ee_no_spk');
            return idbGet('keys', 'opks').then(function (opks) {
                opks = opks || {};
                var opkSecret = null;
                if (env.init.opk_id && opks[env.init.opk_id]) {
                    opkSecret = opks[env.init.opk_id];
                    delete opks[env.init.opk_id];
                    return idbPut('keys', 'opks', opks).then(function () {
                        return responderSession(peer, identity, env, spkSecret, opkSecret);
                    });
                }
                return responderSession(peer, identity, env, spkSecret, opkSecret);
            });
        });
    }

    /** 加密一条消息。返回 envelope 对象（JSON.stringify 后作为 messages.message，msg_type='e2ee'）。 */
    function encrypt(peer, plaintext, isMarkdown) {
        var pt = utf8(plaintext);
        return idbGet('keys', 'identity').then(function (identity) {
            if (!identity) throw new Error('e2ee_no_identity');
            return idbGet('sessions', peer).then(function (sess) {
                var isNew = !sess, initData = null;
                var p = isNew
                    ? ensureSession(peer, identity).then(function (s) {
                        sess = s;
                        initData = { ik: identity.ik_pub, ek: sess._x3dhEk, spk_id: sess.theirSPKId, opk_id: sess.theirOPKId };
                        return sess;
                    })
                    : Promise.resolve(sess);
                return p.then(function (s) {
                    var rr = ratchetEncrypt(s, pt);
                    return idbGet('sentkeys', peer).then(function (sent) {
                        sent = sent || {};
                        sent[rr.headerKey] = toB64(rr.mk);
                        var ks = Object.keys(sent);
                        if (ks.length > 2000) delete sent[ks[0]];
                        return idbPut('sentkeys', peer, sent);
                    }).then(function () {
                        return idbPut('sessions', peer, s);
                    }).then(function () {
                        var env = { v: 1, h: rr.h, nonce: rr.nonce, ct: rr.ct, md: isMarkdown ? 1 : 0 };
                        if (initData) env.init = initData;
                        return env;
                    });
                });
            });
        });
    }

    /** 解密一条 e2ee 消息（envelope 文本或对象）。返回 {plaintext, isMarkdown}。 */
    function decrypt(peer, envelopeText) {
        var env = (typeof envelopeText === 'string') ? (function () { try { return JSON.parse(envelopeText); } catch (e) { return null; } })() : envelopeText;
        if (!env || env.v !== 1 || !env.h || !env.ct) return Promise.reject(new Error('e2ee_bad_env'));
        var hk = env.h.dh + ':' + env.h.n;
        return Promise.all([idbGet('keys', 'identity'), idbGet('sessions', peer), idbGet('sentkeys', peer)]).then(function (r) {
            var identity = r[0], sess = r[1], sent = r[2] || {};
            if (!identity) throw new Error('e2ee_no_identity');
            // 1) 自己发的消息：用存储的消息密钥直解
            if (sent[hk]) {
                var pt = nacl.secretbox.open(fromB64(env.ct), fromB64(env.nonce), fromB64(sent[hk]));
                if (!pt) throw new Error('e2ee_decrypt_fail');
                return { plaintext: nacl.util.encodeUTF8(pt), isMarkdown: !!env.md };
            }
            // 2) 现有会话解密
            if (sess) {
                try {
                    var pt2 = ratchetDecrypt(sess, env);
                    return idbPut('sessions', peer, sess).then(function () {
                        return { plaintext: nacl.util.encodeUTF8(pt2), isMarkdown: !!env.md };
                    });
                } catch (e) {
                    if (!env.init) throw e;
                    // 已有会话但解不开 + 带 init → 按 init 重建 responder 会话（双方同时首发等）
                }
            }
            // 3) 无会话 / 需重建：必须是 init 消息
            if (!env.init) throw new Error('e2ee_no_session');
            return buildResponder(peer, identity, env).then(function (newSess) {
                var pt3 = ratchetDecrypt(newSess, env);
                return idbPut('sessions', peer, newSess).then(function () {
                    return { plaintext: nacl.util.encodeUTF8(pt3), isMarkdown: !!env.md };
                });
            });
        });
    }

    function apiPost(params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch('../../api/e2ee.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }
    function apiGet(params) {
        var qs = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
        return fetch('../../api/e2ee.php?' + qs).then(function (r) { return r.json(); });
    }

    /** 确保身份密钥存在并已注册；返回身份对象。
     *  已有本地身份时，若服务器上没有同 key_id 的登记（丢失/被覆盖）则重发。 */
    function ensureIdentity() {
        return idbGet('keys', 'identity').then(function (existing) {
            if (existing && existing.ik_secret) {
                return apiGet({ action: 'my_keys' }).then(function (d) {
                    if (d && d.success && d.has_keys && d.key_id === existing.key_id) return existing;
                    return registerKeys(existing).then(function () { return existing; });
                }).catch(function () { return existing; });
            }
            var ik = nacl.box.keyPair();
            var sig = nacl.sign.keyPair();
            var spk = nacl.box.keyPair();
            var spkSig = nacl.sign.detached(spk.publicKey, sig.secretKey);
            var identity = {
                ik_secret: toB64(ik.secretKey),
                ik_pub: toB64(ik.publicKey),
                sig_secret: toB64(sig.secretKey),
                sig_pub: toB64(sig.publicKey),
                spk_secret: toB64(spk.secretKey),
                spk_pub: toB64(spk.publicKey),
                spk_sig: toB64(spkSig),
                spk_key_id: 'spk-' + randomId(8),
                key_id: 'ik-' + randomId(8)
            };
            // 记录 SPK 历史（按 id），让接收方能解密发给任何已发布 SPK 的消息
            return idbGet('keys', 'spks').then(function (spks) {
                spks = spks || {};
                spks[identity.spk_key_id] = { secret: identity.spk_secret, pub: identity.spk_pub };
                return idbPut('keys', 'spks', spks);
            }).then(function () {
                return idbPut('keys', 'identity', identity);
            }).then(function () {
                return registerKeys(identity);
            }).then(function () {
                return identity;
            });
        });
    }

    /** 把公钥包（含 OPK 池）注册/更新到服务器；OPK 私钥留本地。 */
    function registerKeys(identity) {
        var opks = genOPKPool(OPK_BATCH);
        var opkMap = {};
        opks.forEach(function (o) { opkMap[o.id] = o.secret; });
        return idbPut('keys', 'opks', opkMap).then(function () {
            var body = new URLSearchParams();
            body.append('action', 'register_keys');
            body.append('key_id', identity.key_id);
            body.append('ik_pub', identity.ik_pub);
            body.append('sig_pub', identity.sig_pub);
            body.append('spk_pub', identity.spk_pub);
            body.append('spk_sig', identity.spk_sig);
            body.append('spk_key_id', identity.spk_key_id);
            opks.forEach(function (o, i) {
                body.append('opks[' + i + '][id]', o.id);
                body.append('opks[' + i + '][pub]', o.pub);
            });
            return fetch('../../api/e2ee.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (r) { return r.json(); });
        });
    }

    /* ---------------- 状态（每对话） ---------------- */

    /** 我在与 peer 的对话里是否开启 E2EE。 */
    function getStatus(peer) {
        return apiGet({ action: 'get_status', username: peer }).then(function (d) {
            return !!(d && d.success && d.enabled);
        });
    }

    /** 对方视角：同一对话的共享状态（与 getStatus 相同值，兼容旧调用方）。 */
    function getPartnerStatus(peer) {
        return getStatus(peer);
    }

    /** 开关某个对话的 E2EE（套用于对话：任一方开/关即整体开/关）：
     *  开 → 先确保密钥注册；随后通知对方本对话状态变化。 */
    function setStatus(on, peer) {
        var p = on
            ? ensureIdentity().then(function () { return apiPost({ action: 'set_status', username: peer, on: '1' }); })
            : apiPost({ action: 'set_status', username: peer, on: '0' });
        return p.then(function (d) {
            if (d && d.success) {
                return apiPost({ action: 'notify_status', username: peer, on: on ? '1' : '0' }).then(function (n) {
                    if (n && n.success && n.message_id) d.message_id = n.message_id;
                    return d;
                }).catch(function () { return d; });
            }
            return d;
        });
    }

    /* ---------------- 安全码（WhatsApp 式 60 位比对） ---------------- */
    /** 双方身份公钥的确定性指纹 → 60 位数字（5 位一组）。双方一致 = 无中间人/冒充。
     *  注意：同浏览器多账号共享 IndexedDB 会算错；不同浏览器/设备各自算自己的才正确。 */
    function safetyNumber(peer) {
        return idbGet('keys', 'identity').then(function (identity) {
            if (!identity || !identity.ik_pub) throw new Error('e2ee_no_identity');
            return apiGet({ action: 'get_bundle', username: peer }).then(function (b) {
                if (!b || !b.success || !b.ik_pub) throw new Error('e2ee_no_bundle');
                var mine = identity.ik_pub, theirs = b.ik_pub;
                var a = mine < theirs ? mine : theirs;
                var z = mine < theirs ? theirs : mine;
                var ua = fromB64(a), ub = fromB64(z);
                var bytes = new Uint8Array(ua.length + ub.length);
                bytes.set(ua, 0);
                bytes.set(ub, ua.length);
                var h = nacl.hash(bytes); // SHA-512
                var hex = '';
                for (var i = 0; i < h.length; i++) hex += h[i].toString(16).padStart(2, '0');
                var digits = (BigInt('0x' + hex) % (10n ** 60n)).toString().padStart(60, '0');
                var parts = [];
                for (var j = 0; j < 12; j++) parts.push(digits.substr(j * 5, 5));
                return parts.join(' ');
            });
        });
    }

    /* ---------------- 初始化 ---------------- */
    function init() {
        if (!global.nacl || !global.nacl.util || !global.indexedDB) return Promise.resolve({ ok: false, reason: 'no-crypto' });
        return ensureIdentity().catch(function () { /* 生成失败不阻塞 */ }).then(function () { return { ok: true }; });
    }

    global.E2EE = {
        init: init,
        ensureIdentity: ensureIdentity,
        setStatus: setStatus,
        getStatus: getStatus,
        getPartnerStatus: getPartnerStatus,
        safetyNumber: safetyNumber,
        encrypt: encrypt,
        decrypt: decrypt,
        _internal: { idbGet: idbGet, idbPut: idbPut }
    };
})(typeof window !== 'undefined' ? window : this);
