# FizyoRez Beta

FizyoRez, yalnızca PHP 8 ve MySQL/MariaDB ile çalışan fizyoterapi işletmesi yönetim uygulamasıdır. Doktor, teşhis veya görüntülü görüşme modülü içermez.

## Hazır modüller

- `/admin` altında profesyonel ekip paneli; `/customer` altında danışan portalı
- Danışanın kendi üyeliğini oluşturması; personel hesaplarının yetkili ekip tarafından açılması
- Kullanıcı bilgisi, durum, rol ve şifre yönetimi
- Kullanıcı bazlı değil rol bazlı yetki matrisi (`super_admin`, `admin`, `staff`, `consultant`, `customer`)
- Her fizyoterapist için tarih bazlı otomatik hafta içi 08:00–12:00 ve 13:00–18:00 takvimi; günlük saat düzenleme
- Birebir ve kapasiteli grup seansları, 7 günlük planlama matrisi, çakışma kontrolü ve rol bazlı tarih değişikliği onayı
- Tamamen bakiye tabanlı satış: danışan onaylanmış bakiyesiyle paket/hak veya tek seans satın alır
- Yükleme, kesinti, paket, tek seans, düzeltme ve iadeleri birleştiren tek finans hareket defteri
- Kartın yalnızca marka/son dört hanesini saklayan beta yükleme; tüm yükleme ve düşümler rol bazlı onaya tabi
- Süper yöneticinin kendi kaydını onaylayabildiği, diğer roller için işlemi oluşturan ile onaylayanı ayıran kontrol
- Nakit, banka/EFT, manuel kart/POS ve PayPal beta tahsilatlarını danışan bakiyesine aktaran onay akışı
- Fizyoterapi değerlendirme/seans notları, danışan geçmişi ve paylaşılabilir süreç planı
- Egzersiz kütüphanesi ve danışana özel egzersiz programları
- Denetim kayıtları ve gelecekteki istemciler için `/api/v1` beta API altyapısı

## Klasörler

- `admin/`: ekip paneli giriş noktası
- `app/`: uygulama servisleri ve web ekranları
- `api/v1/`: beta JSON API
- `assets/`: arayüz stilleri ve küçük etkileşimler
- `database/schema.sql`: temiz beta veritabanı kurulumu
- `database/migrations/`: mevcut verileri silmeden, numara sırasıyla çalıştırılan yükseltmeler
- `cron/send-mail.php`: e-posta kuyruğu görevi

Kurulum ve mevcut veritabanını yükseltme için [DEPLOYMENT.md](DEPLOYMENT.md) dosyasını izleyin.
