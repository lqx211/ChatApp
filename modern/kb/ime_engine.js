/* ============================================================
   ChatApp 拼音输入法引擎
   读取 Google Pinyin data（Apache 2.0 许可，来自 google-input-tools）
   data 格式：
     sourceMap      {拼音: [Huffman码, 码长]}   （编码表）
     sourceSegments  排序后的源词 Huffman 码数组（二分查找定位）
     targetPositions 每个源词对应的目标词区间起点
     targetSegments  目标词 Huffman 码数组
     targetMap       目标词 Huffman 解码树
     targetProbs     目标词概率
     defaultProb     概率归一化基数
     chosTokens      所有合法拼音音节（| 分隔）
   本文件是干净重写的解码器（不依赖 Google 的 closure 框架）。
   ============================================================ */
var ImePinyin = (function () {
    var _ready = false;
    var _d = null; // {sourceMap, targetMap, sourceSegments, targetSegments, targetPositions, targetProbs, defaultProb, chosTokens[]}
    var _learn = {}; // 用户习惯：word -> {pinyin, count, is_custom}
    // 模糊音 + 错别字容错配置（on: 模糊音；typo: 错别字；penalty 越靠近 0 越靠后）
    var _fuzzy = {
        on: true,
        typo: true,
        penalty: 0.85,      // 模糊音：轻微偏向精确，但仍按真实词频竞争（否则 是/中/中国 永远排不上）
        typoPenalty: 0.6,   // 错别字容错：编辑距离修正，更靠后
        rules: {
            'z': ['zh'], 'zh': ['z'],
            'c': ['ch'], 'ch': ['c'],
            's': ['sh'], 'sh': ['s'],
            'n': ['l'], 'l': ['n'],
            'r': ['l'], 'l': ['r'],
            'f': ['h'], 'h': ['f'],
            'k': ['g'], 'g': ['k'],
            'an': ['ang'], 'ang': ['an'],
            'en': ['eng'], 'eng': ['en'],
            'in': ['ing'], 'ing': ['in'],
            'ian': ['iang'], 'iang': ['ian'],
            'uan': ['uang'], 'uang': ['uan']
        }
    };
    function setFuzzy(on) { _fuzzy.on = !!on; }
    function isFuzzy() { return _fuzzy.on; }

    /* ---------- 加载词典（懒加载：动态 script 标签，8MB 一次性） ---------- */
    function load(cb) {
        if (_ready) { if (cb) cb(true); return; }
        var s = document.createElement('script');
        // 相对 modern/wp/ 的路径（与 chat.php 引用 ../style ../scripts 一致）
        s.src = '../kb/data/pinyin_data.js';
        s.onload = function () {
            _d = {
                sourceMap: window.sourceMap || null,
                targetMap: window.targetMap || null,
                sourceSegments: window.sourceSegments || null,
                targetSegments: window.targetSegments || null,
                targetPositions: window.targetPositions || null,
                targetProbs: window.targetProbs || null,
                defaultProb: Number(window.defaultProb) || 1,
                chosTokens: String(window.chosTokens || '').split('|')
            };
            // 音节集合（用于切分 + 前缀联想）
            _d.syllableSet = {};
            for (var i = 0; i < _d.chosTokens.length; i++) _d.syllableSet[_d.chosTokens[i]] = true;
            _ready = !!(_d.sourceMap && _d.targetMap && _d.sourceSegments && _d.targetSegments);
            if (cb) cb(_ready);
        };
        s.onerror = function () { if (cb) cb(false); };
        document.head.appendChild(s);
    }
    function isReady() { return _ready; }

    /* ---------- 64位编码：拼音列表 → 与 Google Long.toNumber() 一致的 Number ---------- */
    function longToNumber(big) {
        var low32 = Number(big & 0xFFFFFFFFn);            // 无符号低32位
        var highRaw = Number((big >> 32n) & 0xFFFFFFFFn); // 无符号高32位
        var highSigned = highRaw >= 0x80000000 ? highRaw - 0x100000000 : highRaw;
        return highSigned * 4294967296 + low32;           // 与 Google Long.toNumber() 相同表示
    }
    function encodeTokens(tokens) {
        var map = _d.sourceMap;
        var bufferArray = [];
        var buffer = 0n, sign = 1n, currentSize = 0;
        for (var i = 0; i < tokens.length; i++) {
            var token = tokens[i];
            if (!Object.prototype.hasOwnProperty.call(map, token)) return 0;
            var enc_bit = BigInt(map[token][0]);
            var enc_length = map[token][1];
            if (currentSize + enc_length >= 63) {
                bufferArray.unshift(longToNumber(buffer | (sign << BigInt(currentSize))));
                buffer = enc_bit;
                currentSize = enc_length;
            } else {
                buffer = buffer | (enc_bit << BigInt(currentSize));
                currentSize += enc_length;
            }
        }
        if (currentSize > 0) {
            bufferArray.unshift(longToNumber(buffer | (sign << BigInt(currentSize))));
        }
        return bufferArray.length === 1 ? bufferArray[0] : bufferArray;
    }

    /* ---------- 解码：Huffman 码 → 汉字 ---------- */
    function decodeTarget(num) {
        var map = _d.targetMap;
        if (Array.isArray(num)) {
            var str = '';
            for (var i = 0; i < num.length; i++) str = decodeTarget(num[i]) + str;
            return str;
        }
        var str = '', pos = map, v = num;
        while (v !== 1) {
            var bit = v % 2;
            v = (v - bit) / 2;
            pos = pos[bit];
            if (!Array.isArray(pos)) { str = str + pos; pos = map; }
        }
        return str;
    }

    /* ---------- 比较（与 Google compareFn 一致，供二分查找） ---------- */
    function compareFn(val1, val2) {
        if (Array.isArray(val1) && Array.isArray(val2)) {
            if (val1.length === val2.length) {
                for (var i = 0; i < val1.length; i++) {
                    if (val1[i] < val2[i]) return -1;
                    if (val1[i] > val2[i]) return 1;
                }
                return 0;
            }
            return val1.length - val2.length;
        }
        if (Array.isArray(val1)) return 1;
        if (Array.isArray(val2)) return -1;
        return val1 - val2;
    }
    // 二分查找：找到返回索引，否则返回负的插入点
    function binarySearch(arr, target) {
        var lo = 0, hi = arr.length - 1;
        while (lo <= hi) {
            var mid = (lo + hi) >> 1;
            var c = compareFn(arr[mid], target);
            if (c === 0) return mid;
            if (c < 0) lo = mid + 1; else hi = mid - 1;
        }
        return -(lo + 1);
    }

    /* ---------- 源词定位 → 目标词区间 ---------- */
    function getTargetPos(source) {
        var idx = binarySearch(_d.sourceSegments, encodeTokens(source));
        if (idx < 0) return { start: 0, end: -1 };
        var start = _d.targetPositions[idx];
        var end = idx < _d.targetPositions.length - 1 ? _d.targetPositions[idx + 1] : _d.targetSegments.length;
        return { start: start, end: end };
    }

    /* ---------- 取目标词映射（某组拼音 → 候选） ---------- */
    function getTargetMappings(tokens) {
        var source = tokens; // 单路径（本引擎直接给确定音节序列）
        var pos = getTargetPos(source);
        var out = [];
        for (var i = pos.start; i < pos.end && i < _d.targetSegments.length; i++) {
            var word = decodeTarget(_d.targetSegments[i]);
            var prob = _d.targetProbs[i];
            out.push({ word: word, prob: prob === undefined ? 1 : prob / _d.defaultProb });
        }
        return out;
    }

    /* ---------- 拼音切分为音节（贪心最长匹配 chosTokens；' 为音节分隔符，如 xi'an、w'q） ---------- */
    function segmentPinyin(py) {
        var set = _d.syllableSet || {};
        var res = [];
        var parts = String(py || '').split("'");
        for (var p = 0; p < parts.length; p++) {
            var part = parts[p];
            if (!part) continue; // 空段（连续撇号）跳过
            var i = 0, n = part.length;
            while (i < n) {
                var matched = null, ml = 0;
                // 最长匹配（最多 6 个字母：zhuang/chuang/shuang 等）
                for (var len = Math.min(6, n - i); len >= 1; len--) {
                    var sub = part.substr(i, len);
                    if (set[sub]) { matched = sub; ml = len; break; }
                }
                if (!matched) { res.push(part.charAt(i)); i++; continue; }
                res.push(matched); i += ml;
            }
        }
        return res;
    }

    // 是否完整拼音（每个字都是合法音节；忽略 ' 分隔符）
    function isComplete(py) {
        var set = _d.syllableSet || {};
        var s = segmentPinyin(py);
        var clean = String(py || '').replace(/'/g, '');
        if (s.join('') !== clean) return false;
        for (var i = 0; i < s.length; i++) {
            if (!set[s[i]]) return false;
        }
        return true;
    }

    /* ---------- 拼音前缀联想（简拼/首字母）：w → 我(wo)/哇(wa)/玩(wan)/挖(wa) ---------- */
    function abbrevCandidates(prefix) {
        var out = [], seen = {};
        var toks = _d.chosTokens;
        // 收集所有匹配前缀音节的候选（不按音节顺序填满，避免最长音节挤掉常用字）
        for (var i = 0; i < toks.length; i++) {
            var s = toks[i];
            if (s.length > prefix.length && s.indexOf(prefix) === 0) {
                var m = getTargetMappings([s]);
                for (var j = 0; j < m.length; j++) {
                    if (m[j].word && !seen[m[j].word]) { seen[m[j].word] = true; out.push(m[j]); }
                }
            }
        }
        // 按词频全局排序，取前 12（decode 再截断）
        out.sort(function (a, b) { return (b.prob || 0) - (a.prob || 0); });
        return out.slice(0, 12);
    }

    /* ---------- 模糊音 / 错别字容错 ---------- */
    // 音节的模糊音变体（应用规则，只保留合法音节）：zong→zhong、si→shi…
    function fuzzyVariants(syl) {
        if (!_fuzzy.on) return [];
        var out = [], seen = {}; seen[syl] = 1;
        var queue = [syl];
        while (queue.length) {
            var cur = queue.shift();
            for (var a in _fuzzy.rules) {
                if (!Object.prototype.hasOwnProperty.call(_fuzzy.rules, a)) continue;
                var idx = cur.indexOf(a);
                if (idx < 0) continue;
                var alts = _fuzzy.rules[a];
                for (var b = 0; b < alts.length; b++) {
                    var rep = cur.slice(0, idx) + alts[b] + cur.slice(idx + a.length);
                    if (!seen[rep] && _d.syllableSet[rep]) { seen[rep] = 1; out.push(rep); queue.push(rep); }
                }
            }
        }
        return out;
    }
    // 编辑距离 ≤1（含相邻换位 Damerau）—— 错别字容错
    function isOneEdit(a, b) {
        var la = a.length, lb = b.length;
        if (la === lb) {
            var diff = 0, i;
            for (i = 0; i < la; i++) if (a[i] !== b[i]) diff++;
            if (diff === 1) return true;
            for (i = 0; i < la - 1; i++) {
                if (a[i] !== b[i] && a[i] === b[i + 1] && a[i + 1] === b[i] &&
                    a.slice(0, i) === b.slice(0, i) && a.slice(i + 2) === b.slice(i + 2)) return true;
            }
            return false;
        }
        if (Math.abs(la - lb) === 1) {
            var s = la < lb ? a : b, l = la < lb ? b : a, li = 0;
            for (var j = 0; j < l.length && li < s.length; j++) if (s[li] === l[j]) li++;
            return li === s.length;
        }
        return false;
    }
    // 与音节编辑距离=1 的合法音节（错别字容错候选）：xie→xei…
    function typoVariants(syl) {
        if (!_fuzzy.on || !_fuzzy.typo) return [];
        var out = [];
        var toks = _d.chosTokens;
        for (var i = 0; i < toks.length; i++) {
            if (toks[i] === syl) continue;
            if (isOneEdit(syl, toks[i])) out.push(toks[i]);
        }
        return out;
    }
    // 某音节的模糊/容错候选（fz=1 标记近似结果，UI 可加 ≈）
    function fuzzyCandidates(syl, includeTypo) {
        var seen = {}, out = [];
        function addFrom(syl2, pen) {
            var m = getTargetMappings([syl2]);
            for (var k = 0; k < m.length; k++) {
                if (m[k].word && !seen[m[k].word]) { seen[m[k].word] = 1; out.push({ word: m[k].word, prob: (m[k].prob || 0) * pen, fz: 1 }); }
            }
        }
        var fv = fuzzyVariants(syl);
        for (var i = 0; i < fv.length; i++) addFrom(fv[i], _fuzzy.penalty);
        if (includeTypo) {
            var tv = typoVariants(syl);
            for (var j = 0; j < tv.length; j++) addFrom(tv[j], _fuzzy.typoPenalty);
        }
        out.sort(function (a, b) { return (b.prob || 0) - (a.prob || 0); });
        return out.slice(0, 12);
    }
    // 多音节无精确词组时的变体组合（有限枚举，如 zongguo → zhong+guo=中国）
    // useTypo=false 只做模糊音（推荐）：避免 zongguo→gong+zuo=工作 这种错别字假阳性把 中国 挤掉
    function variantCombos(seq, useTypo) {
        var sets = [];
        for (var i = 0; i < seq.length; i++) {
            var st = [seq[i]].concat(fuzzyVariants(seq[i])).concat(useTypo ? typoVariants(seq[i]) : []);
            sets.push(st.slice(0, 4));
        }
        var combos = [[]];
        for (var s = 0; s < sets.length; s++) {
            var next = [];
            for (var c = 0; c < combos.length && next.length <= 64; c++) {
                for (var v = 0; v < sets[s].length; v++) next.push(combos[c].concat([sets[s][v]]));
            }
            combos = next;
        }
        var seen = {}, out = [];
        for (var k = 0; k < combos.length; k++) {
            var cseq = combos[k];
            if (cseq.join('') === seq.join('')) continue;
            var m = getTargetMappings(cseq);
            for (var j = 0; j < m.length && out.length < 12; j++) {
                if (m[j].word && !seen[m[j].word]) { seen[m[j].word] = 1; out.push({ word: m[j].word, prob: (m[j].prob || 0) * _fuzzy.penalty, fz: 1 }); }
            }
        }
        out.sort(function (a, b) { return (b.prob || 0) - (a.prob || 0); });
        return out;
    }

    /* ---------- 音节候选：精确优先 → 模糊/容错 → 单字母前缀联想 ---------- */
    function syllableCandidates(seq) {
        if (seq.length === 1) {
            var m = getTargetMappings(seq);
            if (m.length) {
                // 精确音节有候选：合并模糊音（带惩罚，按概率重排，否则精确占满前 9 名模糊永远排不上）
                if (_fuzzy.on) {
                    var fz = fuzzyCandidates(seq[0], false);
                    if (fz.length) {
                        var seenM = {}, merged = [];
                        for (var i = 0; i < m.length; i++) { if (!seenM[m[i].word]) { seenM[m[i].word] = 1; merged.push(m[i]); } }
                        for (var j = 0; j < fz.length; j++) { if (!seenM[fz[j].word]) { seenM[fz[j].word] = 1; merged.push(fz[j]); } }
                        merged.sort(function (a, b) { return (b.prob || 0) - (a.prob || 0); });
                        return merged;
                    }
                }
                return m;
            }
            // 单字母（w/h/z…）：不套模糊/容错（太噪），直接用前缀联想
            if (seq[0].length === 1) return abbrevCandidates(seq[0]);
            // 多字母音节无候选：模糊/容错 → 再退回前缀联想
            var fc = _fuzzy.on ? fuzzyCandidates(seq[0], true) : [];
            if (fc.length) return fc;
            return abbrevCandidates(seq[0]);
        }
        // 多音节：精确词组优先；2 音节合并模糊词组（zongguo→中国），3-4 音节仅精确为空时回退
        var exact = getTargetMappings(seq);
        if (_fuzzy.on && seq.length === 2) {
            var cb2 = variantCombos(seq, false);
            if (cb2.length) {
                var seenX = {}, merged = [];
                for (var a = 0; a < exact.length; a++) { if (!seenX[exact[a].word]) { seenX[exact[a].word] = 1; merged.push(exact[a]); } }
                for (var b = 0; b < cb2.length; b++) { if (!seenX[cb2[b].word]) { seenX[cb2[b].word] = 1; merged.push(cb2[b]); } }
                merged.sort(function (x, y) { return (y.prob || 0) - (x.prob || 0); });
                return merged;
            }
        }
        if (exact.length) return exact;
        if (_fuzzy.on && seq.length <= 4) {
            var cb = variantCombos(seq, true);
            if (cb.length) return cb;
        }
        return [];
    }

    /* ---------- 最优分词（Viterbi）：倾向用词典词组，组合成整句候选 ---------- */
    function bestSegmentation(s) {
        var n = s.length;
        var dp = new Array(n + 1);
        var back = new Array(n + 1); // {start, word}
        dp[0] = 1;
        for (var i = 1; i <= n; i++) dp[i] = -1;
        for (var i = 0; i < n; i++) {
            if (dp[i] < 0) continue;
            for (var len = 1; len <= Math.min(4, n - i); len++) {
                var seq = s.slice(i, i + len);
                var m = syllableCandidates(seq);
                if (!m.length) continue;
                var c = m[0];
                var score = dp[i] * (c.prob || 0.0001); // 概率相乘：倾向少而长的词组
                if (score > dp[i + len]) {
                    dp[i + len] = score;
                    back[i + len] = { start: i, word: c.word };
                }
            }
        }
        if (dp[n] < 0) return null;
        var words = [], pos = n;
        while (pos > 0) {
            var b = back[pos];
            if (!b) return null;
            words.unshift(b.word);
            pos = b.start;
        }
        return { word: words.join(''), prob: Math.pow(dp[n], 1 / words.length), full: true };
    }

    /* ---------- 用户习惯：加载/记录/查询 ---------- */
    function setLearning(items) {
        _learn = {};
        if (Array.isArray(items)) {
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                if (it && it.word) _learn[it.word] = { pinyin: it.pinyin || '', count: it.count || 1, is_custom: it.is_custom || 0 };
            }
        }
    }
    function bumpLearning(word, pinyin) {
        if (!word) return;
        var l = _learn[word] || { pinyin: pinyin || '', count: 0, is_custom: 0 };
        l.count += 1;
        if (pinyin) l.pinyin = pinyin;
        _learn[word] = l;
    }
    function getLearning() { return _learn; }

    /* ---------- 主入口：拼音字符串 → 候选数组 [{word, prob}] ---------- */
    function decode(py, maxResults) {
        maxResults = maxResults || 9;
        // 保留撇号 '（音节分隔符，如 xi'an、w'q），去掉其它非字母
        py = String(py || '').toLowerCase().replace(/[^a-z']/g, '');
        if (!py) return [];
        var complete = isComplete(py);
        var s = segmentPinyin(py);
        var out = [], seen = {};

        function add(c) { if (c && c.word && !seen[c.word]) { seen[c.word] = true; out.push(c); } }

        // A) 完整拼音才有整串词典词组（存在非法音节时无法整体映射：nihao→你好）
        if (complete) {
            var full = getTargetMappings(s);
            // 单音节时给模糊/容错候选留 ~4 个位（否则精确填满 9 名，模糊永远排不上：zong 里 中/重 出不来）
            var aCap = s.length === 1 ? Math.max(3, maxResults - 4) : maxResults;
            for (var i = 0; i < full.length && out.length < aCap; i++) {
                // 整串多字词组：完整覆盖所有拼音 → 排序时加权置顶（否则被高频单字淹没）
                if (full[i].word && full[i].word.length >= 2) full[i].full = true;
                add(full[i]);
            }
        }

        if (s.length > 1) {
            // 最优分词整句（syllableCandidates 已模糊/容错感知：woxianzaihenkaixin→我现在很开心）
            var best = bestSegmentation(s);
            if (best && best.word.length >= 2) add(best);

            // 每音节 top1 连成句（作为候选）
            var perSyllable = [];
            for (var k = 0; k < s.length; k++) perSyllable.push(syllableCandidates([s[k]]).slice(0, 3));
            var allTop = '', pr = 1;
            for (var t = 0; t < perSyllable.length; t++) {
                if (perSyllable[t][0]) { allTop += perSyllable[t][0].word; pr *= perSyllable[t][0].prob; }
            }
            if (allTop && allTop.length >= 2) add({ word: allTop, prob: pr });
        }

        // 单字候选（跨音节按词频排序；无论完整与否，模糊/容错都能出）
        var singles = [], seenS = {};
        for (var u = 0; u < s.length; u++) {
            var per = syllableCandidates([s[u]]).slice(0, 9);
            for (var v = 0; v < per.length; v++) {
                var w = per[v];
                if (w.word && !seenS[w.word]) { seenS[w.word] = true; singles.push(w); }
            }
        }
        singles.sort(function (a, b) { return (b.prob || 0) - (a.prob || 0); });
        for (var q = 0; q < singles.length && out.length < maxResults; q++) add(singles[q]);

        // B) 拼音前缀联想（简拼/首字母）：w → 我/为/玩…
        if (out.length < maxResults) {
            var ab = abbrevCandidates(py);
            for (var r = 0; r < ab.length && out.length < maxResults; r++) add(ab[r]);
        }

        // C) 用户习惯：词频加权（常用词排前）+ 注入自造词
        var norm = py.replace(/'/g, '');
        for (var li = 0; li < out.length; li++) {
            var lv = _learn[out[li].word];
            if (lv) out[li].prob = (out[li].prob || 0.0001) * (1 + Math.log(1 + lv.count));
        }
        for (var lw in _learn) {
            if (Object.prototype.hasOwnProperty.call(_learn, lw) && _learn[lw].is_custom) {
                var lp = String(_learn[lw].pinyin || '').replace(/'/g, '');
                if (lp && lp === norm && !seen[lw]) out.push({ word: lw, prob: 0.6 + _learn[lw].count * 0.1 });
            }
        }

        // 按「有效分数」降序：整句/整词（full，覆盖完整拼音）加权置顶，避免被高频单字淹没
        var FULL_BOOST = 8;
        out.sort(function (a, b) {
            var sa = (a.prob || 0) * (a.full ? FULL_BOOST : 1);
            var sb = (b.prob || 0) * (b.full ? FULL_BOOST : 1);
            return sb - sa;
        });
        return out.slice(0, maxResults);
    }

    return { load: load, isReady: isReady, decode: decode, segment: segmentPinyin, setLearning: setLearning, bumpLearning: bumpLearning, getLearning: getLearning, setFuzzy: setFuzzy, isFuzzy: isFuzzy };
})();
