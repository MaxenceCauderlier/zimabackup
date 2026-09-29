CREATE TABLE IF NOT EXISTS activity_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    severity TEXT NOT NULL DEFAULT 'info',
    category TEXT NOT NULL DEFAULT 'system',
    title TEXT NOT NULL,
    summary TEXT NULL,
    subject TEXT NULL,
    technical_details TEXT NULL,
    context_json TEXT NOT NULL DEFAULT '{}',
    operation_uuid TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_activity_events_created
    ON activity_events(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_activity_events_severity_created
    ON activity_events(severity, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_activity_events_category_created
    ON activity_events(category, created_at DESC);

CREATE TABLE IF NOT EXISTS runtime_status (
    key TEXT PRIMARY KEY,
    value TEXT NULL,
    updated_at TEXT NOT NULL
);
