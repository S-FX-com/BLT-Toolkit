#!/usr/bin/env bash
# Disposable WordPress on SQLite for BLT Toolkit smoke tests (CLAUDE.md
# "Testing", SPEC 18). Reproduces the shared dev environment: WordPress core,
# the SQLite Database Integration drop-in, WP-CLI, an admin/admin install,
# this repo symlinked in as wp-content/plugins/blt-toolkit, and a blt-site
# helper that gives every agent or tester its own database on one core.
#
# Usage:
#   bin/dev-site.sh TARGET_DIR [WP_VERSION]
#
#   TARGET_DIR   Where to build. Must be absent or empty, and outside this
#                repo: the script writes nothing inside the repo.
#   WP_VERSION   "latest" (default) or a release such as 6.3 or 6.3.5. Use
#                6.3 to smoke-test the WordPress floor (SPEC 3).
#
# Environment:
#   BLT_OBJECT_CACHE=redis  Also install the Redis Object Cache plugin and a
#                           guarded object-cache.php drop-in. At run time the
#                           same variable switches the persistent cache on
#                           for that process only, so one install covers both
#                           rows of the smoke matrix. Start redis-server first;
#                           BLT_REDIS_HOST and BLT_REDIS_PORT override
#                           127.0.0.1:6379.
#   BLT_SQLITE_VERSION      SQLite Database Integration release (default
#                           latest-stable). Its header asks for WordPress 6.4,
#                           but the drop-in loads it without activation, so
#                           6.3 installs work; pin an older one if that breaks.
#   PHP                     PHP binary (default php; needs pdo_sqlite).
#
# Needs curl, tar and unzip. Downloads go to a temporary folder inside
# TARGET_DIR and are removed when the script exits.
#
# Result:
#   TARGET_DIR/wordpress/                 Core. wp-config.php reads BLT_DB_FILE,
#                                         BLT_SITE_URL and BLT_DEBUG_LOG.
#   TARGET_DIR/wordpress/wp-content/database/clean.sqlite
#                                         Snapshot taken right after install.
#   TARGET_DIR/bin/wp                     WP-CLI phar.
#   TARGET_DIR/bin/blt-site               Site helper (run it bare for help):
#     blt-site new NAME            fresh site DB copied from clean.sqlite (admin/admin)
#     blt-site wp NAME ARGS...     run WP-CLI against NAME's DB
#     blt-site serve NAME PORT     php -S at http://localhost:PORT (background)
#     blt-site stop PORT           stop that server
#     blt-site log NAME            path of NAME's PHP debug log (WP_DEBUG is on)
#
# Example:
#   bin/dev-site.sh ~/wp-blt 6.3
#   ~/wp-blt/bin/blt-site new demo
#   ~/wp-blt/bin/blt-site wp demo plugin activate blt-toolkit
#   ~/wp-blt/bin/blt-site serve demo 8890      # log in as admin / admin
#   ~/wp-blt/bin/blt-site stop 8890
#   rm -rf ~/wp-blt                            # nothing else to clean up
set -euo pipefail

usage() {
	sed -n '2,/^set -euo/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'
	exit "${1:-1}"
}

die() {
	echo "dev-site.sh: $*" >&2
	exit 1
}

