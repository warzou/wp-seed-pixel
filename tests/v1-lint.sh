#!/usr/bin/env bash
set -euo pipefail
SRC=/mnt/c/Dev/git/wp-seed-pixel
count=0
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
  count=$((count+1))
done < <(find "$SRC/includes" "$SRC/tests" -type f -name '*.php' -print0)
php -l "$SRC/wp-seed-pixel.php" >/dev/null
php -l "$SRC/uninstall.php" >/dev/null
echo "$((count+2)) PHP sources lint PASS (injection templates are linted after rendering)"
