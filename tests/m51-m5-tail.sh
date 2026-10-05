#!/usr/bin/env bash
set -euo pipefail
SRC=/mnt/c/Dev/git/wp-seed-pixel
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
case "${1:-}" in mariadb|mysql) ;; *) exit 1 ;; esac
DEST="$SRC/reports/storage-m5.1/authority-gate/$1"
source "$SRC/tests/m51-engine-scope.sh" "$1"
for file in m5-mixed.php m5-order-security.php m51-independent.php; do
    bash "$SRC/tests/m4-linux-php.sh" "tests/$file"
done
cp "$ROOT/project/reports/storage-m2/integration.json" "$DEST/m2-integration.json"
cp "$ROOT/project/reports/storage-m2/faults.json" "$DEST/m2-faults.json"
cp "$ROOT/project/reports/storage-m5/mixed.json" "$DEST/m5-mixed.json"
cp "$ROOT/project/reports/storage-m5/order-security.json" "$DEST/m5-order-security.json"
cp "$ROOT/project/reports/storage-m5.1/authority-gate/independent.json" "$DEST/"
cd "$ROOT/project"
sha256sum wp-seed-pixel.php includes/*.php > "$DEST/runtime.sha256"
printf 'Isolated authority gate evidence retained for %s.\n' "$1"
