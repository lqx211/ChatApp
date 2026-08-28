#!/usr/bin/env bash
# ======================================================================
# ChatApp — Open‑source setup script
# ======================================================================
# This script:
#   1. Creates all required directory structures
#   2. Places .gitkeep files so Git tracks empty directories
#   3. Creates database + tables via schema.sql
#   4. Copies example config files when real configs are missing
#
# Usage:
#   chmod +x setup.sh
#   ./setup.sh
#
# Environment variables (optional, for automated deploys):
#   DB_HOST     — MySQL host           (default: 127.0.0.1)
#   DB_PORT     — MySQL port           (default: 3306)
#   DB_NAME     — Database name        (default: chatapp)
#   DB_USER     — MySQL user           (default: root)
#   DB_PASS     — MySQL password       (default: empty)
#   MAINT_USER  — Maintenance username (default: admin)
#   MAINT_PASS  — Maintenance password (default: auto‑generated)
# ======================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

# 防止在部署目标（/var/www/html）内运行：脚本应在源码目录运行
if [ "$ROOT_DIR" = "/var/www/html" ]; then
    echo "错误：请勿在 /var/www/html 内运行本脚本。请在源码目录（如 /workspaces/ChatApp）运行，脚本会自动部署到 /var/www/html。"
    exit 1
fi

echo "===== ChatApp Setup ====="
echo ""


echo "Prepare container environemnt."
sudo apt update -y

# --- PHP 源：sury.org（= ondrej/php PPA 的官方仓库，keyring 方式，不依赖 add-apt-repository）---
# Ubuntu 26.04 (resolute) 默认仓库无 php8.3，需添加 sury/ondrej 源
sudo apt install -y curl ca-certificates
sudo curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
sudo dpkg -i /tmp/debsuryorg-archive-keyring.deb
CODENAME="$(. /etc/os-release && echo "$VERSION_CODENAME")"
echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ $CODENAME main" | sudo tee /etc/apt/sources.list.d/php.list >/dev/null
sudo apt update -y || true

# 自动检测可用的 PHP 主版本（优先 8.3 —— 应用已验证版本；找不到退回 php 元包）
PHP=""
for v in 8.3 8.4 8.5; do
    if apt-cache show "php$v" >/dev/null 2>&1; then PHP="php$v"; break; fi
done
[ -z "$PHP" ] && PHP="php"
echo ">>> 检测到 PHP 包: $PHP"

# ----------------------------------------------------------------------
# MySQL root TCP 认证自愈
#   症状：应用经 TCP 127.0.0.1 以 root 空密码连接被拒（ERROR 1698）
#   原因：Ubuntu root 默认 auth_socket（仅 socket、拒绝 TCP）；MySQL 8.4 又禁用 mysql_native_password
#   修复：自动切换到 caching_sha2_password + 空密码，并验证 TCP 可连（8.0/8.4 均可用，PHP mysqlnd 支持）
# ----------------------------------------------------------------------
ensure_mysql_tcp_root() {
    if mysql -h127.0.0.1 -P3306 -uroot -e "SELECT 1" >/dev/null 2>&1; then
        echo "  ✓ MySQL root TCP 空密码连接正常"
        return 0
    fi
    echo "  ⚠ MySQL root 无法经 TCP 连接，自动修复认证方式…"
    local plugin=""
    plugin="$(sudo mysql -N -e "SELECT plugin FROM mysql.user WHERE user='root' AND host='localhost'" 2>/dev/null | head -1)" || true
    if [ -z "$plugin" ]; then
        echo "  ✗ 无法读取 root 认证插件（MySQL 未运行？请先启动 MySQL）"
        echo "    手动修复：sudo mysql -e \"ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY ''; FLUSH PRIVILEGES;\""
        return 1
    fi
    sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY ''; FLUSH PRIVILEGES;" >/dev/null 2>&1 \
        || sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY ''; FLUSH PRIVILEGES;" >/dev/null 2>&1
    if mysql -h127.0.0.1 -P3306 -uroot -e "SELECT 1" >/dev/null 2>&1; then
        echo "  ✓ 已修复：root 认证 ${plugin} → 现可经 TCP 空密码连接"
        return 0
    fi
    echo "  ✗ 自动修复失败（原插件 ${plugin}）。请手动执行："
    echo "      sudo mysql -e \"ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY ''; FLUSH PRIVILEGES;\""
    return 1
}

