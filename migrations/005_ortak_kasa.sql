-- =====================================================================
-- Güncelleme 005 — her şey ortak: müşteri sahibi, iş payı ve kasa seçimi kaldırıldı
--
-- Panel bu dosyayı ilk açılışta kendiliğinden uygular. Yeni kurulumda gerekmez.
-- Bu sütunlardaki veriler silinir (müşteri sahibi, iş payları, gelir/gider kasası);
-- ödemeler, işler ve müşteriler olduğu gibi kalır.
-- =====================================================================

ALTER TABLE customers DROP COLUMN owner;

ALTER TABLE orders DROP COLUMN share_arda;
ALTER TABLE orders DROP COLUMN share_melih;
ALTER TABLE orders DROP COLUMN share_company;

ALTER TABLE transactions DROP COLUMN wallet;
ALTER TABLE recurring_transactions DROP COLUMN wallet;
