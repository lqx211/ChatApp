#!/usr/bin/env bash
# ChatApp CLI 启动脚本
# 默认启动 Textual TUI (tui.py)
#   --cli   使用旧命令式 CLI (chatapp.py)
#   --ai    使用无头 AI CLI (ai_cli.py)，供 AI/脚本自动化调用，输出 JSON
# 示例:
#   ./chatapp.sh                    # TUI 界面
#   ./chatapp.sh --cli              # 旧版命令行交互
#   ./chatapp.sh --ai login --user _mobtest2 --pass password
#   ./chatapp.sh --ai settings get
#   ./chatapp.sh --help
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# 模式: tui (默认) / cli / ai
MODE="tui"
if [ "${1:-}" = "--cli" ]; then
    MODE="cli"
    shift
elif [ "${1:-}" = "--ai" ]; then
    MODE="ai"
    shift
elif [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
    sed -n '2,12p' "$0"
    exit 0
fi

# 优先使用全局 venv（内含 textual），再退到系统 python3
PYTHON=""
for p in "/Users/jadenlau/.venv/bin/python" "python3" "python"; do
    if [ -x "$p" ] || command -v "$p" >/dev/null 2>&1; then
        PYTHON="$p"
        break
    fi
done

if [ -z "$PYTHON" ]; then
    echo "错误: 未找到 Python3" >&2
    exit 1
fi

# 校验依赖：TUI 模式需要 textual
if [ "$MODE" = "tui" ]; then
    if ! "$PYTHON" -c "import textual" >/dev/null 2>&1; then
        echo "错误: 未安装 textual。请先安装: /Users/jadenlau/.venv/bin/pip install textual" >&2
        exit 1
    fi
    exec "$PYTHON" "$SCRIPT_DIR/tui.py" "$@"
elif [ "$MODE" = "ai" ]; then
    # AI CLI 无第三方依赖，任何 Python 均可；统一用上面选定的解释器
    exec "$PYTHON" "$SCRIPT_DIR/ai_cli.py" "$@"
else
    exec "$PYTHON" "$SCRIPT_DIR/chatapp.py" "$@"
fi