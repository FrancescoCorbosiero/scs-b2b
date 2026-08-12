-- Margini anche a livello di SKU: nuovo match_type 'sku' con uno o più codici
-- esatti separati da virgola (case-insensitive). Le regole SKU sono le più
-- specifiche e vengono valutate PRIMA di quelle brand/nome, a prescindere
-- dalla priority (vedi docs/04-pricing.md). match_value passa a 255 caratteri
-- per ospitare più SKU nella stessa regola.

ALTER TABLE margin_rules MODIFY match_type ENUM('brand','name','sku') NOT NULL;
ALTER TABLE margin_rules MODIFY match_value VARCHAR(255) NOT NULL;
