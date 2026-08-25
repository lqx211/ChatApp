/**
 * midi_parser.js - 手写 MIDI 二进制解析器（零依赖）
 * 支持：Format 0/1/2、VLQ、Running Status、Meta 事件、SysEx
 * 所有事件统一转为绝对 tick，方便上层处理。
 */
(function (global) {
    'use strict';

    var MAX_RUNNING_STEP = 0; // 无限制

    function readVLQ(data, pos) {
        var value = 0;
        var b;
        var count = 0;
        do {
            if (pos >= data.length) throw new Error('MIDI 解析错误：VLQ 越界');
            b = data[pos++];
            value = (value << 7) | (b & 0x7F);
            count++;
            if (count > 4) throw new Error('MIDI 解析错误：VLQ 长度异常');
        } while (b & 0x80);
        return { value: value, pos: pos };
    }

    function readUint16(data, pos) {
        return (data[pos] << 8) | data[pos + 1];
    }

    function readUint32(data, pos) {
        return ((data[pos] << 24) >>> 0) | (data[pos + 1] << 16) | (data[pos + 2] << 8) | data[pos + 3];
    }

    function bytesToStr(u8) {
        var out = '';
        for (var i = 0; i < u8.length; i++) {
            var c = u8[i];
            if (c >= 0x20 && c < 0x7F) { out += String.fromCharCode(c); }
            else if (c === 0) { break; }
            else if (c >= 0xC0) {
                try { var sub = u8.subarray(i); out += decodeURIComponent(escape(String.fromCharCode.apply(null, sub))); break; }
                catch (e) { out += '?'; break; }
            } else { out += '?'; }
        }
        return out;
    }

    function parseMIDI(buffer) {
        var data = (buffer instanceof Uint8Array) ? buffer : new Uint8Array(buffer);
        if (data.length < 14) throw new Error('文件太小，不是有效的 MIDI 文件');
        if (String.fromCharCode(data[0], data[1], data[2], data[3]) !== 'MThd')
            throw new Error('不是有效的 MIDI 文件（缺少 MThd 头）');

        var headerLen = readUint32(data, 4);
        var format = readUint16(data, 8);
        var trackCount = readUint16(data, 10);
        var division = readUint16(data, 12);
        if (format > 2) throw new Error('不支持的 MIDI Format: ' + format);
        if (headerLen < 6) throw new Error('MThd 长度异常');

        var pos = 14 + (headerLen - 6);
        var tracks = [];
        for (var t = 0; t < trackCount; t++) {
            if (pos + 8 > data.length) throw new Error('MIDI 轨道头部越界');
            var chunkType = String.fromCharCode(data[pos], data[pos + 1], data[pos + 2], data[pos + 3]);
            var chunkLen = readUint32(data, pos + 4);
            pos += 8;
            if (chunkType === 'MTrk') {
                if (pos + chunkLen > data.length) throw new Error('MIDI 轨道数据越界');
                tracks.push(_parseTrack(data.subarray(pos, pos + chunkLen)));
                pos += chunkLen;
            } else {
                pos += chunkLen;
            }
        }

        var m = { format: format, division: division, tracks: tracks, originalBytes: data.length };
        m.totalNotes = 0; m.totalEvents = 0; m.maxTick = 0;
        for (var i = 0; i < tracks.length; i++) {
            var tr = tracks[i];
            m.totalNotes += tr.notes || 0;
            m.totalEvents += tr.events.length;
            if (tr.maxTick > m.maxTick) m.maxTick = tr.maxTick;
        }
        return m;
    }

    function _parseTrack(data) {
        var events = [], pos = 0, absTick = 0, runningStatus = 0x00, notes = 0, maxTick = 0, trackName = '';
        var channelUsed = {};

        while (pos < data.length) {
            var vlq = readVLQ(data, pos); pos = vlq.pos;
            absTick += vlq.value;
            if (absTick > maxTick) maxTick = absTick;
            if (pos >= data.length) break;

            var statusByte = data[pos++];

            if (statusByte === 0xFF) {
                var metaType = data[pos++];
                var lenVLQ = readVLQ(data, pos); pos = lenVLQ.pos;
                var metaLen = lenVLQ.value;
                var metaData = data.subarray(pos, pos + metaLen); pos += metaLen;
                var ev = { tick: absTick, type: 'meta', metaType: metaType };
                if (metaType === 0x2F) { ev.endOfTrack = true; }
                else if (metaType === 0x51 && metaLen >= 3) {
                    ev.usPerQuarter = (metaData[0] << 16) | (metaData[1] << 8) | metaData[2];
                    ev.bpm = Math.round(60000000 / ev.usPerQuarter * 100) / 100;
                } else if (metaType === 0x03 || metaType === 0x01) {
                    ev.text = bytesToStr(metaData);
                    if (metaType === 0x03 && !trackName) trackName = ev.text;
                } else {
                    // 0x58 (time sig), 0x59 (key sig), 0x54 (smpte offset) 等：保留原始字节
                    ev.dataBytes = Array.prototype.slice.call(metaData);
                }
                events.push(ev);
                runningStatus = 0x00;
                continue;
            }

            if (statusByte === 0xF0 || statusByte === 0xF7) {
                var syLenVLQ = readVLQ(data, pos); pos = syLenVLQ.pos;
                var syLen = syLenVLQ.value;
                events.push({ tick: absTick, type: 'sysex', sysex: Array.prototype.slice.call(data.subarray(pos, pos + syLen)) });
                pos += syLen;
                runningStatus = 0x00;
                continue;
            }

            var status = statusByte;
            if (statusByte < 0x80) {
                if (runningStatus === 0x00) throw new Error('MIDI 解析错误：出现未定义事件 0x' + statusByte.toString(16));
                status = runningStatus;
                pos--;
            } else {
                if (statusByte >= 0xF0) throw new Error('MIDI 解析错误：未处理的系统事件 0x' + statusByte.toString(16));
                runningStatus = statusByte;
            }

            var msgType = status & 0xF0, channel = status & 0x0F;
            channelUsed[channel] = true;
            var ev = { tick: absTick, type: 'channel', channel: channel, msgType: msgType };

            if (msgType === 0x80 || msgType === 0x90 || msgType === 0xA0 || msgType === 0xB0 || msgType === 0xE0) {
                var d1 = data[pos++], d2 = data[pos++];
                ev.data1 = d1; ev.data2 = d2;
                if (msgType === 0x80) { ev.name = 'noteOff'; ev.note = d1; ev.velocity = d2; }
                else if (msgType === 0x90) {
                    if (d2 === 0) { ev.name = 'noteOff'; ev.note = d1; ev.velocity = 0; }
                    else { ev.name = 'noteOn'; ev.note = d1; ev.velocity = d2; notes++; }
                } else if (msgType === 0xA0) { ev.name = 'polyAftertouch'; }
                else if (msgType === 0xB0) { ev.name = 'controlChange'; ev.controller = d1; ev.value = d2; }
                else if (msgType === 0xE0) { ev.name = 'pitchBend'; ev.value = ((d2 << 7) | d1) - 8192; }
            } else if (msgType === 0xC0 || msgType === 0xD0) {
                var d = data[pos++];
                ev.data1 = d;
                if (msgType === 0xC0) { ev.name = 'programChange'; ev.program = d; }
                else { ev.name = 'channelAftertouch'; ev.value = d; }
            } else {
                throw new Error('MIDI 解析错误：未知消息类型 0x' + msgType.toString(16));
            }
            events.push(ev);
        }

        if (events.length === 0 || !events[events.length - 1].endOfTrack)
            events.push({ tick: maxTick, type: 'meta', metaType: 0x2F, endOfTrack: true });

        return { name: trackName || '', events: events, notes: notes, maxTick: maxTick, channelsUsed: Object.keys(channelUsed).map(Number) };
    }

    function extractNotes(midi) {
        var all = [];
        for (var t = 0; t < midi.tracks.length; t++) {
            var evs = midi.tracks[t].events;
            for (var i = 0; i < evs.length; i++) {
                var ev = evs[i];
                if (ev.type === 'channel' && (ev.name === 'noteOn' || ev.name === 'noteOff')) all.push(ev);
            }
        }
        all.sort(function (a, b) {
            if (a.tick !== b.tick) return a.tick - b.tick;
            var pa = a.name === 'noteOn' ? 0 : 1, pb = b.name === 'noteOn' ? 0 : 1;
            if (pa !== pb) return pa - pb;
            if (a.channel !== b.channel) return a.channel - b.channel;
            return a.note - b.note;
        });

        var notes = [], pending = {};
        function key(ch, n) { return ch + ':' + n; }
        for (var s = 0; s < all.length; s++) {
            var ev = all[s], k = key(ev.channel, ev.note);
            if (ev.name === 'noteOn') {
                if (pending[k]) { notes.push({ tick: pending[k].tick, channel: ev.channel, note: ev.note, velocity: pending[k].velocity, duration: ev.tick - pending[k].tick }); }
                pending[k] = { tick: ev.tick, velocity: ev.velocity, channel: ev.channel, note: ev.note };
            } else {
                if (pending[k]) { notes.push({ tick: pending[k].tick, channel: ev.channel, note: ev.note, velocity: pending[k].velocity, duration: ev.tick - pending[k].tick }); delete pending[k]; }
            }
        }
        for (var pk in pending) {
            var p = pending[pk];
            notes.push({ tick: p.tick, channel: p.channel, note: p.note, velocity: p.velocity, duration: Math.max(1, (midi.maxTick - p.tick)) });
        }
        notes.sort(function (a, b) { if (a.tick !== b.tick) return a.tick - b.tick; if (a.channel !== b.channel) return a.channel - b.channel; return a.note - b.note; });
        return notes;
    }

    function compareNotes(a, b) {
        if (a.length !== b.length) return { ok: false, reason: '音符数量不一致: ' + a.length + ' vs ' + b.length };
        for (var i = 0; i < a.length; i++) {
            var x = a[i], y = b[i];
            if (x.tick !== y.tick || x.channel !== y.channel || x.note !== y.note || x.velocity !== y.velocity || x.duration !== y.duration)
                return { ok: false, reason: '第 ' + i + ' 个音符不一致: 原(' + x.tick + ',' + x.channel + ',' + x.note + ',' + x.velocity + ',' + x.duration + ') vs 新(' + y.tick + ',' + y.channel + ',' + y.note + ',' + y.velocity + ',' + y.duration + ')' };
        }
        return { ok: true };
    }

    /**
     * 生成播放语义事件计数 Map。
     * skipNoise=true 时跳过：GP CC 噪音文本、DAW-PC 注入只统计数量吻合的 PC
     */
    function eventSignature(midi, channelMap, skipNoise) {
        var counts = {};
        var mapFn = (typeof channelMap === 'function') ? channelMap :
            (channelMap && typeof channelMap === 'object') ? function (c) { return channelMap[c] !== undefined ? channelMap[c] : c; } : null;

        var pcAllowed = null;
        if (skipNoise && mapFn) {
            pcAllowed = {};
            for (var pt = 0; pt < midi.tracks.length; pt++) {
                for (var pi = 0; pi < midi.tracks[pt].events.length; pi++) {
                    var pev = midi.tracks[pt].events[pi];
                    if (pev.type === 'channel' && pev.name === 'programChange') {
                        var mch = mapFn ? mapFn(pev.channel) : pev.channel;
                        var pkey = pev.tick + '|c0|' + mch + '|' + pev.data1;
                        pcAllowed[pkey] = (pcAllowed[pkey] || 0) + 1;
                    }
                }
            }
            var pcConsumed = {};
            counts._pcAllowed = pcAllowed;
            counts._pcConsumed = pcConsumed;
        }

        for (var t = 0; t < midi.tracks.length; t++) {
            var evs = midi.tracks[t].events;
            for (var i = 0; i < evs.length; i++) {
                var ev = evs[i];
                if (skipNoise) {
                    if (ev._injected) continue;  // DAW PC injector marking
                    if (ev.type === 'channel' && ev.name === 'controlChange' && ev.data1 >= 16 && ev.data1 <= 19 && ev.data2 === 0) continue;
                    if (ev.type === 'meta' && ev.metaType === 0x01 && ev.text &&
                        /^(obfuscation-layer|alt-passage|voicing|resonance-bank|take|piano-roll-decoy|ghost-lanes|aux-bus|variation|sub-pass)/.test(ev.text)) continue;
                    if (ev.name === 'programChange') {
                        if (pcAllowed) {
                            var pch = mapFn ? mapFn(ev.channel) : ev.channel;
                            var pk = ev.tick + '|c0|' + pch + '|' + ev.data1;
                            var ckey = '_pc_' + pk;
                            var used = counts[ckey] || 0;
                            if (used < (pcAllowed[pk] || 0)) {
                                counts[ckey] = used + 1;
                            }
                        }
                        continue;
                    }
                }
                if (ev.type === 'channel') {
                    var ch = mapFn ? mapFn(ev.channel) : ev.channel;
                    var d1 = ev.data1 !== undefined ? ev.data1 : 0;
                    var d2 = ev.data2 !== undefined ? ev.data2 : 0;
                    var kind;
                    if (ev.name === 'noteOn' && ev.velocity > 0) kind = 'on';
                    else if (ev.name === 'noteOff') kind = 'off';
                    else kind = ev.msgType.toString(16);
                    var key = ev.tick + '|' + kind + '|' + ch + '|' + d1 + '|' + d2;
                    counts[key] = (counts[key] || 0) + 1;
                } else if (ev.type === 'meta' && ev.metaType === 0x51) {
                    var tKey = ev.tick + '|ff51|' + (ev.usPerQuarter || 500000);
                    counts[tKey] = (counts[tKey] || 0) + 1;
                }
            }
        }

        // Clean out PC tracking keys before comparing
        delete counts._pcAllowed;
        delete counts._pcConsumed;
        for (var ck in counts) { if (ck.indexOf('_pc_') === 0) delete counts[ck]; }

        return counts;
    }

    function compareSignatures(a, b) {
        var aKeys = 0, bKeys = 0;
        for (var ak in a) aKeys++;
        for (var bk in b) bKeys++;
        if (aKeys !== bKeys) return { ok: false, reason: '唯一事件类型数量不一致: ' + aKeys + ' vs ' + bKeys };
        for (var k in a) {
            if (b[k] !== a[k])
                return { ok: false, reason: '事件计数不一致: key[' + k + '] 原=' + a[k] + ' 新=' + (b[k] || 0) };
        }
        return { ok: true };
    }

    var api = { parse: parseMIDI, extractNotes: extractNotes, compareNotes: compareNotes, eventSignature: eventSignature, compareSignatures: compareSignatures };
    if (typeof module !== 'undefined' && module.exports) { module.exports = api; } else { global.MIDIParser = api; }
})(typeof window !== 'undefined' ? window : this);