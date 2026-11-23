#!/usr/bin/env bash
# Builds (or refreshes) the optional minimum-versions site "compat-min" of DESIGN.md §12.1: WordPress 6.5.x and
# WooCommerce 8.0.x from wordpress.org, each file checked against the published checksums, on port 8797.
#
#   bash tests/integration/build-compat-min-site.sh <target-wordpress-dir> <lga-site-wordpress-dir> [wp-cli.phar]
#
# Environment: WP_MIN_VERSION (default 6.5.15, the last 6.5 release), WC_MIN_VERSION (default 8.0.5, the last 8.0
# release), PORT (default 8797), DL_DIR (default <target>/../compat-min-downloads), PLUGIN_DIR (default: the plugin
# folder this script belongs to; it is symlinked into the site and its tests/integration/lga-test-env.php copied).
# - WordPress: the release tarball must match the published .sha1 and .md5 files, and every extracted file the
#   md5 of api.wordpress.org/core/checksums (no missing, changed or extra file).
# - WooCommerce: every file of the release ZIP must match the sha256 of downloads.wordpress.org/plugin-checksums
#   (no missing, changed or extra file).
# - The SQLite Database Integration plugin and its db.php drop-in are copied from the LGA site (read-only use);
#   the theme is twentytwentyfour, which ships with WordPress 6.5.
# - wp-config.php as for the LGA site (http://localhost:<port>, SQLite, WP_DEBUG, SAVEQUERIES, DISABLE_WP_CRON);
#   tests/integration/lga-test-env.php is copied to mu-plugins; this plugin folder is symlinked into
#   wp-content/plugins/linkgrowth-autopilot but NOT activated (the compat-min section does that).
# - Installs WordPress with WP-CLI (or wp_install() without it) and activates WooCommerce and twentytwentyfour.
# Any download or checksum failure stops the build with "compat-min not built: <reason>" and exit 3.
set -euo pipefail

DST="${1:-}"; LGA="${2:-}"; WPCLI="${3:-}"
WPV="${WP_MIN_VERSION:-6.5.15}"; WCV="${WC_MIN_VERSION:-8.0.5}"; PORT="${PORT:-8797}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(cd "${PLUGIN_DIR:-$HERE/../..}" && pwd)"
[ -n "$DST" ] && [ -n "$LGA" ] || { sed -n '2,23p' "$0"; exit 2; }
[ -f "$LGA/wp-content/plugins/sqlite-database-integration/load.php" ] && [ -f "$LGA/wp-content/db.php" ] \
  || { echo "build-compat-min-site: $LGA has no SQLite Database Integration plugin and drop-in" >&2; exit 2; }
case "$PORT" in 879[3-7]) ;; *) echo "build-compat-min-site: port $PORT is outside 8793-8797" >&2; exit 2 ;; esac
mkdir -p "$DST"
DST="$(cd "$DST" && pwd)"
DL="${DL_DIR:-$(dirname "$DST")/compat-min-downloads}"
mkdir -p "$DL"
fail() { echo "compat-min not built: $*" >&2; exit 3; }
get() { curl -fsSL --retry 2 --max-time 300 -o "$1" "$2" || fail "download refused: $2"; }

cd "$DL"
TAR="wordpress-$WPV.tar.gz"
[ -f "$TAR" ] || get "$TAR" "https://wordpress.org/$TAR"
get "$TAR.sha1" "https://wordpress.org/$TAR.sha1"
get "$TAR.md5" "https://wordpress.org/$TAR.md5"
[ "$(sha1sum "$TAR" | cut -d' ' -f1)" = "$(tr -d ' \n' < "$TAR.sha1")" ] || fail "$TAR does not match its published sha1"
[ "$(md5sum "$TAR" | cut -d' ' -f1)" = "$(tr -d ' \n' < "$TAR.md5")" ] || fail "$TAR does not match its published md5"
get "wp-$WPV-checksums.json" "https://api.wordpress.org/core/checksums/1.0/?version=$WPV&locale=en_US"
ZIP="woocommerce.$WCV.zip"
[ -f "$ZIP" ] || get "$ZIP" "https://downloads.wordpress.org/plugin/$ZIP"
get "woocommerce-$WCV-checksums.json" "https://downloads.wordpress.org/plugin-checksums/woocommerce/$WCV.json"

X="$(mktemp -d "$DL/extract.XXXXXX")"
trap 'rm -rf "$X"' EXIT
tar -xzf "$TAR" -C "$X"
unzip -q "$ZIP" -d "$X"
php -r '
  [$_, $root, $list, $algo, $label] = $argv;
  $j = json_decode((string) file_get_contents($list), true);
  $want = $algo === "md5" ? ($j["checksums"] ?? null) : array_map(static fn($f) => $f["sha256"], $j["files"] ?? []);
  if (!is_array($want) || !$want) { fwrite(STDERR, "$label: no checksum list\n"); exit(1); }
  $bad = []; $seen = 0;
  foreach ($want as $rel => $h) {
    $p = "$root/$rel";
    if (!is_file($p)) { $bad[] = "missing $rel"; continue; }
    $seen++;
    if (!hash_equals($h, hash_file($algo, $p))) { $bad[] = "changed $rel"; }
  }
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) { $rel = substr($f->getPathname(), strlen($root) + 1); if (!isset($want[$rel])) { $bad[] = "extra $rel"; } }
  printf("%s: %d files match the published %s checksums, %d problem(s)\n", $label, $seen, $algo, count($bad));
  foreach (array_slice($bad, 0, 20) as $b) { echo "  $b\n"; }
  exit($bad ? 1 : 0);
