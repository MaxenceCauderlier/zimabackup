#!/bin/sh
set -eu

if [ "$#" -gt 2 ]; then
    echo "Usage: $0 [github-owner] [tag]" >&2
    exit 1
fi

owner="${1:-maxencecauderlier}"
tag="${2:-0.15.2}"
case "$owner" in
    *[!A-Za-z0-9_.-]*|'')
        echo "Invalid GitHub owner: $owner" >&2
        exit 1
        ;;
esac

base_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
source_file="$base_dir/docker-compose.yml"
output_file="$base_dir/docker-compose.generated.yml"

sed \
    -e "s#maxencecauderlier#${owner}#g" \
    -e "s#zimabackup:0\.15\.2#zimabackup:${tag}#g" \
    -e "s#version: \"0\.15\.2\"#version: \"${tag}\"#g" \
    "$source_file" > "$output_file"

printf 'Generated %s\n' "$output_file"
printf 'Image: ghcr.io/%s/zimabackup:%s\n' "$owner" "$tag"
printf 'Import this file as a custom Compose app in ZimaOS/CasaOS.\n'
