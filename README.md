# Corventech Panel

Corventech için müşteri, hizmet, ödeme ve yenileme takip paneli. Telefonda uygulama gibi çalışır (PWA), masaüstünde sabit yan menüyle açılır.

**Teknoloji:** PHP 8+ (framework yok, PDO) · MySQL / MariaDB · bağımlılıksız CSS/JS. Node veya Docker gerektirmez; standart paylaşımlı hostinge yüklenebilir.

## Özellikler

- **Dashboard:** aktif müşteri, bu ay tahsilat, gecikmiş ödemeler, yaklaşan ödemeler, açık görevler
- **Müşteriler:** arama, durum/sektör/borçlu filtresi, tek dokunuşla arama ve WhatsApp; detay sayfasında hizmetler, ödeme planı, ödeme geçmişi, kalan borç, görevler ve görüşme notları
- **Sosyal medya takvimi:** aylık takvim; güne dokunup müşteri eklenir, paylaşınca işaretlenir; paylaşım günü gelince panelde şerit + bildirim
- **Siparişler:** müşteriye satılan hizmet; hizmet adı ve fiyat her müşteri için **elle girilir**. **Her hizmet ayrı kayıttır** (Web Sitesi, İşletme Paneli, Sosyal Medya ayrı eklenir, `+` ile birleştirilemez); bir müşterinin birden çok hizmeti olabilir ve ödemeler hizmet bazında takip edilir, fazla ödeme başka hizmetin borcuna geçmez; **taksitli / tek seferlik / aylık / yıllık** ödeme, ilk ödeme tarihi, kurulum ücreti, bitiş tarihi
- **Ödemeler:** tahsilat kaydı; hangi dönem için alındığı ve kalan tutar otomatik gelir
- **Yenilemeler:** gecikmiş, bugün, bu hafta ve sonraki 30 günün ödemeleri; WhatsApp hatırlatma mesajı; yıllık yenileme takvimi
- **Görevler:** müşteriye bağlı görevler, sorumlu kişi, son tarih, öncelik
- **Gelir / Gider:** hosting, domain, Claude gibi giderler ve diğer gelirler; tek sefer, her ay veya her yıl tekrar (düzenli kayıtlar kendiliğinden yazılır)
- **Raporlar:** yıllık toplam ve aylık tahsilat grafiği; şirket kasası (tahsilat + diğer gelir − gider = net) ay ay ya da tüm yıl; düzenli gelir, tahsilat oranı, hizmet bazında tahsilat, alacaklar
- **Yedekleme:** tek butonla ZIP (veritabanı .sql + Excel'de açılan tablolar); 7 gün yedek alınmazsa panelde ve menüde hatırlatma
- **Ayarlar:** profil ve şifre, ortak kullanıcılar (yönetici / kullanıcı), WhatsApp mesaj şablonu, açık/koyu tema

## Ödeme planı nasıl çalışır?

Yeni müşteri eklerken (ya da sonradan müşteri sayfasından) hizmet, ödeme tipi, tutar ve **ilk ödeme tarihi** girilir. Panel sonraki ödeme tarihlerini kendisi hesaplar:

- **Taksitli:** toplam tutar + taksit sayısı girilir (10.500 ₺ ÷ 3 = 3 × 3.500 ₺); taksitler ilk taksit tarihinden itibaren her ay aynı güne yazılır, kuruş farkı son taksitte kapanır. Ertesi yıl işteki **Yeni dönem için yenile** butonuyla yeni fiyat ve taksit sayısıyla yeni iş açılır; önceki iş tamamlandı olur, kalan taksitleri borç olarak durur. **Yenilemeler** sayfasındaki yenileme takvimi taksitli işlerin yıl dönümünü de gösterir.
- **Aylık:** her ay aynı gün (31'i olmayan aylarda ayın son günü)
- **Yıllık:** her yıl aynı gün
- **Kurulum ücreti** varsa başlangıç tarihinde bir kez eklenir

Dönemler, tarihi yaklaştıkça (varsayılan 60 gün önceden) otomatik oluşur; cron gerekmez. **Kalan borç** = vadesi gelmiş dönemler − alınan ödemeler. Sipariş durdurulur veya iptal edilirse ileri tarihli ödenmemiş dönemler kaldırılır. Devam ettirilirse aradaki aylar borç yazılmaz. Tek bir dönem gerekirse sipariş sayfasından iptal edilebilir.

## Kurulum

> **Vercel'e kurulum:** adım adım rehber için [VERCEL-KURULUM.md](VERCEL-KURULUM.md).

### 1. Paylaşımlı hosting (cPanel vb.)

1. Hosting panelinden bir **MySQL veritabanı** ve kullanıcısı oluşturun, karakter seti `utf8mb4` olsun.
2. phpMyAdmin'de veritabanını seçip **İçe Aktar** ile `database.sql` dosyasını yükleyin (yalnızca tablolar, müşteri yok).
   > Bu dosya tabloları silip yeniden oluşturur; yalnızca ilk kurulumda kullanın. `ornek-veri.sql` yalnızca deneme içindir, **canlıya yüklemeyin**.
3. Tüm dosyaları FTP / Dosya Yöneticisi ile `public_html` içine yükleyin (veya `public_html/panel` gibi bir alt klasöre).
4. Aynı klasörde `config.local.php` oluşturup veritabanı bilgilerini ve bir **kurulum anahtarı** yazın (aşağıdaki örnek).
5. Siteyi tarayıcıda açın. İlk açılışta **yönetici hesabı oluşturma** ekranı gelir; kurulum anahtarını sorar.
6. Ortaklarınızı **Ayarlar → Kullanıcılar** bölümünden ekleyin.

> PWA (ana ekrana ekleme) için site **HTTPS** üzerinden açılmalıdır. Çoğu hosting ücretsiz SSL (Let's Encrypt) sunar.

### 2. Bilgisayarda (XAMPP)

```bash
# MySQL'i XAMPP kontrol panelinden başlatın, sonra:
C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE corventech_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci"
C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 corventech_panel < database.sql
C:\xampp\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 corventech_panel < ornek-veri.sql
C:\xampp\php\php.exe -S localhost:8090
```

Ardından tarayıcıda `http://localhost:8090` adresini açın.

### Güncelleme (panel zaten kuruluysa)

Yeni dosyaları yüklemeniz yeterli. Panel ilk açıldığında `migrations/` klasöründeki uygulanmamış veritabanı güncellemelerini **kendiliğinden** uygular (uygulanan sürüm `settings.db_version`'da tutulur). Yine de büyük güncellemelerden önce phpMyAdmin'den yedek (Dışa Aktar) almanız önerilir.

- `001_sahip_ve_takvim.sql` — müşteri sahibi (Arda / Melih / Ortak), fiyatsız paketler, sosyal medya takvimi
- `002_gelir_gider.sql` — gelir/gider kaydı
- `003_is_bazinda_pay.sql` — iş bazında pay seçimi, gelir/giderde kasa, düzenli (aylık/yıllık) gelir/giderler
- `004_oturumlar.sql` — oturumlar veritabanında (30 gün; telefonda tekrar tekrar giriş istemez)
- `005_ortak_kasa.sql` — müşteri sahibi, iş payı ve kasa seçimi kaldırıldı (her şey ortak; şirket kasası)
- `006_taksit.sql` — taksitli ödeme (toplam fiyat + taksit sayısı)
- `007_paket_odeme_tipleri.sql` — (008 ile geçersiz kılındı)
- `008_paketler_kaldirildi.sql` — paketler kaldırıldı; hizmet adı her işte elle yazılır (eski paket adları işlere aktarılır)

Yeni bir güncelleme eklerken: `migrations/005_....sql` oluşturun, `includes/migrate.php` içindeki `DB_VERSION`'u artırın ve `database.sql`'deki `db_version` değerini güncelleyin.

### Testler

Hesaplama kurallarını (ay sonu tarihleri, Türkçe tutar okuma, pay oranları…) değiştirdikten sonra:

```bash
php tests/hesaplama-testleri.php
```

Hepsi `OK` olmalı.

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
    'setup_key' => 'uzun-rastgele-bir-anahtar',   // ilk yönetici hesabı için
];
```

Vercel gibi ortamlarda aynı ayarlar **ortam değişkenleriyle** verilir: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SSL` (bulut MySQL için `true`), `SETUP_KEY`, `TRUST_PROXY` (vekil sunucu arkasında `true`).

## Yedekleme

Menüdeki **Yedekleme** sayfasından **Yedek al**'a basın; `corventech-yedek-(tarih).zip` iner:

- `veritabani-(tarih).sql` — asıl yedek. Boş bir veritabanına phpMyAdmin → İçe Aktar ile yüklenince panel aynen geri gelir; başka bir hostinge taşırken de bu dosya kullanılır.
- `excel/` — müşteriler, işler, ödemeler, gelir/gider ve takvim Excel'de açılabilir tablolar olarak (okumak için).

Son yedekten 7 gün geçince her sayfada ve menüde hatırlatma çıkar. Dosyayı sunucudan ayrı bir yerde (Google Drive vb.) saklayın; içinde müşteri bilgileri vardır.

## Güvenlik

- Tüm sorgular PDO prepared statement ile çalışır; tüm formlarda CSRF koruması vardır.
- Şifreler `password_hash` ile saklanır. 5 hatalı girişten sonra 15 dakika beklenir.
- İlk yönetici hesabı kurulum anahtarı (`setup_key` / `SETUP_KEY`) ile korunur.
- Oturumlar veritabanında, 30 gün geçerli; her gün kullanıldıkça uzar.
- `.htaccess`; `config.php`, `.sql` dosyaları, `includes/`, `migrations/` ve `tests/` klasörlerine dışarıdan erişimi engeller.
- Service worker müşteri verisi içeren sayfaları önbelleğe almaz; yalnızca CSS, ikon ve çevrimdışı sayfasını saklar.

## Klasör yapısı

```
├── *.php               Sayfalar (dashboard, musteriler, siparisler, odemeler, yenilemeler…)
├── api.php             Formların kullandığı küçük JSON uç noktası
├── includes/           Ortak kod: bootstrap, veritabanı, oturum, ödeme planı, şablon
├── assets/css|js|icons Tasarım, arayüz davranışları, PWA ikonları
├── manifest.json, sw.js, offline.html   PWA dosyaları
├── migrations/         Kurulu panel için veritabanı güncellemeleri
├── tests/              Hesaplama testleri (php tests/hesaplama-testleri.php)
├── config.php          Ayarlar
├── database.sql        Tablolar (canlı kurulum)
└── ornek-veri.sql      Deneme verisi (canlıya yüklemeyin)
```
