-- Prezzo fisso manuale: un terzo tipo di "margine" nelle regole di
-- /admin/margini. Con margin_type = 'fixed_price' il valore NON è un margine
-- ma il prezzo netto di listino imposto dall'admin (VAT esclusa): offer_price
-- del feed ignorato, nessun arrotondamento (vedi docs/04-pricing.md).
-- Caso d'uso: "lo SKU JS3801 lo vendo a 129 € e basta".

ALTER TABLE margin_rules MODIFY margin_type ENUM('percent','fixed','fixed_price') NOT NULL;
