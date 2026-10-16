-- =====================================================================
-- Güncelleme 001 — müşteri sahibi, fiyatsız paketler, sosyal medya takvimi
--
-- Panel zaten kuruluysa (veriler duruyorsa) phpMyAdmin'de bu dosyayı bir kez
-- içe aktarın. Yeni kurulumda gerekmez; database.sql bunları zaten içerir.
-- =====================================================================

SET NAMES utf8mb4;

-- Müşterinin sorumlusu: Arda / Melih / Ortak
ALTER TABLE customers
    ADD COLUMN owner ENUM('arda','melih','ortak') NOT NULL DEFAULT 'ortak' AFTER status,
    ADD INDEX idx_owner (owner);

-- Paketlerde fiyat tutulmaz; fiyat her müşteri için elle girilir
ALTER TABLE services
    DROP COLUMN default_price,
    DROP COLUMN default_setup_fee,
    DROP COLUMN default_cycle;

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
