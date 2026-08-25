/**
 * obfuscator.js - MIDI 混淆引擎
 *
 * 核心目标：播放效果 100% 不变，但把旋律打散成 10 条看似混乱的轨道，
 * 让扒谱者无法直接提取原旋律。
 *
 * 设计原则（关键）：
 * - MIDI 播放语义由"事件流集合"决定（noteOn/noteOff 的 tick、channel、note、velocity）。
 *   只要这些事件集合完全保留，无论分配到哪条轨道、通道如何重排，播放结果必然一致。
 * - 因此混淆只做"事件集合不变"的重新分配：
 *   1. 每个 noteOn 按通道轮转 + 通道偏移分配到 10 条轨道（相邻旋律不同轨）
 *   2. noteOff 用独立轮转 + 另一偏移（与 noteOn 不同轨，防配对扒谱）
 *   3. programChange 放在该通道"下一个音符"的轨道，保证同 tick 时先执行（安全排序）
 *   4. CC/Pitch/Aftertouch 随机轨道（tick 排序保证时序，跨轨同 tick 顺序由优先级排序保证）
 *   5. 通道随机置换（通道 9 固定保留给打击乐）
 *   6. 轨道命名为 Drums/Lead/FX 等伪轨名 + 注入无害 GP CC 噪音
 *   7. Tempo/拍号/曲名等 meta 独立 conductor 轨
 *
 * 算法 O(n)，避免任何按音符配对的超大内存结构（可处理百万音符 Black MIDI）。
 */
