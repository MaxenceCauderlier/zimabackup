# ZimaBackup

ZimaBackup is a lightweight, application-aware backup manager for ZimaOS, built with PHP 8.4, Twig, SQLite and Restic.

## Current milestone — v0.8 application restore preview

v0.8 keeps the safe file restore workflow and adds non-destructive application inspection:

- inspect application manifests stored inside an encrypted Restic snapshot;
- do all repository reading in the isolated worker, never in the web process;
- stream `restic ls --json` and retain only ZimaBackup manifest files;
- decrypt each application manifest on demand with `restic dump`;
- mask environment values and sensitive fields before anything is persisted in SQLite;
- reconstruct a reviewable Docker Compose runtime definition;
- show images, services, ports, bind mounts, restart policy, networks and common runtime settings;
- warn about ambiguous items such as named Docker volumes, GPU/device requests and missing ZimaOS `x-casaos` metadata;
- never pull an image, create a container, overwrite AppData or execute the reconstructed Compose in v0.8.

The goal of this milestone is reviewability: ZimaBackup shows exactly what it *could* recreate before application installation is enabled in a later version.

## Application-aware backups

Application discovery from v0.5 is still included:

- discover Docker containers through the local Docker Engine API;
- group Compose services into one application;
- keep the Docker socket isolated to the non-HTTP worker;
- detect bind mounts and identify paths under `/DATA` and `/media`;
- recommend persistent configuration under `/DATA/AppData/...`;
- leave large media/user-data mounts opt-in by default;
- create jobs that combine applications and arbitrary folders;
- include a normalized application runtime manifest when an app is selected;
- capture image, environment, ports, networks, devices, mounts and Compose metadata;
- keep secret environment values out of SQLite;
- generate the complete restore manifest only during a backup as a mode `0600` temporary file;
- snapshot that manifest with Restic, then delete the local plaintext copy.

## Development

Copy the environment file:

```bash
cp .env.example .env
```

Build and start:

```bash
docker compose up --build
```

Open:

```text
http://localhost:8090
```

By default, development uses `./dev-data` and `./dev-media`. These directories are mounted as `/DATA` and `/media` inside the containers.

On a real ZimaOS host, set:

```dotenv
ZIMABACKUP_DATA_PATH=/DATA
ZIMABACKUP_MEDIA_PATH=/media
```

The worker also receives the Docker socket so it can discover applications. If the socket is elsewhere, set:

```dotenv
DOCKER_SOCKET_PATH=/path/to/docker.sock
```

## Test a backup

Create a repository, then create a backup using a folder such as:

```text
/DATA/Documents
```

or select one of the detected applications.

Run the backup and wait for a successful snapshot.

## Test an application restore preview

Create or use a snapshot from a backup job that includes at least one detected application. Open **Snapshots** and choose **Applications**.

The first visit automatically queues a worker inspection. After a short refresh, ZimaBackup shows a sanitized Docker Compose preview and compatibility warnings. Environment values are intentionally displayed as `***`.

Watch the worker with:

```bash
docker compose logs -f worker
```

## Test a file restore

Open **Snapshots** and choose **Restore** on a successful snapshot.

The default destination looks like:

```text
/DATA/ZimaBackup/Restores/documents-a1b2c3d4
```

A source that was backed up as:

```text
/DATA/Documents
```

will be restored under:

```text
/DATA/ZimaBackup/Restores/documents-a1b2c3d4/DATA/Documents
```

This is intentional: Restic preserves the original absolute path tree below the restore target.

The target directory must be new or empty. Existing source data is never overwritten by the safe restore workflow.

Watch progress with:

```bash
docker compose logs -f worker
```

## Upgrading

Keep your existing `storage/` directory.

v0.8 adds:

```text
006_snapshot_application_preview.sql
```

The existing `005_restore_runs.sql` migration from v0.7 is still retained.

It is applied automatically on startup.

Rebuild:

```bash
docker compose down
docker compose up --build
```

## Security model

The browser-facing Apache/PHP service:

- has no Docker socket;
- sees `/DATA` and `/media` read-only;
- never executes Restic directly;
- validates UI requests and queues privileged operations.

The worker:

- exposes no HTTP port;
- owns backup and restore execution;
- can read/write `/DATA` and `/media`;
- can query Docker through `/var/run/docker.sock`;
- receives repository recovery-key files through `--password-file`.

Repository recovery keys remain under:

```text
storage/secrets/repositories/
```

Save every displayed recovery key outside the ZimaOS machine.

## Useful commands

Run migrations:

```bash
docker compose exec app php bin/migrate.php
```

Check Restic:

```bash
docker compose exec worker restic version
```

Inspect worker activity:

```bash
docker compose logs -f worker
```
