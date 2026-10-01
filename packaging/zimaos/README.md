# ZimaBackup package for ZimaOS / CasaOS

This directory contains the Docker Compose definition intended for direct import as a custom ZimaOS/CasaOS application. It follows the current top-level `x-casaos` schema.

## Before importing

1. Publish the ZimaBackup image to GHCR.
2. Make the GHCR package public if ZimaOS should pull it without credentials.
3. Generate the Compose file. This package already defaults to GitHub owner `maxencecauderlier`:

```bash
./packaging/zimaos/render-compose.sh
```

This creates `packaging/zimaos/docker-compose.generated.yml`.

## Import into ZimaOS / CasaOS

Open the custom app / Compose import screen and paste or upload the generated compose file. The app exposes the web interface through `${WEBUI_PORT:-8090}` so CasaOS/ZimaOS can assign the Web UI port when supported.

Persistent ZimaBackup state is stored at:

```text
/DATA/AppData/ZimaBackup
```

The web service can read `/DATA` and `/media` but does not receive the Docker socket. The worker can write to `/DATA` and `/media`, receives `/var/run/docker.sock`, and reads `/var/lib/casaos/apps` so ZimaBackup can preserve installed ZimaOS application definitions.

## Security

The Docker socket gives the worker very high privileges even when mounted with `:ro`; the suffix only makes the socket filesystem mount read-only and does not turn Docker API access into read-only access. This privilege is intentionally isolated from the browser-facing service.

## Architecture

The current GHCR workflow publishes `linux/amd64`, so the package declares only `amd64`. Add arm64 to both the image build and `x-casaos.architectures` only after the image is published and tested for arm64.
