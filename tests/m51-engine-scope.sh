#!/usr/bin/env bash
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
case "${1:-}" in mariadb|mysql) ;; *) return 1 ;; esac
[ "$(realpath "$ROOT")" = "$ROOT" ] && [ ! -L "$ROOT" ] && [ "$(stat -c %U "$ROOT")" = "$(id -un)" ] || return 1
export PIXEL_M51_RECOVERY
PIXEL_M51_RECOVERY=$(mktemp -d "$ROOT/private-m51-$1-XXXXXXXX")
chmod 0700 "$PIXEL_M51_RECOVERY"
cp /mnt/c/Dev/git/wp-seed-pixel/tests/m3-config.php "$ROOT/project/.runtime/wordpress/wp-config.php"
