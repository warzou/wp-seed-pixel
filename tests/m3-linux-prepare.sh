#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
SRC=/mnt/c/Dev/git/wp-seed-pixel
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
EXT="$ROOT/root/usr/lib/php/20250925"
cp -an /usr/lib/php/20250925/*.so "$EXT/"
printf 'extension_dir=%s\nextension=mysqlnd\n' "$EXT" > "$ROOT/child.ini"
mkdir -p "$ROOT/project/.runtime" "$ROOT/data" "$ROOT/private" "$ROOT/tmp"
chmod 700 "$ROOT/private"
cp -a "$SRC/includes" "$SRC/tests" "$SRC/assets" "$SRC/languages" "$SRC/tools" "$ROOT/project/"
cp "$SRC/wp-seed-pixel.php" "$SRC/uninstall.php" "$ROOT/project/"
if [ ! -d "$ROOT/project/.runtime/wordpress" ]; then
    curl --fail --max-time 120 -o "$ROOT/wordpress.tar.gz" https://wordpress.org/wordpress-7.1.2.tar.gz
    tar -xzf "$ROOT/wordpress.tar.gz" -C "$ROOT/project/.runtime"
fi
mkdir -p "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel"
mkdir -p "$ROOT/project/reports/adaptive/data" "$ROOT/project/reports/storage-m3"
mkdir -p "$ROOT/project/reports/color"
cp -a "$ROOT/project/includes" "$ROOT/project/assets" "$ROOT/project/languages" "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/"
cp "$ROOT/project/wp-seed-pixel.php" "$ROOT/project/uninstall.php" "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/"
if [ -d "$SRC/.runtime/fixtures" ]; then cp -a "$SRC/.runtime/fixtures" "$ROOT/project/.runtime/"; fi
if [ ! -d "$ROOT/data/mysql" ]; then
    { printf 'CREATE DATABASE IF NOT EXISTS mysql;\nUSE mysql;\n'; cat "$ROOT/root/usr/share/mariadb/mariadb_system_tables.sql"; } | "$ROOT/root/usr/sbin/mariadbd" --no-defaults --bootstrap --datadir="$ROOT/data" --basedir="$ROOT/root/usr" --lc-messages-dir="$ROOT/root/usr/share/mariadb" --user="$(id -un)"
fi
cp "$SRC/tests/m3-config.php" "$ROOT/project/.runtime/wordpress/wp-config.php"
echo 'Linux project prepared.'
