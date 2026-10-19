-- =====================================================================
-- Güncelleme 006 — taksitli ödeme (toplam fiyat + taksit sayısı)
--
-- Panel bu dosyayı ilk açılışta kendiliğinden uygular. Yeni kurulumda gerekmez.
-- =====================================================================

ALTER TABLE orders MODIFY COLUMN billing_cycle ENUM('one_time','monthly','yearly','installment') NOT NULL;
ALTER TABLE orders ADD COLUMN installments TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Taksitli işlerde taksit sayısı (price = toplam tutar)' AFTER price;
