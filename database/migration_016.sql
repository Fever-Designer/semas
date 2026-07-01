-- =====================================================================
-- SEMAS - Migration 016: Attendance-driven eligibility & security
--   1. Store attendance metrics on CAT/Exam eligibility rows
--   2. Register one attendance device per student
--   3. Log every attendance scan/security rejection
--   4. Track classroom coordinates for near-room verification
-- =====================================================================

USE semas;

ALTER TABLE cat_exam_eligibility
    ADD COLUMN attendance_percent DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER absences_count,
    ADD COLUMN total_sessions     INT NOT NULL DEFAULT 0 AFTER attendance_percent,
    ADD COLUMN present_count      INT NOT NULL DEFAULT 0 AFTER total_sessions,
    ADD COLUMN late_count         INT NOT NULL DEFAULT 0 AFTER present_count,
    ADD COLUMN left_early_count   INT NOT NULL DEFAULT 0 AFTER late_count,
    ADD COLUMN requires_review    TINYINT(1) NOT NULL DEFAULT 0 AFTER left_early_count;

CREATE TABLE IF NOT EXISTS attendance_devices (
    device_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    device_hash   CHAR(64) NOT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reset_at      DATETIME NULL,
    reset_by      INT NULL,
    UNIQUE KEY uniq_attendance_device_user (user_id),
    UNIQUE KEY uniq_attendance_device_hash (device_hash),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (reset_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_security_logs (
    security_log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NULL,
    module_id       INT NULL,
    session_id      INT NULL,
    device_hash     CHAR(64) NULL,
    ip_address      VARCHAR(45) NULL,
    event_type      VARCHAR(80) NOT NULL,
    message         TEXT NULL,
    latitude        DECIMAL(10,7) NULL,
    longitude       DECIMAL(10,7) NULL,
    distance_meters DECIMAL(8,2) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE SET NULL,
    FOREIGN KEY (session_id) REFERENCES class_sessions(session_id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE rooms
    ADD COLUMN latitude DECIMAL(10,7) NULL,
    ADD COLUMN longitude DECIMAL(10,7) NULL,
    ADD COLUMN attendance_radius_meters INT NOT NULL DEFAULT 20;
