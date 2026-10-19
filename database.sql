-- =====================================================================
-- Corventech Panel — veritabanı kurulumu (tablolar + örnek veri)
--
-- DİKKAT: Bu dosya mevcut tabloları SİLİP yeniden oluşturur.
-- Yalnızca ilk kurulumda içe aktarın; canlıdaki verinin üzerine yüklemeyin.
--
-- Deneme için örnek müşteri/ödeme verisi isterseniz ardından ornek-veri.sql dosyasını da içe aktarın.
-- Kullanıcı hesabı yoktur: siteyi ilk açtığınızda yönetici hesabı oluşturma ekranı gelir.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS sessions, transactions, recurring_transactions, posts, payments, dues, orders, tasks, customer_notes, customers, services, login_attempts, settings, users;

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

-- Oturumlar (30 gün; telefonda tekrar tekrar giriş istemez)
CREATE TABLE sessions (
    id            VARCHAR(128) NOT NULL PRIMARY KEY,
    data          MEDIUMBLOB NOT NULL,
    last_activity INT UNSIGNED NOT NULL,
    INDEX idx_activity (last_activity)
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
    notes         TEXT NULL,
    registered_at DATE NOT NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_city (city),
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

-- Düzenli gelir/giderler (hosting yıllık, Claude aylık…): panel her dönemin kaydını kendisi oluşturur
CREATE TABLE recurring_transactions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('income','expense') NOT NULL,
    category    VARCHAR(60) NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    cycle       ENUM('monthly','yearly') NOT NULL,
    start_date  DATE NOT NULL,
    last_period INT NOT NULL DEFAULT 0 COMMENT 'Oluşturulan son dönem numarası',
    description VARCHAR(255) NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rtx_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Gelir/gider hareketleri (tek seferlik veya düzenli kayıttan üretilmiş)
CREATE TABLE transactions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('income','expense') NOT NULL,
    category    VARCHAR(60) NOT NULL,
    amount      DECIMAL(12,2) NOT NULL,
    txn_date    DATE NOT NULL,
    description VARCHAR(255) NULL,
    recurring_id INT UNSIGNED NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (txn_date),
    CONSTRAINT fk_txn_recurring FOREIGN KEY (recurring_id) REFERENCES recurring_transactions(id) ON DELETE SET NULL,
    CONSTRAINT fk_txn_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ---------------------------------------------------------------------
-- Ayarlar
-- ---------------------------------------------------------------------

INSERT INTO settings (`key`, `value`) VALUES
('company_name', 'Corventech'),
('upcoming_days', '30'),
('db_version', '5'),
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
