-- =====================================================================
-- Güncelleme 004 — oturumlar veritabanında (30 gün; telefonda tekrar tekrar giriş istemez)
--
-- Panel zaten kuruluysa phpMyAdmin'de bu dosyayı bir kez içe aktarın.
-- Yeni kurulumda gerekmez; database.sql bunu zaten içerir.
-- =====================================================================

CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(128) NOT NULL PRIMARY KEY,
    data          MEDIUMBLOB NOT NULL,
    last_activity INT UNSIGNED NOT NULL,
    INDEX idx_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
