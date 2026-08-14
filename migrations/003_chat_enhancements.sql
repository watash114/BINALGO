-- Add reply_to_message_id to messages
ALTER TABLE messages
ADD COLUMN reply_to_message_id INT(11) DEFAULT NULL AFTER message,
ADD COLUMN is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read,
ADD INDEX idx_reply_to (reply_to_message_id),
ADD CONSTRAINT fk_reply_message FOREIGN KEY (reply_to_message_id) REFERENCES messages(id) ON DELETE SET NULL;

-- Create conversations table
CREATE TABLE IF NOT EXISTS conversations (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user1_id INT(11) NOT NULL,
    user2_id INT(11) NOT NULL,
    last_message TEXT DEFAULT NULL,
    last_activity DATETIME DEFAULT NULL,
    deleted_by_user1 TINYINT(1) NOT NULL DEFAULT 0,
    deleted_by_user2 TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_conversation (user1_id, user2_id),
    INDEX idx_user1 (user1_id),
    INDEX idx_user2 (user2_id),
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
