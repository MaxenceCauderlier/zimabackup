# ZimaBackup

ZimaBackup is a lightweight, application-aware backup and disaster-recovery manager for ZimaOS, built with PHP 8.4, Twig, SQLite and Restic.

## Current milestone — v0.10.1 Repository recovery

v0.10.1 hardens repository management. Missing Restic storage is detected explicitly, integrity checks now report a clean missing-storage state, and a missing repository can be safely reinitialized only when its destination is absent or empty. Historical snapshots from a lost repository are retained as unavailable records after reinitialization.

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

Automatic installation is a **separate confirmed step** after an Original paths restore. The user must type `INSTALL` before the worker can create Docker objects.

### Restore & Install safety rules

ZimaBackup refuses automatic installation when:

- the application is already present in Docker;
- the restore was staging-only;
- selected application data has not been applied to original paths;
- a required bind source is missing;
- a bind mount points outside `/DATA` or `/media` (except a very small read-only system whitelist);
- a named Docker volume is required, because named-volume contents are not backed up yet;
- an existing container name would be replaced.

If container creation or startup fails, ZimaBackup removes Docker containers and networks created by that installation attempt where possible. Restored user/application data is **not deleted**.

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
5. Inspect **Snapshots → Applications** and confirm the sanitized preview.
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

## Safe file restore

Normal snapshot restores still use an isolated target under:

```text
/DATA/ZimaBackup/Restores/...
```

and refuse non-empty targets or repository overlap.

## Upgrading

Keep the existing `storage/` directory.

v0.10.1 adds:

```text
009_management.sql
010_repository_recovery.sql
```

All migrations are applied automatically on startup.

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
- owns backup, restore and application installation execution;
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
