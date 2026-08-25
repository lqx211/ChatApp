# MIDI 混淆器（MIDI Obfuscator）

ChatApp 应用 · 把 MIDI 单条旋律打散成 10 条伪装轨道，**播放效果 100% 不变**，防扒谱 / 防抄袭。

## 使用方法

1. 浏览器打开 `apps/midi_obfuscation/`（纯前端，文件不离开本地）
2. 拖拽或点击上传 `.mid` / `.midi` 文件
3. 按需调整选项：
   - **Note On/Off 分离**：同一音符的按下/抬起拆到不同轨道（默认开）
   - **注入伪轨噪音**：Drums/Lead/FX 等迷惑轨道名 + 无害 GP 控制器事件（默认开）
4. 点击「开始混淆」，页面会显示统计与**播放一致性校验结果**
5. 可内置合成器试听、一键下载 `xxx_obfuscated.mid`

## 混淆原理（播放不变的核心）

MIDI 播放语义由**事件流集合**决定（noteOn/noteOff 的 tick、通道、音高、力度）。
只要这些事件集合完全保留，无论轨道如何打散、通道如何置换，播放结果必然一致。

- 每个 noteOn 按通道轮转 + 通道偏移分配到 10 条轨道，相邻旋律音符绝不在同一轨
- noteOff 独立轮转另一偏移，与 noteOn 不同轨（扒谱者难以配对音符）
- 通道随机置换（通道 9 打击乐固定），无法按 channel 过滤还原
- Tempo / 拍号 / 曲名等 meta 独立 conductor 轨
- Program Change 跟随下一音符轨道，同 tick 优先级排序保证先换音色再发声
- 轨内按绝对 tick 升序 + 安全事件优先级重排，delta 重新 VLQ 编码，时序分毫不差
- 混淆后可自动回读校验：「原始事件集合」与「混淆后逆映射」完全一致

## 文件结构

```
index.php          入口（含 maintenance 门卫）
css/style.css      ChatApp 深色风格
js/midi_parser.js  手写 MIDI 二进制解析器（VLQ / running status / meta / sysex）
js/midi_writer.js  MIDI 生成器（增量缓冲，内存友好）
js/obfuscator.js   混淆引擎（事件集合保持，O(n)）
js/app.js          页面逻辑（上传 / 混淆 / 试听 / 下载 / 校验）
```

## 限制

- 面向常规 MIDI 文件；**超大文件 / 黑乐谱（>800KB）会拒绝处理**，避免浏览器卡死


对了，这个项目不正经。