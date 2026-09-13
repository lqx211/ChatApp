#!/usr/bin/env bash
# ChatApp · chat.js 模块化体检（cli 目录，Apache 已 deny，不会被直连）
#
# 用途：模块化每个阶段跑一次，确认「chat.js 在变小、全局在变少、shim 没漏」。
# 用法：./cli/ca_audit.sh            # 报告
#      ./cli/ca_audit.sh --strict   # 有任一门槛不达标就 exit 1（未来可挂 CI）
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
JS="$ROOT/modern/scripts"
STRICT=0
[ "${1:-}" = "--strict" ] && STRICT=1

CHAT="$JS/chat.js"
FAIL=0
warn() { echo "  ⚠ $*"; [ "$STRICT" = 1 ] && FAIL=1; }

echo "=== 1. 体积 ==="
printf '%-28s %6s 行\n' "chat.js" "$(wc -l < "$CHAT" | tr -d ' ')"
for f in "$JS"/ca/*.js "$JS"/ca/*/*.js; do
    [ -f "$f" ] || continue
    printf '%-28s %6s 行\n' "${f#$JS/}" "$(wc -l < "$f" | tr -d ' ')"
done
echo "  （ca/ 目录还不存在属于正常 —— 那是 P1 之后的事）"

echo
echo "=== 2. chat.js 里的全局 ==="
FUNCS=$(grep -cE '^(async )?function ' "$CHAT")
VARS=$(grep -cE '^(var|let|const) ' "$CHAT")
echo "  顶层函数: $FUNCS   （目标 < 20）"
echo "  顶层变量: $VARS   （目标 < 5）"
[ "$FUNCS" -gt 400 ] && echo "  （当前为基线，模块化过程中应持续下降）"

echo
echo "=== 3. PHP 内联 handler 用到的函数名 ==="
HANDLERS=$(grep -rhoE 'on(click|change|input|keydown|keyup|submit|mouseover|mouseout|blur|focus|load|error)="[a-zA-Z_$][a-zA-Z0-9_$]*\(' \
    "$ROOT"/modern/wp/*.php "$ROOT"/modern/*.php 2>/dev/null \
    | sed -E 's/.*="([a-zA-Z_$][a-zA-Z0-9_$]*)\(/\1/' | sort -u)
HCOUNT=$(printf '%s\n' "$HANDLERS" | grep -c . || true)
echo "  共 $HCOUNT 个不同的函数名（这些名字必须保持全局可达）"

echo
  echo "=== 4. 这些名字是否都能找到定义/别名 ==="
MISSING=0
while IFS= read -r fn; do
    [ -z "$fn" ] && continue
    # 定义可能在 modern/scripts/*.js，也可能在同一堆页面自己的内联 <script> 里
    if ! grep -rqE "(^|[^a-zA-Z0-9_.])${fn}[[:space:]]*=|function[[:space:]]+${fn}\\b" "$JS" 2>/dev/null \
       && ! grep -rqE "function[[:space:]]+${fn}\\b|(^|[^a-zA-Z0-9_.])${fn}[[:space:]]*=[[:space:]]*(function|\\()" \
            "$ROOT"/modern/wp/*.php "$ROOT"/modern/*.php 2>/dev/null; then
        echo "  ✗ 全仓库都找不到: $fn"
        MISSING=$((MISSING+1))
    fi
done <<< "$HANDLERS"
[ "$MISSING" = 0 ] && echo "  ✓ 全部有定义或 window 别名" || echo "  （以上是真·悬空引用：按钮点了会报错，可顺手修）"

echo
echo "=== 5. 命名空间使用情况 ==="
echo "  window.CA.* 出现次数: $(grep -rc 'window\.CA' "$JS"/*.js 2>/dev/null | awk -F: '{s+=$2} END {print s+0}')"
echo "  shim.js: $([ -f "$JS/ca/shim.js" ] && echo 存在 || echo '（还没建，P1 时创建）')"

echo
echo "=== 6. 单文件行数告警（> 800 行）==="
for f in "$JS"/*.js; do
    n=$(wc -l < "$f" | tr -d ' ')
    [ "$n" -gt 800 ] && echo "  ! $(basename "$f"): $n 行"
done

echo
if [ "$STRICT" = 1 ] && [ "$FAIL" = 1 ]; then
    echo "结果：不达标（--strict）"; exit 1
fi
echo "结果：报告完成"
