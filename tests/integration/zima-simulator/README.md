# ZimaBackup integration simulator

This small Docker Compose project simulates a ZimaOS application without requiring ZimaOS.
It uses two containers (Nginx + Redis) and bind-mounts their persistent data under a fake `DATA/AppData` tree.

## 1. Start the simulator

```bash
./tests/integration/zima-simulator/setup.sh
```

The script prints the exact `ZIMABACKUP_DATA_PATH` and `ZIMABACKUP_MEDIA_PATH` values to copy into the root `.env` file. Restart ZimaBackup after changing them.

## 2. Discover and back up the app

In ZimaBackup:

1. Applications -> Refresh
2. Confirm `Zima Demo` is detected.
3. Create a repository at `/media/Backup/ZimaBackup`.
4. Create a backup containing `Zima Demo` and both recommended AppData mounts.
5. Run the backup.
6. Open Snapshots -> Applications and confirm the Compose preview is generated and secrets are masked.

## 3. Simulate a total application loss

```bash
./tests/integration/zima-simulator/destroy-app.sh
```

Then refresh **Applications** in ZimaBackup. `Zima Demo` must disappear before an original-path restore is allowed.

## 4. Restore the application data

Open the previous snapshot -> Applications -> Restore application.

Choose **Restore original paths**, type `RESTORE`, and start the restore.

ZimaBackup first restores into `/DATA/ZimaBackup/ApplicationRestores/...`. It then copies only the selected AppData mounts back to their original logical paths if those targets are missing or empty. Existing files are never overwritten.

## 5. Verify

On the host, the restored Nginx page should exist again under:

```text
runtime/DATA/AppData/zima-demo/nginx/index.html
```

Restart the simulator:

```bash
./tests/integration/zima-simulator/restart-restored-app.sh
```

Open `http://localhost:8095`. The page should contain `Original application data is present.`

The reconstructed `docker-compose.yml` is also stored under the ZimaBackup staging directory. In development it uses logical `/DATA` paths intended for ZimaOS, so use the simulator's original Compose file to restart the test application. Automatic deployment is a later milestone.

## Cleanup

```bash
./tests/integration/zima-simulator/cleanup.sh
```

### Verify the simulated disaster

After `destroy-app.sh`, you can verify that both the host tree and the ZimaBackup worker see the application data as absent:

```bash
./tests/integration/zima-simulator/check-disaster-state.sh
```

The original-path restore is intentionally refused if even one target directory still contains data.
