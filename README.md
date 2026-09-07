# ZimaBackup

ZimaBackup is a lightweight, application-aware backup manager for ZimaOS, built with PHP 8.4, Twig, SQLite and Restic.

## Current milestone — v0.7 safe restore

v0.7 keeps the v0.6 ZimaOS-inspired interface and adds the first real restore workflow:

- list successful Restic snapshots;
- choose a snapshot from the UI;
- restore the complete snapshot to a new directory under `/DATA` or `/media`;
- keep original ZimaOS files untouched;
- require an empty restore target;
- use Restic `--overwrite never` for this safe workflow;
- run the restore only in the isolated worker;
- track restore progress using Restic JSON output;
- show files restored, bytes restored and final status;
- retain restore history in SQLite;
- prevent the restore target from overlapping its own repository.

v0.7 deliberately does **not** restore in place yet. It also does not automatically recreate a Docker/ZimaOS application from the encrypted application manifest. Those are the next restore milestones.

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

## Test a restore

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

The target directory must be new or empty. Existing source data is never overwritten by the v0.7 safe restore workflow.

Watch progress with:

```bash
docker compose logs -f worker
```

## Upgrading

Keep your existing `storage/` directory.

v0.7 adds:

```text
005_restore_runs.sql
```

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
