#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
[ "$(realpath "$ROOT")" = "$ROOT" ] && [ ! -L "$ROOT" ]
cd "$ROOT/project"
count=0
for file in includes/*.php tests/m5*.php tests/runtime.php tests/m3-runtime.php; do
  php -n -l "$file" >/dev/null
  count=$((count + 1))
done
printf 'PHP syntax: %s files PASS\n' "$count"
