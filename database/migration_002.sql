-- =====================================================================
-- SEMAS / Migration 002: Profile, Sessions, Notifications, Suggestions,
--          Event Capacity, QR Security, Reminders
-- ADDITIVE ONLY / does not drop or redefine any existing table/column.
-- Run this once against your existing `semas` database:
--   mysql -u root -p semas < database/migration_002.sql
-- =====================================================================

USE semas;

-- ---------------------------------------------------------------------
-- 1. Student session type (Day / Evening / Weekend)
-- ---------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN session_type ENUM('Day','Evening','Weekend') NULL AFTER department_id;

-- ---------------------------------------------------------------------
-- 2. Announcement targeting: faculty + session, in addition to existing
--    department/audience targeting. Existing target_audience values are
--    kept; new ones are appended (ENUM order does not matter for stored
--    data already using the old values).
-- ---------------------------------------------------------------------
ALTER TABLE announcements
    MODIFY COLUMN target_audience ENUM(
        'All Students','First Year Students','Final Year Students',
        'Specific Department','Specific Faculty','Staff','Event Participants',
        'University Community','Day Students','Evening Students','Weekend Students'
    ) NOT NULL DEFAULT 'All Students',
    ADD COLUMN faculty_id INT NULL AFTER department_id,
    ADD CONSTRAINT fk_announcements_faculty FOREIGN KEY (faculty_id) REFERENCES faculties(faculty_id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 3. Notifications: category grouping for the bell (Events / Announcements
--    / Attendance / System), used purely for UI grouping / no behavior change.
-- ---------------------------------------------------------------------
ALTER TABLE notifications
    ADD COLUMN category ENUM('Event','Announcement','Attendance','System') NOT NULL DEFAULT 'System' AFTER body;

-- ---------------------------------------------------------------------
-- 4. Event capacity, waiting list, registration deadline
-- ---------------------------------------------------------------------
ALTER TABLE events
    ADD COLUMN capacity INT NULL AFTER venue,
    ADD COLUMN registration_deadline DATETIME NULL AFTER capacity,
    ADD COLUMN waitlist_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_deadline,
    ADD COLUMN qr_rotation_seconds INT NOT NULL DEFAULT 0 AFTER qr_expires_at; -- 0 = no rotation, e.g. 30 = new signed token every 30s

ALTER TABLE event_registrations
    ADD COLUMN status ENUM('Registered','Waitlisted','Cancelled') NOT NULL DEFAULT 'Registered' AFTER user_id;

-- ---------------------------------------------------------------------
-- 5. Attendance: distinguish self-scan vs staff-scan vs manual, and a
--    "confirmed_by" column so a staff-confirmed scan is auditable.
-- ---------------------------------------------------------------------
ALTER TABLE attendance_logs
    MODIFY COLUMN verification_method ENUM('QR','StaffScan','Manual') NOT NULL DEFAULT 'QR',
    ADD COLUMN confirmed_by INT NULL AFTER verification_method,
    ADD CONSTRAINT fk_attendance_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(user_id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 6. Event reminder de-duplication (so the 24h/1h/start reminders never
--    send twice for the same user+event+stage).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_reminders_sent (
    reminder_id   INT AUTO_INCREMENT PRIMARY KEY,
    event_id      INT NOT NULL,
    user_id       INT NOT NULL,
    reminder_type ENUM('24h','1h','start') NOT NULL,
    sent_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reminder (event_id, user_id, reminder_type),
    FOREIGN KEY (event_id) REFERENCES events(event_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. Anonymous Suggestion Box.
--    submitted_by_user_id is stored so abuse can be traced internally if
--    ever legally/administratively required, but NO admin-facing query in
--    this codebase selects or displays it / see classes/Suggestion usage.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS suggestions (
    suggestion_id      INT AUTO_INCREMENT PRIMARY KEY,
    category           ENUM('Suggestion','Complaint','Bug Report','Feedback','Request') NOT NULL,
    message            TEXT NOT NULL,
    department_id      INT NULL,
    submitted_by_user_id INT NULL,   -- internal only; never exposed to admin UI/queries
    status             ENUM('New','Replied','Resolved','Archived') NOT NULL DEFAULT 'New',
    admin_reply        TEXT NULL,
    replied_by         INT NULL,
    replied_at         DATETIME NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
    FOREIGN KEY (submitted_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (replied_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. Email change requests (so changing email requires re-verification,
--    same pattern as registration, without touching the users table's
--    live email until confirmed).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_change_requests (
    request_id    INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    new_email     VARCHAR(150) NOT NULL,
    token_hash    VARCHAR(255) NOT NULL,
    expires_at    DATETIME NOT NULL,
    confirmed_at  DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. Dean -> faculty scoping already exists via faculties.dean_user_id.
--    HOD -> department scoping already exists via departments.hod_user_id.
--    No change needed there; the new admin/users.php edit screen reads them.
-- ---------------------------------------------------------------------
