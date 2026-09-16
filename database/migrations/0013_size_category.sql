-- Categoria di taglia del prodotto: 'adult' (normali), 'gs' (grade school,
-- ragazzi), 'ps' (pre-school, bambini — comprese le toddler). Il feed non la
-- fornisce: viene dedotta a sync da nome/size_mapper/taglie (App\Service\
-- SizeCategory) e usata dal filtro catalogo e dalle regole margine.
--
-- Le regole margine possono ora puntare a una categoria (match_type
-- 'size_category', match_value = adult|gs|ps).
--
-- ⚠ Dopo il deploy serve un reprice per popolare la colonna sui prodotti
--   già in tabella: php bin/sync-feed.php --reprice
--   (o un salvataggio qualsiasi in /admin/margini).

ALTER TABLE products ADD COLUMN size_category ENUM('adult','gs','ps') NOT NULL DEFAULT 'adult' AFTER size_mapper;
ALTER TABLE products ADD INDEX idx_products_size_category (size_category);

ALTER TABLE margin_rules MODIFY match_type ENUM('brand','name','sku','size_category') NOT NULL;
