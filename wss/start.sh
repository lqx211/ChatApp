#!/usr/bin/env bash
# ChatApp — WebSocket 服务器启动脚本（macOS / Linux 通用）
#
# 用法:
#   ./wss/start.sh              前台运行（Ctrl+C 停止）
#   ./wss/start.sh -d           后台守护运行（nohup）
#   ./wss/start.sh stop         停止后台进程
#   ./wss/start.sh status       查看运行状态
#   ./wss/start.sh restart      重启后台进程
#   ./wss/start.sh logs         跟踪日志
#
# 说明:
#   - macOS 日常开发用 -d / stop / restart
#   - Linux 服务器生产环境建议改用 systemd（见 chatapp-wss.service）

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
SERVER="$SCRIPT_DIR/wss_server.php"
LOG_DIR="$SCRIPT_DIR"
PID_FILE="$LOG_DIR/wss.pid"
LOG_FILE="$LOG_DIR/wss.out.log"
ERR_FILE="$LOG_DIR/wss.log"

CMD_START="$PHP_BIN $SERVER"

print_status() {
    if [ -f "$PID_FILE" ]; then
        local pid
        pid=$(cat "$PID_FILE" 2>/dev/null || echo "")
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            echo "✅ WebSocket 服务器运行中 (PID $pid)"
            echo "   日志: $LOG_FILE"
            return 0
        else
            echo "⚠️   PID 文件存在但进程未运行（可能是异常退出）"
            rm -f "$PID_FILE"
        fi
    fi
    echo "❌ WebSocket 服务器未运行"
    return 1
}

start_daemon() {
    if print_status >/dev/null 2>&1; then
        echo "已在运行，无需重复启动"
        exit 0
    fi
    echo "启动 WebSocket 服务器 (后台)..."
    # 优先全局 php，找不到再尝试 Homebrew php
    if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
        if [ -x /opt/homebrew/bin/php ]; then
            PHP_BIN=/opt/homebrew/bin/php
        fi
    fi
    nohup "$PHP_BIN" "$SERVER" >> "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"
    sleep 1
    if kill -0 "$(cat "$PID_FILE")" 2>/dev/null; then
        local pid
        pid=$(cat "$PID_FILE")
        echo "✅ 已启动 (PID $pid)"
        echo "   日志: $LOG_FILE"
        echo "   测试: python3 ws_test/ws_cli_test.py wss://wss.lqx211.com (需先登录获取 token)"
    else
        echo "❌ 启动失败，请查看: $LOG_FILE"
        rm -f "$PID_FILE"
        exit 1
    fi
}

stop_daemon() {
    if [ ! -f "$PID_FILE" ]; then
        echo "没有运行中的进程"
        exit 0
    fi
    local pid
    pid=$(cat "$PID_FILE")
    if kill -0 "$pid" 2>/dev/null; then
        echo "停止进程 $pid ..."
        kill "$pid"
        sleep 1
        if kill -0 "$pid" 2>/dev/null; then
            kill -9 "$pid" 2>/dev/null || true
        fi
    fi
    rm -f "$PID_FILE"
    echo "✅ 已停止"
}

case "${1:-}" in
    -d|daemon|start)
        start_daemon
        ;;
    stop)
        stop_daemon
        ;;
    restart)
        stop_daemon
        sleep 1
        start_daemon
        ;;
    status)
        print_status
        ;;
    logs)
        tail -f "$LOG_FILE" 2>/dev/null || echo "日志文件不存在: $LOG_FILE"
        ;;
    *)
        echo "前台运行（Ctrl+C 停止）... 生产环境建议: ./wss/start.sh -d"
        exec "$PHP_BIN" "$SERVER"
        ;;
esac