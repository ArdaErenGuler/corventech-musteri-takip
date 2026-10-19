-- =====================================================================
-- Corventech Panel — ÖRNEK VERİ (isteğe bağlı, yalnızca deneme için)
--
-- database.sql'den SONRA içe aktarın. Kurgusal müşteriler, işler, ödemeler,
-- paylaşımlar ve gelir/giderler ekler. CANLI PANELE YÜKLEMEYİN.
-- Tarihler içe aktarma gününe göre hesaplanır.
-- =====================================================================

SET NAMES utf8mb4;
-- ---------------------------------------------------------------------
-- Örnek müşteriler (kurgusal)
-- ---------------------------------------------------------------------

INSERT INTO customers (id, company_name, contact_name, phone, email, sector, city, status, notes, registered_at) VALUES
(1, 'Lavanta Kafe', 'Elif Kaya', '0555 000 00 01', 'elif@example.com', 'Kafe & Restoran', 'İzmir', 'active', 'Hafta sonu paylaşımlarına ağırlık verilmesini istiyor.', CURDATE() - INTERVAL 160 DAY),
(2, 'Yıldız Diş Kliniği', 'Dt. Murat Yıldız', '0555 000 00 02', 'murat@example.com', 'Sağlık', 'Ankara', 'active', NULL, CURDATE() - INTERVAL 210 DAY),
(3, 'Akdeniz İnşaat', 'Hasan Demir', '0555 000 00 03', 'info@example.com', 'İnşaat', 'Antalya', 'active', 'Alan adı ve barındırma yıllık yenileniyor.', CURDATE() - INTERVAL 365 DAY),
(4, 'Mavi Kuaför', 'Zeynep Arslan', '0555 000 00 04', NULL, 'Güzellik & Bakım', 'İstanbul', 'active', 'Ödemeleri genelde ay sonunda yapıyor.', CURDATE() - INTERVAL 80 DAY),
(5, 'Demir Oto Servis', 'Ali Demir', '0555 000 00 05', 'ali@example.com', 'Otomotiv', 'Bursa', 'active', NULL, CURDATE() - INTERVAL 45 DAY),
(6, 'Ege Emlak', 'Selin Aydın', '0555 000 00 06', 'selin@example.com', 'Emlak', 'İzmir', 'active', NULL, CURDATE() - INTERVAL 105 DAY),
(7, 'Kuzey Lojistik', 'Burak Çelik', '0555 000 00 07', 'burak@example.com', 'Lojistik', 'İstanbul', 'active', NULL, CURDATE() - INTERVAL 25 DAY),
(8, 'Atölye Pilates', 'Deniz Koç', '0555 000 00 08', NULL, 'Spor & Sağlık', 'Ankara', 'potential', 'Full paket için teklif bekliyor.', CURDATE() - INTERVAL 6 DAY),
(9, 'Güneş Optik', 'Kemal Şahin', '0555 000 00 09', NULL, 'Perakende', 'Gaziantep', 'passive', 'Sosyal medya hizmetini sonlandırdı.', CURDATE() - INTERVAL 310 DAY);

-- ---------------------------------------------------------------------
-- Örnek siparişler
-- ---------------------------------------------------------------------

