#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
[ "$(realpath "$ROOT")" = "$ROOT" ] && [ ! -L "$ROOT" ]
mkdir -p "$ROOT/mysql84/packages" "$ROOT/mysql84/root" "$ROOT/mysql84-data"
cd "$ROOT/mysql84/packages"
OPT=(-o "Dir::State::lists=$ROOT/apt/lists" -o "Dir::Cache=$ROOT/apt/cache")
apt-get "${OPT[@]}" download mysql-server-core libgoogle-perftools4t64 libicu78 libmecab2 libprotobuf-lite32t64 libtcmalloc-minimal4t64 libunwind8
for p in ./*.deb; do dpkg-deb -x "$p" "$ROOT/mysql84/root"; done
export LD_LIBRARY_PATH="$ROOT/mysql84/root/usr/lib/x86_64-linux-gnu:$ROOT/root/usr/lib/x86_64-linux-gnu"
"$ROOT/mysql84/root/usr/sbin/mysqld" --no-defaults --initialize-insecure --basedir="$ROOT/mysql84/root/usr" --datadir="$ROOT/mysql84-data" --log-error="$ROOT/mysql84-init.log"
"$ROOT/mysql84/root/usr/sbin/mysqld" --version
