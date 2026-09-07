CREATE TABLE IF NOT EXISTS restore_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    backup_run_id INTEGER NOT NULL,
    repository_id INTEGER NOT NULL,
    snapshot_id TEXT NOT NULL,
    target_path TEXT NOT NULL,
    overwrite_mode TEXT NOT NULL DEFAULT 'never',
    status TEXT NOT NULL DEFAULT 'pending',
    progress_percent REAL NOT NULL DEFAULT 0,
    total_files INTEGER NULL,
    files_restored INTEGER NULL,
    files_skipped INTEGER NULL,
    total_bytes INTEGER NULL,
    bytes_restored INTEGER NULL,
    bytes_skipped INTEGER NULL,
    error_count INTEGER NOT NULL DEFAULT 0,
    error TEXT NULL,
    created_at TEXT NOT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    FOREIGN KEY (backup_run_id) REFERENCES backup_runs(id) ON DELETE RESTRICT,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_restore_runs_status_created
    ON restore_runs(status, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_restore_runs_snapshot
    ON restore_runs(snapshot_id, created_at DESC);