sudo apt install "$PHP" "$PHP-mbstring" "$PHP-gd" "$PHP-curl" apache2 mysql-server mysql-client "$PHP-mysql" php-mysql -y

echo "Install required packages."
sudo apt install mysql-server mysql-client apache2 "$PHP" "$PHP-mbstring" "$PHP-gd" "$PHP-curl" -y

echo "Setup database."
sudo service mysql start
sudo service mysql status

# 确保 root 可经 TCP 空密码连接（auth_socket / 8.4 自动修复），否则后续建库/导入/种子全会被拒
ensure_mysql_tcp_root

sudo apt install php-mysql "$PHP-mysql" "$PHP-mbstring" "$PHP-gd" "$PHP-curl" -y

# --- WSS 配置：自动探测本机默认 IPv4，私网地址写 <IP>:9090（供 OOBE/客户端直连）---
LAN_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}')" || true
[ -z "$LAN_IP" ] && LAN_IP="$(hostname -I 2>/dev/null | awk '{print $1}')" || true
if [ -n "$LAN_IP" ]; then
    echo "  → 检测到本机 IPv4: $LAN_IP ，写入 WSS 私网地址 ${LAN_IP}:9090"
    php -r '
$f = "config/wss_server.php";
$cfg = ["local" => "127.0.0.1:9090", "private" => "", "public" => "wss://wss.lqx211.com"];
if (is_file($f)) { $old = @include $f; if (is_array($old)) { foreach ($cfg as $k=>$v) if (isset($old[$k]) && trim((string)$old[$k]) !== "") $cfg[$k] = trim((string)$old[$k]); } }
if (trim((string)$cfg["private"]) === "" || $cfg["private"] === "0.0.0.0:9090") $cfg["private"] = $argv[1] . ":9090";
@file_put_contents($f, "<?php\n// Auto-generated by setup: private = LAN IP " . $argv[1] . ":9090\nreturn " . var_export($cfg, true) . ";\n");
' "$LAN_IP" 2>/dev/null || echo "  ⚠ 写 WSS 配置失败（php-cli 不可用？）"
else
    echo "  ⚠ 未能探测本机 IPv4，跳过 WSS 私网地址自动填充"
fi

echo "Setup server."
# 清空站点内容（保留 /var/www/html 目录本身，避免删除运行脚本的 cwd）
sudo find /var/www/html -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null
# 完整复制仓库（含 .git，供 Upgrade System 使用；用 ROOT_DIR 绝对源，避免相对路径递归自身）
sudo cp -R "$ROOT_DIR"/. /var/www/html/
# 运行用户写权限（Upgrade System 的 git checkout / 上传需要；容器测试环境）
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 777 /tmp

# --- Security hardening + 友好错误页 ---
# Honor .htaccess (so the data/*.htaccess deny rules take effect), disable
# directory listing, hide PHP errors, and route 403/404/500 to ChatApp's
# friendly error pages.
sudo tee /etc/apache2/conf-available/chatapp-security.conf > /dev/null <<'EOF'
<Directory /var/www/html>
    AllowOverride All
    Options -Indexes
    php_value display_errors 0
    php_value log_errors 1
</Directory>

# ChatApp 友好错误页：403 / 404 / 500 自动导向
ErrorDocument 403 /errors/403.php
ErrorDocument 404 /errors/404.php
ErrorDocument 500 /errors/500.php
EOF
sudo a2enconf chatapp-security
sudo a2enmod rewrite 2>/dev/null || true
sudo service apache2 start
sudo service apache2 status



# ----------------------------------------------------------------------
# 1.  Ensure directory structure
# ----------------------------------------------------------------------
echo "[1/4] Creating directory structure ..."

# Data directories (user‑generated content lives here)
mkdir -p data/ce
mkdir -p data/cep
mkdir -p data/donation
mkdir -p data/res/emoji
mkdir -p data/res/fileicon
mkdir -p data/res/sound
mkdir -p data/res/svg
mkdir -p data/res/wallpaper
mkdir -p data/sc
mkdir -p data/ticket
mkdir -p data/user

# Application directories
mkdir -p apps/music/css
mkdir -p apps/music/images
mkdir -p apps/music/js
mkdir -p apps/music/plugins
mkdir -p apps/devtools/bootstrap
mkdir -p apps/devtools/codemirror
mkdir -p apps/devtools/css
mkdir -p apps/devtools/developtoolbox
mkdir -p apps/devtools/icons
mkdir -p apps/devtools/img
mkdir -p apps/devtools/js
mkdir -p apps/devtools/page