# Absolute, symlink-free path of a file or folder that may not exist yet.
abspath() {
	local p="$1" dir rest=''
	case "$p" in
		/*) ;;
		*) p="$PWD/$p" ;;
	esac
	dir="$p"
	while [ ! -d "$dir" ]; do
		rest="/$(basename "$dir")${rest}"
		dir="$(dirname "$dir")"
	done
	printf '%s%s\n' "$(cd "$dir" && pwd -P)" "$rest"
}

fetch() {
	curl -fsSL --retry 3 --connect-timeout 20 -o "$2" "$1" || die "download failed: $1"
}

case "${1:-}" in
	'' ) usage 1 ;;
	-h | --help ) usage 0 ;;
esac

TARGET_ARG="$1"
WP_VERSION="${2:-latest}"
PHP="${PHP:-php}"
SQLITE_VERSION="${BLT_SQLITE_VERSION:-latest-stable}"
WITH_REDIS=0
if [ "${BLT_OBJECT_CACHE:-}" = 'redis' ]; then
	WITH_REDIS=1
fi

REPO="$(cd "$(dirname "$0")/.." && pwd -P)"
TARGET="$(abspath "$TARGET_ARG")"

case "$TARGET/" in
	"$REPO/"*) die "TARGET_DIR must be outside the repo ($REPO)." ;;
esac
case "$WP_VERSION" in
	latest) WP_URL='https://wordpress.org/latest.tar.gz' ;;
	[0-9]*.[0-9]*)
		printf '%s' "$WP_VERSION" | grep -Eq '^[0-9]+\.[0-9]+(\.[0-9]+)?$' || die "not a WordPress version: $WP_VERSION"
		WP_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
		;;
	*) die "not a WordPress version: $WP_VERSION (use latest, 6.3, 6.3.5, ...)" ;;
esac
printf '%s' "$SQLITE_VERSION" | grep -Eq '^(latest-stable|[0-9]+(\.[0-9]+)*)$' || die "not a plugin version: $SQLITE_VERSION"

for cmd in curl tar unzip "$PHP"; do
	command -v "$cmd" > /dev/null 2>&1 || die "missing command: $cmd"
done
"$PHP" -m | grep -qi '^pdo_sqlite$' || die "$PHP lacks the pdo_sqlite extension."
if [ -e "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2> /dev/null)" ]; then
	die "$TARGET exists and is not empty."
fi

WPDIR="$TARGET/wordpress"
CONTENT="$WPDIR/wp-content"
mkdir -p "$TARGET/bin" "$TARGET/logs"
DL="$(mktemp -d "$TARGET/.downloads.XXXXXX")"
trap 'rm -rf "$DL"' EXIT

echo "Building a WordPress ${WP_VERSION} dev site in ${TARGET}"

# 1. Core.
fetch "$WP_URL" "$DL/wordpress.tar.gz"
tar -xzf "$DL/wordpress.tar.gz" -C "$TARGET"
[ -f "$WPDIR/wp-settings.php" ] || die "the WordPress archive did not unpack to wordpress/."

# 2. SQLite drop-in: db.copy with its two placeholders filled in.
fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_VERSION}.zip" "$DL/sqlite.zip"
unzip -q "$DL/sqlite.zip" -d "$CONTENT/plugins"
SQLITE_DIR="$CONTENT/plugins/sqlite-database-integration"
[ -f "$SQLITE_DIR/db.copy" ] || die "sqlite-database-integration has no db.copy."
"$PHP" -r '
	$src = file_get_contents( $argv[1] );
	$out = str_replace(
		array( "{SQLITE_IMPLEMENTATION_FOLDER_PATH}", "{SQLITE_PLUGIN}" ),
		array( $argv[3], "sqlite-database-integration/load.php" ),
		$src
	);
	if ( false !== strpos( $out, "{SQLITE_" ) ) {
		fwrite( STDERR, "db.copy has a placeholder this script does not know.\n" );
		exit( 1 );
	}
	file_put_contents( $argv[2], $out );
' "$SQLITE_DIR/db.copy" "$CONTENT/db.php" "$SQLITE_DIR"

# 3. WP-CLI.
fetch 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar' "$TARGET/bin/wp"
chmod +x "$TARGET/bin/wp"

# 4. wp-config.php: SQLite file, site URL and debug log come from the
#    environment, so one core serves many site databases.
"$PHP" -r '
	$salts = "";
	foreach ( array( "AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY", "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT", "WP_CACHE_KEY_SALT" ) as $name ) {
		$salts .= sprintf( "define( %s, %s );\n", var_export( $name, true ), var_export( bin2hex( random_bytes( 32 ) ), true ) );
	}
	$config = <<<CONFIG
<?php
/**
 * Dev-only configuration written by BLT Toolkit bin/dev-site.sh.
 *
 * The database is SQLite (wp-content/db.php). Per process:
 * BLT_DB_FILE picks the file in wp-content/database/, BLT_SITE_URL the URL,
 * BLT_DEBUG_LOG the debug log.
 */

define( "DB_NAME", "wp" );
define( "DB_USER", "x" );
define( "DB_PASSWORD", "x" );
define( "DB_HOST", "localhost" );
define( "DB_CHARSET", "utf8" );
define( "DB_COLLATE", "" );

{$salts}
\$table_prefix = "wp_";

if ( getenv( "BLT_SITE_URL" ) ) {
	define( "WP_HOME", getenv( "BLT_SITE_URL" ) );
	define( "WP_SITEURL", getenv( "BLT_SITE_URL" ) );
}
if ( getenv( "BLT_DEBUG_LOG" ) ) {
	define( "WP_DEBUG_LOG", getenv( "BLT_DEBUG_LOG" ) );
}
define( "WP_DEBUG", true );
define( "WP_DEBUG_DISPLAY", false );
if ( ! defined( "WP_DEBUG_LOG" ) ) {
	define( "WP_DEBUG_LOG", true );
}
define( "DB_DIR", __DIR__ . "/wp-content/database/" );
define( "DB_FILE", getenv( "BLT_DB_FILE" ) ? getenv( "BLT_DB_FILE" ) : "wp.sqlite" );

if ( ! defined( "ABSPATH" ) ) {
	define( "ABSPATH", __DIR__ . "/" );
}

require_once ABSPATH . "wp-settings.php";

CONFIG;
	file_put_contents( $argv[1], $config );
' "$WPDIR/wp-config.php"

# 5. Optional Redis object cache, guarded so it is off unless asked for.
if [ "$WITH_REDIS" = 1 ]; then
	fetch 'https://downloads.wordpress.org/plugin/redis-cache.latest-stable.zip' "$DL/redis-cache.zip"
	unzip -q "$DL/redis-cache.zip" -d "$CONTENT/plugins"
	[ -f "$CONTENT/plugins/redis-cache/includes/object-cache.php" ] || die "redis-cache has no includes/object-cache.php."
	cat > "$CONTENT/object-cache.php" << 'PHP'
<?php
/**
 * Dev-only object cache switch written by BLT Toolkit bin/dev-site.sh.
 *
 * Redis is used only when BLT_OBJECT_CACHE=redis is set for the PHP process;
 * otherwise WordPress falls back to its non-persistent cache. Keys carry a
 * per-install, per-database prefix and flushes are selective, so sites that
 * share one Redis server never see or wipe each other's entries.
 */
if ( 'redis' === getenv( 'BLT_OBJECT_CACHE' ) ) {
	if ( ! defined( 'WP_REDIS_PREFIX' ) ) {
		define( 'WP_REDIS_PREFIX', substr( md5( __DIR__ ), 0, 8 ) . ':' . ( getenv( 'BLT_DB_FILE' ) ? getenv( 'BLT_DB_FILE' ) : 'wp.sqlite' ) );
	}
	if ( ! defined( 'WP_REDIS_SELECTIVE_FLUSH' ) ) {
		define( 'WP_REDIS_SELECTIVE_FLUSH', true );
	}
	if ( getenv( 'BLT_REDIS_HOST' ) && ! defined( 'WP_REDIS_HOST' ) ) {
		define( 'WP_REDIS_HOST', getenv( 'BLT_REDIS_HOST' ) );
	}
	if ( getenv( 'BLT_REDIS_PORT' ) && ! defined( 'WP_REDIS_PORT' ) ) {
		define( 'WP_REDIS_PORT', (int) getenv( 'BLT_REDIS_PORT' ) );
	}
	require __DIR__ . '/plugins/redis-cache/includes/object-cache.php';
}
PHP
fi

# 6. Install (always without the object cache, so the snapshot is plain).
env -u BLT_OBJECT_CACHE BLT_DB_FILE=wp.sqlite BLT_DEBUG_LOG="$TARGET/logs/install-debug.log" \
	"$PHP" -d memory_limit=512M "$TARGET/bin/wp" --allow-root --path="$WPDIR" core install \
	--url='http://localhost:8889' --title='BLT Dev' \
	--admin_user=admin --admin_password=admin --admin_email=admin@example.com \
	--skip-email
cp "$CONTENT/database/wp.sqlite" "$CONTENT/database/clean.sqlite"

# 7. This repo as the blt-toolkit plugin (a symlink; the repo is not touched).
ln -s "$REPO" "$CONTENT/plugins/blt-toolkit"

# 8. Site helper. It finds its install from its own location.
cat > "$TARGET/bin/blt-site" << 'SH'
#!/usr/bin/env bash
# Per-site WordPress dev databases sharing one core install (written by
# BLT Toolkit bin/dev-site.sh).
#   blt-site new NAME            fresh DB copied from the clean install (admin/admin)
#   blt-site wp NAME ARGS...     run WP-CLI against NAME's DB
#   blt-site serve NAME PORT     start php -S for NAME on PORT (background), log in logs/NAME-server.log
#   blt-site stop PORT           stop the server on PORT
#   blt-site log NAME            path of NAME's PHP debug log
# Env: BLT_OBJECT_CACHE=redis enables the Redis object cache when it was installed (start redis-server first).
set -euo pipefail
DEV="$(cd "$(dirname "$0")/.." && pwd -P)"
WPDIR="$DEV/wordpress"
DB="$WPDIR/wp-content/database"
PHP="${PHP:-php}"
cmd="${1:-}"
name="${2:-}"
mkdir -p "$DEV/logs"

need_name() {
	case "$name" in
		'' | *[!A-Za-z0-9_-]*) echo "NAME must be letters, digits, - or _" >&2; exit 1 ;;
	esac
}

