-- =====================================================================
-- SEMAS - Migration 018: Repair duplicate class attendance sessions.
--
-- Some live databases can miss the unique (module_id, session_date,
-- window_name) key if migration_005 was run after duplicate rows already
-- existed. This repair keeps the newest session for each module/date/window,
-- preserves non-conflicting attendance logs, removes duplicate session rows,
-- ensures Auto placeholders are a valid verification_method, then ensures
-- the unique key is present.
--
--   mysql -u root -p semas < database/migration_018.sql
-- =====================================================================
USE semas;

ALTER TABLE class_attendance_logs
    MODIFY COLUMN verification_method ENUM('QR','Manual','Auto') NOT NULL DEFAULT 'QR';

DROP TEMPORARY TABLE IF EXISTS tmp_class_session_keepers;
CREATE TEMPORARY TABLE tmp_class_session_keepers AS
SELECT
    cs.session_id,
    MAX(keep_cs.session_id) AS keep_session_id
FROM class_sessions cs
JOIN class_sessions keep_cs
  ON keep_cs.module_id = cs.module_id
 AND keep_cs.session_date = cs.session_date
 AND keep_cs.window_name <=> cs.window_name
WHERE cs.window_name IS NOT NULL
GROUP BY cs.session_id;

INSERT IGNORE INTO class_attendance_logs (
    session_id,
    user_id,
    attendance_type,
    checkin_time,
    status,
    verification_method,
    confirmed_by,
    ip_address,
    latitude,
    longitude,
    distance_meters,
    gps_passed,
    device_id
)
SELECT
    k.keep_session_id,
    cal.user_id,
    cal.attendance_type,
    cal.checkin_time,
    cal.status,
    cal.verification_method,
    cal.confirmed_by,
    cal.ip_address,
    cal.latitude,
    cal.longitude,
    cal.distance_meters,
    cal.gps_passed,
    cal.device_id
FROM class_attendance_logs cal
JOIN tmp_class_session_keepers k ON k.session_id = cal.session_id
WHERE k.session_id <> k.keep_session_id;

DELETE cal
FROM class_attendance_logs cal
JOIN tmp_class_session_keepers k ON k.session_id = cal.session_id
WHERE k.session_id <> k.keep_session_id;

DELETE cs
FROM class_sessions cs
JOIN tmp_class_session_keepers k ON k.session_id = cs.session_id
WHERE k.session_id <> k.keep_session_id;

DROP PROCEDURE IF EXISTS ensure_class_session_unique_key;
DELIMITER //
CREATE PROCEDURE ensure_class_session_unique_key()
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'class_sessions'
          AND index_name = 'uniq_module_date_window'
    ) THEN
        ALTER TABLE class_sessions
            ADD UNIQUE KEY uniq_module_date_window (module_id, session_date, window_name);
    END IF;
END//
DELIMITER ;

CALL ensure_class_session_unique_key();
DROP PROCEDURE IF EXISTS ensure_class_session_unique_key;
DROP TEMPORARY TABLE IF EXISTS tmp_class_session_keepers;
