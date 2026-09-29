# ZimaBackup

ZimaBackup is a lightweight, application-aware backup and disaster-recovery manager for ZimaOS, built with PHP 8.4, Twig, SQLite and Restic.

## Current milestone — v0.14.0 Activity & Diagnostics

v0.14.0 adds a persistent, user-readable Activity log and current-state diagnostics. Backups, restores, storage maintenance, application recovery and important failures are recorded in SQLite with plain-language summaries. Raw Docker/Restic output remains available behind **Show technical details**. The worker reports a heartbeat so the interface can distinguish a current background-processing problem from an old historical error.

Activity history defaults to 30 days and 5,000 events and can be adjusted in Settings. High-frequency, low-value operations such as snapshot browsing and successful periodic Docker discovery are intentionally not logged.

### Scheduling

Backup jobs can run:

- manually;
- daily at a selected time;
- weekly on a selected weekday and time.

The worker calculates `next_run_at` in the configured `TZ`. When a scheduled run becomes due, the backup operation and the next due time are committed together so a worker restart does not normally queue the same occurrence twice.

### Recovery point cleanup

Automatic recovery point cleanup is configured per backup job and can combine:

- keep last N snapshots;
- keep daily snapshots;
- keep weekly snapshots;
- keep monthly snapshots.

The rules are inclusive: a recovery point kept by any configured rule is retained. ZimaBackup always keeps at least one recovery point and applies cleanup only to recovery points known to belong to that backup job.

Cleanup runs separately after a successful backup. Internally it removes exact Restic snapshot IDs. Disk space that becomes unused is reclaimed separately with **Free unused space**.

### Storage maintenance

Backup storage now supports:

- manual verification;
- **Free unused space**;
- automatic backup checks (enabled by default every 7 days);
- optional automatic freeing of unused space (disabled by default, interval configurable in Settings).

Deleting a recovery point can leave shared or no-longer-referenced backup data on disk. **Free unused space** permanently removes only data no longer required by any remaining recovery point.

### Minimal interface

The main sections are now:

- **Overview** — overall state, last successful backup, next scheduled backup, jobs and recent activity;
- **Backups** — simple job list and detail pages;
- **Storage** — backup storage state and maintenance actions;
- **Restore** — recovery points and restore history;
- **Applications** — detected Docker/ZimaOS applications;
- **Settings** — restore paths, discovery and storage maintenance.

The redesign intentionally removes the previous dashboard-card style, gradients, large decorative metrics and unnecessary status chrome.

## User-facing terminology

ZimaBackup deliberately hides Restic-specific vocabulary from normal users:

| Interface | Internal Restic term | Meaning |
| --- | --- | --- |
| **Storage** | repository | The encrypted location where backups are stored. |
| **Recovery point** | snapshot | A restorable state created by a successful backup. |
| **Delete recovery point** | forget | Removes that recovery point from available restores. |
| **Free unused space** | prune | Permanently removes backup data no longer required by any remaining recovery point. |

The Restic names remain in code, logs and contributor documentation for accurate diagnostics.

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



## v0.14.0 - Activity & Diagnostics

- New **Activity** page with severity and category filters.
- Persistent SQLite event history for backups, restores, application recovery, storage maintenance and background errors.
- Plain-language event summaries with optional raw technical details and operation UUIDs.
- Worker heartbeat with **Online / Busy / Offline** diagnostics.
- Overview now reports current problems instead of counting every historical failed backup forever.
- Diagnostics detect missing/failed storage, latest backup failures, Docker discovery failure and very long-running operations.
- Configurable Activity retention (default 30 days / 5,000 events).
- New migration: `014_activity_diagnostics.sql`.

## v0.13.1 - Plain-language UX

- Replaced Restic jargon in the normal UI with user-focused backup terminology.
- Added plain-language explanations for deleting recovery points and freeing unused space.
- Changed Restic `forgotten` / `forgetting` states to **Deleted** / **Deleting** in the interface.
- Renamed repository navigation to **Storage** while keeping all internal route/database identifiers unchanged.
- No database migration is required from v0.13.0.

## v0.13.0 - Internationalization

- English source interface with automatic fallback.
- Complete French UI translation.
- Language selector in Settings (`English` / `Français`).
- Locale stored in SQLite under `ui.language`.
- Shared Twig `t()` helper, translated statuses/errors, localized dates, and JavaScript messages generated from the same catalog.
- New languages can be added by creating `translations/<locale>.php` and registering the locale in `Translator::supportedLocales()`.
- Low-level Restic/Docker output remains unchanged when no safe application-level translation is known.

## v0.12.0 - Restore maturity

This release makes Restore a practical recovery workflow instead of an all-or-nothing operation.

- Browse snapshot contents through the privileged worker. The web process never opens Restic repositories directly.
- Browse only logical user roots (`/DATA` and `/media`); internal backup manifests remain hidden.
- Restore one or multiple selected files/folders using Restic `--include`, or restore the whole snapshot.
- Directory listings are streamed from `restic ls --json` and cached per snapshot/path. The UI caps one directory view at 1,000 entries to avoid unbounded browser payloads.
- File restores keep `--overwrite never` and a separate empty target directory.
- Completed/failed file restore targets can be cleaned from Restore history without deleting the source snapshot.
- Application restore staging workspaces can be cleaned after restore/install activity has finished. Original AppData applied to `/DATA` or `/media` is never removed by staging cleanup.
- Cleanup runs through the worker and refuses symlinked restore paths.
