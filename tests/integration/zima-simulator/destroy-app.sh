#!/usr/bin/env sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "$HERE/../../.." && pwd)
SIM_ROOT="$HERE/runtime"
export SIM_ROOT

# Stop and remove the simulated application first.
docker compose -f "$HERE/docker-compose.yml" down --remove-orphans

# Redis creates files/directories as the container user. Depending on the host
# filesystem, a normal host-side rm can leave root/container-owned data behind.
# Delete the disposable simulator AppData from a root helper container instead.
mkdir -p "$SIM_ROOT/DATA/AppData"
docker run --rm --user 0:0 --entrypoint sh \
    -v "$SIM_ROOT/DATA/AppData:/target" \
    redis:7-alpine \
    -c 'rm -rf -- /target/zima-demo'

if [ -e "$SIM_ROOT/DATA/AppData/zima-demo" ]; then
    printf 'ERROR: simulator AppData still exists on the host:\n%s\n' "$SIM_ROOT/DATA/AppData/zima-demo" >&2
    exit 1
fi

# If ZimaBackup is running, verify the worker sees the same disaster state.
if docker compose --project-directory "$PROJECT_ROOT" -f "$PROJECT_ROOT/docker-compose.yml" ps -q worker 2>/dev/null | grep -q .; then
    if ! docker compose --project-directory "$PROJECT_ROOT" -f "$PROJECT_ROOT/docker-compose.yml" exec -T worker \
        sh -c 'test ! -e /DATA/AppData/zima-demo'; then
        printf '\nERROR: host AppData was removed, but the ZimaBackup worker still sees /DATA/AppData/zima-demo.\n' >&2
        printf 'Check ZIMABACKUP_DATA_PATH in .env: it must point to %s/DATA\n' "$SIM_ROOT" >&2
        docker compose --project-directory "$PROJECT_ROOT" -f "$PROJECT_ROOT/docker-compose.yml" exec -T worker \
            sh -c 'find /DATA/AppData/zima-demo -maxdepth 3 -mindepth 1 -ls 2>/dev/null || true' >&2 || true
        exit 1
    fi
fi

printf 'Application containers and AppData removed.\n'
printf 'Verified: /DATA/AppData/zima-demo is absent from the simulated ZimaOS data tree.\n'
printf 'Backup repository under runtime/media is untouched.\n'
