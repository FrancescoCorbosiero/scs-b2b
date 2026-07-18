-- Ciclo di vita ordine (vedi docs/06): la richiesta nasce 'pending' con email
-- di istruzioni pagamento (bonifico); l'admin la conferma all'arrivo del
-- pagamento (email con ricevuta pro-forma, numero assegnato in quel momento)
-- oppure la annulla. L'indirizzo di spedizione arriva dal form ordine e
-- alimenta anche l'ordine dropship automatico (AUTO_DROPSHIP_ON_REQUEST).

ALTER TABLE order_requests ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER notes;
ALTER TABLE order_requests ADD COLUMN confirmed_at DATETIME NULL AFTER status;
ALTER TABLE order_requests ADD COLUMN cancelled_at DATETIME NULL AFTER confirmed_at;
ALTER TABLE order_requests ADD COLUMN address_street VARCHAR(255) NULL AFTER phone;
ALTER TABLE order_requests ADD COLUMN address_city VARCHAR(128) NULL AFTER address_street;
ALTER TABLE order_requests ADD COLUMN address_zip VARCHAR(16) NULL AFTER address_city;
ALTER TABLE order_requests ADD KEY idx_orders_status (status);

-- NB: le richieste precedenti alla migrazione restano 'pending' (gestite col
-- vecchio flusso): l'admin può confermarle o annullarle manualmente.
