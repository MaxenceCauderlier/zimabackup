#!/bin/sh
set -eu

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
    echo "Usage: $0 <github-owner> [tag]" >&2
    exit 1
fi

owner="$1"
tag="${2:-0.15.1}"
source_file="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/docker-compose.yml"
output_file="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)/docker-compose.generated.yml"

sed \
    -e "s#MaxenceCauderlier#${owner}#g" \
    -e "s#zimabackup:0\.15\.1#zimabackup:${tag}#g" \
    -e "s#version: \"0\.15\.1\"#version: \"${tag}\"#g" \
    "$source_file" > "$output_file"

printf 'Generated %s\n' "$output_file"
printf 'Image: ghcr.io/%s/zimabackup:%s\n' "$owner" "$tag"
