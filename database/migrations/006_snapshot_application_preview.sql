CREATE TABLE IF NOT EXISTS snapshot_application_scans (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    backup_run_id INTEGER NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'pending',
    application_count INTEGER NOT NULL DEFAULT 0,
    error TEXT NULL,
    created_at TEXT NOT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    FOREIGN KEY (backup_run_id) REFERENCES backup_runs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS snapshot_applications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    backup_run_id INTEGER NOT NULL,
    app_key TEXT NOT NULL,
    name TEXT NOT NULL,
    project_name TEXT NULL,
    image TEXT NULL,
    manifest_path TEXT NOT NULL,
    container_count INTEGER NOT NULL DEFAULT 0,
    compose_preview TEXT NOT NULL,
    manifest_preview_json TEXT NOT NULL,
    warnings_json TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL,
    UNIQUE (backup_run_id, app_key),
    FOREIGN KEY (backup_run_id) REFERENCES backup_runs(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_snapshot_applications_run
    ON snapshot_applications(backup_run_id, name COLLATE NOCASE);
