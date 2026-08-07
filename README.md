# FizyoRez Beta

FizyoRez, yalnızca PHP 8 ve MySQL/MariaDB ile çalışan fizyoterapi işletmesi yönetim uygulamasıdır. Doktor, teşhis veya görüntülü görüşme modülü içermez.

## Hazır modüller

- `/admin` altında profesyonel ekip paneli; `/customer` altında danışan portalı
- Danışanın kendi üyeliğini oluşturması; personel hesaplarının yetkili ekip tarafından açılması
- Kullanıcı bilgisi, durum, rol ve şifre yönetimi
- Kullanıcı bazlı değil rol bazlı yetki matrisi (`super_admin`, `admin`, `staff`, `consultant`, `customer`)
- Fizyoterapistin kendi çalışma günleri/izinleri; yetkili resepsiyonun tüm programları yönetmesi
- Birebir ve kapasiteli grup seansları, çakışma kontrolü, iptal ve tarih değişikliği
- Paket hakkı hareket defteri: her düşüm, iade ve manuel düzeltmede sebep, bakiye ve işlemi yapan kişi
- Beta ödeme kayıtları: nakit, banka/EFT, manuel kart/POS, PayPal beta ve diğer
- Fizyoterapi değerlendirme/seans notları, danışan geçmişi ve paylaşılabilir süreç planı
- Egzersiz kütüphanesi ve danışana özel egzersiz programları
- Denetim kayıtları ve gelecekteki istemciler için `/api/v1` beta API altyapısı

## Klasörler

- `admin/`: ekip paneli giriş noktası
- `app/`: uygulama servisleri ve web ekranları
- `api/v1/`: beta JSON API
- `assets/`: arayüz stilleri ve küçük etkileşimler
- `database/schema.sql`: temiz beta veritabanı kurulumu
- `cron/send-mail.php`: e-posta kuyruğu görevi

Kurulum için [DEPLOYMENT.md](DEPLOYMENT.md) dosyasını izleyin. Beta verileri önemli değilse eski tabloları temizleyip şemayı yeniden kurmak en güvenli güncelleme yoludur.
