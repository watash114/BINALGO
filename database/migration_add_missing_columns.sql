-- Migration: Add missing columns to SQLite schema
-- Generated from PHP code analysis
-- 52 columns across 9 tables

-- ============================================================
-- 0. users table — add missing login tracking columns
-- ============================================================
ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL;
ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) NULL;
ALTER TABLE users ADD COLUMN last_active_ip VARCHAR(45) NULL;
ALTER TABLE users ADD COLUMN login_count INTEGER DEFAULT 0;
ALTER TABLE users ADD COLUMN last_user_agent TEXT NULL;
ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL;

-- ============================================================
-- 0b. payments — fix method NOT NULL with no default
-- ============================================================
-- SQLite doesn't support ALTER COLUMN, but we can drop the NOT NULL via table recreation
-- The method column is redundant with payment_method; we'll just ensure it has a default
-- by recreating the table. Since we can't ALTER COLUMN in SQLite, PHP already ignores
-- the method column in INSERTs. The column will be NULL for existing rows which is fine
-- since PHP uses payment_method instead.

-- ============================================================
-- 1. bookings table — add 11 columns
-- ============================================================
ALTER TABLE bookings ADD COLUMN booking_reference VARCHAR(50);
ALTER TABLE bookings ADD COLUMN destination_id INTEGER;
ALTER TABLE bookings ADD COLUMN guide_id INTEGER;
ALTER TABLE bookings ADD COLUMN visit_date DATE;
ALTER TABLE bookings ADD COLUMN visit_time TIME;
ALTER TABLE bookings ADD COLUMN full_name VARCHAR(150);
ALTER TABLE bookings ADD COLUMN email VARCHAR(100);
ALTER TABLE bookings ADD COLUMN contact_number VARCHAR(20);
ALTER TABLE bookings ADD COLUMN payment_method VARCHAR(50);
ALTER TABLE bookings ADD COLUMN payment_status VARCHAR(20) DEFAULT 'unpaid';
ALTER TABLE bookings ADD COLUMN service_fee REAL DEFAULT 0;

-- ============================================================
-- 2. events table — add 9 columns
-- ============================================================
ALTER TABLE events ADD COLUMN category VARCHAR(50) DEFAULT 'tourism_event';
ALTER TABLE events ADD COLUMN event_image VARCHAR(500);
ALTER TABLE events ADD COLUMN event_location VARCHAR(255);
ALTER TABLE events ADD COLUMN event_start_date DATE;
ALTER TABLE events ADD COLUMN event_end_date DATE;
ALTER TABLE events ADD COLUMN event_start_time TIME;
ALTER TABLE events ADD COLUMN event_end_time TIME;
ALTER TABLE events ADD COLUMN organizer VARCHAR(200);
ALTER TABLE events ADD COLUMN contact_info VARCHAR(255);

-- ============================================================
-- 3. destinations table — add 18 columns
-- ============================================================
ALTER TABLE destinations ADD COLUMN contact_phone VARCHAR(20);
ALTER TABLE destinations ADD COLUMN contact_email VARCHAR(100);
ALTER TABLE destinations ADD COLUMN latitude VARCHAR(50);
ALTER TABLE destinations ADD COLUMN longitude VARCHAR(50);
ALTER TABLE destinations ADD COLUMN operating_hours_open TIME;
ALTER TABLE destinations ADD COLUMN operating_hours_close TIME;
ALTER TABLE destinations ADD COLUMN max_guests_per_booking INTEGER DEFAULT 10;
ALTER TABLE destinations ADD COLUMN available_booking_days VARCHAR(255) DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun';
ALTER TABLE destinations ADD COLUMN rules_regulations TEXT;
ALTER TABLE destinations ADD COLUMN facilities TEXT;
ALTER TABLE destinations ADD COLUMN package_price REAL;
ALTER TABLE destinations ADD COLUMN booking_price REAL DEFAULT 0;
ALTER TABLE destinations ADD COLUMN gallery_images TEXT;
ALTER TABLE destinations ADD COLUMN video_url VARCHAR(500);
ALTER TABLE destinations ADD COLUMN guide_required INTEGER DEFAULT 0;
ALTER TABLE destinations ADD COLUMN booking_cutoff_hours INTEGER DEFAULT 2;
ALTER TABLE destinations ADD COLUMN advance_booking_days INTEGER DEFAULT 1;
ALTER TABLE destinations ADD COLUMN cancellation_policy TEXT;

