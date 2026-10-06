#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
SRC=/mnt/c/Dev/git/wp-seed-pixel
test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-m3-environment
mkdir -p "$ROOT/project/.runtime" "$ROOT/updates"
cd "$ROOT/packages"
apt-get -o "Dir::State::lists=$ROOT/apt/lists" -o "Dir::Cache=$ROOT/apt/cache" download php8.5-zip libzip5
for p in php8.5-zip*.deb libzip*.deb; do dpkg-deb -x "$p" "$ROOT/root"; done
cd "$ROOT"
if [ ! -f wordpress.tar.gz ]; then curl --fail --location --max-time 120 https://wordpress.org/latest.tar.gz -o wordpress.tar.gz; fi
test ! -e "$ROOT/project/.runtime/wordpress"
tar -xzf wordpress.tar.gz -C "$ROOT/project/.runtime"
cp -a "$SRC/includes" "$SRC/assets" "$SRC/languages" "$SRC/tests" "$SRC/tools" "$ROOT/project/"
cp "$SRC/wp-seed-pixel.php" "$SRC/uninstall.php" "$ROOT/project/"
mkdir -p "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel"
cp -a "$SRC/includes" "$SRC/assets" "$SRC/languages" "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/"
cp "$SRC/wp-seed-pixel.php" "$SRC/uninstall.php" "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/"
mkdir -p "$ROOT/project/.runtime/fixtures"
mkdir -m 0700 -p "$ROOT/recovery"
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
"$ROOT/root/usr/bin/mariadb-install-db" --no-defaults --datadir="$ROOT/data" --basedir="$ROOT/root/usr" --auth-root-authentication-method=normal --skip-test-db > "$ROOT/db-install.log" 2>&1
echo 'Disposable WordPress and private database prepared.'