' "$X/wordpress" "$DL/wp-$WPV-checksums.json" md5 "WordPress $WPV" || fail "WordPress $WPV files do not match the checksums"
php -r '
  [$_, $root, $list, $algo, $label] = $argv;
  $j = json_decode((string) file_get_contents($list), true);
  $want = array_map(static fn($f) => $f["sha256"], $j["files"] ?? []);
  if (!$want) { fwrite(STDERR, "$label: no checksum list\n"); exit(1); }
  $bad = []; $seen = 0;
  foreach ($want as $rel => $h) {
    $p = "$root/$rel";
    if (!is_file($p)) { $bad[] = "missing $rel"; continue; }
    $seen++;
    if (!hash_equals($h, hash_file("sha256", $p))) { $bad[] = "changed $rel"; }
  }
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) { $rel = substr($f->getPathname(), strlen($root) + 1); if (!isset($want[$rel])) { $bad[] = "extra $rel"; } }
  printf("%s: %d files match the published sha256 checksums, %d problem(s)\n", $label, $seen, count($bad));
  foreach (array_slice($bad, 0, 20) as $b) { echo "  $b\n"; }
  exit($bad ? 1 : 0);
' "$X/woocommerce" "$DL/woocommerce-$WCV-checksums.json" sha256 "WooCommerce $WCV" || fail "WooCommerce $WCV files do not match the checksums"

if [ ! -f "$DST/wp-load.php" ]; then
  echo "Installing WordPress $WPV core into $DST"
  (cd "$X/wordpress" && tar -cf - .) | (cd "$DST" && tar -xf -)
fi
INSTALLED_WP="$(php -r 'include $argv[1] . "/wp-includes/version.php"; echo $wp_version;' "$DST")"
[ "$INSTALLED_WP" = "$WPV" ] || fail "$DST already holds WordPress $INSTALLED_WP, not $WPV"
mkdir -p "$DST/wp-content/plugins" "$DST/wp-content/mu-plugins"
[ -d "$DST/wp-content/plugins/woocommerce" ] || cp -a "$X/woocommerce" "$DST/wp-content/plugins/woocommerce"
[ -d "$DST/wp-content/plugins/sqlite-database-integration" ] \
  || cp -a "$LGA/wp-content/plugins/sqlite-database-integration" "$DST/wp-content/plugins/sqlite-database-integration"
[ -f "$DST/wp-content/db.php" ] || cp -a "$LGA/wp-content/db.php" "$DST/wp-content/db.php"

if [ ! -f "$DST/wp-config.php" ]; then
  echo "Writing wp-config.php (port $PORT)"
  SALTS="$(php -r '$c="abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*()-_[]{}<>~+=,.;:/?|";
    foreach(["AUTH_KEY","SECURE_AUTH_KEY","LOGGED_IN_KEY","NONCE_KEY","AUTH_SALT","SECURE_AUTH_SALT","LOGGED_IN_SALT","NONCE_SALT"] as $k){
      $s=""; for($i=0;$i<64;$i++){ $s.=$c[random_int(0,strlen($c)-1)]; } printf("define( %s, %s );\n", var_export($k,true), var_export($s,true)); }')"
  cat > "$DST/wp-config.php" <<EOF
<?php
// LGA minimum-versions site "compat-min" (DESIGN.md §12.1): WordPress $WPV, WooCommerce $WCV. Throwaway test install.
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

cp "$PLUGIN/tests/integration/lga-test-env.php" "$DST/wp-content/mu-plugins/lga-test-env.php"
LINK="$DST/wp-content/plugins/linkgrowth-autopilot"
if [ -L "$LINK" ]; then
  [ "$(readlink "$LINK")" = "$PLUGIN" ] || { rm "$LINK"; ln -s "$PLUGIN" "$LINK"; }
elif [ ! -e "$LINK" ]; then
  ln -s "$PLUGIN" "$LINK"
fi

URL="http://localhost:$PORT"
if [ -n "$WPCLI" ] && [ -f "$WPCLI" ]; then
  WP=(php "$WPCLI" --allow-root --path="$DST" --skip-plugins=linkgrowth-autopilot)
  if ! "${WP[@]}" core is-installed >/dev/null 2>&1; then
    "${WP[@]}" core install --url="$URL" --title="LGA compat-min" --admin_user=admin --admin_password='adminpass123!' \
      --admin_email=admin@example.com --skip-email
  fi
  "${WP[@]}" plugin is-active woocommerce >/dev/null 2>&1 || "${WP[@]}" plugin activate woocommerce
  [ "$("${WP[@]}" option get stylesheet)" = twentytwentyfour ] || "${WP[@]}" theme activate twentytwentyfour
  echo "WordPress $("${WP[@]}" core version), WooCommerce $("${WP[@]}" plugin get woocommerce --field=version), PHP $(php -r 'echo PHP_VERSION;')"
else
  php -d memory_limit=512M -r '
    define( "WP_INSTALLING", true );
    $_SERVER["HTTP_HOST"] = "localhost:" . $argv[2];
    require $argv[1] . "/wp-load.php";
    require_once ABSPATH . "wp-admin/includes/upgrade.php";
    require_once ABSPATH . "wp-admin/includes/plugin.php";
    if ( ! is_blog_installed() ) {
        wp_install( "LGA compat-min", "admin", "admin@example.com", true, "", "adminpass123!" );
    }
    if ( ! is_plugin_active( "woocommerce/woocommerce.php" ) ) {
        $a = activate_plugin( "woocommerce/woocommerce.php" );
        echo is_wp_error( $a ) ? "WooCommerce: " . $a->get_error_message() . "\n" : "WooCommerce active\n";
    }
    if ( "twentytwentyfour" !== get_option( "stylesheet" ) ) { switch_theme( "twentytwentyfour" ); }
  ' "$DST" "$PORT"
fi
echo "compat-min site ready: $DST ($URL)"
