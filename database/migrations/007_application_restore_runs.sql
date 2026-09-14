CREATE TABLE IF NOT EXISTS application_restore_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    snapshot_application_id INTEGER NOT NULL,
    backup_run_id INTEGER NOT NULL,
    repository_id INTEGER NOT NULL,
    app_key TEXT NOT NULL,
    app_name TEXT NOT NULL,
    mode TEXT NOT NULL DEFAULT 'staging',
    status TEXT NOT NULL DEFAULT 'pending',
    staging_path TEXT NOT NULL,
    compose_path TEXT NULL,
    selected_mounts_json TEXT NOT NULL DEFAULT '[]',
    applied_paths_json TEXT NOT NULL DEFAULT '[]',
    progress_percent REAL NOT NULL DEFAULT 0,
    total_files INTEGER NULL,
    files_restored INTEGER NULL,
    total_bytes INTEGER NULL,
    bytes_restored INTEGER NULL,
    error_count INTEGER NOT NULL DEFAULT 0,
    error TEXT NULL,
    created_at TEXT NOT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    FOREIGN KEY (snapshot_application_id) REFERENCES snapshot_applications(id) ON DELETE RESTRICT,
    FOREIGN KEY (backup_run_id) REFERENCES backup_runs(id) ON DELETE RESTRICT,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_application_restore_runs_status
    ON application_restore_runs(status, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_application_restore_runs_snapshot_app
    ON application_restore_runs(snapshot_application_id, created_at DESC);
