-- =====================================================================
-- SEMAS / Migration 005: HOD becomes central academic authority
--   1. Modules: HOD-managed (room, CAT/Exam date, Ongoing/Completed status)
--   2. Module enrollment (students register themselves)
--   3. Assignments + submissions
--   4. Announcements: new "Module Students" audience (Lecturer -> their
--      module's registered students)
--   5. Drops the student-facing "scan a class QR" flow from migration_004 /
--      attendance is now fully lecturer-controlled (manual search or the
--      lecturer scanning the STUDENT's personal QR), per product decision.
--      class_sessions / class_attendance_logs tables are KEPT (still used,
--      just no QR is ever shown to a student for class attendance).
-- ---------------------------------------------------------------------
--   mysql -u root -p semas < database/migration_005.sql
-- =====================================================================

USE semas;

-- ---------------------------------------------------------------------
-- 0. class_sessions: tag each session with which FIXED window it belongs
--    to (Day / Evening / WeekendMorning / WeekendAfternoon), so the
--    lecturer's "Take Attendance" action can find-or-create exactly one
--    session per (module, date, window) instead of an arbitrary
--    click-to-start time. See includes/ClassAttendance.php.
-- ---------------------------------------------------------------------
ALTER TABLE class_sessions
    ADD COLUMN window_name ENUM('Day','Evening','WeekendMorning','WeekendAfternoon') NULL AFTER session_date,
    ADD UNIQUE KEY uniq_module_date_window (module_id, session_date, window_name);

-- ---------------------------------------------------------------------
-- 1. Modules: HOD-managed fields. status ENUM renamed Active/Archived ->
--    Ongoing/Completed to match the new "Ongoing Modules / Completed
--    Modules" language used everywhere (lecturer dashboard, student
--    module list, CAT/Exam slip eligibility).
-- ---------------------------------------------------------------------
ALTER TABLE modules
    MODIFY COLUMN status ENUM('Active','Archived','Ongoing','Completed') NOT NULL DEFAULT 'Ongoing';
UPDATE modules SET status = 'Ongoing' WHERE status = 'Active';
UPDATE modules SET status = 'Completed' WHERE status = 'Archived';
ALTER TABLE modules
    MODIFY COLUMN status ENUM('Ongoing','Completed') NOT NULL DEFAULT 'Ongoing';

ALTER TABLE modules
    ADD COLUMN room VARCHAR(100) NULL AFTER session_type,
    ADD COLUMN cat_date DATE NULL AFTER room,
    ADD COLUMN exam_date DATE NULL AFTER cat_date,
    ADD COLUMN created_by INT NULL AFTER lecturer_id,
    ADD CONSTRAINT fk_modules_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
-- 2. Module enrollment / students register themselves; attendance,
--    assignments, and lecturer announcements for a module are only ever
--    visible to/sent to students who appear in this table.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS module_enrollments (
    enrollment_id   INT AUTO_INCREMENT PRIMARY KEY,
    module_id       INT NOT NULL,
    user_id         INT NOT NULL,
    registered_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_module_user (module_id, user_id),
    FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. Assignments & submissions (lecturer uploads, students submit
--    PDF/ZIP only, only while enrolled and before the deadline).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assignments (
    assignment_id   INT AUTO_INCREMENT PRIMARY KEY,
    module_id       INT NOT NULL,
    title           VARCHAR(200) NOT NULL,
    instructions    TEXT NULL,
    attachment_path VARCHAR(255) NULL,
    deadline        DATETIME NOT NULL,
    created_by      INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assignment_submissions (
    submission_id   INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id   INT NOT NULL,
    user_id         INT NOT NULL,
    file_path       VARCHAR(255) NOT NULL,
    submitted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_assignment_user (assignment_id, user_id),
    FOREIGN KEY (assignment_id) REFERENCES assignments(assignment_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. Announcements: Lecturer -> students registered in one of their
--    modules.
-- ---------------------------------------------------------------------
ALTER TABLE announcements
    MODIFY COLUMN target_audience ENUM(
        'All Students','First Year Students','Final Year Students',
        'Specific Department','Specific Faculty','Staff','Event Participants',
        'University Community','Day Students','Evening Students','Weekend Students',
        'Department Lecturers','Module Students'
    ) NOT NULL DEFAULT 'All Students';
