ALTER TABLE backup_jobs ADD COLUMN schedule_last_queued_at TEXT NULL;
ALTER TABLE backup_jobs ADD COLUMN retention_enabled INTEGER NOT NULL DEFAULT 0;

ALTER TABLE backup_runs ADD COLUMN retention_state TEXT NULL;
ALTER TABLE backup_runs ADD COLUMN retention_error TEXT NULL;
UPDATE backup_runs SET retention_state = 'not_applicable' WHERE retention_state IS NULL;

ALTER TABLE repositories ADD COLUMN last_prune_at TEXT NULL;
ALTER TABLE repositories ADD COLUMN last_prune_status TEXT NULL;
ALTER TABLE repositories ADD COLUMN last_prune_error TEXT NULL;

CREATE TABLE IF NOT EXISTS retention_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    backup_job_id INTEGER NOT NULL,
    trigger_backup_run_id INTEGER NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    keep_last INTEGER NOT NULL,
    keep_daily INTEGER NOT NULL,
    keep_weekly INTEGER NOT NULL,
    keep_monthly INTEGER NOT NULL,
    removed_count INTEGER NOT NULL DEFAULT 0,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    error TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (trigger_backup_run_id) REFERENCES backup_runs(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_backup_jobs_schedule_due
    ON backup_jobs(enabled, deleted_at, schedule_type, next_run_at);

CREATE INDEX IF NOT EXISTS idx_backup_runs_retention_state
    ON backup_runs(retention_state, finished_at DESC);

CREATE INDEX IF NOT EXISTS idx_retention_runs_job_created
    ON retention_runs(backup_job_id, created_at DESC);
