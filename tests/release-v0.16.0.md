# ZimaBackup v0.16.0 — focused release checks

v0.16.0 is a pre-1.0 reliability/diagnostics release. It does not change the Restic repository format and adds no database migration.

## Upgrade

- Upgrade from v0.15.10 with the existing `/DATA/AppData/ZimaBackup` data directory.
- Recreate both containers with `docker compose up -d --force-recreate`.
- Confirm the worker heartbeat returns to `online`.
- Confirm existing repositories, jobs, recovery points and restore history are unchanged.

## Native ZimaOS restore readiness

- In the worker, `getent hosts host.docker.internal` resolves an address.
- In **Settings → ZimaOS integration**, the local API endpoint is shown.
- Within 60 seconds of worker startup, the API status becomes **Ready** when ZimaOS is reachable.
- The last worker API check time updates periodically.
- Removing/breaking the host-gateway mapping makes Settings show **Needs attention** and Dashboard show a warning.
- Restoring the mapping clears the warning without changing backup data.

## Native application restore

- Inspect an existing ZimaOS application recovery point.
- Restore it using **Original paths**.
- Confirm the page asks for ZimaOS username/password and `INSTALL` confirmation.
- With the API reachable, valid credentials complete the native App Management installation.
- The restored application appears in ZimaOS Applications, not only in Docker.
- Invalid credentials fail clearly and do not create an unmanaged Docker fallback.
- If API connectivity is broken, the page warns before install and the failure message does not expose raw PHP `file_get_contents` / `getaddrinfo` details.

## Regression checks from v0.15.10

- Reinspect a recovery point that already has restore history: no foreign-key failure occurs.
- The existing `snapshot_applications.id` remains stable after rescan.
- Production Twig templates update after image replacement without manually deleting `storage/cache/twig`.
- Generic Docker apps without an exact ZimaOS definition still use the Docker fallback path.
- No ZimaOS password or access token appears in SQLite or Activity logs.
