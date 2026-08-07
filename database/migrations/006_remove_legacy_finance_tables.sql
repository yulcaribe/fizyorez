-- FizyoRez beta: 005 ile birleşik finans tablosuna taşınan eski finans tablolarını kaldırır.
-- Bu dosya yalnızca 005_unified_financial_transactions.sql başarıyla çalıştırıldıktan sonra uygulanır.
-- Aktarılmamış tek bir kayıt bile varsa tabloları silmez ve cleanup_status alanında BLOCKED döndürür.

SET NAMES utf8mb4;

-- Eski ödeme durum geçmişini silmeden önce değiştirilemez denetim günlüğüne arşivler.
INSERT INTO audit_logs
    (actor_id, action, entity_type, entity_id, details_json, ip_address, created_at)
SELECT
    pe.created_by,
    'financial.legacy_payment_event_archived',
    'legacy_payment_event',
    pe.id,
    JSON_OBJECT(
        'financial_transaction_id', ft.id,
        'legacy_payment_id', pe.payment_id,
        'from_status', pe.from_status,
        'to_status', pe.to_status,
        'note', pe.note
    ),
    NULL,
    pe.created_at
FROM payment_events pe
INNER JOIN financial_transactions ft
    ON ft.legacy_source = 'payments' AND ft.legacy_id = pe.payment_id
LEFT JOIN audit_logs archived
    ON archived.action = 'financial.legacy_payment_event_archived'
   AND archived.entity_type = 'legacy_payment_event'
   AND archived.entity_id = pe.id
WHERE archived.id IS NULL;

SELECT COUNT(*) INTO @unmigrated_payments
FROM payments legacy_payment
LEFT JOIN financial_transactions ft
    ON ft.legacy_source = 'payments' AND ft.legacy_id = legacy_payment.id
WHERE ft.id IS NULL;

SELECT COUNT(*) INTO @unmigrated_wallet_transactions
FROM wallet_transactions legacy_wallet
LEFT JOIN financial_transactions ft
    ON ft.legacy_source = 'wallet_transactions' AND ft.legacy_id = legacy_wallet.id
WHERE ft.id IS NULL;

SELECT COUNT(*) INTO @unarchived_payment_events
FROM payment_events pe
LEFT JOIN audit_logs archived
    ON archived.action = 'financial.legacy_payment_event_archived'
   AND archived.entity_type = 'legacy_payment_event'
   AND archived.entity_id = pe.id
WHERE archived.id IS NULL;

SET @legacy_cleanup_ok = IF(
    @unmigrated_payments = 0
    AND @unmigrated_wallet_transactions = 0
    AND @unarchived_payment_events = 0,
    1,
    0
);

-- Bağımlı tablo önce kaldırılır. Kontrol başarısızsa DROP yerine zararsız bir SELECT çalışır.
SET @drop_payment_events_sql = IF(
    @legacy_cleanup_ok = 1,
    'DROP TABLE IF EXISTS payment_events',
    'SELECT ''BLOCKED: payment_events korunuyor'' AS cleanup_warning'
);
PREPARE drop_payment_events_stmt FROM @drop_payment_events_sql;
EXECUTE drop_payment_events_stmt;
DEALLOCATE PREPARE drop_payment_events_stmt;

SET @drop_payments_sql = IF(
    @legacy_cleanup_ok = 1,
    'DROP TABLE IF EXISTS payments',
    'SELECT ''BLOCKED: payments korunuyor'' AS cleanup_warning'
);
PREPARE drop_payments_stmt FROM @drop_payments_sql;
EXECUTE drop_payments_stmt;
DEALLOCATE PREPARE drop_payments_stmt;

SET @drop_wallet_transactions_sql = IF(
    @legacy_cleanup_ok = 1,
    'DROP TABLE IF EXISTS wallet_transactions',
    'SELECT ''BLOCKED: wallet_transactions korunuyor'' AS cleanup_warning'
);
PREPARE drop_wallet_transactions_stmt FROM @drop_wallet_transactions_sql;
EXECUTE drop_wallet_transactions_stmt;
DEALLOCATE PREPARE drop_wallet_transactions_stmt;

SELECT
    IF(@legacy_cleanup_ok = 1, 'REMOVED', 'BLOCKED') AS cleanup_status,
    @unmigrated_payments AS unmigrated_payments,
    @unmigrated_wallet_transactions AS unmigrated_wallet_transactions,
    @unarchived_payment_events AS unarchived_payment_events;
