ALTER TABLE application_install_runs ADD COLUMN install_method TEXT NOT NULL DEFAULT 'docker';
ALTER TABLE application_install_runs ADD COLUMN zimaos_app_id TEXT NULL;
ALTER TABLE application_install_runs ADD COLUMN native_verified_at TEXT NULL;
