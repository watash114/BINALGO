CREATE TABLE IF NOT EXISTS calls (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    caller_id INT(11) NOT NULL,
    receiver_id INT(11) NOT NULL,
    call_type ENUM('voice','video') NOT NULL DEFAULT 'voice',
    status ENUM('completed','missed','declined','cancelled','ongoing') NOT NULL DEFAULT 'completed',
    started_at DATETIME DEFAULT NULL,
    ended_at DATETIME DEFAULT NULL,
    duration INT(11) NOT NULL DEFAULT 0 COMMENT 'Duration in seconds',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_caller (caller_id),
    INDEX idx_receiver (receiver_id),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
