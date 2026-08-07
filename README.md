# FizyoRez Beta

FizyoRez, yalnızca PHP 8 ve MySQL/MariaDB ile çalışan fizyoterapi işletmesi yönetim uygulamasıdır. Doktor, teşhis veya görüntülü görüşme modülü içermez.

## Hazır modüller

- `/admin` altında profesyonel ekip paneli; `/customer` altında danışan portalı
- Danışanın kendi üyeliğini oluşturması; personel hesaplarının yetkili ekip tarafından açılması
- Kullanıcı bilgisi, durum, rol ve şifre yönetimi
- Kullanıcı bazlı değil rol bazlı yetki matrisi (`super_admin`, `admin`, `staff`, `consultant`, `customer`)
- Her fizyoterapist için tarih bazlı otomatik hafta içi 08:00–12:00 ve 13:00–18:00 takvimi; günlük saat düzenleme
- Birebir ve kapasiteli grup seansları, çakışma kontrolü, iptal ve tarih değişikliği
- Paket hakkı hareket defteri: her düşüm, iade ve manuel düzeltmede sebep, bakiye ve işlemi yapan kişi
- Danışan test cüzdanı ve yalnızca ayarlanan test kartını kabul eden sahte bakiye yükleme akışı
- Sekmeli beta ödeme kayıtları; gerekçeli “ödendi” geri alma, iade, nakit, banka/EFT, manuel kart/POS ve PayPal beta
- Fizyoterapi değerlendirme/seans notları, danışan geçmişi ve paylaşılabilir süreç planı
- Egzersiz kütüphanesi ve danışana özel egzersiz programları
- Denetim kayıtları ve gelecekteki istemciler için `/api/v1` beta API altyapısı

## Klasörler

- `admin/`: ekip paneli giriş noktası
- `app/`: uygulama servisleri ve web ekranları
- `api/v1/`: beta JSON API
- `assets/`: arayüz stilleri ve küçük etkileşimler
- `database/schema.sql`: temiz beta veritabanı kurulumu
- `database/migrations/002_wallets_date_calendars.sql`: mevcut verileri silmeden bu sürüme yükseltme
- `cron/send-mail.php`: e-posta kuyruğu görevi

Kurulum ve mevcut veritabanını yükseltme için [DEPLOYMENT.md](DEPLOYMENT.md) dosyasını izleyin.
