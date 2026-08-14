-- ============================================================
-- Migration 006: Restore missing tables and columns lost
-- during ibdata1 corruption recovery
-- ============================================================

-- Add missing columns to destinations
ALTER TABLE destinations
    ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(20) DEFAULT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS contact_email VARCHAR(100) DEFAULT NULL AFTER contact_phone,
    ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,7) DEFAULT NULL AFTER contact_email,
    ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,7) DEFAULT NULL AFTER latitude,
    ADD COLUMN IF NOT EXISTS operating_hours_open TIME DEFAULT NULL AFTER longitude,
    ADD COLUMN IF NOT EXISTS operating_hours_close TIME DEFAULT NULL AFTER operating_hours_open,
    ADD COLUMN IF NOT EXISTS category VARCHAR(50) DEFAULT 'other' AFTER operating_hours_close,
    ADD COLUMN IF NOT EXISTS max_guests_per_booking INT(11) NOT NULL DEFAULT 10 AFTER capacity_limit,
    ADD COLUMN IF NOT EXISTS available_booking_days VARCHAR(100) DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun' AFTER max_guests_per_booking,
    ADD COLUMN IF NOT EXISTS entrance_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER available_booking_days,
    ADD COLUMN IF NOT EXISTS package_price DECIMAL(10,2) DEFAULT NULL AFTER entrance_fee,
    ADD COLUMN IF NOT EXISTS gallery_images TEXT DEFAULT NULL AFTER image,
    ADD COLUMN IF NOT EXISTS booking_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER gallery_images,
    ADD COLUMN IF NOT EXISTS guide_required TINYINT(1) NOT NULL DEFAULT 1 AFTER booking_enabled,
    ADD COLUMN IF NOT EXISTS booking_cutoff_hours INT(11) NOT NULL DEFAULT 2 AFTER guide_required,
    ADD COLUMN IF NOT EXISTS advance_booking_days INT(11) NOT NULL DEFAULT 1 AFTER booking_cutoff_hours,
    ADD COLUMN IF NOT EXISTS cancellation_policy TEXT DEFAULT NULL AFTER advance_booking_days,
    ADD COLUMN IF NOT EXISTS featured TINYINT(1) NOT NULL DEFAULT 0 AFTER cancellation_policy,
    ADD COLUMN IF NOT EXISTS rules_regulations TEXT DEFAULT NULL AFTER accessibility_info,
    ADD COLUMN IF NOT EXISTS facilities TEXT DEFAULT NULL AFTER rules_regulations;

-- Add missing columns to events (status enum fix)
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS publish_date DATETIME DEFAULT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS end_date DATE DEFAULT NULL AFTER publish_date;

-- Add missing columns to bookings
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS destination_id INT(11) DEFAULT NULL AFTER schedule_id,
    ADD COLUMN IF NOT EXISTS guide_id INT(11) DEFAULT NULL AFTER destination_id,
    ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) DEFAULT 'pending' AFTER special_requests,
    ADD COLUMN IF NOT EXISTS visit_date DATE DEFAULT NULL AFTER payment_status;

-- payments table
CREATE TABLE IF NOT EXISTS payments (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id INT(11) NOT NULL,
    tourist_id INT(11) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    service_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('card','gcash','maya','cash','other') NOT NULL DEFAULT 'card',
    card_last_four VARCHAR(4) DEFAULT NULL,
    card_brand VARCHAR(20) DEFAULT NULL,
    transaction_id VARCHAR(255) DEFAULT NULL,
    reference_number VARCHAR(50) NOT NULL,
    payment_status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    payment_date DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_booking (booking_id),
    INDEX idx_tourist (tourist_id),
    INDEX idx_status (payment_status),
    INDEX idx_reference (reference_number),
    INDEX idx_created (created_at),
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payments_tourist FOREIGN KEY (tourist_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- guide_payouts table
CREATE TABLE IF NOT EXISTS guide_payouts (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    guide_id INT(11) NOT NULL,
    booking_id INT(11) NOT NULL,
    payment_id INT(11) NOT NULL,
    tour_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    commission_rate DECIMAL(5,2) NOT NULL DEFAULT 15.00,
    commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    net_earning DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payout_status ENUM('pending','approved','paid','rejected') NOT NULL DEFAULT 'pending',
    approved_by INT(11) DEFAULT NULL,
    approved_at DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    payout_reference VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_guide (guide_id),
    INDEX idx_booking (booking_id),
    INDEX idx_payment (payment_id),
    INDEX idx_status (payout_status),
    INDEX idx_created (created_at),
    CONSTRAINT fk_gp_guide FOREIGN KEY (guide_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_gp_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_gp_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_gp_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- destination_guides table
CREATE TABLE IF NOT EXISTS destination_guides (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    destination_id INT(11) NOT NULL,
    guide_id INT(11) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dest_guide (destination_id, guide_id),
    INDEX idx_destination (destination_id),
    INDEX idx_guide (guide_id),
    CONSTRAINT fk_dg_destination FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_dg_guide FOREIGN KEY (guide_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- event_bookmarks table
CREATE TABLE IF NOT EXISTS event_bookmarks (
    id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id INT(11) NOT NULL,
    user_id INT(11) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_event_user (event_id, user_id),
    INDEX idx_event (event_id),
    INDEX idx_user (user_id),
    CONSTRAINT fk_eb_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_eb_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add password_reset columns to users if missing
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS reset_token VARCHAR(255) DEFAULT NULL AFTER avatar,
    ADD COLUMN IF NOT EXISTS reset_expires DATETIME DEFAULT NULL AFTER reset_token;
