-- =====================================================================
-- Güncelleme 003 — pay her iş (sipariş) için ayrı seçilir; gelir/giderde kasa seçimi
--
-- Panel zaten kuruluysa phpMyAdmin'de bu dosyayı bir kez içe aktarın.
-- Yeni kurulumda gerekmez; database.sql bunları zaten içerir.
-- =====================================================================

SET NAMES utf8mb4;

-- Her işin parasının yüzde kaçının hangi kasaya gideceği (toplam 100)
ALTER TABLE orders
    ADD COLUMN share_arda    DECIMAL(7,4) NOT NULL DEFAULT 0   AFTER setup_fee,
    ADD COLUMN share_melih   DECIMAL(7,4) NOT NULL DEFAULT 0   AFTER share_arda,
    ADD COLUMN share_company DECIMAL(7,4) NOT NULL DEFAULT 100 AFTER share_melih;

-- Mevcut işler: müşterinin sahibine göre (kişisel müşteri 2/3 sahibine, 1/3 şirkete; ortak müşteri tamamı şirkete)
UPDATE orders o JOIN customers c ON c.id = o.customer_id
SET o.share_arda    = IF(c.owner = 'arda', 66.6667, 0),
    o.share_melih   = IF(c.owner = 'melih', 66.6667, 0),
    o.share_company = IF(c.owner = 'ortak', 100, 33.3333);

-- Gider hangi kasadan ödendi / gelir hangi kasaya girdi
ALTER TABLE transactions
    ADD COLUMN wallet ENUM('company','arda','melih') NOT NULL DEFAULT 'company' AFTER type;

-- Düzenli gelir/giderler (hosting yıllık, Claude aylık…): panel her dönemin kaydını kendisi oluşturur
CREATE TABLE recurring_transactions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('income','expense') NOT NULL,
    wallet      ENUM('company','arda','melih') NOT NULL DEFAULT 'company',
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

ALTER TABLE transactions
    ADD COLUMN recurring_id INT UNSIGNED NULL AFTER description,
    ADD CONSTRAINT fk_txn_recurring FOREIGN KEY (recurring_id) REFERENCES recurring_transactions(id) ON DELETE SET NULL;

-- Tek oran ayarı artık kullanılmıyor
DELETE FROM settings WHERE `key` = 'company_share';
