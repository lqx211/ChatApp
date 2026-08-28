#!/usr/bin/env bash
# ======================================================================
# ChatApp — 修改 root admin（uid 10000）密码（CLI）
#
# 用法（在容器 / 源码目录任意位置）：
#   bash gh_set_admin_pass.sh                          # 交互式输入新密码
#   ADMIN_PASS='MyNewPass' bash gh_set_admin_pass.sh   # 非交互式
#
# 可选环境变量（与 gh_container_setup.sh 一致）：
#   DB_HOST / DB_PORT / DB_USER / DB_PASS / DB_NAME
# ======================================================================
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-chatapp}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

MYSQL_CMD="mysql -h ${DB_HOST} -P ${DB_PORT} -u ${DB_USER}"
if [ -n "${DB_PASS}" ]; then
    MYSQL_CMD="${MYSQL_CMD} -p${DB_PASS}"
fi

# 1) 获取新密码
if [ -n "${ADMIN_PASS:-}" ]; then
    NEW_PASS="${ADMIN_PASS}"
else
    printf "New admin password: "
    read -r NEW_PASS || true
    if [ -z "$NEW_PASS" ]; then
        echo "Empty password — abort."
        exit 1
    fi
    printf "Confirm: "
    read -r CONF || true
    if [ "$CONF" != "$NEW_PASS" ]; then
        echo "Passwords did not match — abort."
        exit 1
    fi
fi

# 2) bcrypt 哈希（php-cli，与 setup 一致）
HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT), "\n";' "$NEW_PASS" 2>/dev/null || true)"
if [ -z "$HASH" ]; then
    echo "php-cli unavailable — cannot hash password."
    exit 1
fi

# 3) 更新 uid 10000（只插入 HASH，无引号注入风险）
if ${MYSQL_CMD} "${DB_NAME}" -e "UPDATE users SET password='${HASH}' WHERE user_id=10000;" 2>/dev/null; then
    echo "  ✓ Admin (uid 10000) password updated."
else
    echo "  ⚠  Could not update admin password (MySQL running? uid 10000 present?)."
    exit 1
fi
