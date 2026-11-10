#!/usr/bin/env bash
# Glama build step (Debian base, run as root at image build time). Glama generates its own
# Dockerfile, so the Dockerfile in this repo is not used there; this script does the same
# job: a throwaway WordPress on SQLite with FatalGuard active and real demo errors.
# Glama settings: build steps ["bash docker/glama-build.sh"],
#                 CMD ["php", "/opt/fatalguard-mcp/fatalguard-mcp-stdio.php"]
set -eux

WP_VERSION=7.1.4
WP_CLI_VERSION=2.12.0
SQLITE_PLUGIN_VERSION=3.1.3
WP_ROOT=/var/www/html
REPO="$(cd "$(dirname "$0")/.." && pwd)"

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends php-cli php-sqlite3 php-mbstring php-xml curl unzip ca-certificates
rm -rf /var/lib/apt/lists/*

curl -fsSL -o /usr/local/bin/wp \
	"https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
chmod +x /usr/local/bin/wp

mkdir -p "$WP_ROOT"
cd "$WP_ROOT"
curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" | tar -xz --strip-components=1
rm -rf wp-content/plugins/akismet wp-content/plugins/hello.php
mkdir -p wp-content/uploads
curl -fsSL -o /tmp/sqlite.zip \
	"https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_PLUGIN_VERSION}.zip"
unzip -q /tmp/sqlite.zip -d wp-content/plugins
rm /tmp/sqlite.zip
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#${WP_ROOT}/wp-content/plugins/sqlite-database-integration#" \
	-e 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#' \
	wp-content/plugins/sqlite-database-integration/db.copy > wp-content/db.php

wp --allow-root config create --dbname=wordpress --dbuser=unused --dbpass=unused --skip-check
wp --allow-root config set WP_DEBUG true --raw
wp --allow-root config set WP_DEBUG_DISPLAY false --raw
wp --allow-root core install --url=http://localhost --title="FatalGuard MCP demo" \
	--admin_user=admin --admin_password=unused-sandbox --admin_email=admin@example.com --skip-email
wp --allow-root plugin activate sqlite-database-integration
wp --allow-root theme delete --all

# The plugin is this repository, minus the mirror-only files.
mkdir -p /opt/fatalguard-mcp wp-content/plugins/demo-shop wp-content/plugins/fatalguard
cp "$REPO/docker/fatalguard-mcp-stdio.php" /opt/fatalguard-mcp/
cp "$REPO/docker/demo-shop.php" wp-content/plugins/demo-shop/
tar -C "$REPO" --exclude=./.git --exclude=./docker --exclude=./Dockerfile --exclude=./.dockerignore \
	--exclude=./glama.json --exclude=./README.md --exclude=./server.json -cf - . \
	| tar -C wp-content/plugins/fatalguard -xf -
wp --allow-root plugin activate fatalguard
wp --allow-root config set WP_DEBUG_LOG "$(wp --allow-root eval 'echo fatalguard_php_log();')"

# Real demo data: PHP writes the warnings; FatalGuard's drop-in captures the fatal
# (that run exits non-zero by design).
DEMO_SHOP=warnings wp --allow-root eval 'require WP_PLUGIN_DIR . "/demo-shop/demo-shop.php";'
DEMO_SHOP=fatal wp --allow-root eval 'require WP_PLUGIN_DIR . "/demo-shop/demo-shop.php";' > /dev/null 2>&1 || true
