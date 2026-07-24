-- SEMAS - Migration 029: Principal role and suggestion resolution schema
USE semas;

INSERT INTO roles (role_name)
SELECT 'Principal'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Principal');

ALTER TABLE suggestions
    ADD COLUMN IF NOT EXISTS resolved_by INT NULL,
    ADD COLUMN IF NOT EXISTS resolved_at DATETIME NULL;

CREATE INDEX IF NOT EXISTS idx_suggestions_resolved_by ON suggestions (resolved_by);
