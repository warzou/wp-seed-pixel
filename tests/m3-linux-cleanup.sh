#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
if [ ! -e "$ROOT" ]; then printf 'Owned M3 environment already absent.\n'; exit 0; fi
if [ -L "$ROOT" ] || [ "$(realpath -- "$ROOT")" != "$ROOT" ] || [ "$(stat -c %U "$ROOT")" != "$(id -un)" ]; then
    printf 'Cleanup refused: unexpected owned root.\n' >&2
    exit 1
fi
if findmnt -rn -o TARGET | grep -Fq "$ROOT/"; then
    printf 'Cleanup refused: an owned test volume is still mounted.\n' >&2
    exit 1
fi
for proc in /proc/[0-9]*; do
    [ "${proc##*/}" = "$$" ] && continue
    cmd=$(tr '\0' ' ' < "$proc/cmdline" 2>/dev/null || true)
    case "$cmd" in *"$ROOT"*) printf 'Cleanup refused: owned process %s is still running.\n' "${proc##*/}" >&2; exit 1 ;; esac
done
# Exact, owned disposable root only; never uploads or another project directory.
rm -rf -- "$ROOT"
test ! -e "$ROOT"
printf 'Owned M3 environment physically absent.\n'
