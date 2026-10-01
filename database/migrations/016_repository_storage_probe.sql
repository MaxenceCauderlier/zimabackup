ALTER TABLE repositories ADD COLUMN storage_present INTEGER NULL;
ALTER TABLE repositories ADD COLUMN storage_empty INTEGER NULL;
ALTER TABLE repositories ADD COLUMN storage_checked_at TEXT NULL;

-- Existing status is only a hint until the worker performs its first probe.
UPDATE repositories
SET storage_present = CASE WHEN status = 'missing' THEN 0 ELSE NULL END,
    storage_empty = NULL,
    storage_checked_at = NULL;
