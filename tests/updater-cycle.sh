#!/usr/bin/env bash
set -euo pipefail
SRC=$(cd "$(dirname "$0")/.." && pwd)
ROOT=/home/warzy/.cache/wp-seed-pixel-051-updater-lab
export PIXEL_UPDATER_SOURCE="$SRC" LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
PHP=(bash "$SRC/tests/updater-lab.sh" php)
export PIXEL_OLD_ZIP="$SRC/reports/release-0.5.0-20261007/final/candidate/wp-seed-pixel-0.5.0.zip"
WP="$ROOT/project/.runtime/wordpress"
case "${1:-}" in
init)
  "$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root -e 'CREATE DATABASE IF NOT EXISTS pixel_updater CHARACTER SET utf8mb4;'
  "${PHP[@]}" "$SRC/tests/updater-native-lab.php" install
  ;;
matrix)
  "$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root pixel_updater -e "UPDATE wp_options SET option_value='a:0:{}' WHERE option_name='active_plugins';"
  rm -f -- "$WP/private-channel" "$WP/wp-content/mu-plugins/pixel-updater-fixture.php"
  "${PHP[@]}" "$SRC/tests/updater-matrix.php"
  "${PHP[@]}" "$SRC/tests/updater-override.php" '' ''
  "${PHP[@]}" "$SRC/tests/updater-override.php" 'http://updates.example.invalid/manifest.json' ''
  "${PHP[@]}" "$SRC/tests/updater-override.php" 'https://updates.example.invalid/manifest.json' 'https://updates.example.invalid/manifest.json'
  ;;
transport)
  "${PHP[@]}" "$SRC/tests/updater-native-lab.php" transport
  ;;
reset)
  test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-051-updater-lab
  "$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root -e 'DROP DATABASE pixel_updater; CREATE DATABASE pixel_updater CHARACTER SET utf8mb4;'
  for target in "$WP/wp-content/plugins/wp-seed-pixel" "$WP/wp-content/mu-plugins" "$WP/wp-content/uploads" "$ROOT/private"; do
    test ! -L "$target"
    [[ "$(realpath -m "$target")" = "$ROOT/"* ]]
    rm -rf -- "$target"
  done
  rm -f -- "$WP/private-channel"
  mkdir -m 0700 "$ROOT/private"
  "${PHP[@]}" "$SRC/tests/updater-native-lab.php" install
  "${PHP[@]}" "$SRC/tests/updater-native-lab.php" setup "${2:?channel}"
  "${PHP[@]}" "$SRC/tests/updater-native-lab.php" seed
  ;;
verify)
  "${PHP[@]}" "$SRC/tests/updater-native-lab.php" verify
  ;;
*) exit 2 ;;
esac
