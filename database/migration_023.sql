-- =====================================================================
-- SEMAS - Migration 023: Multiple phones per student
-- ---------------------------------------------------------------------
-- A student may use multiple phones. A phone/device remains exclusive to
-- one student through uniq_attendance_device_hash.
-- =====================================================================

USE semas;

ALTER TABLE attendance_devices
    ADD KEY idx_attendance_device_user (user_id);

ALTER TABLE attendance_devices
    DROP INDEX uniq_attendance_device_user;
