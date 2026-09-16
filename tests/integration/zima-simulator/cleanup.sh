#!/usr/bin/env sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SIM_ROOT="$HERE/runtime"
export SIM_ROOT

# v0.9.1 may recreate the simulator directly through the Docker Engine API.
# Remove any such project-labeled containers even if Compose does not know the
# original local project file state anymore.
ids=$(docker ps -aq --filter label=com.docker.compose.project=zima-demo)
if [ -n "$ids" ]; then
    docker rm -f $ids >/dev/null 2>&1 || true
fi

docker compose -f "$HERE/docker-compose.yml" down -v --remove-orphans || true
rm -rf "$SIM_ROOT"
mkdir -p "$SIM_ROOT/DATA" "$SIM_ROOT/media"
printf 'Simulator data removed.\n'
