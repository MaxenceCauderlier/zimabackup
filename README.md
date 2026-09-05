# ZimaBackup

ZimaBackup is a lightweight backup manager for ZimaOS, built with PHP 8.4, Twig, SQLite and Restic.

## Current milestone — v0.4 manual backups

v0.4 completes the first real end-to-end backup path:

- create encrypted local Restic repositories;
- create manual backup jobs;
- select one or more `/DATA/...` or `/media/...` source folders;
- reject recursive source/repository path layouts;
- queue `backup.run` operations from the web UI;
- execute Restic only in the isolated worker;
- parse Restic JSON Lines status messages;
- show live percentage, files and processed bytes;
- prevent the same job from running twice concurrently;
- store the snapshot ID and backup statistics;
- retain partial Restic snapshots as `warning` when Restic exit code 3 is returned;
- show recent run history;
- list successful/warning snapshots in the Snapshots screen.

Scheduling and restores are deliberately not enabled yet. They will reuse the exact same queue/worker architecture after the manual path has been validated on a real ZimaOS machine.

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

By default, development uses `./dev-data` and `./dev-media`. These directories are mounted as `/DATA` and `/media` inside the containers, so Restic snapshots keep native ZimaOS-style paths.

To test with real ZimaOS storage, change `.env`:

```dotenv
ZIMABACKUP_DATA_PATH=/DATA
ZIMABACKUP_MEDIA_PATH=/media
```

## Test the first real backup

1. Create a repository, for example:

```text
Name: Test Backup
Location: /media/TestBackup/ZimaBackup
```

2. Put a few files in a development source:

```bash
mkdir -p dev-data/Documents
printf 'hello ZimaBackup\n' > dev-data/Documents/example.txt
```

3. In **Backups → New backup**, create:

```text
Name: Documents
Repository: Test Backup
Source: /DATA/Documents
```

4. Open the job and press **Run now**.

5. Watch the worker if needed:

```bash
docker compose logs -f worker
```

The job page refreshes automatically while the run is queued/running. A successful run will show the Restic snapshot ID, processed size and file counts.

## Upgrading from v0.3.1

Keep your existing `storage/` directory. v0.4 adds migration `003_backup_execution.sql`, which is applied automatically on startup.

The internal mounts changed from `/host/DATA` and `/host/media` to `/DATA` and `/media`. Your `.env` values do **not** change; existing repositories remain in the same host folders.

Rebuild:

```bash
docker compose down
docker compose up --build
```

If status changes feel slow, use:

```dotenv
WORKER_INTERVAL=5
```

## Useful commands

Run migrations:

```bash
docker compose exec app php bin/migrate.php
```

Check Restic:

```bash
docker compose exec worker restic version
```

Run PHP linting:

```bash
docker compose exec app composer lint
```

Inspect worker activity:

```bash
docker compose logs -f worker
```

## Storage

Application state is stored in `storage/`:

```text
storage/
├── database.sqlite
├── cache/
├── logs/
└── secrets/
    └── repositories/
```

Repository recovery keys are stored as mode `0600` files under `storage/secrets/repositories/` and passed to Restic with `--password-file`. The secret itself is not stored in SQLite.

Save the displayed recovery key outside the ZimaOS machine. Losing both ZimaBackup's local state and the recovery key makes an encrypted Restic repository unrecoverable.

## Privilege model

The browser-facing Apache/PHP service only validates requests and writes jobs/operations to SQLite. `/DATA` and `/media` are mounted read-only there.

The worker exposes no HTTP port. It receives read/write mounts and is the only service allowed to initialize repositories, create Restic snapshots and, later, restore data.
