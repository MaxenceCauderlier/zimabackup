CREATE TABLE IF NOT EXISTS application_install_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    application_restore_run_id INTEGER NOT NULL,
    app_key TEXT NOT NULL,
    app_name TEXT NOT NULL,
    project_name TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    stage TEXT NULL,
    container_ids_json TEXT NOT NULL DEFAULT '[]',
    created_networks_json TEXT NOT NULL DEFAULT '[]',
    error TEXT NULL,
    created_at TEXT NOT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    FOREIGN KEY (application_restore_run_id) REFERENCES application_restore_runs(id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_application_install_runs_restore
    ON application_install_runs(application_restore_run_id, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_application_install_runs_status
    ON application_install_runs(status, created_at DESC);
