ALTER TABLE repositories ADD COLUMN archived_at TEXT NULL;
ALTER TABLE repositories ADD COLUMN last_check_at TEXT NULL;
ALTER TABLE repositories ADD COLUMN last_check_status TEXT NULL;
ALTER TABLE repositories ADD COLUMN last_check_error TEXT NULL;

ALTER TABLE backup_jobs ADD COLUMN deleted_at TEXT NULL;

ALTER TABLE backup_runs ADD COLUMN snapshot_state TEXT NOT NULL DEFAULT 'present';
ALTER TABLE backup_runs ADD COLUMN forgotten_at TEXT NULL;
ALTER TABLE backup_runs ADD COLUMN snapshot_forget_error TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_repositories_archived
    ON repositories(archived_at, name COLLATE NOCASE);

CREATE INDEX IF NOT EXISTS idx_backup_jobs_deleted
    ON backup_jobs(deleted_at, enabled, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_backup_runs_snapshot_state
    ON backup_runs(snapshot_state, finished_at DESC);