(function (global) {
    'use strict';

    // 兼容浏览器 / Node：获取真正的全局对象
    var G = typeof globalThis !== 'undefined' ? globalThis : (typeof window !== 'undefined' ? window : global);

    var FAKE_TRACK_NAMES = [
        'Drums', 'Lead Synth', 'FX Percussion', 'Pad', 'Arp',
        'Brass Section', 'Strings', 'Choir', 'Bass', 'SFX & Sweeps'
    ];

    var PRIORITY = {
        'programChange': 0,
        'noteOn': 1,
        'noteOff': 2,
        'controlChange': 3,
        'pitchBend': 4,
        'polyAftertouch': 5,
        'channelAftertouch': 6,
        'meta': 7,
        'sysex': 8
    };

    /**
     * 洗牌（Fisher-Yates），返回新数组
     */
    function shuffle(arr) {
        var a = arr.slice();
        for (var i = a.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = a[i]; a[i] = a[j]; a[j] = tmp;
        }
        return a;
    }

    /**
     * 生成 0..15 的随机置换（通道 9 固定）
     */
    function channelMapFor(midi) {
        var onlyUsed = {}; // 只为统计
        for (var t = 0; t < midi.tracks.length; t++) {
            var evs = midi.tracks[t].events;
            for (var i = 0; i < evs.length; i++) {
                if (evs[i].type === 'channel') onlyUsed[evs[i].channel] = true;
            }
        }
        // 排除通道 9（打击乐固定），剩余 15 个通道随机置换
        var pool = [];
        for (var c = 0; c < 16; c++) if (c !== 9) pool.push(c);
        var shuffled = shuffle(pool);
        var map = {};
        var pi = 0;
        for (var j = 0; j < 16; j++) {
            if (j === 9) {
                map[j] = 9;
            } else {
                map[j] = shuffled[pi++];
            }
        }
        return map;
    }

    /**
     * 主混淆函数
     * @param {Object} midi - MIDIParser.parse 的结果
     * @param {Object} options - {splitOff, injectNoise, trackCount, skipVerify}
     *    - skipVerify: true 时跳过回读签名校验（超大文件省内存）
     * @returns {{midi:Uint8Array, channelMap:Object, stats:Object, verify:Object}}
     */
    function obfuscate(midi, options) {
        options = options || {};
        var splitOff = options.splitOff !== false;
        var injectNoise = options.injectNoise !== false;
        var trackCount = options.trackCount || 10; // TRACK COUNT HERE
        var skipVerify = !!options.skipVerify;
        var TC = trackCount;

        var chMap = channelMapFor(midi);

        // ---- 组装 10 条轨道事件 ----
        var newTracks = [];
        for (var tI = 0; tI < TC; tI++) {
            //newTracks.push({ events: [], name: FAKE_TRACK_NAMES[tI % FAKE_TRACK_NAMES.length] });
            newTracks.push({ events: [], name: 'Obfuscated ' + (tI + 1) });
        }

        var conductorEvents = [];
        var origTrackNames = [];

        // 通道轮转计数器（noteOn / noteOff 独立）
        var rotate = {};
        var offRotate = {};
        // 通道轨道偏移（确定性，防相邻通道碰撞）
        function chOffset(ch) { return ((ch % TC) + TC) % TC; }
        function chOffOffset(ch) { return (((ch * 7 + 3) % TC) + TC) % TC; }

        // ---- 元数据与事件分布 ----
        for (var t = 0; t < midi.tracks.length; t++) {
            var events = midi.tracks[t].events;
            for (var i = 0; i < events.length; i++) {
                var ev = events[i];

                // ---- Meta 事件 ----
                if (ev.type === 'meta') {
                    if (ev.metaType === 0x2F) continue; // EOT 最后统一加
                    if (ev.metaType === 0x51 || ev.metaType === 0x58 || ev.metaType === 0x59 ||
                        ev.metaType === 0x54 || ev.metaType === 0x7F || ev.metaType === 0x06 || ev.metaType === 0x05) {
                        conductorEvents.push(cloneMeta(ev));
                        continue;
                    }
                    if (ev.metaType === 0x03) {
                        if (ev.text && origTrackNames.indexOf(ev.text) === -1) origTrackNames.push(ev.text);
                        conductorEvents.push(cloneMeta(ev));
                        continue;
                    }
                    if (ev.metaType === 0x01 || ev.metaType === 0x02) {
                        conductorEvents.push(cloneMeta(ev));
                        continue;
                    }
                    // 其它 meta（歌词、标记等）
                    var targetRand = Math.floor(Math.random() * TC);
                    newTracks[targetRand].events.push(cloneMeta(ev));
                    continue;
                }

                // ---- SysEx ----
                if (ev.type === 'sysex') {
                    conductorEvents.push({
                        tick: ev.tick,
                        type: 'sysex',
                        sysex: ev.sysex.slice()
                    });
                    continue;
                }

                // ---- Channel 事件 ----
                if (ev.type !== 'channel') continue;
                var mappedCh = chMap[ev.channel];
                var ch = ev.channel;

                if (ev.name === 'noteOn' && ev.velocity > 0) {
                    // Note On：轮转 + 通道偏移
                    var r = rotate[ch] || 0;
                    rotate[ch] = r + 1;
                    var noteTrack = (r + chOffset(ch)) % TC;
                    newTracks[noteTrack].events.push({
                        tick: ev.tick,
                        type: 'channel',
                        msgType: 0x90,
                        channel: mappedCh,
                        name: 'noteOn',
                        data1: ev.note,
                        data2: ev.velocity
                    });
                    continue;
                }

                if (ev.name === 'noteOff' || (ev.name === 'noteOn' && ev.velocity === 0)) {
                    // Note Off：统一用 0x80（显式 Note Off），独立轮转，保证与 NoteOn 不同轨
                    var or = offRotate[ch] || 0;
                    offRotate[ch] = or + 1;
                    var offTrack = splitOff
                        ? (or + chOffOffset(ch)) % TC
                        : (or + chOffset(ch)) % TC;
                    newTracks[offTrack].events.push({
                        tick: ev.tick,
                        type: 'channel',
                        msgType: 0x80,
                        channel: mappedCh,
                        name: 'noteOff',
                        data1: ev.note,
                        data2: ev.name === 'noteOn' ? 0 : ev.velocity
                    });
                    continue;
                }

                if (ev.name === 'programChange') {
                    // 放在该通道下一个音符的轨道（当前 rotate 值 → 下一个音符会使用）
                    var pcTrack = ((rotate[ch] || 0) + chOffset(ch)) % TC;
                    newTracks[pcTrack].events.push({
                        tick: ev.tick,
                        type: 'channel',
                        msgType: 0xC0,
                        channel: mappedCh,
                        name: 'programChange',
                        data1: ev.program,
                        data2: 0
                    });
                    continue;
                }

                // 其它控制事件（CC / Pitch Bend / Aftertouch）：随机轨道
                var ctrlTrack = Math.floor(Math.random() * TC);
                newTracks[ctrlTrack].events.push({
                    tick: ev.tick,
                    type: 'channel',
                    msgType: ev.msgType,
                    channel: mappedCh,
                    name: ev.name,
                    data1: ev.data1,
                    data2: ev.data2 || 0
                });
            }
        }

        // ---- 注入冗余噪音 ----
        if (injectNoise) {
            var noiseChars = 'ACDEFG';
            var noiseStrings = [
                'obfuscation-layer', 'alt-passage', 'voicing', 'resonance-bank B',
                'take', 'piano-roll-decoy', 'ghost-lanes', 'aux-bus', 'variation', 'sub-pass'
            ];
            for (var nt = 0; nt < TC; nt++) {
                var eventsInTrack = newTracks[nt].events;
                if (eventsInTrack.length === 0) continue;
                var firstTick = eventsInTrack[0].tick;
                var midTick = eventsInTrack.length > 2 ?
                    eventsInTrack[Math.floor(eventsInTrack.length / 2)].tick : firstTick;

                newTracks[nt].events.push({
                    tick: firstTick,
                    type: 'meta',
                    metaType: 0x01,
                    text: noiseStrings[nt % noiseStrings.length] + ' ' + noiseChars[nt % noiseChars.length]
                });
                if (midTick > 0) {
                    var fakeCh = ((nt + 1) % 16 === 9) ? 0 : (nt + 1) % 16;
                    newTracks[nt].events.push({
                        tick: midTick,
                        type: 'channel',
                        msgType: 0xB0,
                        channel: chMap[fakeCh],
                        name: 'controlChange',
                        controller: 16 + (nt % 4),
                        value: 0,
                        data1: 16 + (nt % 4),
                        data2: 0
                    });
                }
            }
        }

        // ---- 轨道内排序：tick 升序 + 安全优先级 ----
        function safeSort(track) {
            track.events.sort(function (a, b) {
                if (a.tick !== b.tick) return a.tick - b.tick;
                var pa = PRIORITY[a.name] !== undefined ? PRIORITY[a.name] : 7;
                var pb = PRIORITY[b.name] !== undefined ? PRIORITY[b.name] : 7;
                if (pa !== pb) return pa - pb;
                // 同 tick 同优先级随机抖动
                return Math.random() < 0.5 ? -1 : 1;
            });
        }

        // ---- DAW 兼容性：轨道 PC 注入（防 Logic Pro 乐器错乱）----
        // 音符打散到 10 条轨道后，轨里可能包含多个通道的音符但缺失 PC 事件，
        // 导致 DAW 把整轨当默认乐器播放。若 tick 0 用一个 PC，tick 5000 又换一次乐器，
        // 我们也必须把后续 PC 注入到有相关通道的轨道里。
        // 解决：收集所有 PC 事件（原通道 + 时间），然后注入到所有缺少该 PC 的轨道中。
        var allPC_byOrig = [];       // [{ tick, channel, program }]
        var invChMap = {};
        for (var mk in chMap) invChMap[chMap[mk]] = Number(mk);
        for (var ct = 0; ct < midi.tracks.length; ct++) {
            var cevs = midi.tracks[ct].events;
            for (var ci = 0; ci < cevs.length; ci++) {
                var cev = cevs[ci];
                if (cev.type === 'channel' && cev.name === 'programChange') {
                    allPC_byOrig.push({ tick: cev.tick, channel: cev.channel, program: cev.program });
                }
            }
        }

        // 为每条输出轨补充它缺少的 PC 事件
        // 用收集到的 PC 事件 + 轨道包含每个通道的时间段来决定是否需要注入
        for (var ot2 = 0; ot2 < TC; ot2++) {
            var trackEvs = newTracks[ot2].events;

            // 收集该轨道中每个通道的音符出现的时间范围
            var chNoteRange = {}; // newCh -> { firstTick, lastTick }
            var existingPC = {};  // newCh -> Set of ticks that already have a PC event
            for (var ei = 0; ei < trackEvs.length; ei++) {
                var tev = trackEvs[ei];
                if (tev.type !== 'channel') continue;
                if (tev.name === 'noteOn' || tev.name === 'noteOff' || tev.name === 'programChange') {
                    var rk = tev.channel;
                    if (!chNoteRange[rk]) chNoteRange[rk] = { firstTick: tev.tick, lastTick: tev.tick };
                    if (tev.tick < chNoteRange[rk].firstTick) chNoteRange[rk].firstTick = tev.tick;
                    if (tev.tick > chNoteRange[rk].lastTick) chNoteRange[rk].lastTick = tev.tick;
                }
                if (tev.name === 'programChange') {
                    if (!existingPC[tev.channel]) existingPC[tev.channel] = {};
                    existingPC[tev.channel][tev.tick] = true;
                }
            }

            // 对每个轨道中的通道，注入缺失的 PC 事件
            for (var newChStr in chNoteRange) {
                var newCh = Number(newChStr);
                var origCh = invChMap[newCh];
                if (origCh === undefined) continue;
                for (var pi = 0; pi < allPC_byOrig.length; pi++) {
                    var p = allPC_byOrig[pi];
                    if (p.channel !== origCh) continue;
                    // 检查该 PC 是否在轨道中存在
                    if (existingPC[newCh] && existingPC[newCh][p.tick]) continue;
                    // 注入到轨道中（在音符之前，但至少在 0 之后）
                    var injTick = Math.max(0, p.tick);
                    trackEvs.push({
                        tick: injTick, type: 'channel', msgType: 0xC0,
                        channel: newCh, name: 'programChange',
                        data1: p.program, data2: 0,
                        _injected: true
                    });
                }
            }
        }

        // ---- conductor 轨：曲名 + tempo + 拍号 ----
        if (origTrackNames.length > 0) {
            conductorEvents.push({
                tick: 0,
                type: 'meta',
                metaType: 0x03,
                text: origTrackNames.join(' / ')
            });
        }
        conductorEvents.sort(function (a, b) { return a.tick - b.tick; });

        // ---- 组装输出 ----
        var outTrackEvents = [];
        outTrackEvents.push(conductorEvents);
        for (var ot = 0; ot < TC; ot++) {
            var te = newTracks[ot].events;
            te.push({
                tick: 0,
                type: 'meta',
                metaType: 0x03,
                text: newTracks[ot].name
            });
            safeSort(newTracks[ot]);
            outTrackEvents.push(te);
        }

        // EOT：conductor 和每条轨道末尾
        var maxAllTick = midi.maxTick + 1;
        for (var et = 0; et < outTrackEvents.length; et++) {
            var list = outTrackEvents[et];
            var endTick = maxAllTick;
            for (var ei = 0; ei < list.length; ei++) {
                if (list[ei].tick > endTick) endTick = list[ei].tick;
            }
            list.push({ tick: endTick, type: 'meta', metaType: 0x2F, endOfTrack: true });
        }

        var midiBytes;
        try {
            midiBytes = G.MIDIWriter.build({
                format: 1,
                division: midi.division,
                tracks: outTrackEvents
            });
        } catch (e) {
            return { error: '编码失败: ' + e.message };
        }

        // ---- 自我校验：事件集合签名对比（播放语义等价）----
        // 原文件用原始通道签名；混淆后文件用逆映射还原到原始通道后签名对比
        // skipNoise=true：跳过注入的 GP CC 噪音与文本噪音（对播放无影响）
        var verify = { ok: false, reason: '未校验' };
        if (skipVerify) {
            verify = { ok: true, skipped: true };
        } else {
            try {
                var re = G.MIDIParser.parse(midiBytes);
                var invMap = {};
                for (var mk in chMap) invMap[chMap[mk]] = Number(mk);
                var origSig = G.MIDIParser.eventSignature(midi, null, true);
                var newSig = G.MIDIParser.eventSignature(re, invMap, true);
                verify = G.MIDIParser.compareSignatures(origSig, newSig);
            } catch (e) {
                verify = { ok: false, reason: '回读校验异常: ' + e.message };
            }
        }

        return {
            midi: midiBytes,
            channelMap: chMap,
            verify: verify,
            stats: {
                origTracks: midi.tracks.length,
                newTracks: outTrackEvents.length,
                origNotes: midi.totalNotes,
                origEvents: midi.totalEvents,
                origBytes: midi.originalBytes,
                newBytes: midiBytes.length
            }
        };
    }

    function cloneMeta(ev) {
        var c = {
            tick: ev.tick,
            type: 'meta',
            metaType: ev.metaType
        };
        if (ev.metaType === 0x51 && ev.usPerQuarter !== undefined) {
            c.usPerQuarter = ev.usPerQuarter;
        }
        if (ev.text !== undefined) c.text = ev.text;
        if (ev.dataBytes) c.dataBytes = ev.dataBytes.slice();
        return c;
    }

    var api = {
        obfuscate: obfuscate
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        global.MIDIObfuscator = api;
    }
})(typeof window !== 'undefined' ? window : this);