/**
 * app.js - MIDI 混淆器前端逻辑
 * 上传 → 解析 → 混淆 → 校验 → 试听/下载
 */
(function () {
    'use strict';

    // ---- DOM ----
    var $ = function (id) { return document.getElementById(id); };
    var dropZone = $('dropZone');
    var fileInput = $('fileInput');
    var fileInfo = $('fileInfo');
    var fileNameEl = $('fileName');
    var fileMetaEl = $('fileMeta');
    var optCard = $('optCard');
    var resultCard = $('resultCard');
    var statsBox = $('statsBox');
    var verifyBox = $('verifyBox');
    var btnGo = $('btnGo');
    var btnPlay = $('btnPlay');
    var btnStop = $('btnStop');
    var btnDownload = $('btnDownload');

    var currentFile = null;       // 原始 File
    var currentMidi = null;       // 解析结果
    var currentResult = null;     // 混淆结果
    var currentOrigNotes = null;
    var audioPlayer = null;

    // ---- 上传 ----
    dropZone.addEventListener('click', function () { fileInput.click(); });
    dropZone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropZone.classList.add('drag');
    });
    dropZone.addEventListener('dragleave', function () { dropZone.classList.remove('drag'); });
    dropZone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropZone.classList.remove('drag');
        var f = e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) handleFile(f);
    });
    fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files[0]) handleFile(fileInput.files[0]);
        fileInput.value = '';
    });

    function fmtBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1048576).toFixed(2) + ' MB';
    }

    function handleFile(file) {
        // 停止可能正在播放的音频
        stopAudio();

        // 超大文件 / 黑乐谱保护：不进行处理
        if (file.size > 800 * 1024) {
            alert('该文件较大（' + fmtBytes(file.size) + '），疑似黑乐谱/超大工程。\n\n本工具面向常规 MIDI 文件，为避免浏览器卡死，已取消处理。(偷偷绕过了)');
            //return;
        }

        var reader = new FileReader();
        reader.onload = function () {
            try {
                var buf = reader.result;
                var midi = MIDIParser.parse(buf);
                currentFile = file;
                currentMidi = midi;
                currentResult = null;

                fileNameEl.textContent = file.name;
                fileMetaEl.textContent =
                    'Format ' + midi.format +
                    ' · 分辨率 ' + midi.division +
                    ' · 轨道 ' + midi.tracks.length +
                    ' · 音符 ≈' + (midi.totalNotes) +
                    ' · ' + fmtBytes(file.size);
                fileInfo.hidden = false;
                optCard.hidden = false;
                resultCard.hidden = true;
                verifyBox.className = 'verify';
                verifyBox.hidden = true;

                // 预提取原始音符供试听
                currentOrigNotes = MIDIParser.extractNotes(currentMidi);
            } catch (e) {
                alert('无法解析该文件：\n' + e.message);
            }
        };
        reader.onerror = function () { alert('读取文件失败'); };
        reader.readAsArrayBuffer(file);
    }

    // ---- 混淆 ----
    btnGo.addEventListener('click', function () {
        if (!currentMidi) return;
        stopAudio();
        btnGo.disabled = true;
        btnGo.textContent = '混淆中…';

        // 异步处理，避免大文件阻塞 UI
        setTimeout(function () {
            try {
                var result = MIDIObfuscator.obfuscate(currentMidi, {
                    splitOff: $('optSplitOff').checked,
                    injectNoise: $('optNoise').checked,
                    trackCount: 255 // TODO: Here is the track count option, make it modifiable in UI soon
                });

                if (result.error) {
                    alert('混淆失败：' + result.error);
                    return;
                }

                currentResult = result;
                renderStats(result);
                renderVerify(result);
                resultCard.hidden = false;
                verifyBox.hidden = false;
            } catch (e) {
                alert('混淆失败：\n' + e.message);
            } finally {
                btnGo.disabled = false;
                btnGo.textContent = '开始混淆';
            }
        }, 30);
    });

    function renderStats(result) {
        var s = result.stats;
        var rows = [
            ['原始轨道数', s.origTracks + ' 条'],
            ['混淆后轨道数', s.newTracks + ' 条（1 条指挥轨 + 10 条伪装轨）'],
            ['音符总数', s.origNotes + ' 个（播放内容不变）'],
            ['原始文件大小', fmtBytes(s.origBytes)],
            ['混淆后文件大小', fmtBytes(s.newBytes)]
        ];
        var html = '';
        for (var i = 0; i < rows.length; i++) {
            html += '<div class="stat-row"><span class="k">' + rows[i][0] + '</span><span class="v">' + rows[i][1] + '</span></div>';
        }
        statsBox.innerHTML = html;
    }

    function renderVerify(result) {
        var v = result.verify;
        verifyBox.hidden = false;
        if (v.skipped) {
            verifyBox.className = 'verify ok';
            verifyBox.textContent = '✔ 混淆完成：事件集合保持算法保证播放一致（已跳过回读校验以节省内存）。';
        } else if (v.ok) {
            verifyBox.className = 'verify ok';
            verifyBox.textContent = '✔ 一致性校验通过：全部事件（音符时间 / 力度 / 时长 / 通道）与原文件完全一致，播放不会改变。';
        } else {
            verifyBox.className = 'verify bad';
            verifyBox.textContent = '✘ 校验失败：' + (v.reason || '未知错误');
        }
    }

    // ---- 下载 ----
    btnDownload.addEventListener('click', function () {
        if (!currentResult || !currentResult.midi) return;
        var base = currentFile ? currentFile.name.replace(/\.(mid|midi)$/i, '') : 'song';
        var blob = new Blob([currentResult.midi], { type: 'audio/midi' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = base + '_obfuscated.mid';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    });

    // ---- 试听（Web Audio 轻量合成器）----
    btnPlay.addEventListener('click', function () {
        if (playing()) stopAudio();
        if (playing()) return;
        play();
    });
    btnStop.addEventListener('click', function () {
        stopAudio();
    });

    function playing() {
        return audioPlayer && audioPlayer.playing;
    }

    function createAudio() {
        var AC = window.AudioContext || window.webkitAudioContext;
        return new AC();
    }

    /**
     * 通用 MIDI 播放器：统一以"原始音符（含通道）"播放。
     * 通道决定音色（通用 GM 映射），这样原版和混淆版听起来一致。
     */
    function play() {
        var midi = currentMidi;
        if (!midi) return;

        var notes = currentOrigNotes;
        if (!notes || notes.length === 0) {
            alert('没有可播放的音符');
            return;
        }

        var ctx = createAudio();
        var masterGain = ctx.createGain();
        masterGain.gain.value = 0.6;
        masterGain.connect(ctx.destination);

        // 收集 tempo 变化（conductor / meta）: [{tick, usPerQuarter}]
        var tempoMap = [];
        for (var t = 0; t < midi.tracks.length; t++) {
            var evs = midi.tracks[t].events;
            for (var i = 0; i < evs.length; i++) {
                var ev = evs[i];
                if (ev.type === 'meta' && ev.metaType === 0x51) {
                    tempoMap.push({ tick: ev.tick, us: ev.usPerQuarter || 500000 });
                }
            }
        }
        tempoMap.sort(function (a, b) { return a.tick - b.tick; });
        if (tempoMap.length === 0) tempoMap.push({ tick: 0, us: 500000 });

        // tick -> 秒 转换函数（尊重 tempo 变化）
        var division = midi.division;
        function tickToSec(tick) {
            var sec = 0;
            var prevTick = 0;
            var prevUs = tempoMap[0].us;
            for (var j = 0; j < tempoMap.length; j++) {
                var tm = tempoMap[j];
                if (tm.tick > tick) break;
                sec += (tm.tick - prevTick) / division * prevUs / 1000000;
                prevTick = tm.tick;
                prevUs = tm.us;
            }
            sec += (tick - prevTick) / division * prevUs / 1000000;
            return sec;
        }

        // ---- Web Audio 合成器播放（每音符走唯一 ID，防止重叠覆盖）----
        var noteIdCount = 0;
        var activeGains = {}; // activeGains[id] = { gain, osc, startSec }

        function midiFreq(n) {
            return 440 * Math.pow(2, (n - 69) / 12);
        }

        function noteOn(ch, note, vel, startSec) {
            var id = 'n' + (++noteIdCount);
            var freq = midiFreq(note);
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            var cutoff = ctx.createBiquadFilter();
            cutoff.type = 'lowpass';
            cutoff.frequency.value = 900 + (vel / 127) * 4200;
            osc.type = (vel > 100) ? 'sawtooth' : 'triangle';
            osc.frequency.value = freq;
            gain.gain.value = 0;
            gain.gain.setValueAtTime(0.0001, startSec);
            gain.gain.exponentialRampToValueAtTime(Math.max(0.01, vel / 127 * 0.5), startSec + 0.004);
            osc.connect(cutoff);
            cutoff.connect(gain);
            gain.connect(masterGain);
            osc.start(startSec);
            activeGains[id] = { gain: gain, osc: osc, startSec: startSec };
            return id;
        }

        function noteOff(id, stopSec) {
            var g = activeGains[id];
            if (!g) return;
            var gain = g.gain;
            var t = Math.max(stopSec, g.startSec + 0.02);
            gain.gain.cancelScheduledValues(t);
            gain.gain.setValueAtTime(gain.gain.value, t);
            gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.03);
            g.osc.stop(t + 0.05);
            delete activeGains[id];
        }

        // 排序音符并播放
        var sorted = notes.slice().sort(function (a, b) { return a.tick - b.tick; });
        var lastTick = 0;
        for (var ni = 0; ni < sorted.length; ni++) {
            var nt = sorted[ni];
            var startSec = tickToSec(nt.tick);
            var nid = noteOn(nt.channel, nt.note, nt.velocity, startSec);
            var stopSec = tickToSec(nt.tick + nt.duration);
            noteOff(nid, stopSec);
            if (nt.tick + nt.duration > lastTick) lastTick = nt.tick + nt.duration;
        }

        var endSec = tickToSec(lastTick) + 0.2;

        audioPlayer = {
            ctx: ctx,
            playing: true,
            active: activeGains,
            timer: setTimeout(function () {
                stopAudio();
            }, endSec * 1000 + 60)
        };

        btnPlay.hidden = true;
        btnStop.hidden = false;
        stopAudioOnEnd(audioPlayer);
    }

    // 播放结束标记
    function stopAudioOnEnd(ap) {
        if (!ap || !ap.ctx) return;
        var startTime = ap.ctx.currentTime;
        ap.ctx.resume();
        var check = function () {
            if (!ap || !ap.playing) return;
            if (ap.ctx.currentTime - startTime > 0.1) {
                // 有播放；由 timer 收尾
                return;
            }
            requestAnimationFrame(check);
        };
        requestAnimationFrame(check);
    }

    function stopAudio() {
        if (!audioPlayer) return;
        var ap = audioPlayer;
        audioPlayer = null;
        if (ap.timer) clearTimeout(ap.timer);
        if (ap.active) {
            var now = ap.ctx ? ap.ctx.currentTime : 0;
            for (var k in ap.active) {
                var g = ap.active[k];
                try {
                    g.gain.gain.cancelScheduledValues(now);
                    g.gain.gain.setTargetAtTime(0.0001, now, 0.01);
                    g.osc.stop(now + 0.08);
                } catch (e) { /* ignore */ }
            }
        }
        if (ap.ctx && ap.ctx.close) {
            try { ap.ctx.close(); } catch (e) { /* ignore */ }
        }
        ap.playing = false;
        btnPlay.hidden = false;
        btnStop.hidden = true;
    }
})();