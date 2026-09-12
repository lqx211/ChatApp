#!/usr/bin/env python3
"""把 CustomPinyinDictionary_Fcitx.txt 编译成引擎用的 ext_data.bin。

输入格式每行：词 拼音 频率（空格分隔；拼音可带 ' 分隔符；本词典频率列全为 0，忽略）。
输出格式（全部小端）：
    uint32 keyCount
    uint32 keysIdx[keyCount+1]    —— keysBlob 中每个键的字节偏移
    uint32 wordsIdx[keyCount+1]   —— wordsBlob 中每个键词表的字节偏移
    keysBlob                       —— 每个拼音 UTF-8 + NUL，按字节升序（拼音是 ASCII，即字典序）
    wordsBlob                      —— 每个词 UTF-8 + NUL，键内按输入顺序去重

用法：
    python3 tools/build_ext_dict.py <input.txt> <output.bin>
"""
import sys
import os
import struct
from collections import OrderedDict


def norm_py(py: str) -> str:
    return py.strip().replace("'", "").lower()


def main(src: str, dst: str) -> None:
    groups = OrderedDict()  # norm_py -> [word]
    with open(src, encoding='utf-8') as f:
        for line in f:
            line = line.rstrip('\n')
            if not line:
                continue
            parts = line.split(' ')
            if len(parts) < 2:
                continue
            w = parts[0]
            py = norm_py(parts[1])
            if not py or not w:
                continue
            g = groups.get(py)
            if g is None:
                groups[py] = [w]
            elif w not in g:
                g.append(w)

    keys = sorted(groups.keys())
    n = len(keys)

    header_size = 4 + 8 * (n + 1)  # uint32 keyCount + keysIdx + wordsIdx

    keys_bytes = bytearray()
    keys_idx = [0] * (n + 1)
    for i, k in enumerate(keys):
        keys_idx[i] = header_size + len(keys_bytes)
        keys_bytes += k.encode('utf-8') + b'\x00'
    keys_idx[n] = header_size + len(keys_bytes)

    words_base = header_size + len(keys_bytes)
    words_bytes = bytearray()
    words_idx = [0] * (n + 1)
    for i, k in enumerate(keys):
        words_idx[i] = words_base + len(words_bytes)
        for w in groups[k]:
            words_bytes += w.encode('utf-8') + b'\x00'
    words_idx[n] = words_base + len(words_bytes)

    header = struct.pack('<I', n)
    for arr in (keys_idx, words_idx):
        header += b''.join(struct.pack('<I', x) for x in arr)
    out = header + bytes(keys_bytes) + bytes(words_bytes)

    os.makedirs(os.path.dirname(dst), exist_ok=True)
    with open(dst, 'wb') as fo:
        fo.write(out)
    print(f'keys={n} size={len(out) / 1024 / 1024:.1f}MB -> {dst}')


if __name__ == '__main__':
    if len(sys.argv) != 3:
        print('usage: python3 tools/build_ext_dict.py <input.txt> <output.bin>')
        sys.exit(1)
    main(sys.argv[1], sys.argv[2])
