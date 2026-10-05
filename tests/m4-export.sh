#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
OUT=/mnt/c/Dev/git/wp-seed-pixel/reports/storage-m4
test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-m3-environment
test -d "$ROOT/project/reports/storage-m4"
if find "$ROOT/project/reports/storage-m4" -type l -print -quit | grep -q .; then exit 1; fi
mkdir -p "$OUT/regression"
cp -a "$ROOT/project/reports/storage-m4/." "$OUT/"
for name in integration faults native; do cp "$ROOT/project/reports/storage-m3/$name.json" "$OUT/regression/m3-$name.json"; done
echo 'Final local JSON/regression evidence exported; no database or media export.'
