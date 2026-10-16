# Vercel'e kurulum

Panel PHP + MySQL ile çalışır. Vercel'de PHP topluluk eklentisiyle (`vercel-php`) çalışır; MySQL'i Vercel vermediği için ayrı bir bulut veritabanı gerekir. Gerekli ayarlar repoda hazır: `vercel.json`, `api/index.php`, `.vercelignore`.

> **Not:** Vercel'in ücretsiz Hobby planı kurallara göre yalnızca kişisel / ticari olmayan projeler içindir. Şirket paneli bu kapsama girmeyebilir; uzun vadede Pro plan ya da PHP hosting düşünün. Taşıma için: panel → **Yedekleme** → yedek ZIP'teki `.sql` dosyası yeni yere içe aktarılır.

## 1. Veritabanı (Aiven — ücretsiz MySQL)

1. [aiven.io](https://aiven.io) → hesap aç → **Create service** → **MySQL** → **Free plan**.
2. Bölge olarak Avrupa'da (mümkünse Frankfurt'a yakın) bir yer seçin; Vercel fonksiyonu `fra1` (Frankfurt) bölgesinde çalışır, uzak bölge sayfaları yavaşlatır.
3. Servis açılınca **Overview / Connection information** bölümünden şunları alın:
   - Host, Port, User (`avnadmin`), Password, Database (`defaultdb`)
   - **CA certificate** → "Show" / indir (`ca.pem`)

Tabloları elle kurmanıza gerek yok: panel ilk açıldığında veritabanı boşsa `database.sql`'i kendisi uygular (müşteri verisi olmadan, yalnızca tablolar + 6 paket).

## 2. Vercel projesi

1. Vercel → **Add New… → Project** → GitHub'dan `corventech-musteri-takip` reposunu seçin.
2. **Framework Preset:** *Other*. Build / Output ayarlarına dokunmayın.
3. **Environment Variables** (Production için):

| Değişken | Değer |
|---|---|
| `DB_HOST` | Aiven host |
| `DB_PORT` | Aiven port |
| `DB_NAME` | `defaultdb` |
| `DB_USER` | `avnadmin` |
| `DB_PASS` | Aiven şifresi |
| `DB_SSL` | `true` |
| `DB_SSL_CA` | `ca.pem` dosyasının **tüm içeriği** (`-----BEGIN CERTIFICATE-----` dahil) |
| `SETUP_KEY` | Uzun, rastgele bir anahtar (ilk yönetici hesabı için; kimseyle paylaşmayın) |
| `TRUST_PROXY` | `true` |

4. **Deploy**.

## 3. İlk açılış

1. Vercel'in verdiği adresi açın → **Yönetici hesabı oluştur** ekranı gelir.
2. `SETUP_KEY` değerini, adınızı, e-postanızı ve şifrenizi girin.
3. **Ayarlar → Kullanıcılar**'dan ortağınızın hesabını açın.
4. İsterseniz Vercel → **Settings → Domains**'ten `panel.corventech.tr` ekleyin; Vercel'in gösterdiği DNS kaydını alan adınızın paneline girin.

## 4. Sonrası

- **Haftalık yedek:** menüde **Yedekleme** → *Yedek al*. 7 gün geçince panel hatırlatır. ZIP'i Google Drive gibi bir yerde saklayın.
- **Güncelleme:** GitHub'a gönderilen her değişiklik Vercel'de otomatik yayına girer. Veritabanı güncellemeleri (`migrations/`) panel ilk açıldığında kendiliğinden uygulanır.
- **Aiven ücretsiz servis** uzun süre kullanılmazsa durdurulabilir (önceden e-posta gelir; konsoldan tekrar açılır). Panel düzenli kullanıldıkça sorun olmaz.

## Sorun giderme

| Belirti | Neden / çözüm |
|---|---|
| "Veritabanına bağlanılamadı" | `DB_*` değişkenlerini, `DB_SSL=true` ve `DB_SSL_CA` içeriğini kontrol edin. Değişken değiştirdikten sonra **Redeploy** gerekir. |
| Sayfalar çok yavaş | Aiven bölgesi Vercel bölgesinden (`fra1`) uzak; `vercel.json` → `regions` değerini veritabanına yakın bölgeyle değiştirin. |
| 404 / PHP kodu görünüyor | `vercel.json` repoda olmalı; Framework Preset *Other* olmalı. |
| Kurulum ekranı "anahtar hatalı" | `SETUP_KEY` değişkeninin değeri ile girilen aynı olmalı (boşluk olmadan). |

Hata ayrıntısı için geçici olarak `config.php` → `debug` `true` yapılabilir; işiniz bitince geri alın.
