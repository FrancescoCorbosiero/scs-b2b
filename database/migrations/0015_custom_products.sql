-- Prodotti propri (docs/06 § /admin/prodotti-propri): l'admin li importa da
-- JSON/CSV nello STESSO formato del feed GoldenSneakers, ma restano separati
-- dai prodotti del feed: source = 'feed' | 'custom'.
--  - il sync del feed non tocca mai i 'custom' (né li aggiorna né li
--    disattiva) e l'import non tocca mai i 'feed';
--  - lo SKU resta unico su tutto il catalogo (carrello e ordini lo usano
--    come chiave): un import con uno SKU del feed viene rifiutato;
--  - il catalogo li mostra in una sezione dedicata ("Disponibili in sede") e
--    non finiscono mai nell'ordine verso GoldenSneakers.
-- Prezzo: offer_price + regole margine, esattamente come per il feed.

ALTER TABLE products ADD COLUMN source ENUM('feed','custom') NOT NULL DEFAULT 'feed' AFTER sku;
ALTER TABLE products ADD INDEX idx_products_source (source, is_active);
