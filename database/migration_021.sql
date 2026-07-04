-- =====================================================================
-- SEMAS - Migration 021: Add Twilio to sms_logs.provider enum
-- ---------------------------------------------------------------------
--   mysql -u root -p semas < database/migration_021.sql
-- =====================================================================



ALTER TABLE sms_logs
    MODIFY provider ENUM('africastalking','vonage','twilio') NOT NULL DEFAULT 'twilio';
