#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
SRC=/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion
CYCLE="${1:?cycle required}"
[[ "$CYCLE" = 1 || "$CYCLE" = 2 ]]
test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-m3-environment
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
"$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root -e 'DROP DATABASE pixel_m3; CREATE DATABASE pixel_m3 CHARACTER SET utf8mb4;'
for TARGET in "$ROOT/recovery" "$ROOT/project/.runtime/wordpress/wp-content/uploads"; do
    test ! -L "$TARGET"
    [[ "$(realpath -m "$TARGET")" = "$ROOT/"* ]]
    rm -rf -- "$TARGET"
done
mkdir -m 0700 "$ROOT/recovery"
bash "$SRC/tests/format-sync.sh"
bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/productization-install.php"
if [[ -n "${PIXEL_FORMAT_ZIP:-}" ]]; then
    PIXEL_RELEASE_ZIP="$PIXEL_FORMAT_ZIP" bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/release-compatibility.php" install "cycle-$CYCLE"
fi
bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/format-test.php" "$CYCLE"
bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/format-profiles.php" "$CYCLE"
bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/format-security.php" "$CYCLE"
bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/format-claims.php" "$CYCLE"
bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/format-crash.php" "$CYCLE"
bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/format-browser-state.php"
printf 'Final cycle %s backend PASS; browser state ready.\n' "$CYCLE"
