/**
 * midi_writer.js - MIDI 生成器（format 1，多轨，VLQ 编码，零依赖）
 * 输入：事件列表（含绝对 tick）→ 输出标准 MIDI 二进制
 */
(function (global) {
    'use strict';

    /**
     * 编码 VLQ
     * @returns {number[]}
     */
    function encodeVLQ(value) {
        value = Math.max(0, Math.floor(value));
        var buffer = [value & 0x7F];
        while (value > 0x7F) {
            value >>= 7;
            buffer.unshift((value & 0x7F) | 0x80);
        }
        return buffer;
    }

    /**
     * 将一个事件的绝对 tick 与上一个事件的差异转为 delta，并编码事件字节。
     * @param {Object} ev - {tick, type:'meta'|'channel'|'sysex', ...}
     * @param {number} delta
     * @returns {number[]}
     */
    function eventToBytes(ev, delta, runningStatusRef) {
        var out = [];
        var vlq = encodeVLQ(delta);
        for (var i = 0; i < vlq.length; i++) out.push(vlq[i]);

        if (ev.type === 'meta') {
            out.push(0xFF);
            out.push(ev.metaType);
            var data = [];
            if (ev.metaType === 0x51 && ev.usPerQuarter !== undefined) {
                // Tempo
                data = [(ev.usPerQuarter >> 16) & 0xFF, (ev.usPerQuarter >> 8) & 0xFF, ev.usPerQuarter & 0xFF];
            } else if (ev.metaType === 0x2F) {
                data = [];
            } else if (ev.metaType === 0x03 || ev.metaType === 0x01 || ev.metaType === 0x02 || ev.metaType === 0x06) {
                data = strToUtf8Bytes(ev.text || '');
            } else if (ev.dataBytes) {
                data = ev.dataBytes;
            }
            var lenVLQ = encodeVLQ(data.length);
            for (var j = 0; j < lenVLQ.length; j++) out.push(lenVLQ[j]);
            for (var k = 0; k < data.length; k++) out.push(data[k]);
            return out;
        }

        if (ev.type === 'sysex') {
            out.push(0xF0);
            var syLenVLQ = encodeVLQ((ev.sysex || []).length);
            for (var s = 0; s < syLenVLQ.length; s++) out.push(syLenVLQ[s]);
            for (var sy = 0; sy < (ev.sysex || []).length; sy++) out.push(ev.sysex[sy] & 0x7F);
            return out;
        }

        // ---- Channel 事件 ----
        var statusByte = ev.msgType | (ev.channel & 0x0F);
        var bytes = [statusByte];
        if (ev.msgType === 0x80 || ev.msgType === 0x90 || ev.msgType === 0xA0 || ev.msgType === 0xB0 || ev.msgType === 0xE0) {
            bytes.push(ev.data1 & 0x7F);
            bytes.push(ev.data2 & 0x7F);
        } else {
            bytes.push(ev.data1 & 0x7F);
        }
        for (var b = 0; b < bytes.length; b++) out.push(bytes[b]);
        return out;
    }

    function strToUtf8Bytes(str) {
        var out = [];
        for (var i = 0; i < str.length; i++) {
            var code = str.charCodeAt(i);
            if (code < 0x80) {
                out.push(code);
            } else if (code < 0x800) {
                out.push(0xC0 | (code >> 6), 0x80 | (code & 0x3F));
            } else {
                out.push(0xE0 | (code >> 12), 0x80 | ((code >> 6) & 0x3F), 0x80 | (code & 0x3F));
            }
        }
        return out;
    }

    /**
     * 增量写入缓冲：按需倍增扩容，内存接近实际字节数。
     */
    function ByteBuffer() {
        this.buf = new Uint8Array(1024);
        this.len = 0;
    }
    ByteBuffer.prototype.ensure = function (extra) {
        if (this.len + extra <= this.buf.length) return;
        var newLen = this.buf.length * 2;
        while (newLen < this.len + extra) newLen *= 2;
        var nb = new Uint8Array(newLen);
        nb.set(this.buf.subarray(0, this.len));
        this.buf = nb;
    };
    ByteBuffer.prototype.push = function (b) {
        this.ensure(1);
        this.buf[this.len++] = b;
    };
    ByteBuffer.prototype.pushArray = function (arr) {
        if (arr.length === 0) return;
        this.ensure(arr.length);
        for (var i = 0; i < arr.length; i++) this.buf[this.len + i] = arr[i];
        this.len += arr.length;
    };
    ByteBuffer.prototype.toUint8Array = function () {
        return new Uint8Array(this.buf.subarray(0, this.len));
    };

    /**
     * 将一条轨道的事件（绝对 tick）编码为 MTrk 数据。
     * 事件必须先按 tick 升序排列（混淆引擎保证）。
     * @param {Array<Object>} events
     * @returns {Uint8Array} 不含 MTrk 头的完整轨道内容
     */
    function encodeTrack(events) {
        var bytes = new ByteBuffer();
        var prevTick = 0;

        for (var i = 0; i < events.length; i++) {
            var ev = events[i];
            var delta = ev.tick - prevTick;
            if (delta < 0) throw new Error('编码错误：事件 tick 回退 (' + i + ')');
            prevTick = ev.tick;
            var chunk = eventToBytes(ev, delta);
            bytes.pushArray(chunk);
        }

        // 确保 EOT
        var last = events.length ? events[events.length - 1] : null;
        if (!last || !(last.type === 'meta' && last.metaType === 0x2F)) {
            bytes.pushArray(eventToBytes({ type: 'meta', metaType: 0x2F, tick: prevTick }, 0));
        }

        return bytes.toUint8Array();
    }

    /**
     * 生成完整 MIDI 文件。
     * @param {Object} spec - {format:number, division:number, tracks:Array<Array<Object>>}
     * @returns {Uint8Array}
     */
    function buildMIDI(spec) {
        var format = spec.format !== undefined ? spec.format : 1;
        var division = spec.division !== undefined ? spec.division : 480;
        var trackList = spec.tracks || [];

        var chunks = [];
        chunks.push(bytesOfString('MThd'));
        chunks.push(uint32Bytes(6));
        chunks.push(uint16Bytes(format));
        chunks.push(uint16Bytes(trackList.length));
        chunks.push(uint16Bytes(division));

        for (var t = 0; t < trackList.length; t++) {
            var trackData = encodeTrack(trackList[t] || []);
            chunks.push(bytesOfString('MTrk'));
            chunks.push(uint32Bytes(trackData.length));
            chunks.push(trackData);
        }

        var totalLen = 0;
        for (var c = 0; c < chunks.length; c++) totalLen += chunks[c].length;
        var out = new Uint8Array(totalLen);
        var pos = 0;
        for (var d = 0; d < chunks.length; d++) {
            out.set(chunks[d], pos);
            pos += chunks[d].length;
        }
        return out;
    }

    function bytesOfString(str) {
        var out = new Uint8Array(str.length);
        for (var i = 0; i < str.length; i++) out[i] = str.charCodeAt(i);
        return out;
    }

    function uint16Bytes(v) {
        return new Uint8Array([(v >> 8) & 0xFF, v & 0xFF]);
    }

    function uint32Bytes(v) {
        return new Uint8Array([(v >>> 24) & 0xFF, (v >>> 16) & 0xFF, (v >>> 8) & 0xFF, v & 0xFF]);
    }

    var api = {
        build: buildMIDI,
        encodeTrack: encodeTrack
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    } else {
        global.MIDIWriter = api;
    }
})(typeof window !== 'undefined' ? window : this);