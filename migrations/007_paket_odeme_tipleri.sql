-- =====================================================================
-- Güncelleme 007 — paketlere göre izin verilen ödeme tipleri
--
-- Sosyal Medya yalnızca aylık; Kurumsal Web Sitesi ve İşletme Paneli yıllık ya da taksitli.
-- Kombo paketlerde ve özel hizmetlerde kısıtlama yoktur (allowed_cycles boş).
-- Panel bu dosyayı ilk açılışta kendiliğinden uygular. Yeni kurulumda gerekmez.
-- =====================================================================

ALTER TABLE services ADD COLUMN allowed_cycles VARCHAR(60) NULL COMMENT 'İzin verilen ödeme tipleri (virgülle); boşsa hepsi' AFTER description;

UPDATE services SET allowed_cycles = 'monthly' WHERE name = 'Sosyal Medya';
UPDATE services SET allowed_cycles = 'installment,yearly' WHERE name IN ('Kurumsal Web Sitesi', 'İşletme Paneli');
