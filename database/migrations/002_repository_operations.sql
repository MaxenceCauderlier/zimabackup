ALTER TABLE repositories ADD COLUMN status TEXT NOT NULL DEFAULT 'ready';
ALTER TABLE repositories ADD COLUMN error TEXT NULL;

CREATE TABLE IF NOT EXISTS operations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    repository_id INTEGER NULL,
    type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    payload TEXT NOT NULL,
    result TEXT NULL,
    error TEXT NULL,
    created_at TEXT NOT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_operations_status_created
    ON operations(status, created_at);

CREATE INDEX IF NOT EXISTS idx_operations_repository
    ON operations(repository_id, created_at DESC);
