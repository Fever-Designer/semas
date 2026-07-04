-- =====================================================================
-- SEMAS / Migration 006:
--   1. Announcement recipient scoping (so a user only ever sees
--      announcements actually addressed to them on the board)
--   2. Sign In / Sign Out class attendance with one-scan-per-IP dedup
--   3. CAT/Exam eligibility engine + HOD approval workflow
--   4. Public Holidays & Umuganda
--   5. System Settings / University Branding (key-value store)
--   6. Invigilator field on modules
-- ---------------------------------------------------------------------
--   mysql -u root -p semas < database/migration_006.sql
-- =====================================================================



-- ---------------------------------------------------------------------
-- 1. Announcement recipient scoping
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcement_recipients (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT NOT NULL,
    user_id         INT NOT NULL,
    UNIQUE KEY uniq_announcement_user (announcement_id, user_id),
    FOREIGN KEY (announcement_id) REFERENCES announcements(announcement_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- (No new target_audience ENUM value needed here: Administrator's "system-wide"
--  announcements reuse the existing 'University Community' value, which already
--  resolves to every active user regardless of role / see AudienceResolver::resolve().)

-- ---------------------------------------------------------------------
-- 2. Sign In / Sign Out class attendance with per-IP dedup.
--    A student's first scan in a session = Sign In; a later scan = Sign
--    Out. The UNIQUE key on (session_id, ip_address, attendance_type)
--    means only ONE Sign In and ONE Sign Out can ever be recorded from a
--    given IP address per session / stops one device/IP from "scanning"
--    multiple different accounts in for the same class.
-- ---------------------------------------------------------------------
ALTER TABLE class_attendance_logs
    DROP INDEX uniq_session_user,
    ADD COLUMN attendance_type ENUM('Sign In','Sign Out') NOT NULL DEFAULT 'Sign In' AFTER user_id,
    ADD COLUMN ip_address VARCHAR(45) NULL AFTER verification_method,
    ADD UNIQUE KEY uniq_session_user_type (session_id, user_id, attendance_type),
    ADD UNIQUE KEY uniq_session_ip_type (session_id, ip_address, attendance_type);

-- ---------------------------------------------------------------------
-- 3. CAT/Exam eligibility engine. HOD generates a decision list per
--    module per exam_type; the SYSTEM's computed decision is based on
--    how many sessions the student missed (Absent, or no record at all)
--    before the relevant cutoff date; the HOD then reviews and either
--    Approves the system decision or Overrides it with a reason.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cat_exam_eligibility (
    eligibility_id   INT AUTO_INCREMENT PRIMARY KEY,
    module_id        INT NOT NULL,
    user_id          INT NOT NULL,
    exam_type        ENUM('CAT','Exam') NOT NULL,
    absences_count   INT NOT NULL DEFAULT 0,
    system_decision  ENUM('Allowed','Not Allowed') NOT NULL,
    hod_decision     ENUM('Pending','Approved','Overridden') NOT NULL DEFAULT 'Pending',
    final_decision   ENUM('Allowed','Not Allowed') NOT NULL,
    override_reason  TEXT NULL,
    decided_by       INT NULL,
    decided_at       DATETIME NULL,
    generated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_module_user_examtype (module_id, user_id, exam_type),
    FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. Public Holidays & Umuganda (HOD-managed; disables/reschedules
--    class attendance windows for the affected day).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS holidays (
    holiday_id          INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date         DATE NOT NULL UNIQUE,
    title                 VARCHAR(150) NOT NULL,
    holiday_type          ENUM('Public Holiday','Umuganda') NOT NULL DEFAULT 'Public Holiday',
    -- Umuganda-only overrides (per spec: Morning 13:30-16:30, Afternoon 17:00-20:30);
    -- stored per-row so a future Umuganda date with different hours is possible.
    override_morning_start TIME NULL,
    override_morning_end   TIME NULL,
    override_afternoon_start TIME NULL,
    override_afternoon_end   TIME NULL,
    notes                VARCHAR(255) NULL,
    created_by           INT NOT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. System Settings / University Branding / simple key-value store.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by    INT NULL,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('university_name', 'University of Kigali'),
    ('theme_gold', '#D4A24C'),
    ('theme_ink', '#1E2A52'),
    ('academic_year', ''),
    ('current_semester', '');

-- ---------------------------------------------------------------------
-- 6. Umuganda needs two extra window_name values beyond the normal four
--    (see includes/ClassAttendance.php's holiday-aware currentWindow()).
-- ---------------------------------------------------------------------
ALTER TABLE class_sessions
    MODIFY COLUMN window_name ENUM('Day','Evening','WeekendMorning','WeekendAfternoon','UmugandaMorning','UmugandaAfternoon') NULL;

-- ---------------------------------------------------------------------
-- 7. Invigilator (must be a lecturer) per module / used for both the
--    module record and CAT/Exam scheduling.
-- ---------------------------------------------------------------------
ALTER TABLE modules
    ADD COLUMN invigilator_id INT NULL AFTER lecturer_id,
    ADD CONSTRAINT fk_modules_invigilator FOREIGN KEY (invigilator_id) REFERENCES lecturers(lecturer_id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 8. Backfill announcement_recipients for historical announcements sent
--    before this migration existed. Deliberately placed LAST: it's a
--    best-effort convenience (anyone who has a notification tied to an
--    announcement counts as a recipient), not a structural requirement /
--    if it fails for any reason, every table/column change above it has
--    already been committed and the app works correctly either way.
--    (Earlier version of this migration used the wrong column names /
--    notifications.category and notifications.related_announcement_id are
--    the real ones, not notifications.type / notifications.related_id.)
-- ---------------------------------------------------------------------
INSERT IGNORE INTO announcement_recipients (announcement_id, user_id)
SELECT n.related_announcement_id, n.user_id FROM notifications n
WHERE n.category = 'Announcement' AND n.related_announcement_id IS NOT NULL;
