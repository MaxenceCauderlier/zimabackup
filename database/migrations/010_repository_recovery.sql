ALTER TABLE repositories ADD COLUMN missing_since TEXT NULL;
ALTER TABLE backup_runs ADD COLUMN snapshot_unavailable_reason TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_repositories_missing_since
    ON repositories(status, missing_since);
