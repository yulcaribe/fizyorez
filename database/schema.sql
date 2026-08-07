-- FizyoRez beta - clean installation schema (MySQL 8 / MariaDB 10.4+)
-- Existing beta databases should run migrations 002 and 003 in numeric order instead.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role ENUM('super_admin', 'admin', 'consultant', 'staff', 'customer') NOT NULL,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    phone VARCHAR(40) NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('pending', 'active', 'suspended', 'passive') NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    privacy_consent_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role (role),
    INDEX idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL,
    permission_group VARCHAR(100) NOT NULL,
    INDEX idx_permissions_group (permission_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id SMALLINT UNSIGNED NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
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
    color VARCHAR(20) NOT NULL DEFAULT '#0f766e',
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
    payment_status ENUM('paid', 'pending', 'awaiting_approval', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
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

CREATE TABLE IF NOT EXISTS customer_wallets (
    customer_id INT UNSIGNED PRIMARY KEY,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('topup', 'payment', 'refund', 'manual') NOT NULL DEFAULT 'topup',
    amount DECIMAL(12,2) NOT NULL,
    balance_before DECIMAL(12,2) NOT NULL,
    balance_after DECIMAL(12,2) NOT NULL,
    status ENUM('approved', 'rejected') NOT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'test_card',
    reference_no VARCHAR(80) NOT NULL,
    card_last_four CHAR(4) NULL,
    note VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_wallet_reference (reference_no),
    INDEX idx_wallet_transactions_customer (customer_id, id),
    INDEX idx_wallet_transactions_status (status, created_at)
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

CREATE TABLE IF NOT EXISTS consultant_calendar_days (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consultant_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    is_working TINYINT(1) NOT NULL DEFAULT 0,
    source ENUM('default', 'manual') NOT NULL DEFAULT 'default',
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (consultant_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_consultant_work_date (consultant_id, work_date),
    INDEX idx_calendar_days_date (work_date, is_working)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consultant_calendar_slots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    calendar_day_id BIGINT UNSIGNED NOT NULL,
    period ENUM('morning', 'afternoon', 'custom') NOT NULL DEFAULT 'custom',
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    FOREIGN KEY (calendar_day_id) REFERENCES consultant_calendar_days(id) ON DELETE CASCADE,
    UNIQUE KEY uq_calendar_day_slot (calendar_day_id, start_time, end_time),
    INDEX idx_calendar_slots_day (calendar_day_id, start_time, end_time)
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
    payment_status ENUM('paid', 'pending', 'awaiting_approval', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
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

CREATE TABLE IF NOT EXISTS reservation_change_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_id BIGINT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    requested_starts_at DATETIME NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    review_note VARCHAR(500) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    pending_reservation_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN reservation_id ELSE NULL END) STORED,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_pending_reservation_change (pending_reservation_id),
    INDEX idx_reservation_change_status (status, created_at),
    INDEX idx_reservation_change_reservation (reservation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS credit_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_package_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    reservation_id BIGINT UNSIGNED NULL,
    amount INT NOT NULL COMMENT 'Positive adds credit, negative deducts credit',
    balance_before INT UNSIGNED NOT NULL,
    balance_after INT UNSIGNED NOT NULL,
    reason VARCHAR(80) NOT NULL,
    note VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_package_id) REFERENCES customer_packages(id) ON DELETE RESTRICT,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_credit_package (customer_package_id, id),
    INDEX idx_credit_customer (customer_id, id),
    INDEX idx_credit_reservation (reservation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    target_type ENUM('package', 'reservation') NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'TRY',
    method ENUM('cash', 'bank_transfer', 'card_manual', 'paypal_beta', 'other') NOT NULL,
    status ENUM('pending', 'awaiting_approval', 'paid', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
    reference_no VARCHAR(190) NULL,
    note VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_payments_customer (customer_id, created_at),
    INDEX idx_payments_target (target_type, target_id),
    INDEX idx_payments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NOT NULL,
    note VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_payment_events_payment (payment_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    details_json LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_actor (actor_id, created_at),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinical_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    consultant_id INT UNSIGNED NOT NULL,
    reservation_id BIGINT UNSIGNED NULL,
    note_type ENUM('assessment', 'treatment', 'progress', 'discharge') NOT NULL DEFAULT 'treatment',
    subjective TEXT NULL,
    objective TEXT NULL,
    assessment TEXT NULL,
    plan TEXT NULL,
    visibility ENUM('internal', 'customer_shared') NOT NULL DEFAULT 'internal',
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (consultant_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_clinical_customer (customer_id, created_at),
    INDEX idx_clinical_consultant (consultant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patient_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    history_type ENUM('injury', 'condition', 'allergy', 'goal', 'other') NOT NULL DEFAULT 'other',
    title VARCHAR(190) NOT NULL,
    details TEXT NULL,
    event_date DATE NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_patient_history_customer (customer_id, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exercise_library (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    description TEXT NULL,
    instructions TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_exercise_active (active, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exercise_programs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    notes TEXT NULL,
    starts_at DATE NOT NULL,
    expires_at DATE NULL,
    status ENUM('draft', 'active', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_program_customer (customer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exercise_program_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_id BIGINT UNSIGNED NOT NULL,
    exercise_id INT UNSIGNED NOT NULL,
    sets_count SMALLINT UNSIGNED NULL,
    repetitions VARCHAR(60) NULL,
    hold_seconds SMALLINT UNSIGNED NULL,
    frequency_text VARCHAR(190) NULL,
    instructions TEXT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (program_id) REFERENCES exercise_programs(id) ON DELETE CASCADE,
    FOREIGN KEY (exercise_id) REFERENCES exercise_library(id) ON DELETE RESTRICT,
    INDEX idx_program_items_program (program_id, sort_order)
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

INSERT IGNORE INTO roles (id, slug, name, is_system, sort_order) VALUES
(1, 'super_admin', 'Süper Yönetici', 1, 10), (2, 'admin', 'Yönetici', 1, 20),
(3, 'staff', 'Resepsiyon / Personel', 1, 30), (4, 'consultant', 'Fizyoterapist', 1, 40),
(5, 'customer', 'Danışan', 1, 50);

INSERT IGNORE INTO permissions (slug, name, permission_group) VALUES
('dashboard.view', 'Genel bakışı görüntüleme', 'Panel'),
('users.view', 'Kullanıcıları görüntüleme', 'Kullanıcılar'), ('users.create', 'Kullanıcı oluşturma', 'Kullanıcılar'),
('users.update', 'Kullanıcı bilgilerini değiştirme', 'Kullanıcılar'), ('users.change_role', 'Kullanıcı rolünü değiştirme', 'Kullanıcılar'),
('users.change_status', 'Kullanıcı durumunu değiştirme', 'Kullanıcılar'),
('roles.view', 'Rol matrisini görüntüleme', 'Yetkilendirme'), ('roles.manage', 'Rol yetkilerini değiştirme', 'Yetkilendirme'),
('reservations.view_all', 'Tüm rezervasyonları görüntüleme', 'Rezervasyon'), ('reservations.manage_all', 'Tüm rezervasyonları yönetme', 'Rezervasyon'),
('reservations.manage_own', 'Kendi rezervasyonlarını yönetme', 'Rezervasyon'),
('reservations.reschedule_approve', 'Danışan tarih değişikliği taleplerini onaylama', 'Rezervasyon'),
('schedules.view_all', 'Tüm çalışma programlarını görüntüleme', 'Takvim'), ('schedules.manage_all', 'Tüm çalışma programlarını yönetme', 'Takvim'),
('schedules.manage_own', 'Kendi çalışma programını yönetme', 'Takvim'), ('time_off.manage_all', 'Tüm izinleri yönetme', 'Takvim'),
('time_off.manage_own', 'Kendi izinlerini yönetme', 'Takvim'),
('services.manage', 'Hizmetleri yönetme', 'Tanımlar'), ('packages.manage', 'Paketleri yönetme', 'Tanımlar'),
('credits.adjust', 'Paket haklarını gerekçeli düzeltme', 'Paket ve Haklar'),
('payments.view_all', 'Tüm ödemeleri görüntüleme', 'Ödemeler'), ('payments.create', 'Manuel ödeme kaydı oluşturma', 'Ödemeler'),
('payments.approve', 'Ödeme onaylama', 'Ödemeler'), ('payments.refund', 'Ödeme iade işlemi', 'Ödemeler'),
('clinical.view_all', 'Danışan fizyoterapi kayıtlarını görüntüleme', 'Fizyoterapi Kayıtları'),
('clinical.create', 'Fizyoterapi değerlendirme ve seans notu ekleme', 'Fizyoterapi Kayıtları'),
('clinical.edit', 'Fizyoterapi kayıtlarını düzenleme', 'Fizyoterapi Kayıtları'),
('exercises.manage', 'Egzersiz ve programları yönetme', 'Egzersiz Programı'),
('reports.view', 'Raporları görüntüleme', 'Raporlar'), ('settings.manage', 'Sistem ayarlarını yönetme', 'Sistem'),
('audit_logs.view', 'İşlem kayıtlarını görüntüleme', 'Sistem');

-- Yönetici: günlük ve operasyonel yönetim; rol matrisi ve kritik sistem ayarları süper yöneticiye bırakılır.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE slug IN (
    'dashboard.view','users.view','users.create','users.update','users.change_status',
    'roles.view','reservations.view_all','reservations.manage_all','reservations.reschedule_approve','schedules.view_all','schedules.manage_all',
    'time_off.manage_all','services.manage','packages.manage','credits.adjust','payments.view_all','payments.create',
    'payments.approve','payments.refund','clinical.view_all','clinical.create','clinical.edit','exercises.manage','reports.view','audit_logs.view'
);

-- Resepsiyon / personel: kullanıcı, rezervasyon, takvim, paket ve tahsilat operasyonları.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 3, id FROM permissions WHERE slug IN (
    'dashboard.view','users.view','users.create','users.update','reservations.view_all','reservations.manage_all',
    'schedules.view_all','schedules.manage_all','time_off.manage_all','packages.manage','payments.view_all','payments.create'
);

-- Fizyoterapist: kendi takvimi/seansları ve fizyoterapi kayıtları.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 4, id FROM permissions WHERE slug IN (
    'dashboard.view','reservations.manage_own','schedules.manage_own','time_off.manage_own',
    'clinical.view_all','clinical.create','clinical.edit','exercises.manage'
);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('booking_change_deadline_hours', '12'), ('late_cancel_burn_credit', '1'),
('credit_deduction_policy', 'on_booking'), ('reservation_reminder_hours', '24'),
('currency', 'TRY'), ('bank_name', ''), ('bank_iban', ''), ('bank_account_name', ''),
('test_card_number', '4242424242424242'), ('test_card_expiry', '12/30'), ('test_card_cvv', '123'),
('calendar_default_hours_version', '2');

-- Beta demo accounts. Password: password (change immediately outside local test environments).
INSERT IGNORE INTO users (id, role, name, email, phone, password_hash, status, privacy_consent_at) VALUES
(1, 'super_admin', 'Süper Yönetici', 'admin@demo.local', '+905550000001', '$2y$10$rJ8ZFZ2VmPvfa6G1gUCeVOhCugGyNHpn3tbCrt1quL7gUvaw3YPEe', 'active', NOW()),
(2, 'consultant', 'Elif Fizyoterapist', 'danisman@demo.local', '+905550000002', '$2y$10$rJ8ZFZ2VmPvfa6G1gUCeVOhCugGyNHpn3tbCrt1quL7gUvaw3YPEe', 'active', NOW()),
(3, 'customer', 'Deniz Danışan', 'musteri@demo.local', '+905550000003', '$2y$10$rJ8ZFZ2VmPvfa6G1gUCeVOhCugGyNHpn3tbCrt1quL7gUvaw3YPEe', 'active', NOW());

INSERT IGNORE INTO customer_wallets (customer_id, balance) VALUES (3, 0);

INSERT IGNORE INTO consultant_profiles (user_id, title, bio, color, booking_deadline_hours) VALUES
(2, 'Fizyoterapist', 'Birebir ve grup fizyoterapi seanslarını yönetir.', '#0f766e', 12);

INSERT IGNORE INTO services (id, name, description, type, duration_minutes, capacity, price, active) VALUES
(1, 'Birebir Fizyoterapi Seansı', 'Kişiye özel fizyoterapi uygulaması.', 'one_to_one', 60, 1, 900.00, 1),
(2, 'Grup Egzersiz Seansı', 'Fizyoterapist eşliğinde grup egzersizi.', 'group', 50, 6, 450.00, 1);

INSERT IGNORE INTO packages (id, name, description, total_credits, validity_days, price, active) VALUES
(1, '10 Seanslık Paket', '10 fizyoterapi seansı kullanım hakkı.', 10, 45, 7500.00, 1),
(2, 'Aylık 8 Seans', '30 gün içinde 8 seans kullanım hakkı.', 8, 30, 6200.00, 1);

INSERT IGNORE INTO customer_packages (id, customer_id, package_id, credits_total, credits_remaining, starts_at, expires_at, status, payment_status) VALUES
(1, 3, 1, 10, 10, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 45 DAY), 'active', 'paid');

INSERT IGNORE INTO credit_transactions (id, customer_package_id, customer_id, amount, balance_before, balance_after, reason, note, created_by) VALUES
(1, 1, 3, 10, 0, 10, 'package_assigned', 'Demo paket açılış bakiyesi', 1);

INSERT IGNORE INTO consultant_availability (id, consultant_id, weekday, start_time, end_time, is_active) VALUES
(1, 2, 1, '08:00:00', '18:00:00', 1), (2, 2, 2, '08:00:00', '18:00:00', 1),
(3, 2, 3, '08:00:00', '18:00:00', 1), (4, 2, 4, '08:00:00', '18:00:00', 1),
(5, 2, 5, '08:00:00', '18:00:00', 1), (6, 2, 6, '08:00:00', '18:00:00', 1);
