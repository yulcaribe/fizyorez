# FizyoRez Beta Kurulumu

## Gereksinimler

- PHP 8.0+ (önerilen 8.2 veya 8.3)
- `pdo_mysql`, `session` ve `mbstring` PHP eklentileri
- MySQL 8 veya MariaDB 10.4+
- Apache `mod_rewrite` ve `.htaccess` desteği

## 1. Dosyaları yükleyin

Proje içeriğini alan adının web köküne yükleyin. Yönetim paneli otomatik olarak `/admin` adresinde, danışan girişi `/login` adresinde çalışır.

## 2. Yerel yapılandırmayı oluşturun

`config/config.example.php` dosyasını `config/config.php` adıyla kopyalayın ve alan adı, veritabanı, e-posta ve güvenlik değerlerini doldurun. `config/config.php` Git tarafından bilerek yok sayılır; gerçek şifreleri GitHub'a göndermeyin.

Uygulama bir alt klasördeyse `app.base_path` değerini örneğin `/fizyorez` yapın. Alan adının kökündeyse boş bırakın.

## 3. Veritabanını hazırlayın

Bu sürüm beta verilerini korumayı hedeflemez. Mevcut test verileri önemli değilse eski FizyoRez tablolarını yedekleyip boş bir veritabanı oluşturun. phpMyAdmin'de sırasıyla:

1. `database/schema.sql`
2. Canlı yönetici hesabı gerekiyorsa `database/production-admin.sql`

dosyalarını içe aktarın.

Demo şemasındaki hesaplar yalnızca yerel/beta test içindir ve varsayılan şifreleri `password` değeridir. İnternete açık kurulumda demo hesaplarını pasife alın ve gerçek süper yönetici şifresini hemen değiştirin.

## 4. Kurulumu kontrol edin

Tarayıcıda `/health` adresini açın. Tüm satırlar “Hazır” olmalıdır. Ardından:

- Ekip paneli: `/admin`
- Giriş: `/login`
- Danışan üyeliği: `/register`
- Beta API durumu: `/api/v1/health`

## 5. E-posta kuyruğu

Hosting zamanlanmış görevine aşağıdaki PHP dosyasını ekleyin:

```text
php /tam/yol/cron/send-mail.php
```

Cron ve API güvenlik anahtarlarını uzun, rastgele ve birbirinden farklı tutun.

## Canlıya geçmeden önce

- Test veritabanı şifresini ve daha önce GitHub'a girmiş olabilecek anahtarları yenileyin.
- `config/config.php` dosyasının GitHub'a gönderilmediğini doğrulayın.
- Demo hesaplarını kapatın.
- HTTPS'i zorunlu yapın ve düzenli veritabanı yedeği alın.
- PayPal/banka/kart seçeneklerinin beta aşamasında manuel kayıt olduğunu personele bildirin; canlı entegrasyon eklenmeden otomatik tahsilat yapmazlar.
