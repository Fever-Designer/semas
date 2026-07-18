-- SEMAS - Migration 024: Immediate attendance warning delivery
USE semas;

CREATE TABLE IF NOT EXISTS attendance_warning_deliveries (
    delivery_id       INT AUTO_INCREMENT PRIMARY KEY,
    module_id         INT NOT NULL,
    user_id           INT NOT NULL,
    warning_threshold INT NOT NULL,
    missed_days       INT NOT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_attendance_warning (module_id, user_id, warning_threshold),
    FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
