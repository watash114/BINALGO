-- ============================================================
-- Migration 007: Fix all remaining missing columns
-- ============================================================

-- EVENTS: Add all missing columns + fix status enum
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS category VARCHAR(50) DEFAULT 'tourism_event' AFTER description,
    ADD COLUMN IF NOT EXISTS event_image VARCHAR(500) DEFAULT NULL AFTER category,
    ADD COLUMN IF NOT EXISTS event_location VARCHAR(255) DEFAULT NULL AFTER event_image,
    ADD COLUMN IF NOT EXISTS event_start_date DATE DEFAULT NULL AFTER event_location,
    ADD COLUMN IF NOT EXISTS event_end_date DATE DEFAULT NULL AFTER event_start_date,
    ADD COLUMN IF NOT EXISTS event_start_time TIME DEFAULT NULL AFTER event_end_date,
    ADD COLUMN IF NOT EXISTS event_end_time TIME DEFAULT NULL AFTER event_start_time,
    ADD COLUMN IF NOT EXISTS organizer VARCHAR(200) DEFAULT NULL AFTER event_end_time,
    ADD COLUMN IF NOT EXISTS contact_info VARCHAR(255) DEFAULT NULL AFTER organizer;

-- Fix events status enum to include 'draft' and 'published'
ALTER TABLE events
    MODIFY COLUMN status ENUM('draft','published','active','cancelled','completed') NOT NULL DEFAULT 'draft';

-- SCHEDULES: Add end_date if missing
ALTER TABLE schedules
    ADD COLUMN IF NOT EXISTS end_date DATE DEFAULT NULL AFTER start_date;

-- NOTIFICATIONS: Add updated_at if missing
ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- ACTIVITY_LOGS: Add session_id column if needed
ALTER TABLE activity_logs
    ADD COLUMN IF NOT EXISTS session_id VARCHAR(100) DEFAULT NULL AFTER details;

-- FEEDBACK: Add updated_at if missing
ALTER TABLE feedback
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
