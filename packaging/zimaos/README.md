# ZimaOS package

This directory contains the source Compose definition used to test ZimaBackup as a custom ZimaOS application.

## 1. Publish the container image

Push the project to GitHub. The workflow in `.github/workflows/docker-publish.yml` publishes:

- `develop` -> `ghcr.io/MaxenceCauderlier/zimabackup:dev`
- `main` -> `ghcr.io/MaxenceCauderlier/zimabackup:edge`
- `vX.Y.Z` tag -> `X.Y.Z`, `X.Y` and `latest`

After the first GHCR publication, make the package public in GitHub Package settings if anonymous ZimaOS pulls are desired.

## 2. Generate your ZimaOS Compose

From the project root:

```bash
./packaging/zimaos/render-compose.sh YOUR_GITHUB_USERNAME 0.15.1
```

This creates `packaging/zimaos/docker-compose.generated.yml` with the image, repository and metadata URLs filled in.

## 3. Import into ZimaOS

Use the generated Compose as a custom application definition in ZimaOS. ZimaBackup stores persistent state in:

```text
/DATA/AppData/ZimaBackup
```

The browser-facing service receives `/DATA` and `/media` read-only. The worker receives them read/write and receives the Docker socket. `/var/lib/casaos/apps` is mounted read-only so ZimaBackup can preserve exact installed ZimaOS application definitions.

## Security note

The worker Docker socket is intentionally isolated from the web service. Access to `/var/run/docker.sock`, even when mounted read-only, is highly privileged because Docker API operations are not equivalent to filesystem read-only access.
