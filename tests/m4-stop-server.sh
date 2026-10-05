#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-m3-environment
for proc in /proc/[0-9]*; do
    pid="${proc##*/}"
    command=$(tr '\0' ' ' < "$proc/cmdline" 2>/dev/null || true)
    case "$command" in
        php*"-S 127.0.0.1:8877"*"$ROOT/project/.runtime/wordpress"*) kill "$pid" 2>/dev/null || true ;;
    esac
done
echo 'Only owned local PHP server processes signalled.'
