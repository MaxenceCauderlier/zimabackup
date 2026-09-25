# ZimaBackup

ZimaBackup is a lightweight, application-aware backup and disaster-recovery manager for ZimaOS, built with PHP 8.4, Twig, SQLite and Restic.

## Current milestone — v0.11 Scheduling & Retention

v0.11 adds day-to-day automation and deliberately simplifies the interface. The UI now behaves more like a quiet system utility than a generic SaaS dashboard: text-first navigation, compact actions, simple lists and details, and much less decorative chrome.

### Scheduling

Backup jobs can run:

- manually;
- daily at a selected time;
- weekly on a selected weekday and time.

The worker calculates `next_run_at` in the configured `TZ`. When a scheduled run becomes due, the backup operation and the next due time are committed together so a worker restart does not normally queue the same occurrence twice.

### Retention

Retention is configured per backup job and can combine:

- keep last N snapshots;
- keep daily snapshots;
- keep weekly snapshots;
- keep monthly snapshots.

The rules are inclusive: a snapshot kept by any configured rule is retained. ZimaBackup always keeps at least one snapshot and applies retention only to snapshots known to belong to that backup job. It does not run a repository-wide Restic retention policy that could accidentally affect another job sharing the same repository.

Retention runs separately after a successful backup. It uses exact snapshot IDs with `restic forget`. Disk space is reclaimed separately by `restic prune`.

### Repository maintenance

Repositories now support:

- manual integrity check;
- manual prune;
- automatic integrity checks (enabled by default every 7 days);
- optional automatic prune (disabled by default, interval configurable in Settings).

Automatic prune is queued only when ZimaBackup knows that snapshots were forgotten since the previous prune.

### Minimal interface

The main sections are now:

- **Overview** — overall state, last successful backup, next scheduled backup, jobs and recent activity;
- **Backups** — simple job list and detail pages;
- **Repositories** — repository state and maintenance actions;
- **Restore** — recovery points and restore history;
- **Applications** — detected Docker/ZimaOS applications;
- **Settings** — restore paths, discovery and repository maintenance.

The redesign intentionally removes the previous dashboard-card style, gradients, large decorative metrics and unnecessary status chrome.

## Existing disaster-recovery workflow

The existing Restore & Install workflow remains available and currently supports:

1. discover a Docker/ZimaOS application;
2. back up selected AppData and an encrypted runtime manifest;
3. inspect the application inside a Restic snapshot;
4. restore selected application data safely;
5. restore data to original paths only when destinations are absent or empty;
6. reconstruct the secret-bearing Docker definition;
7. pull missing images;
8. recreate required Docker networks;
9. recreate and start the backed-up containers;
10. verify that the recreated containers remain running.

Automatic installation is a separate confirmed step after an Original paths restore. The user must type `INSTALL` before the worker can create Docker objects.

### Restore & Install safety rules

ZimaBackup refuses automatic installation when:

- the application is already present in Docker;
- the restore was staging-only;
- selected application data has not been applied to original paths;
- a required bind source is missing;
- a bind mount points outside `/DATA` or `/media` (except a very small read-only system whitelist);
- a named Docker volume is required, because named-volume contents are not backed up yet;
- an existing container name would be replaced.

If container creation or startup fails, ZimaBackup removes Docker containers and networks created by that installation attempt where possible. Restored user/application data is not deleted.

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

The application timezone comes from `TZ` (the example uses `Europe/Paris`) and is also used for scheduled backup times.

The isolated worker receives the Docker socket. The browser-facing `app` container never receives it.

## Integration simulator (no ZimaOS required)

A reproducible Docker integration environment is included under:

```text
tests/integration/zima-simulator/
```

Start it:

```bash
./tests/integration/zima-simulator/setup.sh
```

Copy the two paths printed by the script into the root `.env`, then restart ZimaBackup.

The simulator creates the Compose project `zima-demo` with Nginx + Redis and bind-mounted data under a fake `/DATA/AppData` tree.

### Complete disaster-recovery test

1. **Applications → Refresh** and confirm `Zima Demo` is detected.
2. Create repository `/media/Backup/ZimaBackup`.
3. Create a backup containing `Zima Demo` and its selected Nginx/Redis mounts.
4. Run the backup successfully.
5. Inspect **Restore → Applications** and confirm the sanitized preview.
6. Destroy the simulated app:

```bash
./tests/integration/zima-simulator/destroy-app.sh
./tests/integration/zima-simulator/check-disaster-state.sh
```

7. Refresh Applications so `Zima Demo` disappears.
8. Restore the application with **Original paths**, typing `RESTORE`.
9. When the restore succeeds, use **Restore & Install**, type `INSTALL` and submit.
10. Wait for status **Application running**.
11. Verify the result:

```bash
./tests/integration/zima-simulator/verify-auto-install.sh
```

The test passes when the recreated Docker project is running and `http://localhost:8095` serves the restored HTML file.

## Upgrading

Keep the existing `storage/` directory.

v0.11 adds:

```text
011_scheduling_retention.sql
```

Previous migrations are kept and applied automatically when required.

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
- owns backup, restore, retention and application installation execution;
- can read/write `/DATA` and `/media`;
- accesses Docker through `/var/run/docker.sock`;
- uses the Docker Engine API directly instead of exposing Docker control to the web process;
- receives repository recovery keys through Restic `--password-file`;
- keeps reconstructed secret-bearing Compose files worker-side with mode `0600`.

Repository recovery keys remain under:

```text
storage/secrets/repositories/
```

Save every displayed recovery key outside the ZimaOS machine.

## Useful commands

```bash
docker compose exec app php bin/migrate.php
docker compose exec worker restic version
docker compose logs -f worker
```
