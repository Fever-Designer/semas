-- =====================================================================
-- SEMAS / Migration 011:
--   1. Registrar role (student account management)
--   2. Coordinator role (Weekend-session academic authority)
--   3. must_change_password flag on users (first-login enforcement)
--   4. Rooms table + room_id on modules (conflict-aware room assignment)
--   5. Module intakes (JAN / MAY / SEPT, multiple per module)
--   6. Module cross-cutting departments (many-to-many)
--   7. Announcements: Registrar / Coordinator target audiences
--   8. UoK faculties & departments (SBME, SCIT, SED, SOL, SGS, SPEP)
--   9. Login by registration number support (auth uses email OR reg_number)
--  10. Coordinator scope on modules (session_type includes Weekend-only mgmt)
-- ---------------------------------------------------------------------
--   mysql -u root -p semas < database/migration_011.sql
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. Registrar role
-- ---------------------------------------------------------------------
INSERT INTO roles (role_name)
    SELECT 'Registrar' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Registrar');

-- ---------------------------------------------------------------------
-- 2. Coordinator role
-- ---------------------------------------------------------------------
INSERT INTO roles (role_name)
    SELECT 'Coordinator' WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = 'Coordinator');

-- ---------------------------------------------------------------------
-- 3. must_change_password flag / set to 1 for auto-created student
--    accounts (reg_number = password). Cleared after first change.
-- ---------------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- ---------------------------------------------------------------------
-- 4. Rooms / predefined campus rooms. A room can be assigned to AT MOST
--    ONE Ongoing module per session_type (Day / Evening / Weekend).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
    room_id    INT AUTO_INCREMENT PRIMARY KEY,
    room_name  VARCHAR(100) NOT NULL UNIQUE,
    capacity   INT NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO rooms (room_name) VALUES
    ('KINIGI'),
    ('KALISIMBI'),
    ('BISOKE'),
    ('MUHOZA'),
    ('GAHINGA'),
    ('COMPUTER LAB 1'),
    ('COMPUTER LAB 2'),
    ('MUHABURA'),
    ('101.5'),
    ('101.2'),
    ('SEBAYA');

-- room_id FK on modules (nullable / old rows stay NULL until re-saved)
ALTER TABLE modules
    ADD COLUMN IF NOT EXISTS room_id INT NULL AFTER room,
    ADD CONSTRAINT fk_modules_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE SET NULL;

-- Back-fill room_id for any existing rows that have a matching room name
UPDATE modules m
JOIN   rooms r ON r.room_name = m.room
SET    m.room_id = r.room_id
WHERE  m.room_id IS NULL AND m.room IS NOT NULL;

-- ---------------------------------------------------------------------
-- 5. Module intakes / a module can belong to multiple intake cohorts
--    (JAN, MAY, SEPT); students can only register if their assigned
--    intake matches one of the module's intakes.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS module_intakes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    module_id  INT NOT NULL,
    intake     ENUM('JAN','MAY','SEPT') NOT NULL,
    UNIQUE KEY uniq_module_intake (module_id, intake),
    FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Student intake assignment (stored on users table)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS intake ENUM('JAN','MAY','SEPT') NULL AFTER year_of_study;

-- ---------------------------------------------------------------------
-- 6. Module cross-cutting departments (many-to-many)
--    modules.department_id stays as the "primary / owning" department;
--    module_departments records additional departments that can also
--    see and enroll in the module.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS module_departments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    module_id     INT NOT NULL,
    department_id INT NOT NULL,
    UNIQUE KEY uniq_module_dept (module_id, department_id),
    FOREIGN KEY (module_id)     REFERENCES modules(module_id)         ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. Announcements: add Registrar and Coordinator to target audiences
-- ---------------------------------------------------------------------
ALTER TABLE announcements
    MODIFY COLUMN target_audience ENUM(
        'All Students','First Year Students','Final Year Students',
        'Specific Department','Specific Faculty','Staff','Event Participants',
        'University Community','Day Students','Evening Students','Weekend Students',
        'Department Lecturers','Module Students','Registrar','Coordinator','All Staff'
    ) NOT NULL DEFAULT 'All Students';

