#!/usr/bin/env bash
set -euo pipefail
SRC=/mnt/c/Dev/git/wp-seed-pixel
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
cycle="${1:-}"
case "$cycle" in 1|2) ;; *) exit 1 ;; esac
for file in m4.php m4-faults.php m4-adversarial.php m4-accounting.php m4-native.php; do
    bash "$SRC/tests/m4-linux-php.sh" "tests/$file"
done
mkdir -p "$ROOT/project/reports/storage-m4/cycle-$cycle"
cp "$ROOT/project/reports/storage-m4/"*.json "$ROOT/project/reports/storage-m4/cycle-$cycle/"
