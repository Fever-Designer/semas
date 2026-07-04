-- =====================================================================
-- SEMAS / Migration 008: Full Attendance Lifecycle
--   1. Module date bounds (start_date / end_date) / attendance is only
--      active within this window; set by HOD when creating the module.
--   2. CAT/Exam schedule gains start_time + end_time so the invigilator
--      can enforce the "no early sign-out within first 1 hour" rule.
--   3. cat_exam_attendance_logs / invigilator-controlled sign-in / sign-
--      out during CAT or Exam (students NEVER scan themselves during an
--      assessment; all entries are made by the assigned invigilator).
-- ---------------------------------------------------------------------
--   mysql -u root -p semas < database/migration_008.sql
-- =====================================================================

USE semas;

-- ---------------------------------------------------------------------
-- 1. Module start and end dates.
--    Attendance scans (both student self-scan and lecturer manual scan)
--    are blocked outside [start_date, end_date]. Auto-completion still
--    runs on exam_date as before (Module::autoCompleteExpired).
-- ---------------------------------------------------------------------
ALTER TABLE modules
    ADD COLUMN start_date DATE NULL AFTER module_qr_secret,
    ADD COLUMN end_date   DATE NULL AFTER start_date;

-- ---------------------------------------------------------------------
-- 2. CAT/Exam schedule: actual session times for that sitting.
--    start_time is used to enforce the "must stay for at least 1 hour"
--    rule at sign-out; end_time closes the invigilator's sign-out window.
-- ---------------------------------------------------------------------
ALTER TABLE cat_exam_schedules
    ADD COLUMN start_time TIME NULL AFTER scheduled_date,
    ADD COLUMN end_time   TIME NULL AFTER start_time;

-- ---------------------------------------------------------------------
-- 3. CAT/Exam attendance log / separate table from class_attendance_logs
--    because the rules, triggers, and slip generation are all different.
--    One Sign In and one Sign Out row per (schedule �/ student).
--    Only rows where BOTH Sign In AND Sign Out exist enable the student
--    to generate an Evidence Slip.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cat_exam_attendance_logs (
    cat_attendance_id  INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id        INT NOT NULL,
    user_id            INT NOT NULL,              -- student
    attendance_type    ENUM('Sign In','Sign Out') NOT NULL,
    recorded_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by        INT NOT NULL,              -- invigilator user_id
    status             ENUM('Present','Absent')   NOT NULL DEFAULT 'Present',
    UNIQUE KEY uniq_schedule_user_type (schedule_id, user_id, attendance_type),
    FOREIGN KEY (schedule_id)  REFERENCES cat_exam_schedules(schedule_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)      REFERENCES users(user_id)                  ON DELETE CASCADE,
    FOREIGN KEY (recorded_by)  REFERENCES users(user_id)                  ON DELETE RESTRICT
) ENGINE=InnoDB;
