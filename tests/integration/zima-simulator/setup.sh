#!/usr/bin/env sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SIM_ROOT="$HERE/runtime"
export SIM_ROOT

mkdir -p "$SIM_ROOT/DATA/AppData/zima-demo/nginx" \
         "$SIM_ROOT/DATA/AppData/zima-demo/redis" \
         "$SIM_ROOT/media/Backup"

cat > "$SIM_ROOT/DATA/AppData/zima-demo/nginx/index.html" <<'HTML'
<!doctype html>
<html><body><h1>ZimaBackup simulator</h1><p>Original application data is present.</p></body></html>
HTML

# Redis runs as a non-root user in its container. Wide permissions are limited
# to this disposable integration-test directory only.
chmod 0777 "$SIM_ROOT/DATA/AppData/zima-demo/redis"

printf '\nUse these values in ZimaBackup .env:\n\n'
printf 'ZIMABACKUP_DATA_PATH=%s/DATA\n' "$SIM_ROOT"
printf 'ZIMABACKUP_MEDIA_PATH=%s/media\n\n' "$SIM_ROOT"

docker compose -f "$HERE/docker-compose.yml" up -d
printf 'Simulator started. Web test app: http://localhost:%s\n' "${SIM_WEB_PORT:-8095}"
