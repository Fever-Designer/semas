-- SEMAS - Migration 030: Login throttling
USE semas;

CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id      BIGINT AUTO_INCREMENT PRIMARY KEY,
    identifier_hash CHAR(64) NOT NULL,
    ip_address      VARCHAR(45) NOT NULL,
    attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempt_window (identifier_hash, ip_address, attempted_at),
    INDEX idx_login_attempt_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
