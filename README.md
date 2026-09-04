# ZimaBackup

ZimaBackup is a lightweight backup manager for ZimaOS, built with PHP 8.4, Twig, SQLite and Restic.

## Current milestone — v0.3 visual foundation

The application can create real encrypted local Restic repositories and now ships with the first complete ZimaBackup visual system.

Implemented:

- New ZimaOS-inspired responsive interface
- Active sidebar navigation and mobile top bar
- Protection overview and guided setup status
- Repository card grid with health states
- Redesigned repository creation and recovery-key flows
- PHP 8.4 + Apache Docker image
- Restic installed in the application image
- Twig + AltoRouter
- SQLite migrations with startup locking for web/worker concurrency
- Dashboard and navigation
- Dedicated privileged worker
- Generic SQLite operation queue
- Local repository creation under `/DATA` or `/media`
- Strong automatically generated Restic recovery key
- Recovery key displayed once after creation
- Password stored in a mode `0600` secret file, never in SQLite
- `restic init` followed by `restic check`
- Repository states: `pending`, `initializing`, `ready`, `failed`
- Automatic refresh while initialization is running
- CSRF protection on repository creation
- Logical `/DATA` and `/media` path mapping

The next milestone adds backup jobs and the first real `restic backup` execution, using this interface as the permanent visual foundation.

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

By default, development uses `./dev-data` and `./dev-media`. This prevents accidental access to a workstation's real storage.

To test against ZimaOS paths, change `.env`:

```dotenv
ZIMABACKUP_DATA_PATH=/DATA
ZIMABACKUP_MEDIA_PATH=/media
```

## Test repository creation

In development, create a repository with for example:

```text
Name: Test Backup
Location: /media/TestBackup/ZimaBackup
```

The application will:

1. create the repository record in SQLite;
2. generate a strong recovery key;
3. show that recovery key once in the browser;
4. enqueue a `repository.init` operation;
5. let the worker run `restic init`;
6. run `restic check`;
7. mark the repository `ready` or `failed`.

Watch the worker if needed:

```bash
docker compose logs -f worker
```

The resulting development repository will be visible on the host under:

```text
./dev-media/TestBackup/ZimaBackup
```

## Upgrading from the v0.1.1 scaffold

Keep your existing `storage/` directory. The new migration is applied automatically at startup.

Rebuild:

```bash
docker compose down
docker compose up --build
```

If your existing `.env` still contains `WORKER_INTERVAL=30`, changing it to `5` makes asynchronous status updates more responsive during development.

## Useful commands

Run migrations:

```bash
docker compose exec app php bin/migrate.php
```

Check Restic:

```bash
docker compose exec app restic version
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

Important: save the displayed recovery key outside the ZimaOS machine. Losing both ZimaBackup's local state and that recovery key makes an encrypted Restic repository unrecoverable.

## Privilege model

The web UI runs through Apache as `www-data`. It validates input and enqueues work only.

The separate worker has no published HTTP port and performs the privileged filesystem/Restic operations. This keeps user-controlled Restic execution out of the browser-facing PHP process.
