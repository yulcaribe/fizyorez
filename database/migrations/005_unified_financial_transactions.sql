-- FizyoRez beta: ödeme ve bakiye hareketlerini tek finans tablosunda birleştirir.
-- Mevcut payments ve wallet_transactions kayıtları silinmez; yeni tabloya bir kez kopyalanır.

SET NAMES utf8mb4;

UPDATE permissions SET name = 'Tahsilatı danışan bakiyesine yükleme talebi oluşturma' WHERE slug = 'payments.create';
UPDATE permissions SET name = 'Finans hareketi ve bakiye kesintisi onaylama' WHERE slug = 'payments.approve';
UPDATE permissions SET name = 'İade / ters finans hareketi oluşturma' WHERE slug = 'payments.refund';

CREATE TABLE IF NOT EXISTS financial_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('topup', 'package_purchase', 'session_purchase', 'manual_adjustment', 'refund', 'legacy_payment') NOT NULL,
    direction ENUM('credit', 'debit') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reserved_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'TRY',
    method ENUM('wallet', 'cash', 'bank_transfer', 'card_manual', 'customer_card', 'paypal_beta', 'manual_adjustment', 'other', 'legacy') NOT NULL,
    source ENUM('customer_portal', 'staff', 'system', 'migration') NOT NULL DEFAULT 'staff',
    target_type VARCHAR(40) NULL,
    target_id BIGINT UNSIGNED NULL,
    result_target_id BIGINT UNSIGNED NULL,
    effective_date DATE NULL,
    parent_transaction_id BIGINT UNSIGNED NULL,
    status ENUM('awaiting_approval', 'approved', 'rejected', 'cancelled', 'refunded') NOT NULL DEFAULT 'awaiting_approval',
    balance_before DECIMAL(12,2) NULL,
    balance_after DECIMAL(12,2) NULL,
    wallet_applied TINYINT(1) NOT NULL DEFAULT 0,
    provider VARCHAR(60) NULL,
    reference_no VARCHAR(190) NULL,
    card_brand VARCHAR(30) NULL,
    card_last_four CHAR(4) NULL,
    note VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    reviewed_by INT UNSIGNED NULL,
    review_note VARCHAR(500) NULL,
    reviewed_at DATETIME NULL,
    legacy_source VARCHAR(40) NULL,
    legacy_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_transaction_id) REFERENCES financial_transactions(id) ON DELETE SET NULL,
    UNIQUE KEY uq_financial_legacy (legacy_source, legacy_id),
    INDEX idx_financial_customer (customer_id, created_at),
    INDEX idx_financial_status (status, created_at),
    INDEX idx_financial_direction (direction, created_at),
    INDEX idx_financial_target (target_type, target_id),
    INDEX idx_financial_parent (parent_transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eski doğrudan paket/seans ödeme kayıtlarını salt tarihçe olarak taşır.
-- Bu kayıtlar geçmişte cüzdana uygulanmadığı için wallet_applied=0 kalır.
INSERT IGNORE INTO financial_transactions
    (customer_id, transaction_type, direction, amount, reserved_amount, currency, method, source,
     target_type, target_id, status, wallet_applied, reference_no, note, created_by, reviewed_by,
     reviewed_at, legacy_source, legacy_id, created_at, updated_at)
SELECT
    p.customer_id,
    CASE WHEN p.target_type = 'package' THEN 'package_purchase' ELSE 'session_purchase' END,
    'debit', p.amount, 0, p.currency,
    CASE
        WHEN p.method = 'cash' THEN 'cash'
        WHEN p.method = 'bank_transfer' THEN 'bank_transfer'
        WHEN p.method = 'card_manual' THEN 'card_manual'
        WHEN p.method = 'paypal_beta' THEN 'paypal_beta'
        ELSE 'legacy'
    END,
    'migration', p.target_type, p.target_id,
    CASE
        WHEN p.status IN ('pending', 'awaiting_approval') THEN 'awaiting_approval'
        WHEN p.status = 'paid' THEN 'approved'
        WHEN p.status = 'cancelled' THEN 'cancelled'
        ELSE 'refunded'
    END,
    0, p.reference_no, p.note, p.created_by, p.approved_by, p.approved_at,
    'payments', p.id, p.created_at, p.updated_at
FROM payments p;

-- Eski sürümde paket tanımlanmış ancak henüz ödeme hareketi açılmamış kayıtları
-- bakiye kesintisi onayına taşır. Yetersiz bakiye varsa onay sırasında güvenle durur.
INSERT IGNORE INTO financial_transactions
    (customer_id, transaction_type, direction, amount, reserved_amount, currency, method, source,
     target_type, target_id, effective_date, status, wallet_applied, reference_no, note, created_by,
     legacy_source, legacy_id, created_at)
SELECT
    cp.customer_id, 'package_purchase', 'debit', p.price, p.price, 'TRY', 'wallet', 'migration',
    'package', cp.id, cp.starts_at, 'awaiting_approval', 0,
    CONCAT('LEGACY-PKG-', cp.id), 'Eski sürümden aktarılan bekleyen paket bakiye kesintisi.', NULL,
    'customer_packages', cp.id, cp.created_at
FROM customer_packages cp
INNER JOIN packages p ON p.id = cp.package_id
WHERE cp.payment_status IN ('pending', 'awaiting_approval')
  AND p.price >= 0
  AND NOT EXISTS (
      SELECT 1 FROM payments old_payment
      WHERE old_payment.target_type = 'package' AND old_payment.target_id = cp.id
  );

-- Eski cüzdan hareketlerini aynı finans günlüğüne taşır; mevcut bakiyeyi yeniden uygulamaz.
INSERT IGNORE INTO financial_transactions
    (customer_id, transaction_type, direction, amount, reserved_amount, currency, method, source,
     status, balance_before, balance_after, wallet_applied, provider, reference_no, card_last_four,
     note, created_by, reviewed_by, reviewed_at, legacy_source, legacy_id, created_at)
SELECT
    wt.customer_id,
    CASE
        WHEN wt.transaction_type = 'topup' THEN 'topup'
        WHEN wt.transaction_type = 'refund' THEN 'refund'
        WHEN wt.transaction_type = 'manual' THEN 'manual_adjustment'
        ELSE 'legacy_payment'
    END,
    CASE WHEN wt.amount < 0 THEN 'debit' ELSE 'credit' END,
    ABS(wt.amount), 0, 'TRY',
    CASE
        WHEN wt.provider = 'test_card' THEN 'customer_card'
        WHEN wt.provider = 'manual_adjustment' THEN 'manual_adjustment'
        ELSE 'legacy'
    END,
    CASE WHEN wt.created_by = wt.customer_id THEN 'customer_portal' ELSE 'migration' END,
    CASE WHEN wt.status = 'approved' THEN 'approved' ELSE 'rejected' END,
    wt.balance_before, wt.balance_after,
    CASE WHEN wt.status = 'approved' THEN 1 ELSE 0 END,
    wt.provider, wt.reference_no, wt.card_last_four, wt.note, wt.created_by,
    CASE WHEN wt.status = 'approved' THEN wt.created_by ELSE NULL END,
    CASE WHEN wt.status = 'approved' THEN wt.created_at ELSE NULL END,
    'wallet_transactions', wt.id, wt.created_at
FROM wallet_transactions wt;
