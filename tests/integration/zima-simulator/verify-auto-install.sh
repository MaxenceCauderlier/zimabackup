#!/usr/bin/env sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PORT="${SIM_WEB_PORT:-8095}"

printf 'Checking restored Docker project...\n'
containers=$(docker ps --filter label=com.docker.compose.project=zima-demo --format '{{.Names}} {{.Status}}')
if [ -z "$containers" ]; then
    printf 'ERROR: no running container found for project zima-demo.\n' >&2
    exit 1
fi
printf '%s\n' "$containers"

printf '\nChecking restored web data on http://localhost:%s ...\n' "$PORT"
body=$(curl -fsS "http://localhost:$PORT/")
printf '%s\n' "$body" | grep -q 'Original application data is present.' || {
    printf 'ERROR: restored page content was not found.\n' >&2
    exit 1
}
printf 'OK: application was recreated and restored data is being served.\n'
