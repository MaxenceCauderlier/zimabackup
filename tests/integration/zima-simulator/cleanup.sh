#!/usr/bin/env sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SIM_ROOT="$HERE/runtime"
export SIM_ROOT

docker compose -f "$HERE/docker-compose.yml" down -v --remove-orphans || true
rm -rf "$SIM_ROOT"
mkdir -p "$SIM_ROOT/DATA" "$SIM_ROOT/media"
printf 'Simulator data removed.\n'
