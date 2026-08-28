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

read -p "Are you sure to run setup? [yn] " a

if [ "$a" != "y" ]; then exit; fi

ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

echo "===== ChatApp Setup ====="
echo ""

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
 * AUTO-GENERATED during setup.
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

# Copy api/config.php as a template if it doesn't exist
if [ ! -f api/config.example.php ]; then
    cp api/config.php api/config.example.php
    echo "  → api/config.example.php created (template for reference)"
fi

# ----------------------------------------------------------------------
# 4.  MySQL database setup
# ----------------------------------------------------------------------
echo "[4/4] Setting up MySQL database ..."

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
echo "  → Ensuring database '${DB_NAME}' exists ..."
${MYSQL_CMD} -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci;" 2>/dev/null || {
    echo "  ⚠  Could not create database. Is MySQL running? Check your credentials."
    echo "     You can import schema.sql manually later."
}

# Run schema
if [ -f schema.sql ]; then
    echo "  → Importing schema.sql ..."
    ${MYSQL_CMD} "${DB_NAME}" < schema.sql 2>/dev/null || {
        echo "  ⚠  schema.sql import had errors. Some tables may already exist — that's usually fine."
    }
else
    echo "  ⚠  schema.sql not found. Skipping."
fi

# if you are on mac, remove sudo below
sudo apt install mysql-server mysql-client apache2 php8.3 php8.3-mbstring php8.3-gd php8.3-curl -y # its going to be brew and without sudo in mac
sudo mysql -e "CREATE DATABASE IF NOT EXISTS chatapp DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci;"
# MySQL 8.4 已禁用 mysql_native_password；用 caching_sha2_password（mysqlnd/PHP 完整支持）让 root 可 TCP 空密码登录
sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '';"
sudo mysql -e "FLUSH PRIVILEGES;"
sudo apt install php-mysql php8.3-mysql php8.3-mbstring php8.3-gd php8.3-curl -y

# --- Security hardening: honor .htaccess (so data/*.htaccess rules apply)
# --- and disable directory listing. (On mac/brew adjust the conf path.)
if [ -d /etc/apache2/conf-available ]; then
    sudo tee /etc/apache2/conf-available/chatapp-security.conf > /dev/null <<'EOF'
<Directory /var/www/html>
    AllowOverride All
    Options -Indexes
</Directory>
EOF
    sudo a2enconf chatapp-security || true
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
fi
ADMIN_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT), "\n";' "$ADMIN_PASS_S" 2>/dev/null || true)"
if [ -n "$ADMIN_HASH" ]; then
    ${MYSQL_CMD} "${DB_NAME}" -e "INSERT INTO users (user_id, username, password, role, enabled, cache_key, created_at) VALUES (10000, '${ADMIN_USER_S}', '${ADMIN_HASH}', 'admin', 1, '$(openssl rand -hex 32)', NOW()) ON DUPLICATE KEY UPDATE username = username;" 2>/dev/null || echo "  ⚠  Could not seed admin (table may not exist yet)."
    echo "  → Seeded root admin: username='${ADMIN_USER_S}'"
    echo "    ⚠ Password: '${ADMIN_PASS_S}' — SAVE THIS NOW, change after first login."
else
    echo "  ⚠  php-cli unavailable; cannot hash admin password. Seed admin manually."
fi

# ----------------------------------------------------------------------
# Done
# ----------------------------------------------------------------------
echo ""
echo "===== Setup complete! ====="
echo ""
echo "Next steps:"
echo "  1. Edit maintenance/config.php and set your own credentials."
echo "  2. Review api/config.php for DB_HOST / DB_PASS if using a remote DB."
echo "  3. Point your web server to:  $(pwd)"
echo "  4. Open the app in your browser."
echo ""