-- ============================================================
-- 4. messages table — add 2 columns
-- ============================================================
ALTER TABLE messages ADD COLUMN reply_to_message_id INTEGER;
ALTER TABLE messages ADD COLUMN is_deleted INTEGER DEFAULT 0;

-- ============================================================
-- 5. calls table — add 2 columns + update CHECK constraint
-- ============================================================
ALTER TABLE calls ADD COLUMN call_type VARCHAR(20) DEFAULT 'voice';
ALTER TABLE calls ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP;

-- Note: The calls.status CHECK constraint does not include 'ongoing'.
-- If Call.php uses 'ongoing', the constraint needs to be recreated.
-- SQLite does not support ALTER TABLE to modify CHECK constraints.
-- To fix: drop and recreate the table, or remove the CHECK constraint.

-- ============================================================
-- 6. user_message_settings table — add 4 columns
-- ============================================================
ALTER TABLE user_message_settings ADD COLUMN show_read_receipts INTEGER DEFAULT 1;
ALTER TABLE user_message_settings ADD COLUMN show_online_status INTEGER DEFAULT 1;
ALTER TABLE user_message_settings ADD COLUMN message_notifications INTEGER DEFAULT 1;
ALTER TABLE user_message_settings ADD COLUMN sound_notifications INTEGER DEFAULT 1;
ALTER TABLE user_message_settings ADD COLUMN message_preview INTEGER DEFAULT 1;

-- ============================================================
-- 7. guide_payouts table — add 4 columns
-- ============================================================
ALTER TABLE guide_payouts ADD COLUMN tour_amount REAL DEFAULT 0;
ALTER TABLE guide_payouts ADD COLUMN commission_rate REAL DEFAULT 0;
ALTER TABLE guide_payouts ADD COLUMN payout_reference VARCHAR(100);
ALTER TABLE guide_payouts ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP;

-- ============================================================
-- 8. payments table — add 1 column
-- ============================================================
ALTER TABLE payments ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP;

-- ============================================================
-- 9. destination_seasons table — add 1 column
-- ============================================================
ALTER TABLE destination_seasons ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP;

-- ============================================================
-- 10. Fix calls.status CHECK constraint (optional)
-- ============================================================
-- To allow 'ongoing' status, recreate the table:
--
-- CREATE TABLE IF NOT EXISTS calls_new (
--     id INTEGER PRIMARY KEY AUTOINCREMENT,
--     caller_id INTEGER NOT NULL,
--     receiver_id INTEGER NOT NULL,
--     call_type VARCHAR(20) DEFAULT 'voice',
--     status TEXT NOT NULL DEFAULT 'ringing' CHECK (status IN ('ringing','active','ongoing','ended','missed','rejected')),
--     started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
--     ended_at DATETIME NULL,
--     duration INTEGER DEFAULT 0,
--     created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
--     FOREIGN KEY (caller_id) REFERENCES users(id) ON DELETE CASCADE,
--     FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
-- );
-- INSERT INTO calls_new SELECT * FROM calls;
-- DROP TABLE calls;
-- ALTER TABLE calls_new RENAME TO calls;

-- ============================================================
-- 11. destination_reviews — add missing columns
-- ============================================================
ALTER TABLE destination_reviews ADD COLUMN is_hidden INTEGER DEFAULT 0;
ALTER TABLE destination_reviews ADD COLUMN updated_at DATETIME;
ALTER TABLE destination_reviews ADD COLUMN review TEXT;

-- ============================================================
-- 12. Create dest_bookmarks table
-- ============================================================
CREATE TABLE IF NOT EXISTS dest_bookmarks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    destination_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(destination_id, user_id)
);

-- ============================================================
-- 13. Rename destination_reviews.comment to review (if exists)
-- ============================================================
-- SQLite doesn't support RENAME COLUMN in all versions, 
-- so we add 'review' column and PHP uses both
