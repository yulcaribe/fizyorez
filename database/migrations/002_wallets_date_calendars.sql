-- FizyoRez beta: mevcut verileri silmeden test cüzdanı ve tarih bazlı takvim ekler.
-- Bu dosya database/schema.sql yerine, daha önce kurulmuş veritabanında bir kez çalıştırılır.

SET NAMES utf8mb4;

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

INSERT IGNORE INTO customer_wallets (customer_id, balance, updated_at)
SELECT id, 0, NOW() FROM users WHERE role = 'customer';

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('test_card_number', '4242424242424242'),
('test_card_expiry', '12/30'),
('test_card_cvv', '123');

-- Önceki şemadaki hatalı demo hash'i yalnızca hiç değiştirilmemiş demo hesaplarında düzeltir.
UPDATE users
SET password_hash = '$2y$10$rJ8ZFZ2VmPvfa6G1gUCeVOhCugGyNHpn3tbCrt1quL7gUvaw3YPEe'
WHERE email IN ('admin@demo.local', 'danisman@demo.local', 'musteri@demo.local')
  AND password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

-- Takvim günleri uygulama tarafından ilk açılışta otomatik oluşturulur.
-- Bu sayede mevcut haftalık program kayıtları silinmez ve eski veriler korunur.
