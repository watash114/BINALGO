CREATE TABLE IF NOT EXISTS user_message_settings (
    user_id INT(11) NOT NULL PRIMARY KEY,
    show_read_receipts TINYINT(1) NOT NULL DEFAULT 1,
    show_online_status TINYINT(1) NOT NULL DEFAULT 1,
    message_notifications TINYINT(1) NOT NULL DEFAULT 1,
    sound_notifications TINYINT(1) NOT NULL DEFAULT 1,
    message_preview TINYINT(1) NOT NULL DEFAULT 1,
    who_can_message ENUM('everyone','booked_only') NOT NULL DEFAULT 'everyone',
    blocked_users TEXT DEFAULT NULL COMMENT 'JSON array of user IDs',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
