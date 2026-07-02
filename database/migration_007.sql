-- =====================================================================
-- SEMAS / Migration 007:
--   1. Module-level static QR code secret (for classroom print QR)
--   2. CAT/Exam schedule table (room + invigilator per module per exam type)
--   3. HOD manual student enrollment permission (no constraint needed /
--      handled at application layer)
-- ---------------------------------------------------------------------
--   mysql -u root -p semas < database/migration_007.sql
-- =====================================================================

USE semas;

-- ---------------------------------------------------------------------
-- 1. Static per-module QR secret used for the printable classroom QR.
--    Unlike class_sessions.qr_secret (rotates per session), this is
--    generated once when the module is created and never changes so the
--    HOD can print it once and paste it on the board.
-- ---------------------------------------------------------------------
ALTER TABLE modules
    ADD COLUMN module_qr_secret VARCHAR(64) NULL AFTER invigilator_id;

-- ---------------------------------------------------------------------
-- 2. CAT/Exam scheduling metadata / room, invigilator, and the actual
--    exam date for that exam type.  One row per (module �/ exam_type).
--    Multiple modules can share the same room (no UNIQUE on room).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cat_exam_schedules (
    schedule_id     INT AUTO_INCREMENT PRIMARY KEY,
    module_id       INT NOT NULL,
    exam_type       ENUM('CAT','Exam') NOT NULL,
    scheduled_date  DATE NOT NULL,
    room            VARCHAR(150) NOT NULL,
    invigilator_id  INT NOT NULL,
    created_by      INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_module_examtype (module_id, exam_type),
    FOREIGN KEY (module_id)      REFERENCES modules(module_id)   ON DELETE CASCADE,
    FOREIGN KEY (invigilator_id) REFERENCES lecturers(lecturer_id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by)     REFERENCES users(user_id)
) ENGINE=InnoDB;
