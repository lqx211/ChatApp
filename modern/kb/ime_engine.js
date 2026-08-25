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

    /* ---------- 音节候选：单字母无精确词条时用前缀联想顶替（w → 我/为/玩…） ---------- */
    function syllableCandidates(seq) {
        if (seq.length === 1) {
            var m = getTargetMappings(seq);
            if (m.length) return m;
            return abbrevCandidates(seq[0]);
        }
        return getTargetMappings(seq);
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
        return { word: words.join(''), prob: dp[n] };
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

        // A) 完整拼音：整句/整词候选优先
        if (complete) {
            // 整串词典词组（nihao→你好）
            var full = getTargetMappings(s);
            for (var i = 0; i < full.length && out.length < maxResults; i++) add(full[i]);

            if (s.length > 1) {
                // 最优分词整句（woxianzaihenkaixin → 我现在很开心）
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

                // 单字候选（跨音节按词频排序）
                var singles = [], seenS = {};
                for (var u = 0; u < perSyllable.length; u++) {
                    for (var v = 0; v < perSyllable[u].length; v++) {
                        var w = perSyllable[u][v];
                        if (w.word && !seenS[w.word]) { seenS[w.word] = true; singles.push(w); }
                    }
                }
                singles.sort(function (a, b) { return (b.prob || 0) - (a.prob || 0); });
                for (var q = 0; q < singles.length && out.length < maxResults; q++) add(singles[q]);
            }
        }

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

        // 按概率降序（含用户习惯加权后重排）
        out.sort(function (a, b) { return (b.prob || 0) - (a.prob || 0); });
        return out.slice(0, maxResults);
    }

    return { load: load, isReady: isReady, decode: decode, segment: segmentPinyin, setLearning: setLearning, bumpLearning: bumpLearning, getLearning: getLearning };
})();