INSERT INTO orders (id, customer_id, service_id, title, billing_cycle, price, setup_fee, start_date, first_due_date, end_date, status, notes) VALUES
(1, 1, 1, NULL, 'monthly', 7500, 0, CURDATE() - INTERVAL 150 DAY, CURDATE() - INTERVAL 150 DAY, NULL, 'active', NULL),
(2, 2, 4, NULL, 'monthly', 10000, 12500, CURDATE() - INTERVAL 200 DAY, CURDATE() - INTERVAL 200 DAY, NULL, 'active', NULL),
(3, 3, 2, NULL, 'yearly', 4000, 20000, CURDATE() - INTERVAL 360 DAY, (CURDATE() - INTERVAL 360 DAY) + INTERVAL 1 YEAR, NULL, 'active', 'İlk yıl kurulum ücretine dahil; yenileme yıllık.'),
(4, 4, 1, NULL, 'monthly', 6000, 0, CURDATE() - INTERVAL 75 DAY, CURDATE() - INTERVAL 75 DAY, NULL, 'active', 'Özel fiyat.'),
(5, 5, 3, NULL, 'yearly', 12000, 0, CURDATE() - INTERVAL 40 DAY, CURDATE() - INTERVAL 40 DAY, NULL, 'active', NULL),
(6, 6, 6, NULL, 'monthly', 14000, 15000, CURDATE() - INTERVAL 100 DAY, CURDATE() - INTERVAL 100 DAY, NULL, 'active', NULL),
(7, 7, 5, NULL, 'monthly', 9000, 0, CURDATE() - INTERVAL 20 DAY, CURDATE() - INTERVAL 20 DAY, NULL, 'active', NULL),
(8, 9, 1, NULL, 'monthly', 5000, 0, CURDATE() - INTERVAL 300 DAY, CURDATE() - INTERVAL 300 DAY, CURDATE() - INTERVAL 120 DAY, 'cancelled', NULL),
(9, 1, NULL, 'Web sitesi tasarımı', 'one_time', 18000, 0, CURDATE() - INTERVAL 120 DAY, CURDATE() - INTERVAL 120 DAY, NULL, 'completed', NULL);

-- Kurulum ücretleri
INSERT INTO dues (order_id, customer_id, period_no, due_date, amount)
SELECT id, customer_id, 0, start_date, setup_fee FROM orders
WHERE billing_cycle <> 'one_time' AND setup_fee > 0;

-- Tek seferlik ödemeler
INSERT INTO dues (order_id, customer_id, period_no, due_date, amount)
SELECT id, customer_id, 1, first_due_date, price FROM orders WHERE billing_cycle = 'one_time';

-- Aylık / yıllık dönemler (bugün + 60 güne kadar; sonrasını panel kendisi üretir)
INSERT INTO dues (order_id, customer_id, period_no, due_date, amount)
SELECT o.id, o.customer_id, n.n,
       IF(o.billing_cycle = 'monthly', o.first_due_date + INTERVAL (n.n - 1) MONTH, o.first_due_date + INTERVAL (n.n - 1) YEAR),
       o.price
FROM orders o
JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
      UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12) n
WHERE o.billing_cycle IN ('monthly', 'yearly')
  AND IF(o.billing_cycle = 'monthly', o.first_due_date + INTERVAL (n.n - 1) MONTH, o.first_due_date + INTERVAL (n.n - 1) YEAR) <= CURDATE() + INTERVAL 60 DAY
  AND (o.end_date IS NULL OR IF(o.billing_cycle = 'monthly', o.first_due_date + INTERVAL (n.n - 1) MONTH, o.first_due_date + INTERVAL (n.n - 1) YEAR) <= o.end_date);

-- ---------------------------------------------------------------------
-- Örnek ödemeler
--   Mavi Kuaför: son iki ay ödenmedi · Kuzey Lojistik: ilk ay ödenmedi
--   Ege Emlak: son dönem kısmi ödendi · diğerleri zamanında
-- ---------------------------------------------------------------------

INSERT INTO payments (customer_id, due_id, amount, paid_at, method)
SELECT d.customer_id, d.id, d.amount,
       LEAST(d.due_date + INTERVAL MOD(d.id, 4) DAY, CURDATE()),
       ELT(MOD(d.id, 3) + 1, 'transfer', 'cash', 'card')
FROM dues d
WHERE d.due_date <= CURDATE() - INTERVAL 3 DAY
  AND d.order_id <> 7
  AND NOT (d.order_id = 4 AND d.due_date > CURDATE() - INTERVAL 70 DAY)
  AND NOT (d.order_id = 6 AND d.period_no > 0 AND d.due_date > CURDATE() - INTERVAL 30 DAY);

INSERT INTO payments (customer_id, due_id, amount, paid_at, method, note)
SELECT d.customer_id, d.id, 5000, CURDATE() - INTERVAL 1 DAY, 'transfer', 'Kalanı ay sonunda ödenecek'
FROM dues d
WHERE d.order_id = 6 AND d.period_no > 0 AND d.due_date <= CURDATE() AND d.due_date > CURDATE() - INTERVAL 30 DAY;

-- ---------------------------------------------------------------------
-- Örnek görevler ve notlar
-- ---------------------------------------------------------------------

