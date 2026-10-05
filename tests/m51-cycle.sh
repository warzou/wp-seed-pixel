#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
SRC=/mnt/c/Dev/git/wp-seed-pixel
[ "$(realpath "$ROOT")" = "$ROOT" ] && [ ! -L "$ROOT" ]
case "${1:-}" in 1|2) ;; *) exit 1 ;; esac
OUT="$ROOT/project/reports/storage-m5.1/cycle-$1"
mkdir -p "$OUT"
for file in m51-locks.php m51-upload-budget.php m51-quarantine.php m51-crash.php; do
  bash "$SRC/tests/m4-linux-php.sh" "tests/$file"
done
cp "$ROOT/project/reports/storage-m5.1/"*.json "$OUT/"
cd "$ROOT/project"
sha256sum wp-seed-pixel.php includes/*.php > "$OUT/runtime.sha256"
