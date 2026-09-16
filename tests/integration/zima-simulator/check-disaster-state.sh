#!/usr/bin/env sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -- "$HERE/../../.." && pwd)
SIM_ROOT="$HERE/runtime"

printf 'Host simulator path:\n'
if [ -e "$SIM_ROOT/DATA/AppData/zima-demo" ]; then
    printf '  NOT CLEAN: %s/DATA/AppData/zima-demo still exists\n' "$SIM_ROOT"
    find "$SIM_ROOT/DATA/AppData/zima-demo" -maxdepth 3 -mindepth 1 -ls 2>/dev/null || true
else
    printf '  OK: zima-demo AppData is absent\n'
fi

printf '\nWorker view:\n'
if docker compose --project-directory "$PROJECT_ROOT" -f "$PROJECT_ROOT/docker-compose.yml" ps -q worker 2>/dev/null | grep -q .; then
    docker compose --project-directory "$PROJECT_ROOT" -f "$PROJECT_ROOT/docker-compose.yml" exec -T worker sh -c '
        if [ -e /DATA/AppData/zima-demo ]; then
            echo "  NOT CLEAN: /DATA/AppData/zima-demo exists"
            find /DATA/AppData/zima-demo -maxdepth 3 -mindepth 1 -ls 2>/dev/null || true
            exit 1
        fi
        echo "  OK: /DATA/AppData/zima-demo is absent"
    '
else
    printf '  Worker is not running; host state only was checked.\n'
fi
