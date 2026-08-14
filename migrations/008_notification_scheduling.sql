-- ============================================================
-- Migration 008: Notification scheduling, priority & delivery tracking
-- ============================================================

-- Add columns for scheduling, priority, delivery status and audience tracking.
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS priority ENUM('low','normal','urgent') NOT NULL DEFAULT 'normal' AFTER link,
    ADD COLUMN IF NOT EXISTS scheduled_at DATETIME DEFAULT NULL AFTER priority,
    ADD COLUMN IF NOT EXISTS status ENUM('delivered','failed','scheduled') NOT NULL DEFAULT 'delivered' AFTER scheduled_at,
    ADD COLUMN IF NOT EXISTS audience VARCHAR(100) DEFAULT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS recipient_count INT NOT NULL DEFAULT 1 AFTER audience,
    ADD COLUMN IF NOT EXISTS batch_id VARCHAR(40) DEFAULT NULL AFTER recipient_count;

-- Index for fast history grouping / filtering
ALTER TABLE notifications
    ADD INDEX IF NOT EXISTS idx_notifications_batch (batch_id),
    ADD INDEX IF NOT EXISTS idx_notifications_status (status);

-- Expand type enum to cover all notification kinds used by the app
-- (previously 'announcement'/'system'/'event_published' silently stored as '').
ALTER TABLE notifications
    MODIFY COLUMN type ENUM(
        'booking','cancellation','payment_success','payment_failed','feedback',
        'event_published','event_cancelled','event_updated','announcement','system',
        'verification','registration','assignment','schedule','message','general'
    ) NOT NULL DEFAULT 'general';
