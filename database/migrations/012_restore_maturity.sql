ALTER TABLE restore_runs ADD COLUMN selection_mode TEXT NOT NULL DEFAULT 'full';
ALTER TABLE restore_runs ADD COLUMN selected_paths_json TEXT NOT NULL DEFAULT '[]';
ALTER TABLE restore_runs ADD COLUMN cleanup_status TEXT NOT NULL DEFAULT 'not_requested';
ALTER TABLE restore_runs ADD COLUMN cleaned_at TEXT NULL;
ALTER TABLE restore_runs ADD COLUMN cleanup_error TEXT NULL;

ALTER TABLE application_restore_runs ADD COLUMN cleanup_status TEXT NOT NULL DEFAULT 'not_requested';
ALTER TABLE application_restore_runs ADD COLUMN cleaned_at TEXT NULL;
ALTER TABLE application_restore_runs ADD COLUMN cleanup_error TEXT NULL;

CREATE TABLE IF NOT EXISTS snapshot_browser_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    backup_run_id INTEGER NOT NULL,
    path TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    entries_json TEXT NOT NULL DEFAULT '[]',
    entry_count INTEGER NOT NULL DEFAULT 0,
    truncated INTEGER NOT NULL DEFAULT 0,
    error TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (backup_run_id, path),
    FOREIGN KEY (backup_run_id) REFERENCES backup_runs(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_snapshot_browser_cache_run_path
    ON snapshot_browser_cache(backup_run_id, path);

CREATE INDEX IF NOT EXISTS idx_restore_runs_cleaned
    ON restore_runs(cleaned_at, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_application_restore_runs_cleaned
    ON application_restore_runs(cleaned_at, created_at DESC);
