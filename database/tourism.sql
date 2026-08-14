-- ============================================================
-- Tour Guide Management System - Database Schema
-- Database: tourism_db
-- Engine: InnoDB | Charset: utf8mb4
-- ============================================================

-- Drop existing database if it exists
DROP DATABASE IF EXISTS tourism_db;

-- Create the database
CREATE DATABASE IF NOT EXISTS tourism_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE tourism_db;

-- ============================================================
-- 1. USERS TABLE
-- ============================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','staff','guide','tourist') NOT NULL DEFAULT 'tourist',
    name VARCHAR(150) NOT NULL,
    gender ENUM('male','female') NOT NULL,
    age INT NOT NULL CHECK (age BETWEEN 1 AND 120),
    phone VARCHAR(20) NOT NULL,
    avatar VARCHAR(500) NULL,
    status ENUM('active','inactive','suspended','pending') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_users_role (role),
    INDEX idx_users_status (status),
    INDEX idx_users_email (email),
    INDEX idx_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. TOURIST PROFILES TABLE
-- ============================================================
CREATE TABLE tourist_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    emergency_contact VARCHAR(150) NOT NULL,
    emergency_contact_number VARCHAR(20) NOT NULL,
    disability ENUM('none','physical','visual','hearing','other') NOT NULL DEFAULT 'none',
    disability_details TEXT NULL,

    CONSTRAINT fk_tourist_profiles_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. GUIDE PROFILES TABLE
