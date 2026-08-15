-- SQLite schema for Tourism DB

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role TEXT NOT NULL DEFAULT 'tourist' CHECK (role IN ('admin','staff','guide','tourist')),
    name VARCHAR(150) NOT NULL,
    gender TEXT NOT NULL CHECK (gender IN ('male','female')),
    age INTEGER NOT NULL CHECK (age BETWEEN 1 AND 120),
    phone VARCHAR(20) NOT NULL,
    avatar VARCHAR(500) NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('active','inactive','suspended','pending')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tourist_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    emergency_contact VARCHAR(150) NOT NULL,
    emergency_contact_number VARCHAR(20) NOT NULL,
    disability TEXT NOT NULL DEFAULT 'none' CHECK (disability IN ('none','physical','visual','hearing','other')),
    disability_details TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS guide_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    years_of_experience INTEGER NOT NULL DEFAULT 0,
    languages TEXT NOT NULL,
    specializations TEXT NOT NULL,
    availability_status TEXT NOT NULL DEFAULT 'available' CHECK (availability_status IN ('available','on_tour','off_duty','on_leave','suspended')),
    bio TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS id_verifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    id_type TEXT NOT NULL CHECK (id_type IN ('passport','drivers_license','national_id','voters_id','senior_citizen','other')),
    id_file_path VARCHAR(500) NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    verified_by INTEGER NULL,
    verified_at DATETIME NULL,
    admin_notes TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS destinations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(255) NOT NULL,
    difficulty TEXT NOT NULL DEFAULT 'easy' CHECK (difficulty IN ('easy','moderate','difficult','extreme')),
    capacity_limit INTEGER NOT NULL DEFAULT 0,
    recommended_age_min INTEGER NOT NULL DEFAULT 1,
    recommended_age_max INTEGER NOT NULL DEFAULT 100,
    accessibility_info TEXT NULL,
    image VARCHAR(500) NULL,
    entrance_fee REAL DEFAULT 0,
    category VARCHAR(100) NULL,
    featured INTEGER DEFAULT 0,
    booking_enabled INTEGER DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive')),
    created_by INTEGER NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS destination_seasons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    destination_id INTEGER NOT NULL,
    season_type TEXT NOT NULL CHECK (season_type IN ('peak','off_peak')),
    months VARCHAR(255) NOT NULL,
    description TEXT NULL,
    FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS destination_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    destination_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    destination_id INTEGER NOT NULL,
    max_participants INTEGER NOT NULL DEFAULT 0,
    min_participants INTEGER NOT NULL DEFAULT 1,
    min_age INTEGER NOT NULL DEFAULT 1,
    max_age INTEGER NULL,
    health_restrictions TEXT NULL,
    accessibility_info TEXT NULL,
    requires_guide INTEGER NOT NULL DEFAULT 1,
    duration_hours REAL NOT NULL DEFAULT 1.0,
    price REAL NOT NULL DEFAULT 0.0,
    event_date DATE NULL,
    event_time TIME NULL,
    image VARCHAR(500) NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','cancelled','completed')),
    created_by INTEGER NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    guide_id INTEGER NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    available_spots INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled','in_progress','completed','cancelled')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (guide_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS bookings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tourist_id INTEGER NOT NULL,
    schedule_id INTEGER NOT NULL,
    booking_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    num_participants INTEGER NOT NULL DEFAULT 1,
    total_price REAL NOT NULL DEFAULT 0.0,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','confirmed','cancelled','completed')),
    special_requests TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tourist_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    tourist_id INTEGER NOT NULL,
    guide_id INTEGER NOT NULL,
    schedule_id INTEGER NOT NULL,
    guide_rating INTEGER NOT NULL CHECK (guide_rating BETWEEN 1 AND 5),
    communication_rating INTEGER NOT NULL CHECK (communication_rating BETWEEN 1 AND 5),
    safety_rating INTEGER NOT NULL CHECK (safety_rating BETWEEN 1 AND 5),
    organization_rating INTEGER NOT NULL CHECK (organization_rating BETWEEN 1 AND 5),
    overall_rating INTEGER NOT NULL CHECK (overall_rating BETWEEN 1 AND 5),
    comment TEXT NULL,
    suggestions TEXT NULL,
    complaints TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    FOREIGN KEY (tourist_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (guide_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id INTEGER NOT NULL,
    receiver_id INTEGER NOT NULL,
    message TEXT NOT NULL,
    file_url VARCHAR(500) NULL,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'general',
    is_read INTEGER NOT NULL DEFAULT 0,
    link VARCHAR(500) NULL,
    priority VARCHAR(20) DEFAULT 'normal',
    scheduled_at DATETIME NULL,
    status VARCHAR(20) DEFAULT 'sent',
    audience VARCHAR(50) DEFAULT 'user',
    recipient_count INTEGER DEFAULT 1,
    batch_id VARCHAR(100) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS destination_guides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    destination_id INTEGER NOT NULL,
    guide_id INTEGER NOT NULL,
    is_primary INTEGER DEFAULT 0,
    status VARCHAR(20) DEFAULT 'active',
    FOREIGN KEY (destination_id) REFERENCES destinations(id) ON DELETE CASCADE,
    FOREIGN KEY (guide_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(destination_id, guide_id)
);

CREATE TABLE IF NOT EXISTS conversations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user1_id INTEGER NOT NULL,
    user2_id INTEGER NOT NULL,
    last_message TEXT NULL,
    last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
    deleted_by_user1 INTEGER DEFAULT 0,
    deleted_by_user2 INTEGER DEFAULT 0,
    FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE(user1_id, user2_id)
);

CREATE TABLE IF NOT EXISTS user_message_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    who_can_message VARCHAR(50) DEFAULT 'everyone',
    blocked_users TEXT DEFAULT '[]',
    show_online INTEGER DEFAULT 1,
    allow_messages VARCHAR(50) DEFAULT 'everyone',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS activity_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS guide_payouts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guide_id INTEGER NOT NULL,
    booking_id INTEGER NULL,
    payment_id INTEGER NULL,
    amount REAL NOT NULL DEFAULT 0,
    commission_amount REAL DEFAULT 0,
    net_earning REAL DEFAULT 0,
    payout_status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (payout_status IN ('pending','approved','paid','rejected')),
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','paid','rejected')),
    reference_number VARCHAR(100) NULL,
    notes TEXT NULL,
    approved_by INTEGER NULL,
    approved_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guide_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    booking_id INTEGER NOT NULL,
    tourist_id INTEGER NULL,
    amount REAL NOT NULL DEFAULT 0,
    tax REAL DEFAULT 0,
    service_fee REAL DEFAULT 0,
    total_amount REAL NOT NULL DEFAULT 0,
    method VARCHAR(50) NOT NULL,
    payment_method VARCHAR(50) DEFAULT 'card',
    card_last_four VARCHAR(4) NULL,
    card_brand VARCHAR(50) NULL,
    reference VARCHAR(255) NULL,
    reference_number VARCHAR(100) NULL,
    transaction_id VARCHAR(255) NULL,
    payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','completed','failed','refunded')),
    payment_date DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS calls (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    caller_id INTEGER NOT NULL,
    receiver_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'ringing' CHECK (status IN ('ringing','active','ended','missed','rejected')),
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    duration INTEGER DEFAULT 0,
    FOREIGN KEY (caller_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS message_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    show_online INTEGER NOT NULL DEFAULT 1,
    allow_messages TEXT NOT NULL DEFAULT 'everyone' CHECK (allow_messages IN ('everyone','contacts','none')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Admin account (password: admin123)
INSERT OR IGNORE INTO users (username, email, password, role, name, gender, age, phone, status)
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
