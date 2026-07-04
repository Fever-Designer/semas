-- =====================================================================
-- SEMAS - Migration 019: Normalize class attendance placeholders.
--
-- Older databases briefly had class_attendance_logs.verification_method
-- without the Auto enum value, so Auto placeholder inserts could be stored
-- as an empty enum value. Empty placeholders must not count as real
-- attendance.
--
--   mysql -u root -p semas < database/migration_019.sql
-- =====================================================================


ALTER TABLE class_attendance_logs
    MODIFY COLUMN verification_method ENUM('QR','Manual','Auto') NOT NULL DEFAULT 'QR';

UPDATE class_attendance_logs
SET verification_method = 'Auto'
WHERE verification_method = ''
  AND attendance_type = 'Sign In'
  AND status = 'Absent';
