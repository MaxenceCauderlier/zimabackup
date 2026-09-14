#!/usr/bin/env sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SIM_ROOT="$HERE/runtime"
export SIM_ROOT

docker compose -f "$HERE/docker-compose.yml" up -d
printf 'Simulator restarted from the restored AppData. Open http://localhost:%s\n' "${SIM_WEB_PORT:-8095}"
