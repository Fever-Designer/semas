-- =====================================================================
-- SEMAS / Migration 012:
--   Safety re-runs of migration_011 tables (IF NOT EXISTS guards)
--   + Semester Calendar feature
-- ---------------------------------------------------------------------
--   mysql -u root -p semas < database/migration_012.sql
-- =====================================================================



-- Safety net: ensure migration_011 tables exist before any PHP code
-- tries to query them. These statements are all idempotent.

-- Roles
INSERT INTO roles (role_name)
    SELECT 'Registrar' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Registrar');
INSERT INTO roles (role_name)
    SELECT 'Coordinator' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Coordinator');

-- must_change_password column
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- intake column on users
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS intake ENUM('JAN','MAY','SEPT') NULL AFTER year_of_study;

-- rooms table
CREATE TABLE IF NOT EXISTS rooms (
    room_id   INT AUTO_INCREMENT PRIMARY KEY,
    room_name VARCHAR(100) NOT NULL UNIQUE,
    capacity  INT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO rooms (room_name) VALUES
    ('KINIGI'),('KALISIMBI'),('BISOKE'),('MUHOZA'),('GAHINGA'),
    ('COMPUTER LAB 1'),('COMPUTER LAB 2'),('MUHABURA'),
    ('101.5'),('101.2'),('SEBAYA');

-- room_id on modules
ALTER TABLE modules
    ADD COLUMN IF NOT EXISTS room_id INT NULL AFTER room;

-- Add FK only if it doesn't already exist
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'modules' AND CONSTRAINT_NAME = 'fk_modules_room'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@fk = 0,
    'ALTER TABLE modules ADD CONSTRAINT fk_modules_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- start_date / end_date on modules
ALTER TABLE modules
    ADD COLUMN IF NOT EXISTS start_date DATE NULL,
    ADD COLUMN IF NOT EXISTS end_date   DATE NULL;

-- module_intakes
CREATE TABLE IF NOT EXISTS module_intakes (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    module_id INT NOT NULL,
    intake    ENUM('JAN','MAY','SEPT') NOT NULL,
    UNIQUE KEY uniq_module_intake (module_id, intake),
    FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- module_departments
CREATE TABLE IF NOT EXISTS module_departments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    module_id     INT NOT NULL,
    department_id INT NOT NULL,
    UNIQUE KEY uniq_module_dept (module_id, department_id),
    FOREIGN KEY (module_id)     REFERENCES modules(module_id)         ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- announcements target_audience enum update
ALTER TABLE announcements
    MODIFY COLUMN target_audience ENUM(
        'All Students','First Year Students','Final Year Students',
        'Specific Department','Specific Faculty','Staff','Event Participants',
        'University Community','Day Students','Evening Students','Weekend Students',
        'Department Lecturers','Module Students','Registrar','Coordinator','All Staff'
    ) NOT NULL DEFAULT 'All Students';

-- Faculties (UoK)
INSERT IGNORE INTO faculties (faculty_name, faculty_code) VALUES
    ('School of Business Management & Economics', 'SBME'),
    ('School of Computing & Information Technology', 'SCIT'),
    ('School of Education', 'SED'),
    ('School of Law', 'SOL'),
    ('School of Graduate Studies', 'SGS'),
    ('School of Professional & Executive Programmes', 'SPEP');

-- SBME departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id, d.dname, d.dcode FROM faculties f,
(SELECT 'Accounting' AS dname,'ACC' AS dcode UNION ALL SELECT 'Finance','FIN' UNION ALL
 SELECT 'Marketing','MKT' UNION ALL SELECT 'Economics','ECO' UNION ALL
 SELECT 'Procurement & Supply Chain','PSC' UNION ALL
 SELECT 'Public Administration & Local Governance','PALG') d
WHERE f.faculty_code='SBME';

-- SCIT departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id, d.dname, d.dcode FROM faculties f,
(SELECT 'Information Technology' AS dname,'IT' AS dcode UNION ALL
 SELECT 'Computer Science','CS' UNION ALL SELECT 'Business Information Technology','BIT') d
WHERE f.faculty_code='SCIT';

-- SED departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id, d.dname, d.dcode FROM faculties f,
(SELECT 'Early Childhood Development Education' AS dname,'ECDE' AS dcode UNION ALL
 SELECT 'Educational Management & Administration','EMA' UNION ALL
 SELECT 'Special Needs & Inclusive Education','SNE') d
WHERE f.faculty_code='SED';

-- SOL departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id,'Law' AS dname,'LLB' AS dcode FROM faculties f WHERE f.faculty_code='SOL';

-- SGS departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id, d.dname, d.dcode FROM faculties f,
(SELECT 'MBA (Business Administration)' AS dname,'MBA' AS dcode UNION ALL
 SELECT 'MSc Finance','MSCF' UNION ALL SELECT 'MSc Economics','MSCE' UNION ALL
 SELECT 'MSc IT','MSCIT' UNION ALL SELECT 'MSc Project Management','MSCPM' UNION ALL
 SELECT 'MSc Procurement & Supply Chain','MSCPSC' UNION ALL
 SELECT 'MA Public Policy & Management','MAPPM') d
WHERE f.faculty_code='SGS';

-- SPEP departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id, d.dname, d.dcode FROM faculties f,
(SELECT 'CPA (Rwanda)' AS dname,'CPA' AS dcode UNION ALL SELECT 'ACCA','ACCA' UNION ALL
 SELECT 'CIPS','CIPS' UNION ALL SELECT 'CIA','CIA' UNION ALL SELECT 'CIFA','CIFA' UNION ALL
 SELECT 'ATD','ATD' UNION ALL SELECT 'FIA','FIA' UNION ALL SELECT 'IPSAS','IPSAS' UNION ALL
 SELECT 'Real Estate Management','REM' UNION ALL SELECT 'Short Courses','SC') d
WHERE f.faculty_code='SPEP';

-- =====================================================================
-- NEW: Semester Calendar
-- Three semesters per year, one per intake cohort (JAN / MAY / SEPT).
-- When the Registrar saves a calendar entry, all students in that intake
-- are notified by email automatically.
-- =====================================================================
CREATE TABLE IF NOT EXISTS semester_calendars (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(9)            NOT NULL,   -- e.g. '2025/2026'
    intake        ENUM('JAN','MAY','SEPT') NOT NULL,
    semester_name VARCHAR(100)          NOT NULL,   -- e.g. 'Semester 1 / Jan 2026'
    start_date    DATE                  NOT NULL,
    end_date      DATE                  NOT NULL,
    notes         TEXT                  NULL,
    created_by    INT                   NULL,
    created_at    TIMESTAMP             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_year_intake (academic_year, intake),
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
