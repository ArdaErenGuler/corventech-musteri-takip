-- =====================================================================
-- Corventech Panel — veritabanı kurulumu (tablolar + örnek veri)
--
-- DİKKAT: Bu dosya mevcut tabloları SİLİP yeniden oluşturur.
-- Yalnızca ilk kurulumda içe aktarın; canlıdaki verinin üzerine yüklemeyin.
--
-- Örnek verideki tarihler içe aktarma gününe göre hesaplanır.
-- Kullanıcı hesabı yoktur: siteyi ilk açtığınızda yönetici hesabı oluşturma ekranı gelir.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS transactions, posts, payments, dues, orders, tasks, customer_notes, customers, services, login_attempts, settings, users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Tablolar
-- ---------------------------------------------------------------------

CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','user') NOT NULL DEFAULT 'user',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE settings (
    `key`   VARCHAR(64) PRIMARY KEY,
    `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE customers (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name  VARCHAR(150) NULL,
    contact_name  VARCHAR(150) NOT NULL DEFAULT '',
    phone         VARCHAR(30)  NULL,
    email         VARCHAR(190) NULL,
    sector        VARCHAR(100) NULL,
    city          VARCHAR(60)  NULL,
    status        ENUM('active','potential','passive') NOT NULL DEFAULT 'active',
    owner         ENUM('arda','melih','ortak') NOT NULL DEFAULT 'ortak',
    notes         TEXT NULL,
    registered_at DATE NOT NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_city (city),
    INDEX idx_owner (owner),
    CONSTRAINT fk_customers_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE customer_notes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NULL,
    body        TEXT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    CONSTRAINT fk_notes_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Paket adları (fiyat tutulmaz; fiyat her müşteri için elle girilir)
CREATE TABLE services (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(120) NOT NULL,
    kind              ENUM('single','combo') NOT NULL DEFAULT 'single',
    description       TEXT NULL,
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    sort_order        INT NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Siparişler: müşteriye satılan hizmet ve ödeme planı
CREATE TABLE orders (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id    INT UNSIGNED NOT NULL,
    service_id     INT UNSIGNED NULL,
    title          VARCHAR(150) NULL,
    billing_cycle  ENUM('one_time','monthly','yearly') NOT NULL,
    price          DECIMAL(12,2) NOT NULL,
    setup_fee      DECIMAL(12,2) NOT NULL DEFAULT 0,
    start_date     DATE NOT NULL,
    first_due_date DATE NOT NULL,
    end_date       DATE NULL,
    sync_from      DATE NULL COMMENT 'Bu tarihten önceki dönemler otomatik üretilmez (durdurulup devam edilen siparişler için)',
    status         ENUM('active','paused','cancelled','completed') NOT NULL DEFAULT 'active',
    notes          TEXT NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_status (status),
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Beklenen ödemeler (dönemler): period_no 0 = kurulum ücreti, 1+ = dönemler
CREATE TABLE dues (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    period_no   INT NOT NULL,
    due_date    DATE NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    status      ENUM('open','waived') NOT NULL DEFAULT 'open',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order_period (order_id, period_no),
    INDEX idx_due_date (due_date),
    INDEX idx_customer (customer_id),
    CONSTRAINT fk_dues_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_dues_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Alınan ödemeler (tahsilatlar)
CREATE TABLE payments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    due_id      INT UNSIGNED NULL,
    amount      DECIMAL(12,2) NOT NULL,
    paid_at     DATE NOT NULL,
    method      ENUM('transfer','cash','card','other') NOT NULL DEFAULT 'transfer',
    note        VARCHAR(255) NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer (customer_id),
    INDEX idx_paid_at (paid_at),
    INDEX idx_due (due_id),
    CONSTRAINT fk_payments_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_due FOREIGN KEY (due_id) REFERENCES dues(id) ON DELETE SET NULL,
    CONSTRAINT fk_payments_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE tasks (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(200) NOT NULL,
    description  TEXT NULL,
    customer_id  INT UNSIGNED NULL,
    assigned_to  INT UNSIGNED NULL,
    due_date     DATE NULL,
    priority     ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
    status       ENUM('todo','doing','done') NOT NULL DEFAULT 'todo',
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_status (status),
    INDEX idx_due (due_date),
    CONSTRAINT fk_tasks_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tasks_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Sosyal medya paylaşım takvimi
CREATE TABLE posts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id    INT UNSIGNED NULL,
    title          VARCHAR(200) NOT NULL,
    platforms      SET('instagram','facebook','tiktok','linkedin','youtube','x') NOT NULL DEFAULT 'instagram',
    post_type      ENUM('post','reels','story','carousel','video') NOT NULL DEFAULT 'post',
    scheduled_date DATE NOT NULL,
    scheduled_time TIME NULL,
    caption        TEXT NULL,
    notes          TEXT NULL,
    status         ENUM('planned','ready','published') NOT NULL DEFAULT 'planned',
    assigned_to    INT UNSIGNED NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at   DATETIME NULL,
    INDEX idx_date (scheduled_date),
    INDEX idx_status (status),
    CONSTRAINT fk_posts_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_posts_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_posts_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Şirket kasası hareketleri: hosting, domain, Claude gibi giderler ve müşteri dışı gelirler
CREATE TABLE transactions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('income','expense') NOT NULL,
    category    VARCHAR(60) NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    txn_date    DATE NOT NULL,
    description VARCHAR(255) NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (txn_date),
    CONSTRAINT fk_txn_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ---------------------------------------------------------------------
-- Ayarlar
-- ---------------------------------------------------------------------

INSERT INTO settings (`key`, `value`) VALUES
('company_name', 'Corventech'),
('upcoming_days', '30'),
('company_share', '1/3'),
('wa_template', 'Merhaba {musteri}, {hizmet} hizmetinizin {tarih} tarihli {tutar} tutarındaki ödemesini hatırlatmak isteriz. İyi çalışmalar, Corventech');

-- ---------------------------------------------------------------------
-- Paketler (6 adet) — fiyat yok, her müşteride elle girilir
-- ---------------------------------------------------------------------

INSERT INTO services (id, name, kind, description, sort_order) VALUES
(1, 'Sosyal Medya', 'single', 'Hesap yönetimi, içerik planı ve paylaşımlar.', 1),
(2, 'Kurumsal Web Sitesi', 'single', 'Site tasarımı, kurulum, barındırma ve bakım.', 2),
(3, 'İşletme Paneli', 'single', 'İşletmeye özel yönetim paneli.', 3),
(4, 'Web Sitesi + Sosyal Medya', 'combo', NULL, 4),
(5, 'İşletme Paneli + Sosyal Medya', 'combo', NULL, 5),
(6, 'Full Paket (Web + Panel + Sosyal Medya)', 'combo', NULL, 6);

-- ---------------------------------------------------------------------
-- Örnek müşteriler (kurgusal)
-- ---------------------------------------------------------------------

INSERT INTO customers (id, company_name, contact_name, phone, email, sector, city, status, owner, notes, registered_at) VALUES
(1, 'Lavanta Kafe', 'Elif Kaya', '0555 000 00 01', 'elif@example.com', 'Kafe & Restoran', 'İzmir', 'active', 'arda', 'Hafta sonu paylaşımlarına ağırlık verilmesini istiyor.', CURDATE() - INTERVAL 160 DAY),
(2, 'Yıldız Diş Kliniği', 'Dt. Murat Yıldız', '0555 000 00 02', 'murat@example.com', 'Sağlık', 'Ankara', 'active', 'melih', NULL, CURDATE() - INTERVAL 210 DAY),
(3, 'Akdeniz İnşaat', 'Hasan Demir', '0555 000 00 03', 'info@example.com', 'İnşaat', 'Antalya', 'active', 'ortak', 'Alan adı ve barındırma yıllık yenileniyor.', CURDATE() - INTERVAL 365 DAY),
(4, 'Mavi Kuaför', 'Zeynep Arslan', '0555 000 00 04', NULL, 'Güzellik & Bakım', 'İstanbul', 'active', 'arda', 'Ödemeleri genelde ay sonunda yapıyor.', CURDATE() - INTERVAL 80 DAY),
(5, 'Demir Oto Servis', 'Ali Demir', '0555 000 00 05', 'ali@example.com', 'Otomotiv', 'Bursa', 'active', 'melih', NULL, CURDATE() - INTERVAL 45 DAY),
(6, 'Ege Emlak', 'Selin Aydın', '0555 000 00 06', 'selin@example.com', 'Emlak', 'İzmir', 'active', 'ortak', NULL, CURDATE() - INTERVAL 105 DAY),
(7, 'Kuzey Lojistik', 'Burak Çelik', '0555 000 00 07', 'burak@example.com', 'Lojistik', 'İstanbul', 'active', 'arda', NULL, CURDATE() - INTERVAL 25 DAY),
(8, 'Atölye Pilates', 'Deniz Koç', '0555 000 00 08', NULL, 'Spor & Sağlık', 'Ankara', 'potential', 'melih', 'Full paket için teklif bekliyor.', CURDATE() - INTERVAL 6 DAY),
(9, 'Güneş Optik', 'Kemal Şahin', '0555 000 00 09', NULL, 'Perakende', 'Gaziantep', 'passive', 'ortak', 'Sosyal medya hizmetini sonlandırdı.', CURDATE() - INTERVAL 310 DAY);

-- ---------------------------------------------------------------------
-- Örnek siparişler
-- ---------------------------------------------------------------------

INSERT INTO orders (id, customer_id, service_id, title, billing_cycle, price, setup_fee, start_date, first_due_date, end_date, status, notes) VALUES
(1, 1, 1, NULL, 'monthly', 7500, 0, CURDATE() - INTERVAL 150 DAY, CURDATE() - INTERVAL 150 DAY, NULL, 'active', NULL),
(2, 2, 4, NULL, 'monthly', 10000, 12500, CURDATE() - INTERVAL 200 DAY, CURDATE() - INTERVAL 200 DAY, NULL, 'active', NULL),
(3, 3, 2, NULL, 'yearly', 4000, 20000, CURDATE() - INTERVAL 360 DAY, (CURDATE() - INTERVAL 360 DAY) + INTERVAL 1 YEAR, NULL, 'active', 'İlk yıl kurulum ücretine dahil; yenileme yıllık.'),
(4, 4, 1, NULL, 'monthly', 6000, 0, CURDATE() - INTERVAL 75 DAY, CURDATE() - INTERVAL 75 DAY, NULL, 'active', 'Özel fiyat.'),
(5, 5, 3, NULL, 'monthly', 2500, 10000, CURDATE() - INTERVAL 40 DAY, CURDATE() - INTERVAL 40 DAY, NULL, 'active', NULL),
(6, 6, 6, NULL, 'monthly', 14000, 15000, CURDATE() - INTERVAL 100 DAY, CURDATE() - INTERVAL 100 DAY, NULL, 'active', NULL),
(7, 7, 5, NULL, 'monthly', 9000, 0, CURDATE() - INTERVAL 20 DAY, CURDATE() - INTERVAL 20 DAY, NULL, 'active', NULL),
(8, 9, 1, NULL, 'monthly', 5000, 0, CURDATE() - INTERVAL 300 DAY, CURDATE() - INTERVAL 300 DAY, CURDATE() - INTERVAL 120 DAY, 'cancelled', NULL),
(9, 1, 2, 'Web sitesi tasarımı', 'one_time', 18000, 0, CURDATE() - INTERVAL 120 DAY, CURDATE() - INTERVAL 120 DAY, NULL, 'completed', NULL);

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

-- Örnek şirket gelir/giderleri
INSERT INTO transactions (type, category, amount, txn_date, description) VALUES
('expense', 'Hosting', 3600, CURDATE() - INTERVAL 70 DAY, 'Yıllık sunucu yenileme'),
('expense', 'Claude / Yazılım', 3400, DATE_FORMAT(CURDATE(), '%Y-%m-01'), 'Claude aboneliği'),
('expense', 'Claude / Yazılım', 3400, DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01'), 'Claude aboneliği'),
('expense', 'Domain', 450, CURDATE() - INTERVAL 12 DAY, 'corventech.tr yenileme'),
('expense', 'Reklam', 1500, CURDATE() - INTERVAL 20 DAY, 'Instagram reklamı'),
('income', 'Diğer gelir', 2000, CURDATE() - INTERVAL 8 DAY, 'Danışmanlık');