INSERT INTO tasks (title, description, customer_id, due_date, priority, status, completed_at) VALUES
('Mavi Kuaför ödeme hatırlatması', 'Son iki ayın ödemesi için ara.', 4, CURDATE(), 'high', 'todo', NULL),
('Akdeniz İnşaat alan adı yenilemesi', 'Yıllık yenileme öncesi alan adı ve SSL kontrolü.', 3, CURDATE() + INTERVAL 3 DAY, 'normal', 'todo', NULL),
('Atölye Pilates teklif hazırla', 'Full paket teklifi, indirimli ilk 3 ay.', 8, CURDATE() + INTERVAL 2 DAY, 'normal', 'todo', NULL),
('Ege Emlak aylık içerik planı', 'Yeni projeler için 12 paylaşımlık plan.', 6, CURDATE() + INTERVAL 5 DAY, 'normal', 'doing', NULL),
('Yıldız Diş site yedeği', NULL, 2, CURDATE() - INTERVAL 4 DAY, 'low', 'done', NOW() - INTERVAL 4 DAY);

INSERT INTO customer_notes (customer_id, body, created_at) VALUES
(4, 'Arandı, ay sonunda iki ayı birlikte ödeyeceğini söyledi.', NOW() - INTERVAL 2 DAY),
(8, 'Tanışma toplantısı yapıldı, stüdyo fotoğrafları gönderilecek.', NOW() - INTERVAL 5 DAY);

INSERT INTO posts (customer_id, title, platforms, post_type, scheduled_date, scheduled_time, caption, status, published_at) VALUES
(1, 'Hafta sonu kahvaltı menüsü', 'instagram,facebook', 'carousel', CURDATE(), '10:00', 'Hafta sonu kahvaltısı için yerinizi ayırtın! ☕', 'ready', NULL),
(6, 'Yeni proje tanıtımı', 'instagram', 'reels', CURDATE(), '18:30', NULL, 'planned', NULL),
(2, 'Diş beyazlatma kampanyası', 'instagram,facebook', 'post', CURDATE() + INTERVAL 1 DAY, '12:00', NULL, 'planned', NULL),
(4, 'Saç bakım önerileri', 'instagram,tiktok', 'reels', CURDATE() + INTERVAL 2 DAY, '19:00', NULL, 'planned', NULL),
(1, 'Günün tatlısı', 'instagram', 'story', CURDATE() + INTERVAL 4 DAY, NULL, NULL, 'planned', NULL),
(6, 'Haftanın ilanları', 'instagram,facebook', 'carousel', CURDATE() + INTERVAL 6 DAY, '11:00', NULL, 'planned', NULL),
(7, 'Firma tanıtım videosu', 'linkedin,youtube', 'video', CURDATE() + INTERVAL 9 DAY, '10:00', NULL, 'planned', NULL),
(2, 'Hasta yorumu', 'instagram', 'post', CURDATE() - INTERVAL 1 DAY, '13:00', NULL, 'planned', NULL),
(1, 'Yeni sezon menüsü', 'instagram,facebook', 'carousel', CURDATE() - INTERVAL 3 DAY, '10:00', NULL, 'published', NOW() - INTERVAL 3 DAY),
(4, 'Öncesi / sonrası', 'instagram', 'reels', CURDATE() - INTERVAL 5 DAY, '18:00', NULL, 'published', NOW() - INTERVAL 5 DAY);

-- Örnek gelir/giderler. Düzenli olanların (hosting yıllık, Claude aylık) kayıtlarını panel ilk açılışta oluşturur.
INSERT INTO recurring_transactions (type, category, amount, cycle, start_date, description) VALUES
('expense', 'Hosting', 3600, 'yearly', CURDATE() - INTERVAL 70 DAY, 'Sunucu yenileme'),
('expense', 'Claude / Yazılım', 3400, 'monthly', DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01'), 'Claude aboneliği');

INSERT INTO transactions (type, category, amount, txn_date, description) VALUES
('expense', 'Domain', 450, CURDATE() - INTERVAL 12 DAY, 'corventech.tr yenileme'),
('expense', 'Reklam', 1500, CURDATE() - INTERVAL 20 DAY, 'Instagram reklamı'),
('income', 'Diğer gelir', 2000, CURDATE() - INTERVAL 8 DAY, 'Danışmanlık');
