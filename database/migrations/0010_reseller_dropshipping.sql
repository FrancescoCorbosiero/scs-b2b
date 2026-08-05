-- Dropshipping per il rivenditore (docs/09): al checkout può spedire
-- direttamente al SUO cliente finale (destinatario dedicato) e scegliere di
-- fornire lui l'etichetta di spedizione (client_provides_shipping_label).
-- Default: comportamento attuale (spedizione al rivenditore, etichetta del
-- fornitore).

ALTER TABLE order_requests ADD COLUMN ship_to VARCHAR(16) NOT NULL DEFAULT 'reseller';
ALTER TABLE order_requests ADD COLUMN recipient_name VARCHAR(128) NULL;
ALTER TABLE order_requests ADD COLUMN recipient_street VARCHAR(255) NULL;
ALTER TABLE order_requests ADD COLUMN recipient_city VARCHAR(128) NULL;
ALTER TABLE order_requests ADD COLUMN recipient_zip VARCHAR(16) NULL;
ALTER TABLE order_requests ADD COLUMN recipient_country VARCHAR(2) NULL;
ALTER TABLE order_requests ADD COLUMN recipient_phone VARCHAR(32) NULL;
ALTER TABLE order_requests ADD COLUMN client_provides_label TINYINT(1) NOT NULL DEFAULT 0;

-- Etichetta caricata presso il fornitore (upload-shipping-label): una sola
-- volta per ordine, l'API rifiuta i duplicati.
ALTER TABLE dropship_orders ADD COLUMN label_uploaded_at DATETIME NULL;
ALTER TABLE dropship_orders ADD COLUMN label_file_name VARCHAR(255) NULL;
