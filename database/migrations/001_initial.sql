CREATE TABLE IF NOT EXISTS repositories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'local',
    path TEXT NOT NULL,
    password_file TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS backup_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    repository_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    schedule_type TEXT NOT NULL DEFAULT 'manual',
    schedule_value TEXT NULL,
    next_run_at TEXT NULL,
    keep_last INTEGER NOT NULL DEFAULT 3,
    keep_daily INTEGER NOT NULL DEFAULT 7,
    keep_weekly INTEGER NOT NULL DEFAULT 4,
    keep_monthly INTEGER NOT NULL DEFAULT 6,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS backup_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    backup_job_id INTEGER NOT NULL,
    path TEXT NOT NULL,
    label TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS backup_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    backup_job_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    snapshot_id TEXT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT NULL,
    files_new INTEGER NULL,
    files_changed INTEGER NULL,
    files_unmodified INTEGER NULL,
    bytes_processed INTEGER NULL,
    bytes_added INTEGER NULL,
    error TEXT NULL,
    FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_backup_jobs_next_run_at
    ON backup_jobs(enabled, next_run_at);

CREATE INDEX IF NOT EXISTS idx_backup_runs_job_started
    ON backup_runs(backup_job_id, started_at DESC);
