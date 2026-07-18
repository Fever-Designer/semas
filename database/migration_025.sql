-- SEMAS - Migration 025: Permanent module disciplinary blocks
USE semas;

CREATE TABLE IF NOT EXISTS module_disciplinary_blocks (
    block_id             INT AUTO_INCREMENT PRIMARY KEY,
    module_id            INT NOT NULL,
    user_id              INT NOT NULL,
    missed_days          INT NOT NULL,
    triggered_session_id INT NULL,
    reason               VARCHAR(255) NOT NULL,
    blocked_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_module_disciplinary_block (module_id, user_id),
    FOREIGN KEY (module_id) REFERENCES modules(module_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (triggered_session_id) REFERENCES class_sessions(session_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
