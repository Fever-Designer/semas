-- =====================================================================
-- SEMAS / Migration 009: CAT/Exam Invigilator Attendance Submission
--   1. cat_exam_attendance_logs: add missed_reason + missed_notes for
--      students who signed in but did NOT sign out before submission.
--   2. cat_exam_submissions: tracks when an invigilator finishes and
--      formally submits the full attendance list to the HOD. One row
--      per (schedule �/ invigilator). After submission the HOD sees it.
-- ---------------------------------------------------------------------
--   mysql -u root -p semas < database/migration_009.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. Reason columns for students absent at sign-out time.
--    Set automatically when the invigilator submits / they must give a
--    reason for every student who is still "signed in but not signed out"
--    before they can submit the final list.
-- ---------------------------------------------------------------------
ALTER TABLE cat_exam_attendance_logs
    ADD COLUMN missed_reason ENUM('Cheating','Sickness','Other') NULL AFTER status,
    ADD COLUMN missed_notes  TEXT NULL AFTER missed_reason;

-- ---------------------------------------------------------------------
-- 2. Invigilator submission table.
--    UNIQUE on schedule_id / one and only one submission per assessment.
--    Once submitted, the invigilator cannot make further sign-in/out
--    changes (enforced at the application layer).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cat_exam_submissions (
    submission_id  INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id    INT NOT NULL UNIQUE,
    submitted_by   INT NOT NULL,              -- invigilator user_id
    submitted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes          TEXT NULL,                 -- optional general notes from invigilator
    FOREIGN KEY (schedule_id)  REFERENCES cat_exam_schedules(schedule_id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(user_id)                  ON DELETE RESTRICT
) ENGINE=InnoDB;
