-- =====================================================================
-- SEMAS / Migration 014: GPS proximity + device fingerprint for class
--   attendance scans.
--
--   1. Adds latitude/longitude/distance_meters/gps_passed so a student's
--      Sign In / Sign Out QR scan can be checked against the configured
--      campus radius (same approach already used for event check-ins /
--      see attendance_logs / GpsService).
--   2. Adds device_id (a client-persisted random token, not just IP) with
--      a UNIQUE(session_id, device_id, attendance_type) key so one phone
--      cannot sign multiple different student accounts in/out for the
--      same session / a stronger check than IP alone (shared campus WiFi
--      makes IP-only dedup too coarse).
--
--   mysql -u root -p semas < database/migration_014.sql
-- =====================================================================


ALTER TABLE class_attendance_logs
    ADD COLUMN latitude        DECIMAL(10,7) NULL AFTER ip_address,
    ADD COLUMN longitude       DECIMAL(10,7) NULL AFTER latitude,
    ADD COLUMN distance_meters DECIMAL(8,2)  NULL AFTER longitude,
    ADD COLUMN gps_passed      TINYINT(1)    NULL AFTER distance_meters,
    ADD COLUMN device_id       VARCHAR(64)   NULL AFTER gps_passed,
    ADD UNIQUE KEY uniq_session_device_type (session_id, device_id, attendance_type);
