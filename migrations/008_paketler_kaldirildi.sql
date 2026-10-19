-- =====================================================================
-- Güncelleme 008 — paketler kaldırıldı; her işte hizmet adı elle yazılır
--
-- Mevcut işlerde paket adı "hizmet adı" olarak işe aktarılır, sonra paket tablosu silinir.
-- Panel bu dosyayı ilk açılışta kendiliğinden uygular. Yeni kurulumda gerekmez.
-- =====================================================================

UPDATE orders o JOIN services s ON s.id = o.service_id SET o.title = s.name WHERE o.title IS NULL OR o.title = '';

UPDATE orders SET title = 'Hizmet' WHERE title IS NULL OR title = '';
ALTER TABLE orders MODIFY COLUMN title VARCHAR(150) NOT NULL COMMENT 'Hizmet adı (örn. Sosyal Medya, Web Sitesi)';

ALTER TABLE orders DROP FOREIGN KEY fk_orders_service;
ALTER TABLE orders DROP COLUMN service_id;
DROP TABLE services;
