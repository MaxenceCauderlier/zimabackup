#!/usr/bin/env sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SIM_ROOT="$HERE/runtime"
export SIM_ROOT

docker compose -f "$HERE/docker-compose.yml" down
rm -rf "$SIM_ROOT/DATA/AppData/zima-demo"
printf 'Application containers and AppData removed. Backup repository under runtime/media is untouched.\n'
