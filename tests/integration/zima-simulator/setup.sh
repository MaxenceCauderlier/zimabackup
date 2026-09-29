#!/usr/bin/env sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SIM_ROOT="$HERE/runtime"
export SIM_ROOT

mkdir -p "$SIM_ROOT/DATA/AppData/zima-demo/nginx" \
         "$SIM_ROOT/DATA/AppData/zima-demo/redis" \
         "$SIM_ROOT/media/Backup" \
         "$SIM_ROOT/zimaos-apps/zima-demo"


cat > "$SIM_ROOT/zimaos-apps/zima-demo/docker-compose.yml" <<'YAML'
name: zima-demo
services:
  web:
    image: nginx:alpine
    restart: unless-stopped
    ports:
      - target: 80
        published: "8095"
        protocol: tcp
    environment:
      DEMO_MODE: zima-simulator
      DEMO_PASSWORD: this-must-never-appear-in-the-web-preview
    volumes:
      - type: bind
        source: /DATA/AppData/zima-demo/nginx
        target: /usr/share/nginx/html
  cache:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--appendonly", "yes"]
    environment:
      DEMO_TOKEN: restore-test-token
    volumes:
      - type: bind
        source: /DATA/AppData/zima-demo/redis
        target: /data
x-casaos:
  id: com.zimabackup.simulator
  main: web
  category: Utilities
  title:
    en_US: Zima Demo
    fr_FR: Démo Zima
  version: "1.0.0"
  index: /
  port_map: "8095"
YAML

cat > "$SIM_ROOT/DATA/AppData/zima-demo/nginx/index.html" <<'HTML'
<!doctype html>
<html><body><h1>ZimaBackup simulator</h1><p>Original application data is present.</p></body></html>
HTML

# Redis runs as a non-root user in its container. Wide permissions are limited
# to this disposable integration-test directory only.
chmod 0777 "$SIM_ROOT/DATA/AppData/zima-demo/redis"

printf '\nUse these values in ZimaBackup .env:\n\n'
printf 'ZIMABACKUP_DATA_PATH=%s/DATA\n' "$SIM_ROOT"
printf 'ZIMABACKUP_MEDIA_PATH=%s/media\n' "$SIM_ROOT"
printf 'ZIMAOS_APPS_PATH=%s/zimaos-apps\n\n' "$SIM_ROOT"

docker compose -f "$HERE/docker-compose.yml" up -d
printf 'Simulator started. Web test app: http://localhost:%s\n' "${SIM_WEB_PORT:-8095}"
