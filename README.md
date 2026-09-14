# ZimaBackup

ZimaBackup is a lightweight, application-aware backup and recovery manager for ZimaOS, built with PHP 8.4, Twig, SQLite and Restic.

## Current milestone — v0.9 controlled application restore

v0.9 keeps every previous backup, snapshot, application-discovery and safe file-restore feature, and adds controlled recovery of one application from a snapshot.

The application recovery workflow can now:

- inspect application manifests inside an encrypted snapshot;
- show a sanitized Docker Compose preview before recovery;
- restore only the bind-mounted paths that were selected when the backup job was created;
- use Restic `restore --include` so unrelated snapshot data is not restored;
- restore first into `/DATA/ZimaBackup/ApplicationRestores/...`;
- rebuild a complete `docker-compose.yml` from the encrypted manifest;
- keep that generated Compose file mode `0600` because it can contain original environment values;
- optionally copy staged application data back to its original `/DATA` or `/media` paths;
- refuse original-path recovery while the application is still detected by Docker;
- refuse non-empty original destinations and symbolic-link targets;
- never overwrite existing application files;
- never start Docker containers automatically in v0.9.

The default mode is **Safe staging**. The more invasive **Original paths** mode requires typing `RESTORE` and is designed for disaster-recovery tests where the original application has disappeared.

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

The worker receives the Docker socket so it can discover applications. If the socket is elsewhere, set:

```dotenv
DOCKER_SOCKET_PATH=/path/to/docker.sock
```

## Integration simulator (no ZimaOS required)

v0.9 includes a reproducible Docker integration environment under:

```text
tests/integration/zima-simulator/
```

Start it:

```bash
./tests/integration/zima-simulator/setup.sh
```

The script prints the exact `ZIMABACKUP_DATA_PATH` and `ZIMABACKUP_MEDIA_PATH` values to copy into the root `.env` file.

The simulator creates a Docker Compose application named `zima-demo` with Nginx + Redis and persistent bind mounts under a fake `DATA/AppData` tree.

See:

```text
tests/integration/zima-simulator/README.md
```

for the full backup → destroy → restore → restart disaster-recovery test.

## Test an application backup

1. Open **Applications** and refresh discovery.
2. Create a repository, for example `/media/Backup/ZimaBackup`.
3. Create a backup containing a detected application and selected AppData mounts.
4. Run the backup.
5. Open **Snapshots → Applications**.
6. Verify that the reconstructed Compose preview appears and environment values are masked.

## Test application recovery

From **Snapshots → Applications**, select **Restore application**.

### Safe staging

This restores only the selected application paths underneath:

```text
/DATA/ZimaBackup/ApplicationRestores/...
```

and generates:

```text
docker-compose.yml
```

inside that staging folder. Existing application paths are untouched.

### Original paths

Use this only after the application has disappeared from Docker and its original data directories have been removed or emptied.

Select **Restore original paths**, type:

```text
RESTORE
```

and launch the operation.

ZimaBackup still restores to staging first. It then copies the selected application data to the original logical paths only if those targets are absent or empty. Existing files and symbolic-link destinations are refused.

v0.9 deliberately does **not** run `docker compose up`. Automatic controlled deployment is a later milestone.

## Test a normal file restore

Open **Snapshots** and choose **Restore files** on a successful snapshot.

The default target is under:

```text
/DATA/ZimaBackup/Restores/...
```

and must be new or empty.

## Upgrading

Keep your existing `storage/` directory.

v0.9 adds:

```text
007_application_restore_runs.sql
```

All migrations are applied automatically on startup.

Rebuild:

```bash
docker compose down
docker compose up --build
```

## Security model

The browser-facing Apache/PHP service:

- has no Docker socket;
- sees `/DATA` and `/media` read-only;
- never executes Restic or Docker directly;
- queues privileged operations for the worker.

The worker:

- exposes no HTTP port;
- owns backup and restore execution;
- can read/write `/DATA` and `/media`;
- can query Docker through `/var/run/docker.sock`;
- receives repository recovery-key files through `--password-file`;
- reconstructs secret-bearing Compose files only in worker-controlled storage with mode `0600`.

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
