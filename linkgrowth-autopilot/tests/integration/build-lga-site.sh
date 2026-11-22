#!/usr/bin/env bash
# Builds (or refreshes) the LGA integration site of DESIGN.md §12.1 from an existing local WordPress copy, without
# downloading anything and without touching the source copy.
#
#   bash tests/integration/build-lga-site.sh <source-wordpress-dir> <target-wordpress-dir> [port] [wp-cli.phar]
#
# - Copies WordPress core, wp-content/plugins/woocommerce, wp-content/plugins/sqlite-database-integration, the
#   twentytwentyfive theme and the SQLite drop-in from <source> (read-only use). The source's database, mu-plugins,
#   uploads and other plugins are NOT copied, so the new site has its own SQLite database.
# - Writes wp-config.php (http://localhost:<port>, fresh salts, SQLite, WP_DEBUG and SAVEQUERIES on,
#   DISABLE_WP_CRON, environment type "local") when it does not exist yet.
# - Copies tests/integration/lga-test-env.php into wp-content/mu-plugins/ (on every run, so it stays current) and
#   symlinks this plugin folder to wp-content/plugins/linkgrowth-autopilot.
# - Installs WordPress (WP-CLI "core install" when a wp-cli.phar is given, else wp_install() in PHP) and activates
#   WooCommerce and twentytwentyfive. The plugin itself is activated by tests/integration/run.php.
# Every step is idempotent. Default port 8793 (§0: this project uses 8793-8797).
set -euo pipefail

SRC="${1:-}"; DST="${2:-}"; PORT="${3:-8793}"; WPCLI="${4:-}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(cd "$HERE/../.." && pwd)"
[ -n "$SRC" ] && [ -n "$DST" ] || { sed -n '2,16p' "$0"; exit 2; }
[ -f "$SRC/wp-load.php" ] || { echo "build-lga-site: $SRC is not a WordPress root" >&2; exit 2; }
[ "$(cd "$SRC" && pwd)" != "$(mkdir -p "$DST" && cd "$DST" && pwd)" ] || { echo "build-lga-site: source and target are the same" >&2; exit 2; }
case "$PORT" in 879[3-7]) ;; *) echo "build-lga-site: port $PORT is outside 8793-8797" >&2; exit 2 ;; esac

if [ ! -f "$DST/wp-load.php" ]; then
  echo "Copying WordPress core from $SRC"
  (cd "$SRC" && tar -cf - --exclude=./wp-content --exclude=./wp-config.php .) | (cd "$DST" && tar -xf -)
fi
mkdir -p "$DST/wp-content/plugins" "$DST/wp-content/themes" "$DST/wp-content/mu-plugins"
for f in index.php db.php plugins/index.php themes/index.php; do
  [ -e "$DST/wp-content/$f" ] || cp -a "$SRC/wp-content/$f" "$DST/wp-content/$f"
done
for d in plugins/woocommerce plugins/sqlite-database-integration themes/twentytwentyfive; do
  [ -d "$DST/wp-content/$d" ] || { echo "Copying wp-content/$d"; cp -a "$SRC/wp-content/$d" "$DST/wp-content/$d"; }
done

if [ ! -f "$DST/wp-config.php" ]; then
  echo "Writing wp-config.php (port $PORT)"
  SALTS="$(php -r '$c="abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_[]{}<>~+=,.;:/?|";
    foreach(["AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT"] as $k){
      $s=""; for($i=0;$i<64;$i++){ $s.=$c[random_int(0,strlen($c)-1)]; } printf("define( %s, %s );\n", var_export($k,true), var_export($s,true)); }')"
  cat > "$DST/wp-config.php" <<EOF
<?php
// LGA integration site (DESIGN.md §12.1). Throwaway test install.
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'DB_ENGINE', 'sqlite' );
$SALTS
\$table_prefix = 'wp_';
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', __DIR__ . '/wp-content/debug.log' );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SAVEQUERIES', true );
define( 'WP_HOME', 'http://localhost:$PORT' );
define( 'WP_SITEURL', 'http://localhost:$PORT' );
define( 'DISABLE_WP_CRON', true );
define( 'FS_METHOD', 'direct' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
EOF
fi

cp "$HERE/lga-test-env.php" "$DST/wp-content/mu-plugins/lga-test-env.php"
LINK="$DST/wp-content/plugins/linkgrowth-autopilot"
if [ -L "$LINK" ]; then
  [ "$(readlink "$LINK")" = "$PLUGIN" ] || { rm "$LINK"; ln -s "$PLUGIN" "$LINK"; }
elif [ -e "$LINK" ]; then
  echo "build-lga-site: $LINK exists and is not a symlink; leaving it" >&2
else
  ln -s "$PLUGIN" "$LINK"
fi

URL="http://localhost:$PORT"
if [ -n "$WPCLI" ] && [ -f "$WPCLI" ]; then
  WP=(php "$WPCLI" --allow-root --path="$DST" --skip-plugins=linkgrowth-autopilot)
  if ! "${WP[@]}" core is-installed >/dev/null 2>&1; then
    "${WP[@]}" core install --url="$URL" --title="LGA Test Site" --admin_user=admin --admin_password='adminpass123!' \
      --admin_email=admin@example.com --skip-email
  fi
  "${WP[@]}" plugin is-active woocommerce >/dev/null 2>&1 || "${WP[@]}" plugin activate woocommerce
  [ "$("${WP[@]}" option get stylesheet)" = twentytwentyfive ] || "${WP[@]}" theme activate twentytwentyfive
else
  php -d memory_limit=512M -r '
    define( "WP_INSTALLING", true );
    $_SERVER["HTTP_HOST"] = "localhost:" . $argv[2];
    require $argv[1] . "/wp-load.php";
    require_once ABSPATH . "wp-admin/includes/upgrade.php";
    require_once ABSPATH . "wp-admin/includes/plugin.php";
    if ( ! is_blog_installed() ) {
        $r = wp_install( "LGA Test Site", "admin", "admin@example.com", true, "", "adminpass123!" );
        echo "installed, user " . $r["user_id"] . "\n";
    }
    if ( ! is_plugin_active( "woocommerce/woocommerce.php" ) ) {
        $a = activate_plugin( "woocommerce/woocommerce.php" );
        echo is_wp_error( $a ) ? "WooCommerce: " . $a->get_error_message() . "\n" : "WooCommerce active\n";
    }
    if ( "twentytwentyfive" !== get_option( "stylesheet" ) ) { switch_theme( "twentytwentyfive" ); }
  ' "$DST" "$PORT"
fi
echo "LGA site ready: $DST ($URL)"
