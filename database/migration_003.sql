-- =====================================================================
-- SEMAS — Migration 003: Role-Based User Management & Announcement
--          Module + Campus Polls
-- ADDITIVE ONLY — does not drop or redefine any existing table/column
-- in a way that loses data. Run once against your existing database:
--   mysql -u root -p semas < database/migration_003.sql
-- =====================================================================

USE semas;

-- ---------------------------------------------------------------------
-- 1. Announcements: permanent sender snapshot (full name, role, and
--    department/faculty AT THE TIME the announcement was sent), plus
--    Draft/Published status. Snapshotting protects the audit trail —
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

-- ---------------------------------------------------------------------
-- 4. Campus Polls & Surveys — Administrator, Dean, and HOD can create a
--    poll targeted to the same audience scopes they're permitted to use
--    for announcements; students vote once per poll.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS polls (
    poll_id          INT AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(200) NOT NULL,
    description      TEXT NULL,
    target_audience  ENUM('All Students','Specific Department','Specific Faculty',
                           'Day Students','Evening Students','Weekend Students') NOT NULL DEFAULT 'All Students',
    department_id    INT NULL,
    faculty_id       INT NULL,
    created_by       INT NOT NULL,
    status           ENUM('Open','Closed') NOT NULL DEFAULT 'Open',
    closes_at        DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
    FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS poll_options (
    option_id    INT AUTO_INCREMENT PRIMARY KEY,
    poll_id      INT NOT NULL,
    option_text  VARCHAR(200) NOT NULL,
    FOREIGN KEY (poll_id) REFERENCES polls(poll_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS poll_votes (
    vote_id     INT AUTO_INCREMENT PRIMARY KEY,
    poll_id     INT NOT NULL,
    option_id   INT NOT NULL,
    user_id     INT NOT NULL,
    voted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_poll_user (poll_id, user_id),
    FOREIGN KEY (poll_id) REFERENCES polls(poll_id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES poll_options(option_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
