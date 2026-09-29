ALTER TABLE discovered_apps ADD COLUMN definition_source TEXT NOT NULL DEFAULT 'docker-runtime';
ALTER TABLE discovered_apps ADD COLUMN zimaos_app_id TEXT NULL;
ALTER TABLE discovered_apps ADD COLUMN zimaos_category TEXT NULL;
ALTER TABLE discovered_apps ADD COLUMN zimaos_icon TEXT NULL;
ALTER TABLE discovered_apps ADD COLUMN zimaos_compose_path TEXT NULL;
ALTER TABLE discovered_apps ADD COLUMN exact_definition INTEGER NOT NULL DEFAULT 0;

ALTER TABLE snapshot_applications ADD COLUMN definition_source TEXT NOT NULL DEFAULT 'docker-runtime';
ALTER TABLE snapshot_applications ADD COLUMN zimaos_app_id TEXT NULL;
ALTER TABLE snapshot_applications ADD COLUMN exact_definition INTEGER NOT NULL DEFAULT 0;
