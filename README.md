# Corventech Panel

Corventech için müşteri, hizmet, ödeme ve yenileme takip paneli. Telefonda uygulama gibi çalışır (PWA), masaüstünde sabit yan menüyle açılır.

**Teknoloji:** PHP 8+ (framework yok, PDO) · MySQL / MariaDB · bağımlılıksız CSS/JS. Node veya Docker gerektirmez; standart paylaşımlı hostinge yüklenebilir.

## Özellikler

- **Dashboard:** aktif müşteri, bu ay tahsilat, gecikmiş ödemeler, yaklaşan ödemeler, açık görevler
- **Müşteriler:** Arda / Melih / Ortak ayrımı, arama, durum/sektör/borçlu filtresi, tek dokunuşla arama ve WhatsApp; detay sayfasında hizmetler, ödeme planı, ödeme geçmişi, kalan borç, görevler ve görüşme notları
- **Sosyal medya takvimi:** aylık takvim; güne dokunup müşteri eklenir, paylaşınca işaretlenir; paylaşım günü gelince panelde şerit + bildirim
- **Siparişler:** müşteriye satılan hizmet (6 paket adından biri veya özel hizmet); fiyat her müşteri için **elle girilir**; **tek seferlik / aylık / yıllık** ödeme, ilk ödeme tarihi, kurulum ücreti, bitiş tarihi
- **Ödemeler:** tahsilat kaydı; hangi dönem için alındığı ve kalan tutar otomatik gelir
- **Yenilemeler:** gecikmiş, bugün, bu hafta ve sonraki 30 günün ödemeleri; WhatsApp hatırlatma mesajı; yıllık yenileme takvimi
- **Görevler:** müşteriye bağlı görevler, sorumlu kişi, son tarih, öncelik
- **Raporlar:** aylık tahsilat grafiği, hizmet bazında gelir, aylık düzenli gelir, tahsilat oranı, en çok borcu olanlar
- **Ayarlar:** profil ve şifre, ortak kullanıcılar (yönetici / kullanıcı), WhatsApp mesaj şablonu, açık/koyu tema

## Ödeme planı nasıl çalışır?

Yeni müşteri eklerken (ya da sonradan müşteri sayfasından) hizmet, ödeme tipi, tutar ve **ilk ödeme tarihi** girilir. Panel sonraki ödeme tarihlerini kendisi hesaplar:

- **Aylık:** her ay aynı gün (31'i olmayan aylarda ayın son günü)
- **Yıllık:** her yıl aynı gün
- **Kurulum ücreti** varsa başlangıç tarihinde bir kez eklenir

Dönemler, tarihi yaklaştıkça (varsayılan 60 gün önceden) otomatik oluşur; cron gerekmez. **Kalan borç** = vadesi gelmiş dönemler − alınan ödemeler. Sipariş durdurulur veya iptal edilirse ileri tarihli ödenmemiş dönemler kaldırılır. Devam ettirilirse aradaki aylar borç yazılmaz. Tek bir dönem gerekirse sipariş sayfasından iptal edilebilir.

## Kurulum

### 1. Paylaşımlı hosting (cPanel vb.)

1. Hosting panelinden bir **MySQL veritabanı** ve kullanıcısı oluşturun, karakter seti `utf8mb4` olsun.
2. phpMyAdmin'de veritabanını seçip **İçe Aktar** ile `database.sql` dosyasını yükleyin.
   > Bu dosya tabloları silip yeniden oluşturur; yalnızca ilk kurulumda kullanın.
3. Tüm dosyaları FTP / Dosya Yöneticisi ile `public_html` içine yükleyin (veya `public_html/panel` gibi bir alt klasöre).
4. `config.php` içinde `db_name`, `db_user`, `db_pass` alanlarını kendi bilgilerinizle değiştirin.
5. Siteyi tarayıcıda açın. İlk açılışta **yönetici hesabı oluşturma** ekranı gelir. Kurulumdan hemen sonra bu adımı tamamlayın.
6. Ortaklarınızı **Ayarlar → Kullanıcılar** bölümünden ekleyin.

> PWA (ana ekrana ekleme) için site **HTTPS** üzerinden açılmalıdır. Çoğu hosting ücretsiz SSL (Let's Encrypt) sunar.

### 2. Bilgisayarda (XAMPP)

```bash
# MySQL'i XAMPP kontrol panelinden başlatın, sonra:
C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE corventech_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci"
C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 corventech_panel < database.sql
C:\xampp\php\php.exe -S localhost:8090
```

Ardından tarayıcıda `http://localhost:8090` adresini açın.

### Güncelleme (panel zaten kuruluysa)

Verileriniz dururken yeni sürüme geçmek için dosyaları yükleyin, ardından phpMyAdmin'de `migrations/` klasöründeki henüz uygulanmamış dosyaları sırayla içe aktarın:

- `001_sahip_ve_takvim.sql` — müşteri sahibi (Arda / Melih / Ortak), fiyatsız paketler, sosyal medya takvimi

### Bildirimler hakkında

Paylaşım günü geldiğinde panel açıldığında üstte şerit çıkar ve menüde sayaç görünür. Takvim sayfasındaki **Bildirimleri aç** ile izin verilirse uygulama açıldığında günde bir kez telefon/bilgisayar bildirimi de gösterilir. Uygulama hiç açılmadan bildirim gelmesi için sunucu taraflı Web Push (ve hostingte cron) gerekir; bu ayrı bir adım olarak eklenebilir.

### Ayarları git dışında tutmak

Canlı sunucunun şifrelerini repoya göndermemek için `config.php` yerine aynı klasörde bir `config.local.php` oluşturabilirsiniz (git bu dosyayı yok sayar):

```php
<?php
return [
    'db_name' => 'kullanici_corventech',
    'db_user' => 'kullanici_panel',
    'db_pass' => '********',
];
```

## Güvenlik

- Tüm sorgular PDO prepared statement ile çalışır; tüm formlarda CSRF koruması vardır.
- Şifreler `password_hash` ile saklanır. 5 hatalı girişten sonra 15 dakika beklenir.
- `.htaccess`, `config.php`, `database.sql` ve `includes/` klasörüne dışarıdan erişimi engeller.
- Service worker müşteri verisi içeren sayfaları önbelleğe almaz; yalnızca CSS, ikon ve çevrimdışı sayfasını saklar.

## Klasör yapısı

```
├── *.php               Sayfalar (dashboard, musteriler, siparisler, odemeler, yenilemeler…)
├── api.php             Formların kullandığı küçük JSON uç noktası
├── includes/           Ortak kod: bootstrap, veritabanı, oturum, ödeme planı, şablon
├── assets/css|js|icons Tasarım, arayüz davranışları, PWA ikonları
├── manifest.json, sw.js, offline.html   PWA dosyaları
├── migrations/         Kurulu panel için veritabanı güncellemeleri
├── config.php          Ayarlar
└── database.sql        Tablolar + örnek veri
```
