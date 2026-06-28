-- =====================================================================
-- SEMAS — Migration 004:
--   1. Lecturer role + lecturers table
--   2. Modules + Class Attendance (QR/manual), replacing Polls & Surveys
--   3. Dean becomes university-wide (no longer faculty-scoped)
--   4. HOD can announce to Lecturers in their department
-- ADDITIVE/DESTRUCTIVE NOTE: section 2 below DROPS the polls tables added
-- in migration_003.sql, per product decision to replace that feature with
-- Class Attendance. Back up `polls`, `poll_options`, `poll_votes` first if
-- you need to keep historical poll data.
--   mysql -u root -p semas < database/migration_004.sql
-- =====================================================================

USE semas;

-- ---------------------------------------------------------------------
-- 1. Lecturer role
-- ---------------------------------------------------------------------
INSERT INTO roles (role_name) SELECT 'Lecturer' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Lecturer');

-- Lightweight profile table — a Lecturer is still a row in `users` (role_id =
-- Lecturer), but this table is where department assignment and any
-- lecturer-specific fields live, mirroring how `departments.hod_user_id`
-- and `faculties.dean_user_id` already tie HOD/Dean to their scope.
CREATE TABLE IF NOT EXISTS lecturers (
    lecturer_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL UNIQUE,
    department_id   INT NULL,
    title           VARCHAR(50) NULL,           -- e.g. "Senior Lecturer", "Dr.", "Mr."
    specialization  VARCHAR(150) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. Modules & Class Attendance (replaces Polls & Surveys)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS poll_votes;
DROP TABLE IF EXISTS poll_options;
DROP TABLE IF EXISTS polls;

CREATE TABLE IF NOT EXISTS modules (
    module_id       INT AUTO_INCREMENT PRIMARY KEY,
    module_title    VARCHAR(150) NOT NULL,
    department_id   INT NOT NULL,
    lecturer_id     INT NOT NULL,
    session_type    ENUM('Day','Evening','Weekend') NULL,
    status          ENUM('Active','Archived') NOT NULL DEFAULT 'Active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE CASCADE,
    FOREIGN KEY (lecturer_id) REFERENCES lecturers(lecturer_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS class_sessions (
    session_id      INT AUTO_INCREMENT PRIMARY KEY,
    module_id       INT NOT NULL,
    session_date    DATE NOT NULL,
    start_time      DATETIME NOT NULL,
    end_time        DATETIME NOT NULL,
    qr_secret       VARCHAR(64) NOT NULL,
    status          ENUM('Open','Closed') NOT NULL DEFAULT 'Open',
    created_by      INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS class_attendance_logs (
    attendance_id        INT AUTO_INCREMENT PRIMARY KEY,
    session_id            INT NOT NULL,
    user_id                INT NOT NULL,
    checkin_time           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status                 ENUM('Present','Late','Absent') NOT NULL DEFAULT 'Present',
    verification_method    ENUM('QR','Manual') NOT NULL DEFAULT 'QR',
    confirmed_by            INT NULL,            -- set when a lecturer manually confirmed it
    UNIQUE KEY uniq_session_user (session_id, user_id),
    FOREIGN KEY (session_id) REFERENCES class_sessions(session_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (confirmed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. Dean becomes university-wide. faculties.dean_user_id is kept for
--    record/display purposes (e.g. "Dean of School of Computing" on a
--    business card) but application code no longer uses it to RESTRICT
--    what a Dean can see — Dean now sees/manages every student, full stop.
--    No schema change needed for this; see admin/users.php and
--    dean/announcements.php for the corresponding code change.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- 4. Announcements: allow targeting "Department Lecturers" (HOD -> the
--    lecturers in their own department).
-- ---------------------------------------------------------------------
ALTER TABLE announcements
    MODIFY COLUMN target_audience ENUM(
        'All Students','First Year Students','Final Year Students',
        'Specific Department','Specific Faculty','Staff','Event Participants',
        'University Community','Day Students','Evening Students','Weekend Students',
        'Department Lecturers'
    ) NOT NULL DEFAULT 'All Students';
