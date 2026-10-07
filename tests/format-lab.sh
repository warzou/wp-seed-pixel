#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
SRC=/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion
test ! -e "$ROOT"
bash "$SRC/tests/m3-linux-env.sh"
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
mkdir -p "$ROOT/project/.runtime" "$ROOT/recovery"
chmod 700 "$ROOT/recovery"
cd "$ROOT/packages"
apt-get -o "Dir::State::lists=$ROOT/apt/lists" -o "Dir::Cache=$ROOT/apt/cache" download php8.5-zip libzip5
for p in php8.5-zip*.deb libzip*.deb; do dpkg-deb -x "$p" "$ROOT/root"; done
curl --fail --location --max-time 120 https://wordpress.org/wordpress-7.1.2.tar.gz -o "$ROOT/wordpress.tar.gz"
tar -xzf "$ROOT/wordpress.tar.gz" -C "$ROOT/project/.runtime"
cp -a "$SRC/includes" "$SRC/assets" "$SRC/languages" "$SRC/tests" "$SRC/tools" "$ROOT/project/"
cp "$SRC/wp-seed-pixel.php" "$SRC/uninstall.php" "$ROOT/project/"
mkdir -p "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel"
cp -a "$SRC/includes" "$SRC/assets" "$SRC/languages" "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/"
cp "$SRC/wp-seed-pixel.php" "$SRC/uninstall.php" "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/"
"$ROOT/root/usr/bin/mariadb-install-db" --no-defaults --datadir="$ROOT/data" --basedir="$ROOT/root/usr" --auth-root-authentication-method=normal --skip-test-db > "$ROOT/db-install.log" 2>&1