-- ============================================================
CREATE TABLE guide_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    years_of_experience INT NOT NULL DEFAULT 0 CHECK (years_of_experience >= 0),
    languages TEXT NOT NULL,
    specializations TEXT NOT NULL,
    availability_status ENUM('available','on_tour','off_duty','on_leave','suspended') NOT NULL DEFAULT 'available',
    bio TEXT NULL,

    CONSTRAINT fk_guide_profiles_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_guide_availability (availability_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. ID VERIFICATIONS TABLE
-- ============================================================
CREATE TABLE id_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    id_type ENUM('passport','drivers_license','national_id','voters_id','senior_citizen','other') NOT NULL,
    id_file_path VARCHAR(500) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    verified_by INT NULL,
    verified_at DATETIME NULL,
    admin_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_id_verifications_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_id_verifications_verifier FOREIGN KEY (verified_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_id_verifications_status (status),
    INDEX idx_id_verifications_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. DESTINATIONS TABLE
-- ============================================================
CREATE TABLE destinations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(255) NOT NULL,
    difficulty ENUM('easy','moderate','difficult','extreme') NOT NULL DEFAULT 'easy',
    capacity_limit INT NOT NULL DEFAULT 0,
    recommended_age_min INT NOT NULL DEFAULT 1 CHECK (recommended_age_min >= 1),
    recommended_age_max INT NOT NULL DEFAULT 100 CHECK (recommended_age_max <= 120),
    accessibility_info TEXT NULL,
    image VARCHAR(500) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_destinations_creator FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_destinations_status (status),
    INDEX idx_destinations_difficulty (difficulty),
    INDEX idx_destinations_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. DESTINATION SEASONS TABLE
-- ============================================================
CREATE TABLE destination_seasons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    destination_id INT NOT NULL,
    season_type ENUM('peak','off_peak') NOT NULL,
    months VARCHAR(255) NOT NULL,
    description TEXT NULL,

    CONSTRAINT fk_destination_seasons_dest FOREIGN KEY (destination_id)
        REFERENCES destinations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_destination_seasons_type (season_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. EVENTS TABLE
-- ============================================================
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    destination_id INT NOT NULL,
    max_participants INT NOT NULL DEFAULT 0,
    min_participants INT NOT NULL DEFAULT 1,
    min_age INT NOT NULL DEFAULT 1 CHECK (min_age >= 1),
    max_age INT NULL CHECK (max_age IS NULL OR max_age <= 120),
    health_restrictions TEXT NULL,
    accessibility_info TEXT NULL,
    requires_guide BOOLEAN NOT NULL DEFAULT TRUE,
    duration_hours DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('active','cancelled','completed') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_events_destination FOREIGN KEY (destination_id)
        REFERENCES destinations(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_events_creator FOREIGN KEY (created_by)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_events_status (status),
    INDEX idx_events_destination (destination_id),
    INDEX idx_events_price (price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. SCHEDULES TABLE
-- ============================================================
CREATE TABLE schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    guide_id INT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    available_spots INT NOT NULL DEFAULT 0,
    status ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_schedules_event FOREIGN KEY (event_id)
        REFERENCES events(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_schedules_guide FOREIGN KEY (guide_id)
        REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX idx_schedules_status (status),
    INDEX idx_schedules_dates (start_date, end_date),
    INDEX idx_schedules_guide (guide_id),
    INDEX idx_schedules_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. BOOKINGS TABLE
-- ============================================================
CREATE TABLE bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tourist_id INT NOT NULL,
    schedule_id INT NOT NULL,
    booking_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    num_participants INT NOT NULL DEFAULT 1 CHECK (num_participants >= 1),
    total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending','confirmed','cancelled','completed') NOT NULL DEFAULT 'pending',
    special_requests TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_bookings_tourist FOREIGN KEY (tourist_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_bookings_schedule FOREIGN KEY (schedule_id)
        REFERENCES schedules(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_bookings_tourist (tourist_id),
    INDEX idx_bookings_schedule (schedule_id),
    INDEX idx_bookings_status (status),
    INDEX idx_bookings_date (booking_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. FEEDBACK TABLE
-- ============================================================
CREATE TABLE feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    tourist_id INT NOT NULL,
    guide_id INT NOT NULL,
    schedule_id INT NOT NULL,
    guide_rating INT NOT NULL CHECK (guide_rating BETWEEN 1 AND 5),
    communication_rating INT NOT NULL CHECK (communication_rating BETWEEN 1 AND 5),
    safety_rating INT NOT NULL CHECK (safety_rating BETWEEN 1 AND 5),
    organization_rating INT NOT NULL CHECK (organization_rating BETWEEN 1 AND 5),
    overall_rating INT NOT NULL CHECK (overall_rating BETWEEN 1 AND 5),
    comment TEXT NULL,
    suggestions TEXT NULL,
    complaints TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_feedback_booking FOREIGN KEY (booking_id)
        REFERENCES bookings(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_feedback_tourist FOREIGN KEY (tourist_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_feedback_guide FOREIGN KEY (guide_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_feedback_schedule FOREIGN KEY (schedule_id)
        REFERENCES schedules(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_feedback_tourist (tourist_id),
    INDEX idx_feedback_guide (guide_id),
    INDEX idx_feedback_booking (booking_id),
    INDEX idx_feedback_overall (overall_rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. MESSAGES TABLE
-- ============================================================
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    file_url VARCHAR(500) NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_messages_receiver FOREIGN KEY (receiver_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_messages_sender (sender_id),
    INDEX idx_messages_receiver (receiver_id),
    INDEX idx_messages_read (is_read),
    INDEX idx_messages_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. NOTIFICATIONS TABLE
-- ============================================================
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('booking','cancellation','assignment','schedule','feedback','verification','general') NOT NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    link VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_type (type),
    INDEX idx_notifications_read (is_read),
    INDEX idx_notifications_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. ACTIVITY LOGS TABLE
-- ============================================================
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_activity_logs_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_activity_logs_user (user_id),
    INDEX idx_activity_logs_action (action),
    INDEX idx_activity_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DEFAULT DATA
-- ============================================================

-- Admin account (password: admin123)
INSERT INTO users (username, email, password, role, name, gender, age, phone, status)
VALUES (
    'admin',
    'admin@tourism.com',
    '$2y$10$uidVaAQcO4TpiDopOaUEY.Tyih/kEwdnq0WRUapacLWbySw5l7iW2',
    'admin',
    'System Administrator',
    'male',
    30,
    '+1234567890',
    'active'
);

-- ============================================================
-- SCHEMA COMPLETE
-- ============================================================
