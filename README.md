# ZimaBackup

ZimaBackup is a lightweight, application-aware backup and disaster-recovery manager for ZimaOS, built with PHP 8.4, Twig, SQLite and Restic.

## Current milestone — v0.15.5 Application install staging fix

v0.15.5 fixes Restore & Install on ZimaOS. The unprivileged web process no longer tries to read the protected application staging workspace. The worker now persists the authoritative manifest obtained with `restic dump` to the exact staging path expected by installation, using mode `0600`, and only the worker reads it before touching Docker. The v0.15.3 storage-permission fix remains included.

The browser never receives the raw installed Compose file. Snapshot previews mask environment values, while application restores write the original Compose definition to the protected staging workspace with mode `0600`.

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


## GHCR distribution

ZimaBackup can be built and published automatically to GitHub Container Registry using:

```text
.github/workflows/docker-publish.yml
```

Publishing rules:

- `develop` -> `ghcr.io/maxencecauderlier/zimabackup:dev`
- `main` -> `ghcr.io/maxencecauderlier/zimabackup:edge`
- Git tag `vX.Y.Z` -> `X.Y.Z`, `X.Y` and `latest`
- every published build also receives a `sha-...` traceability tag

The workflow uses GitHub's generated `GITHUB_TOKEN`; no registry password is stored in the repository. Release tags are rejected when they do not match the root `VERSION` file.

After the first successful push, make the GHCR package public in GitHub Package settings if ZimaOS should pull it anonymously.

### Run a prebuilt image manually

Copy the GHCR environment example:

```bash
cp .env.ghcr.example .env
```

Set `ZIMABACKUP_IMAGE`, then run:

```bash
docker compose -f docker-compose.ghcr.yml pull
docker compose -f docker-compose.ghcr.yml up -d
```

### Prepare a ZimaOS custom application

Generate the ZimaOS Compose file (defaults are already set to `maxencecauderlier` and `0.15.5`):

```bash
./packaging/zimaos/render-compose.sh
```

The generated file is:

```text
packaging/zimaos/docker-compose.generated.yml
```

It uses `/DATA/AppData/ZimaBackup` for persistent ZimaBackup state, mounts `/DATA` and `/media` read-only in the browser-facing service, gives write access only to the worker, mounts the Docker socket only in the worker, and reads `/var/lib/casaos/apps` read-only for exact ZimaOS application definitions.

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

v0.15.5 adds no database migration. Previous migrations are kept and applied automatically when required.

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




## v0.15.5 - GHCR Distribution

- Production-oriented multi-stage Dockerfile with build-only Composer tooling removed from the runtime image.
- GitHub Actions workflow publishes GHCR images from `develop`, `main` and semantic version tags.
- Release tags must match the root `VERSION` file.
- Added `docker-compose.ghcr.yml` for prebuilt-image deployments.
- Added a ZimaOS custom-app Compose source with valid current `x-casaos` metadata.
- Added `packaging/zimaos/render-compose.sh` to fill the GitHub owner and image tag.
- Added OCI metadata, BuildKit cache, provenance and SBOM generation in CI.
- No SQLite migration.

## v0.15.0 - ZimaOS Integration

- Worker can mount ZimaOS installed app definitions read-only from `/var/lib/casaos/apps`.
- Docker Compose labels are used to locate the exact file first; `/var/lib/casaos/apps/<project>/docker-compose.yml` is the fallback convention.
- Captures top-level `x-casaos` identity, title, category, icon, version and main-service metadata.
- Application discovery shows whether recovery has an **Exact ZimaOS definition** or only a **Docker runtime fallback**.
- Encrypted application manifests move to `zimabackup.application-manifest.v2` and can contain the original Compose YAML.
- Snapshot inspection displays a sanitized preview of the exact Compose while masking every service environment value.
- Application restore writes the exact backed-up Compose to staging when available; older v1 manifests remain supported.
- New migration: `015_zimaos_integration.sql`.

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


## v0.15.5 - ZimaOS Storage Permission Fix

- Storage presence is probed by the privileged background worker and cached in SQLite.
- The web process no longer traverses `/media` to decide whether a backup repository exists.
- Fixes false `missing` states on ZimaOS disks mounted with restrictive permissions such as `root:root 0750`.
- Repository initialization still validates existing data in the worker immediately before `restic init`.
- Manual backup queueing no longer performs a web-side repository filesystem check.


## v0.15.5 - Application Install Staging Fix

- The web process no longer reads the protected restored application manifest.
- `application.install` queueing stays unprivileged; Docker and manifest checks happen in the worker.
- Application restore explicitly persists the manifest obtained via `restic dump` at the recorded staging path.
- Restored manifest directories remain private and the manifest file is written with mode `0600`.
- The final Docker project name is resolved from the manifest by the worker just before installation.
- No database migration is required.
