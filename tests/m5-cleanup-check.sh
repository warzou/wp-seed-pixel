#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
[ ! -e "$ROOT" ] && [ ! -L "$ROOT" ]
if findmnt -rn -o TARGET | grep -Fq "$ROOT/"; then
  echo 'Owned test mount remains' >&2
  exit 1
fi
for proc in /proc/[0-9]*; do
  [ "${proc##*/}" = "$$" ] && continue
  cmd=$(tr '\0' ' ' < "$proc/cmdline" 2>/dev/null || true)
  case "$cmd" in *"$ROOT"*) echo 'Owned process remains' >&2; exit 1 ;; esac
done
echo 'Owned Linux root, mounts and processes: physically absent'
