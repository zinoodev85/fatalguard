# FatalGuard MCP server — sandboxed demo over stdio.
#
# A throwaway WordPress (SQLite, no MySQL, no web server) with FatalGuard active and a
# little real error data: PHP warnings from a demo plugin and one fatal error captured by
# FatalGuard's own fatal-error drop-in. The container speaks MCP JSON-RPC on stdin/stdout.
#
#   docker build -t fatalguard-mcp . && docker run -i --rm fatalguard-mcp
#
# Real use is the plugin on your own site: https://wordpress.org/plugins/fatalguard/

FROM php:8.3.36-cli-alpine

# Pinned versions.
ARG WP_VERSION=7.1.4
ARG WP_CLI_VERSION=2.12.0
ARG SQLITE_PLUGIN_VERSION=3.1.3

ENV WP_ROOT=/var/www/html
WORKDIR /var/www/html

# The plugin is this repository; the stdio bridge and the demo data sit in docker/.
COPY . /usr/src/fatalguard

# One layer: wp-cli, WordPress core, the official SQLite Database Integration drop-in,
# then the plugin. WP_DEBUG_LOG points at FatalGuard's protected log, as its settings
# toggle does. The demo data is real: PHP writes the warnings, and the plugin's own
# fatal-error drop-in captures the crash (the "fatal" run exits non-zero by design).
RUN set -eux; \
	curl -fsSL -o /usr/local/bin/wp \
		"https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"; \
	chmod +x /usr/local/bin/wp; \
	curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" | tar -xz --strip-components=1; \
	rm -rf wp-content/plugins/akismet wp-content/plugins/hello.php; \
	mkdir -p wp-content/uploads; \
	curl -fsSL -o /tmp/sqlite.zip \
		"https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_PLUGIN_VERSION}.zip"; \
	unzip -q /tmp/sqlite.zip -d wp-content/plugins; \
	rm /tmp/sqlite.zip; \
	sed -e 's#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#/var/www/html/wp-content/plugins/sqlite-database-integration#' \
		-e 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#' \
		wp-content/plugins/sqlite-database-integration/db.copy > wp-content/db.php; \
	wp --allow-root config create --dbname=wordpress --dbuser=unused --dbpass=unused --skip-check; \
	wp --allow-root config set WP_DEBUG true --raw; \
	wp --allow-root config set WP_DEBUG_DISPLAY false --raw; \
	wp --allow-root core install --url=http://localhost --title="FatalGuard MCP demo" \
		--admin_user=admin --admin_password=unused-sandbox --admin_email=admin@example.com --skip-email; \
	wp --allow-root plugin activate sqlite-database-integration; \
	wp --allow-root theme delete --all; \
	mkdir -p /opt/fatalguard-mcp wp-content/plugins/demo-shop; \
	mv /usr/src/fatalguard/docker/fatalguard-mcp-stdio.php /opt/fatalguard-mcp/; \
	mv /usr/src/fatalguard/docker/demo-shop.php wp-content/plugins/demo-shop/; \
	rm -rf /usr/src/fatalguard/docker /usr/src/fatalguard/Dockerfile /usr/src/fatalguard/.dockerignore \
		/usr/src/fatalguard/glama.json /usr/src/fatalguard/README.md /usr/src/fatalguard/server.json; \
	mv /usr/src/fatalguard wp-content/plugins/fatalguard; \
	wp --allow-root plugin activate fatalguard; \
	wp --allow-root config set WP_DEBUG_LOG "$(wp --allow-root eval 'echo fatalguard_php_log();')"; \
	DEMO_SHOP=warnings wp --allow-root eval 'require WP_PLUGIN_DIR . "/demo-shop/demo-shop.php";'; \
	DEMO_SHOP=fatal wp --allow-root eval 'require WP_PLUGIN_DIR . "/demo-shop/demo-shop.php";' > /dev/null 2>&1 || true; \
	chown -R www-data:www-data /var/www/html

USER www-data
CMD ["php", "/opt/fatalguard-mcp/fatalguard-mcp-stdio.php"]
