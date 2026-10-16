-- =====================================================================
-- Güncelleme 002 — şirket gelir/gider kaydı ve pay oranı
--
-- Panel zaten kuruluysa phpMyAdmin'de bu dosyayı bir kez içe aktarın.
-- Yeni kurulumda gerekmez; database.sql bunları zaten içerir.
-- =====================================================================

SET NAMES utf8mb4;

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

-- Arda / Melih müşterilerinden gelen paranın şirkete kalan kısmı (kalanı müşteri sahibine)
INSERT INTO settings (`key`, `value`) VALUES ('company_share', '1/3')
ON DUPLICATE KEY UPDATE `value` = `value`;
