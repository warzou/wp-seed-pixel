#!/usr/bin/env bash
set -euo pipefail
SRC=/mnt/c/Dev/git/wp-seed-pixel
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
case "${1:-}" in mariadb|mysql) ;; *) exit 1 ;; esac
DEST="$SRC/reports/storage-m5.1/authority-gate/$1"
mkdir -p "$DEST"
source "$SRC/tests/m51-engine-scope.sh" "$1"
for file in m51-db-gate.php m51-locks.php; do
    bash "$SRC/tests/m4-linux-php.sh" "tests/$file"
done
cp "$ROOT/project/reports/storage-m5.1/authority-gate/schema-cas.json" "$DEST/"
cp "$ROOT/project/reports/storage-m5.1/locks.json" "$DEST/"
bash "$SRC/tests/m4-linux-php.sh" tests/m51-lab-reset.php
bash "$SRC/tests/m4-linux-php.sh" tests/storage-analyzer.php
cp "$ROOT/project/reports/storage-m1/integration.json" "$ROOT/project/.runtime/m1-integration.json"
cp "$ROOT/project/reports/storage-m1/integration.json" "$DEST/m1-prerequisite.json"
bash "$SRC/tests/m4-linux-php.sh" tests/m51-m2-fixtures.php
bash "$SRC/tests/m51-regression-tail.sh" "$1"