-- ---------------------------------------------------------------------
-- 8. UoK Faculties & Departments
--    Keep existing rows; insert new ones only if code does not already
--    exist (INSERT IGNORE on faculty_code / department_code UNIQUE keys).
-- ---------------------------------------------------------------------

-- Faculties / Schools
INSERT IGNORE INTO faculties (faculty_name, faculty_code) VALUES
    ('School of Business Management & Economics', 'SBME'),
    ('School of Computing & Information Technology', 'SCIT'),
    ('School of Education', 'SED'),
    ('School of Law', 'SOL'),
    ('School of Graduate Studies', 'SGS'),
    ('School of Professional & Executive Programmes', 'SPEP');

-- SBME departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id, d.dname, d.dcode
FROM   faculties f,
(SELECT 'Accounting'                        AS dname, 'ACC'  AS dcode UNION ALL
 SELECT 'Finance',                                                      'FIN'  UNION ALL
 SELECT 'Marketing',                                                    'MKT'  UNION ALL
 SELECT 'Economics',                                                    'ECO'  UNION ALL
 SELECT 'Procurement & Supply Chain',                                   'PSC'  UNION ALL
 SELECT 'Public Administration & Local Governance',                     'PALG') d
WHERE  f.faculty_code = 'SBME';

-- SCIT departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id, d.dname, d.dcode
FROM   faculties f,
(SELECT 'Information Technology'     AS dname, 'IT'  AS dcode UNION ALL
 SELECT 'Computer Science',                                     'CS'  UNION ALL
 SELECT 'Business Information Technology',                      'BIT') d
WHERE  f.faculty_code = 'SCIT';

-- SED departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id, d.dname, d.dcode
FROM   faculties f,
(SELECT 'Early Childhood Development Education'       AS dname, 'ECDE' AS dcode UNION ALL
 SELECT 'Educational Management & Administration',              'EMA'  UNION ALL
 SELECT 'Special Needs & Inclusive Education',                  'SNE') d
WHERE  f.faculty_code = 'SED';

-- SOL departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id, 'Law' AS dname, 'LLB' AS dcode
FROM   faculties f
WHERE  f.faculty_code = 'SOL';

-- SGS departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id, d.dname, d.dcode
FROM   faculties f,
(SELECT 'MBA (Business Administration)' AS dname, 'MBA'  AS dcode UNION ALL
 SELECT 'MSc Finance',                             'MSCF' UNION ALL
 SELECT 'MSc Economics',                           'MSCE' UNION ALL
 SELECT 'MSc IT',                                  'MSCIT' UNION ALL
 SELECT 'MSc Project Management',                  'MSCPM' UNION ALL
 SELECT 'MSc Procurement & Supply Chain',          'MSCPSC' UNION ALL
 SELECT 'MA Public Policy & Management',           'MAPPM') d
WHERE  f.faculty_code = 'SGS';

-- SPEP departments
INSERT IGNORE INTO departments (faculty_id, department_name, department_code)
SELECT f.faculty_id, d.dname, d.dcode
FROM   faculties f,
(SELECT 'CPA (Rwanda)'            AS dname, 'CPA'  AS dcode UNION ALL
 SELECT 'ACCA',                             'ACCA' UNION ALL
 SELECT 'CIPS',                             'CIPS' UNION ALL
 SELECT 'CIA',                              'CIA'  UNION ALL
 SELECT 'CIFA',                             'CIFA' UNION ALL
 SELECT 'ATD',                              'ATD'  UNION ALL
 SELECT 'FIA',                              'FIA'  UNION ALL
 SELECT 'IPSAS',                            'IPSAS' UNION ALL
 SELECT 'Real Estate Management',           'REM'  UNION ALL
 SELECT 'Short Courses',                    'SC') d
WHERE  f.faculty_code = 'SPEP';

-- ---------------------------------------------------------------------
-- 9. modules.start_date / end_date / already added in migration_005 via
--    ALTER TABLE; these columns should exist. If upgrading a database
--    that somehow missed them, add safely.
-- ---------------------------------------------------------------------
ALTER TABLE modules
    ADD COLUMN IF NOT EXISTS start_date DATE NULL,
    ADD COLUMN IF NOT EXISTS end_date   DATE NULL;