mkdir -p css/fonts
mkdir -p errors
mkdir -p filedown/indexfiles
mkdir -p lang
mkdir -p maintenance
mkdir -p modern
mkdir -p ticket

# ----------------------------------------------------------------------
# 2.  Place .gitkeep files so Git tracks empty directories
# ----------------------------------------------------------------------
echo "[2/4] Creating .gitkeep markers ..."

# Determine which directories need a .gitkeep.
# We skip directories that already have tracked content.  The heuristic:
# if an ignore rule hides all files inside, we still place .gitkeep manually.
#
# We also skip .gitkeep inside user‑sub‑dirs (data/user/{uid}/) because
# those are created at runtime.

KEEP_DIRS=(
    "data/ce"
    "data/cep"
    "data/donation"
    "data/res/emoji"
    "data/res/fileicon"
    "data/res/sound"
    "data/res/svg"
    "data/res/wallpaper"
    "data/sc"
    "data/ticket"
    "data/user"
    "apps/music/css"
    "apps/music/images"
    "apps/music/js"
    "apps/music/plugins"
    "apps/devtools/bootstrap"
    "apps/devtools/codemirror"
    "apps/devtools/css"
    "apps/devtools/developtoolbox"
    "apps/devtools/icons"
    "apps/devtools/img"
    "apps/devtools/js"
    "apps/devtools/page"
    "css/fonts"
    "errors"
    "filedown/indexfiles"
    "lang"
    "modern"
    "ticket"
)

for d in "${KEEP_DIRS[@]}"; do
    touch "$d/.gitkeep" 2>/dev/null || true
done

# ----------------------------------------------------------------------
# 3.  Create example config files (when real ones are missing)
# ----------------------------------------------------------------------
echo "[3/4] Creating config templates ..."

if [ ! -f maintenance/config.php ] || grep -q "__CHANGE_ME__" maintenance/config.php; then
    # 自动生成复杂凭据（文件不存在，或仍是 __CHANGE_ME__ 占位符）
    if [ -f maintenance/config.php ]; then
        cp maintenance/config.php maintenance/config.php.placeholder-bak
    fi
    MAINT_GEN_PASS="$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | cut -c1-24)"
    MAINT_GEN_SECRET="$(openssl rand -hex 32)"
    cat > maintenance/config.php << PHPEOF
<?php
/**
 * ChatApp — Maintenance admin credentials
 *
 * AUTO-GENERATED during container setup.
 * Override via MAINT_USER / MAINT_PASS / MAINT_SECRET env vars if needed.
 */
\$MAINT_USER   = getenv('MAINT_USER') ?: 'admin';
\$MAINT_PASS   = getenv('MAINT_PASS') ?: '${MAINT_GEN_PASS}';
\$MAINT_SECRET = getenv('MAINT_SECRET') ?: '${MAINT_GEN_SECRET}';
PHPEOF
    echo "  → maintenance/config.php created (user: admin, pass: ${MAINT_GEN_PASS})"
else
    echo "  → maintenance/config.php already exists — skipping"
fi

# 同步到部署目录：运行时 Apache 从 /var/www/html 读取 maintenance/config.php
# （上面的 cp -R 在生成之前已执行，必须在此补一次部署同步，否则全新容器里该文件缺失）
if [ -f maintenance/config.php ]; then
    sudo cp maintenance/config.php /var/www/html/maintenance/config.php
    sudo chown www-data:www-data /var/www/html/maintenance/config.php
    echo "  → maintenance/config.php synced to /var/www/html"
fi

# Copy api/config.php as a template if it doesn't exist
if [ ! -f api/config.example.php ]; then
    cp api/config.php api/config.example.php
    echo "  → api/config.example.php created (template for reference)"
fi

# ----------------------------------------------------------------------
# 4.  MySQL database setup
# ----------------------------------------------------------------------
echo "[4/4] Setting up MySQL database ..."

# 防御性再确保一次（幂等、秒级）——若中途 MySQL 重启/认证回退，这里兜底
ensure_mysql_tcp_root

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-chatapp}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

# Build MySQL connection args
MYSQL_CMD="mysql -h ${DB_HOST} -P ${DB_PORT} -u ${DB_USER}"
if [ -n "${DB_PASS}" ]; then
    MYSQL_CMD="${MYSQL_CMD} -p${DB_PASS}"
