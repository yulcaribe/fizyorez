-- FizyoRez beta: gerekçeli danışan bakiyesi düzeltme yetkisini ekler.
-- Yeni tablo oluşturmaz ve mevcut bakiye/hareket kayıtlarını değiştirmez.

SET NAMES utf8mb4;

INSERT IGNORE INTO permissions (slug, name, permission_group) VALUES
('wallets.adjust', 'Danışan bakiyesini gerekçeli düzeltme', 'Ödemeler');

-- Yönetici rolü varsayılan yetkilidir. Süper yönetici bütün yetkilere otomatik sahiptir.
-- Diğer rollere Rol ve Yetkiler ekranından verilebilir.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.slug = 'wallets.adjust'
WHERE r.slug = 'admin';
