-- Ordini presso GoldenSneakers con l'API ordini /api/orders/ (docs/09,
-- decisione del titolare del 02/10/2026): i nuovi ordini al fornitore si
-- creano con POST /api/orders/create/ al posto di orders-dropship/.
-- dropship_orders resta il registro unico degli ordini al fornitore:
-- `api` distingue i nuovi ('orders') da quelli storici ('dropship', che si
-- rileggono ancora con l'API orders-dropship). Dall'API ordini arrivano
-- anche il costo di spedizione del fornitore e lo stato del NOSTRO
-- pagamento al fornitore (pro-forma da saldare).

ALTER TABLE dropship_orders ADD COLUMN api VARCHAR(16) NOT NULL DEFAULT 'dropship' AFTER mode;
ALTER TABLE dropship_orders ADD COLUMN shipping_cost DECIMAL(10,2) NULL AFTER total_price;
ALTER TABLE dropship_orders ADD COLUMN payment_status VARCHAR(32) NULL AFTER currency;
ALTER TABLE dropship_orders ADD COLUMN is_paid TINYINT(1) NULL AFTER payment_status;
ALTER TABLE dropship_orders ADD KEY idx_dropship_vendor (api, vendor_order_id);