fi

# Create database if it doesn't exist
# 注意：不要静默吞错——连接失败时把真实 MySQL 错误打出来，方便定位（如 auth_socket 拒绝 TCP）
echo "  → Ensuring database '${DB_NAME}' exists ..."
if ! ${MYSQL_CMD} -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci;"; then
    echo "  ⚠  Could not create database. Is MySQL running? Check your credentials."
    echo "     You can import schema.sql manually later."
fi

# Run schema
if [ -f schema.sql ]; then
    echo "  → Importing schema.sql ..."
    ${MYSQL_CMD} "${DB_NAME}" < schema.sql 2>/dev/null || {
        echo "  ⚠  schema.sql import had errors. Some tables may already exist — that's usually fine."
    }
else
    echo "  ⚠  schema.sql not found. Skipping."
fi

# --- Seed the root admin (uid 10000) BEFORE registration is opened ---
# Registration is public; on a fresh DB the first registered user would otherwise
# become uid 10000 = root. Create a random-password admin here.
if [ -n "${ADMIN_USER:-}" ] && [ -n "${ADMIN_PASS:-}" ]; then
    ADMIN_USER_S="${ADMIN_USER}"
    ADMIN_PASS_S="${ADMIN_PASS}"
else
    ADMIN_USER_S="${ADMIN_USER:-admin}"
    ADMIN_PASS_S="$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | cut -c1-24)"
    # 交互式：允许在 CLI 直接输入自定义 admin 密码（仅当终端 TTY；直接回车则保留随机密码）
    if [ -t 0 ] && [ -z "${ADMIN_PASS:-}" ]; then
        printf "Set your own admin password for '%s' (Enter to keep the random one): " "${ADMIN_USER_S}"
        read -r _custom_pass || true
        if [ -n "$_custom_pass" ]; then
            printf "Confirm password: "
            read -r _custom_confirm || true
            if [ -n "$_custom_confirm" ] && [ "$_custom_confirm" = "$_custom_pass" ]; then
                ADMIN_PASS_S="$_custom_pass"
                echo "  ✓ Using your custom admin password."
            else
                echo "  ⚠  Passwords did not match — keeping the random password."
            fi
        fi
    fi
fi
ADMIN_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT), "\n";' "$ADMIN_PASS_S" 2>/dev/null || true)"
if [ -n "$ADMIN_HASH" ]; then
    ${MYSQL_CMD} "${DB_NAME}" -e "INSERT INTO users (user_id, username, password, role, enabled, cache_key, created_at) VALUES (10000, '${ADMIN_USER_S}', '${ADMIN_HASH}', 'admin', 1, '$(openssl rand -hex 32)', NOW()) ON DUPLICATE KEY UPDATE username = username;" 2>/dev/null || echo "  ⚠  Could not seed admin (table may not exist yet)."
    echo "  → Seeded root admin: username='${ADMIN_USER_S}'"
    echo "    ⚠ Password: '${ADMIN_PASS_S}' — SAVE THIS NOW, change after first login."
else
    echo "  ⚠  php-cli unavailable; cannot hash admin password. Seed admin manually."
fi

# --- WSS systemd 服务：杀掉游离进程，交给 systemd 统一管理 ---
echo "Setup WSS systemd service."
# 先清掉所有游离 wss_server.php（避免和 systemd 抢 9090 端口 → EADDRINUSE 无限重启）
sudo pkill -9 -f wss_server.php 2>/dev/null || true
sleep 1
if [ -d /run/systemd/system ] && command -v systemctl >/dev/null 2>&1 && [ -f /var/www/html/wss/chatapp-wss.service ]; then
    sudo cp /var/www/html/wss/chatapp-wss.service /etc/systemd/system/
    sudo systemctl daemon-reload || true
    sudo systemctl enable chatapp-wss 2>/dev/null || true
    sudo systemctl restart chatapp-wss || true
    sleep 2
    if systemctl is-active --quiet chatapp-wss; then
        echo "  ✓ WSS 服务运行中（systemd chatapp-wss，端口 9090）"
    else
        echo "  ⚠ WSS 服务启动失败，排查: journalctl -u chatapp-wss -n 30 --no-pager"
    fi
else
    echo "  ⚠ 无 systemd 或服务文件缺失，WSS 请手动启动: cd /var/www/html/wss && ./start.sh -d"
fi

# ----------------------------------------------------------------------
# Done
# ----------------------------------------------------------------------