wp_for() {
	BLT_DB_FILE="$name.sqlite" BLT_DEBUG_LOG="$DEV/logs/$name-debug.log" \
		"$PHP" -d memory_limit=512M "$DEV/bin/wp" --allow-root --path="$WPDIR" "$@"
}

case "$cmd" in
	new)
		need_name
		cp "$DB/clean.sqlite" "$DB/$name.sqlite"
		: > "$DEV/logs/$name-debug.log"
		# A reused NAME must not read the old database's cached objects.
		if [ "${BLT_OBJECT_CACHE:-}" = 'redis' ] && [ -f "$WPDIR/wp-content/object-cache.php" ]; then
			wp_for cache flush > /dev/null
		fi
		echo "site $name ready (admin/admin)"
		;;
	wp)
		need_name
		shift 2
		wp_for "$@"
		;;
	serve)
		need_name
		port="${3:?port}"
		BLT_DB_FILE="$name.sqlite" BLT_SITE_URL="http://localhost:$port" BLT_DEBUG_LOG="$DEV/logs/$name-debug.log" \
			nohup "$PHP" -S "localhost:$port" -t "$WPDIR" > "$DEV/logs/$name-server.log" 2>&1 &
		echo "serving $name on http://localhost:$port (pid $!)"
		;;
	stop)
		port="${2:?port}"
		# The trailing space keeps port 890 from matching a server on 8903.
		pkill -f "php -S localhost:${port} " || true
		;;
	log)
		need_name
		echo "$DEV/logs/$name-debug.log"
		;;
	*)
		sed -n '4,9p' "$0"
		exit 1
		;;
esac
SH
chmod +x "$TARGET/bin/blt-site"

echo
echo "Done: WordPress $("$PHP" -r 'include $argv[1]; echo $wp_version;' "$WPDIR/wp-includes/version.php") on SQLite, admin / admin."
echo "  $TARGET/bin/blt-site new demo"
echo "  $TARGET/bin/blt-site wp demo plugin activate blt-toolkit"
echo "  $TARGET/bin/blt-site serve demo 8890"
if [ "$WITH_REDIS" = 1 ]; then
	echo "  Redis object cache installed: export BLT_OBJECT_CACHE=redis to use it."
fi
