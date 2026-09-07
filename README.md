# ZimaBackup

ZimaBackup is a lightweight backup manager for ZimaOS, built with PHP 8.4, Twig, SQLite and Restic.

## Current milestone — v0.5 application discovery

v0.5 keeps the manual backup path from v0.4 and adds application-aware backups:

- discover Docker containers through the local Docker Engine API;
- group Compose services into one application;
- keep the Docker socket isolated to the non-HTTP worker;
- detect bind mounts and identify paths under `/DATA` and `/media`;
- recommend persistent configuration under `/DATA/AppData/...`;
- leave large media/user-data mounts opt-in by default;
- create jobs that combine applications and arbitrary folders;
- always include an application runtime definition when an app is selected;
- capture image, environment, ports, networks, devices, mounts and Compose metadata;
- keep secret environment values out of SQLite;
- generate the complete restore manifest only during a backup as a mode `0600` temporary file;
- snapshot that manifest with Restic, then delete the local plaintext copy;
- tag application snapshots with `zimabackup-app=<app-key>`.

The restore manifest is currently a normalized Docker runtime definition built from Docker inspect data. It is designed to become the input for the future restore/reinstall workflow. v0.5 does **not** yet recreate an application automatically and does not yet export the original ZimaOS App Management API Compose document.

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

ZimaBackup infers the host paths behind its own `/DATA` and `/media` mounts, so discovery also works with development mappings such as `./dev-data -> /DATA`; detected app paths are stored using portable ZimaOS-style paths.

The worker also receives the Docker socket so it can discover applications:

```text
/var/run/docker.sock -> /var/run/docker.sock
```

If your Docker socket lives elsewhere, set:

```dotenv
DOCKER_SOCKET_PATH=/path/to/docker.sock
```

On a real ZimaOS host, set:

```dotenv
ZIMABACKUP_DATA_PATH=/DATA
ZIMABACKUP_MEDIA_PATH=/media
```

## Test application discovery

After starting v0.5, open **Applications**. The worker scans Docker automatically every two minutes by default.

You can also force a refresh from the UI or inspect the worker:

```bash
docker compose logs -f worker
```

A detected Compose app should show its image and persistent mounts. For example:

```text
Jellyfin
/config -> /DATA/AppData/jellyfin/config   recommended
/media  -> /DATA/Media                     optional
```

Then create **Backups → New backup**, select Jellyfin, keep `/config` checked, optionally select `/media`, choose a repository and run the job.

Every selected app adds an encrypted restore manifest to the Restic snapshot. Exact environment values can contain credentials, so they are never cached in the discovery tables.

## Manual folder backups still work

You can continue to create jobs using only folders:

```text
/DATA/Documents
/media/USB/Photos
```

Application and folder sources can also be combined in the same job.

## Upgrading from v0.4

Keep your existing `storage/` directory. v0.5 adds migration:

```text
004_application_discovery.sql
```

It is applied automatically on startup.

Rebuild:

```bash
docker compose down
docker compose up --build
```

The worker now needs access to the Docker socket. The web-facing `app` service does **not** receive it.

## Security model

The browser-facing Apache/PHP service:

- has no Docker socket;
- sees `/DATA` and `/media` read-only;
- validates UI requests and queues operations.

The worker:

- exposes no HTTP port;
- owns Restic execution;
- can read/write `/DATA` and `/media`;
- can query Docker through `/var/run/docker.sock`;
- implements only GET requests in `DockerEngineClient`.

Mounting a Docker socket is inherently privileged even when the bind mount is marked read-only. Keeping it out of the web container reduces exposure, but the worker itself must still be treated as a privileged component.

## Storage

Application state is stored in `storage/`:

```text
storage/
├── database.sqlite
├── cache/
├── logs/
├── manifests/          # temporary; full app manifests are deleted after backup
└── secrets/
    └── repositories/
```

Repository recovery keys are mode `0600` files under `storage/secrets/repositories/` and are passed to Restic using `--password-file`.

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

Run PHP linting:

```bash
docker compose exec app composer lint
```

Inspect worker activity:

```bash
docker compose logs -f worker
```
