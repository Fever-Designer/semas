-- =====================================================================
-- SEMAS / Migration 015: Module attendance submission workflow.
--
--   Before a CAT/Exam, the module's lecturer must formally "submit" the
--   class attendance register (Sign In/Out logs) covering that exam's
--   cutoff window. Submitting freezes further manual_mark edits for the
--   covered sessions and triggers eligibility generation. The HOD/
--   Coordinator then Approves (keeps it locked, eligibility decisions can
--   proceed) or Rejects (unlocks the lecturer to fix the register and
--   resubmit).
--
--   mysql -u root -p semas < database/migration_015.sql
-- =====================================================================
USE semas;

CREATE TABLE IF NOT EXISTS module_attendance_submissions (
    submission_id  INT AUTO_INCREMENT PRIMARY KEY,
    module_id      INT NOT NULL,
    exam_type      ENUM('CAT','Exam') NOT NULL,
    submitted_by   INT NOT NULL,
    submitted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status         ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    decided_by     INT NULL,
    decided_at     DATETIME NULL,
    decision_note  VARCHAR(255) NULL,
    UNIQUE KEY uniq_module_exam_type (module_id, exam_type),
    FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
