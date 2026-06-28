-- =====================================================================
-- SEMAS — Migration 010: WhatsApp delivery log
--   1. whatsapp_logs — mirrors sms_logs for Vonage WhatsApp channel
--   2. Fix sms_logs.provider enum: replace 'twilio' with 'vonage'
-- =====================================================================

USE semas;

-- 1. WhatsApp log table
CREATE TABLE IF NOT EXISTS whatsapp_logs (
    whatsapp_log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NULL,
    to_phone        VARCHAR(30) NOT NULL,
    message         TEXT NOT NULL,
    status          ENUM('Sent','Failed') NOT NULL DEFAULT 'Failed',
    error_message   TEXT NULL,
    sent_at         DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 2. Update sms_logs provider enum to include vonage (replace twilio)
ALTER TABLE sms_logs
    MODIFY COLUMN provider ENUM('africastalking','vonage') NOT NULL DEFAULT 'vonage';
