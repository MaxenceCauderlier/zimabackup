CREATE TABLE IF NOT EXISTS discovered_apps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    provider TEXT NOT NULL DEFAULT 'docker-engine',
    project_name TEXT NULL,
    status TEXT NOT NULL DEFAULT 'unknown',
    image TEXT NULL,
    container_count INTEGER NOT NULL DEFAULT 0,
    manifest_json TEXT NOT NULL,
    present INTEGER NOT NULL DEFAULT 1,
    discovered_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS discovered_app_mounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    application_id INTEGER NOT NULL,
    container_name TEXT NOT NULL,
    service_name TEXT NULL,
    source TEXT NOT NULL,
    destination TEXT NOT NULL,
    mount_type TEXT NOT NULL,
    read_write INTEGER NOT NULL DEFAULT 0,
    eligible INTEGER NOT NULL DEFAULT 0,
    recommended INTEGER NOT NULL DEFAULT 0,
    reason TEXT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (application_id) REFERENCES discovered_apps(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_discovered_apps_present
    ON discovered_apps(present, name COLLATE NOCASE);

CREATE INDEX IF NOT EXISTS idx_discovered_app_mounts_app
    ON discovered_app_mounts(application_id, eligible, recommended);

CREATE TABLE IF NOT EXISTS backup_applications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    backup_job_id INTEGER NOT NULL,
    app_key TEXT NOT NULL,
    app_name TEXT NOT NULL,
    selected_mounts_json TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL,
    FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_backup_applications_job
    ON backup_applications(backup_job_id);
