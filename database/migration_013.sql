-- =====================================================================
-- SEMAS / Migration 013: Email Queue
--   Adds email_queue table for async background email delivery.
--   mysql -u root -p semas < database/migration_013.sql
-- =====================================================================


CREATE TABLE IF NOT EXISTS email_queue (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    to_email       VARCHAR(255)  NOT NULL,
    user_id        INT           NULL,
    subject        VARCHAR(500)  NOT NULL,
    template_name  VARCHAR(100)  NOT NULL,
    vars_json      MEDIUMTEXT    NOT NULL,
    status         ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    attempts       TINYINT       NOT NULL DEFAULT 0,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at   DATETIME      NULL,
    INDEX idx_status_attempts (status, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
