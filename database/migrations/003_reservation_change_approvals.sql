-- FizyoRez beta: danışan rezervasyon tarih değişikliklerini rol bazlı onaya bağlar.
-- Mevcut verileri silmez. 002_wallets_date_calendars.sql sonrasında bir kez çalıştırılır.

SET NAMES utf8mb4;

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

INSERT IGNORE INTO permissions (slug, name, permission_group) VALUES
('reservations.reschedule_approve', 'Danışan tarih değişikliği taleplerini onaylama', 'Rezervasyon');

-- Yönetici rolü varsayılan onaylayıcıdır. Süper yönetici zaten bütün yetkilere otomatik sahiptir.
-- Resepsiyon veya fizyoterapist rolüne bu yetki Rol ve Yetkiler ekranından verilebilir.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.slug = 'reservations.reschedule_approve'
WHERE r.slug = 'admin';
