#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
OUT=/mnt/c/Dev/git/wp-seed-pixel/reports/storage-m5
test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-m3-environment
test -d "$ROOT/project/reports/storage-m5"
if find "$ROOT/project/reports/storage-m5" -type l -print -quit | grep -q .; then exit 1; fi
mkdir -p "$OUT"
cp -a "$ROOT/project/reports/storage-m5/." "$OUT/"
echo 'M5 synthetic JSON evidence exported; no database or media.'
