#!/usr/bin/env bash
#
# Build a WordPress + WooCommerce test environment for the integration suite.
#
# The suite needs four things the unit suite does not: a copy of WordPress core,
# a copy of WooCommerce, a database of its own, and a wp-tests-config.php
# pointing at them. This script produces all four, and is the same script CI runs
# and a person runs, so a green pipeline means something reproducible rather than
# something only GitHub knows how to do.
#
# THE DATABASE IS DESTROYED. The WordPress test library drops and recreates every
# table on every run. Never point this at a database that holds anything.
#
# Usage:
#   scripts/wordpress-test-env.sh [target-directory]
#
# Environment:
#   WP_DB_NAME      database name        (default oxyddt_tests)
#   WP_DB_USER      database user        (default root)
#   WP_DB_PASSWORD  database password    (default empty)
#   WP_DB_HOST      database host        (default 127.0.0.1)
#   WP_VERSION      WordPress to fetch   (default latest)
#   WC_VERSION      WooCommerce to fetch (default latest stable)
#
# It prints two lines on stdout, and nothing else:
#   the path of the config file, then the WooCommerce directory.
# Pass the first to PHPUnit as WP_PHPUNIT__TESTS_CONFIG and the second as
# WP_WOOCOMMERCE_DIR.

set -euo pipefail

target="${1:-${RUNNER_TEMP:-/tmp}/oxyddt-wp}"

db_name="${WP_DB_NAME:-oxyddt_tests}"
db_user="${WP_DB_USER:-root}"
db_password="${WP_DB_PASSWORD:-}"
db_host="${WP_DB_HOST:-127.0.0.1}"
wp_version="${WP_VERSION:-latest}"
wc_version="${WC_VERSION:-latest-stable}"

core="${target}/wordpress"
woocommerce="${target}/woocommerce"
config="${target}/wp-tests-config.php"

mkdir -p "${target}"

if [ ! -f "${core}/wp-load.php" ]; then
	# latest.tar.gz rather than the version-check API: parsing that API wrongly is
	# how a sibling project once installed WordPress 4.7 and spent an afternoon
	# proving the plugin needs PHP 8.
	if [ "${wp_version}" = "latest" ]; then
		archive="https://wordpress.org/latest.tar.gz"
	else
		archive="https://wordpress.org/wordpress-${wp_version}.tar.gz"
	fi

	# Progress goes to stderr. Stdout carries exactly two paths, so that a caller
	# can read them without sifting.
	echo "Fetching ${archive}" >&2

	mkdir -p "${core}"
	curl -fsSL "${archive}" | tar -xz --strip-components=1 -C "${core}"
fi

if [ ! -f "${woocommerce}/woocommerce.php" ]; then
	echo "Fetching WooCommerce ${wc_version}" >&2

	mkdir -p "${target}/wc-zip"
	curl -fsSL "https://downloads.wordpress.org/plugin/woocommerce.${wc_version}.zip" \
		-o "${target}/wc-zip/woocommerce.zip"
	unzip -q -o "${target}/wc-zip/woocommerce.zip" -d "${target}"
	rm -rf "${target}/wc-zip"
fi

cat >"${config}" <<PHP
<?php
define( 'ABSPATH', '${core}/' );

define( 'DB_NAME', '${db_name}' );
define( 'DB_USER', '${db_user}' );
define( 'DB_PASSWORD', '${db_password}' );
define( 'DB_HOST', '${db_host}' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

\$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'OxyDDT integration tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );

define( 'AUTH_KEY', 'tests-only-not-a-secret-1' );
define( 'SECURE_AUTH_KEY', 'tests-only-not-a-secret-2' );
define( 'LOGGED_IN_KEY', 'tests-only-not-a-secret-3' );
define( 'NONCE_KEY', 'tests-only-not-a-secret-4' );
define( 'AUTH_SALT', 'tests-only-not-a-secret-5' );
define( 'SECURE_AUTH_SALT', 'tests-only-not-a-secret-6' );
define( 'LOGGED_IN_SALT', 'tests-only-not-a-secret-7' );
define( 'NONCE_SALT', 'tests-only-not-a-secret-8' );
PHP

# The config is world-readable and holds a database password, so on a shared
# machine it should not be.
chmod 600 "${config}"

echo "${config}"
echo "${woocommerce}"
