CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role ENUM('admin', 'consultant', 'customer') NOT NULL,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(40) NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'passive') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role (role),
    INDEX idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_api_tokens_user (user_id),
    INDEX idx_api_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(120) NOT NULL UNIQUE,
    setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultant_profiles (
    user_id INT UNSIGNED PRIMARY KEY,
    title VARCHAR(120) NULL,
    bio TEXT NULL,
    color VARCHAR(20) NOT NULL DEFAULT '#0891b2',
    booking_deadline_hours INT UNSIGNED NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    type ENUM('one_to_one', 'group') NOT NULL DEFAULT 'one_to_one',
    duration_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    capacity INT UNSIGNED NOT NULL DEFAULT 1,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_services_type (type),
    INDEX idx_services_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    total_credits INT UNSIGNED NOT NULL,
    validity_days INT UNSIGNED NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_packages_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_packages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    package_id INT UNSIGNED NOT NULL,
    credits_total INT UNSIGNED NOT NULL,
    credits_remaining INT UNSIGNED NOT NULL,
    starts_at DATE NOT NULL,
    expires_at DATE NOT NULL,
    status ENUM('active', 'frozen', 'expired', 'cancelled') NOT NULL DEFAULT 'active',
    payment_status ENUM('paid', 'pending', 'cancelled') NOT NULL DEFAULT 'pending',
    low_credit_mail_queued_at DATETIME NULL,
    expiry_mail_queued_at DATETIME NULL,
    payment_mail_queued_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT,
    INDEX idx_customer_packages_customer (customer_id),
    INDEX idx_customer_packages_status (status),
    INDEX idx_customer_packages_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultant_availability (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consultant_id INT UNSIGNED NOT NULL,
    weekday TINYINT UNSIGNED NOT NULL COMMENT '1 Monday, 7 Sunday',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (consultant_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_availability_consultant_weekday (consultant_id, weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultant_time_off (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consultant_id INT UNSIGNED NOT NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    reason VARCHAR(255) NULL,
    FOREIGN KEY (consultant_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_time_off_consultant (consultant_id, start_at, end_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    consultant_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    customer_package_id INT UNSIGNED NULL,
    reservation_type ENUM('package', 'single') NOT NULL DEFAULT 'package',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('pending', 'confirmed', 'cancelled', 'completed', 'no_show') NOT NULL DEFAULT 'confirmed',
    payment_status ENUM('paid', 'pending', 'cancelled') NOT NULL DEFAULT 'pending',
    price DECIMAL(10,2) NOT NULL DEFAULT 0,
    credits_deducted TINYINT(1) NOT NULL DEFAULT 0,
    late_cancelled TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    notes TEXT NULL,
    reminder_queued_at DATETIME NULL,
    payment_mail_queued_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (consultant_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,
    FOREIGN KEY (customer_package_id) REFERENCES customer_packages(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_reservations_customer (customer_id, starts_at),
    INDEX idx_reservations_consultant (consultant_id, starts_at),
    INDEX idx_reservations_service_slot (service_id, consultant_id, starts_at, ends_at),
    INDEX idx_reservations_status (status),
    INDEX idx_reservations_payment (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(190) NOT NULL,
    to_name VARCHAR(160) NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    reservation_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL DEFAULT 'general',
    send_after DATETIME NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    sent_at DATETIME NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
    INDEX idx_mail_queue_status (status, send_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('booking_change_deadline_hours', '12'),
('late_cancel_burn_credit', '1'),
('credit_deduction_policy', 'on_booking'),
('reservation_reminder_hours', '24');

INSERT IGNORE INTO users (id, role, name, email, phone, password_hash, status) VALUES
(1, 'admin', 'Admin Kullanıcı', 'admin@demo.local', '+905550000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'active'),
(2, 'consultant', 'Elif Danışman', 'danisman@demo.local', '+905550000002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'active'),
(3, 'customer', 'Deniz Müşteri', 'musteri@demo.local', '+905550000003', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.', 'active');

INSERT IGNORE INTO consultant_profiles (user_id, title, bio, color, booking_deadline_hours) VALUES
(2, 'Reformer ve Fonksiyonel Egzersiz Uzmanı', 'Birebir ve grup dersleri yönetir.', '#0891b2', 12);

INSERT IGNORE INTO services (id, name, description, type, duration_minutes, capacity, price, active) VALUES
(1, 'Birebir Egzersiz Seansı', 'Danışman eşliğinde birebir çalışma.', 'one_to_one', 60, 1, 900.00, 1),
(2, 'Grup Reformer Dersi', 'Kapasiteli grup dersi.', 'group', 50, 6, 450.00, 1);

INSERT IGNORE INTO packages (id, name, description, total_credits, validity_days, price, active) VALUES
(1, '10 Haklık Paket', '10 ders kullanım hakkı.', 10, 45, 7500.00, 1),
(2, 'Aylık 8 Hak', '30 gün içinde 8 kullanım hakkı.', 8, 30, 6200.00, 1);

INSERT IGNORE INTO customer_packages (id, customer_id, package_id, credits_total, credits_remaining, starts_at, expires_at, status, payment_status) VALUES
(1, 3, 1, 10, 10, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 45 DAY), 'active', 'paid');

INSERT IGNORE INTO consultant_availability (id, consultant_id, weekday, start_time, end_time, is_active) VALUES
(1, 2, 1, '09:00:00', '18:00:00', 1),
(2, 2, 2, '09:00:00', '18:00:00', 1),
(3, 2, 3, '09:00:00', '18:00:00', 1),
(4, 2, 4, '09:00:00', '18:00:00', 1),
(5, 2, 5, '09:00:00', '18:00:00', 1),
(6, 2, 6, '10:00:00', '15:00:00', 1);
