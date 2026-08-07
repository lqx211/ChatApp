#!/usr/bin/env bash
# ChatApp CLI 启动脚本
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# 使用虚拟环境的 python 或系统 python3
PYTHON=""
for p in "python3" "python"; do
    if command -v "$p" >/dev/null 2>&1; then
        PYTHON="$p"
        break
    fi
done

if [ -z "$PYTHON" ]; then
    echo "错误: 未找到 Python3" >&2
    exit 1
fi

exec "$PYTHON" "$SCRIPT_DIR/chatapp.py" "$@"