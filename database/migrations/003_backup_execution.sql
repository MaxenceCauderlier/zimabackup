ALTER TABLE backup_runs ADD COLUMN progress_percent REAL NULL;
ALTER TABLE backup_runs ADD COLUMN total_files INTEGER NULL;
ALTER TABLE backup_runs ADD COLUMN files_done INTEGER NULL;
ALTER TABLE backup_runs ADD COLUMN total_bytes INTEGER NULL;
ALTER TABLE backup_runs ADD COLUMN bytes_done INTEGER NULL;
ALTER TABLE backup_runs ADD COLUMN error_count INTEGER NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_backup_runs_status
    ON backup_runs(status, started_at DESC);
