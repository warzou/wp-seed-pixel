#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
SRC=/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion
test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-m3-environment
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
FINAL="${PIXEL_FORMAT_ZIP:?exact final ZIP required}"
for LABEL in v04 private3 private5; do
    "$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root -e 'DROP DATABASE pixel_m3; CREATE DATABASE pixel_m3 CHARACTER SET utf8mb4;'
    for TARGET in "$ROOT/recovery" "$ROOT/project/.runtime/wordpress/wp-content/uploads"; do
        test ! -L "$TARGET"
        [[ "$(realpath -m "$TARGET")" = "$ROOT/"* ]]
        rm -rf -- "$TARGET"
    done
    mkdir -m 0700 "$ROOT/recovery"
    bash "$SRC/tests/format-sync.sh"
    bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/productization-install.php"
    case "$LABEL" in
        v04)
            export PIXEL_RELEASE_VERSION=0.4.0
            export PIXEL_RELEASE_ZIP=/mnt/c/Dev/git/wp-seed-pixel/reports/final-closure-20261006/candidate/wp-seed-pixel-0.4.0.zip
            ;;
        private3)
            export PIXEL_RELEASE_VERSION=0.5.0-private.3
            export PIXEL_RELEASE_ZIP="$SRC/reports/png-jpeg-claims-private3/candidate/wp-seed-pixel-0.5.0-private.3.zip"
            ;;
        private5)
            export PIXEL_RELEASE_VERSION=0.5.0-private.5
            export PIXEL_RELEASE_ZIP="$SRC/reports/png-jpeg-profile-policy-private5/final/candidate/wp-seed-pixel-0.5.0-private.5.zip"
            ;;
    esac
    bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/release-compatibility.php" install "$LABEL"
    bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/release-compatibility.php" seed "$LABEL"
    export PIXEL_RELEASE_ZIP="$FINAL"
    bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/release-compatibility.php" update "$LABEL"
    bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/release-compatibility.php" verify "$LABEL"
done
