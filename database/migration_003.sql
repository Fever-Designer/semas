-- =====================================================================
-- SEMAS / Migration 003: Role-Based User Management & Announcement
-- ADDITIVE ONLY / does not drop or redefine any existing table/column
-- in a way that loses data. Run once against your existing database:
--   mysql -u root -p semas < database/migration_003.sql
-- =====================================================================



-- ---------------------------------------------------------------------
-- 1. Announcements: permanent sender snapshot (full name, role, and
--    department/faculty AT THE TIME the announcement was sent), plus
--    Draft/Published status. Snapshotting protects the audit trail /
--    if a sender is later renamed, promoted, or deactivated, historical
--    announcements must still show exactly who sent them and in what
--    role, per the "Sent by / Role / Date / Time" display requirement.
-- ---------------------------------------------------------------------
ALTER TABLE announcements
    ADD COLUMN sender_name  VARCHAR(150) NULL AFTER posted_by,
    ADD COLUMN sender_role  VARCHAR(50)  NULL AFTER sender_name,
    ADD COLUMN sender_scope VARCHAR(150) NULL AFTER sender_role,
    ADD COLUMN status ENUM('Draft','Published') NOT NULL DEFAULT 'Published' AFTER message,
    ADD COLUMN sms_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN recipients_count INT NOT NULL DEFAULT 0 AFTER sms_sent;

-- Backfill snapshot columns for historical rows from the current state
-- of the users/roles tables (best-effort; new rows always set these
-- explicitly going forward via includes/Announcement.php).
UPDATE announcements a
JOIN users u ON u.user_id = a.posted_by
JOIN roles r ON r.role_id = u.role_id
SET a.sender_name = u.full_name, a.sender_role = r.role_name
WHERE a.sender_name IS NULL;

-- ---------------------------------------------------------------------
-- 2. Student academic year (used for "Students by year" / "First Year"
--    / "Final Year" / "All years" announcement targeting).
-- ---------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN year_of_study TINYINT NULL AFTER session_type;

-- ---------------------------------------------------------------------
-- 3. Staff account creation traceability: who created a HOD/Dean
--    account and is it a staff account that was provisioned by an
--    admin/HOD rather than self-registered (students self-register).
-- ---------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN created_by INT NULL AFTER updated_at,
    ADD CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL;
