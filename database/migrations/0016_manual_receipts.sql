-- Pro-forma manuali (docs/06 § /admin/proforma): ricevute pro-forma create a
-- mano dall'admin, fuori dal ciclo delle richieste d'ordine (es. vendite in
-- sede, accordi presi al telefono, preventivi da pagare con bonifico).
--  - Stessa numerazione PF-<anno>-<NNNN> delle ricevute degli ordini
--    (receipt_counters, decisione del titolare del 03/10/2026): un unico
--    registro; il numero si assegna alla creazione e non cambia più.
--  - Stessi calcoli di una richiesta d'ordine: imponibile = righe +
--    spedizione, schema IVA da paese + P.IVA (VatService, VAT_ON_ORDER).
--  - Righe libere (descrizione, taglia, quantità, prezzo netto) in JSON:
--    solo prezzi di vendita, MAI costi del fornitore (Regola d'oro n.1).
--  - Mai cancellate: "annullata" conserva il numero (niente buchi nascosti).
-- `lines` è una parola riservata di MySQL: la colonna è lines_json.

CREATE TABLE IF NOT EXISTS manual_receipts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(20) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'issued',      -- issued | cancelled
    user_id INT UNSIGNED NULL,                          -- account da cui sono stati presi i dati
    locale VARCHAR(5) NOT NULL DEFAULT 'it',            -- lingua di PDF ed email
    customer_name VARCHAR(128) NOT NULL,
    company VARCHAR(128) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(32) NULL,
    address_street VARCHAR(255) NULL,
    address_city VARCHAR(128) NULL,
    address_zip VARCHAR(16) NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'IT',
    vat_number VARCHAR(32) NOT NULL,
    vat_scheme VARCHAR(20) NOT NULL,                    -- domestic | eu | reverse_charge | export
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    lines_json MEDIUMTEXT NOT NULL,
    total_items INT UNSIGNED NOT NULL DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,      -- imponibile merce (IVA esclusa)
    shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0,   -- spedizione netta
    total_gross DECIMAL(10,2) NOT NULL DEFAULT 0,       -- imponibile + spedizione + IVA
    notes TEXT NULL,                                    -- stampate sulla pro-forma
    show_bank TINYINT(1) NOT NULL DEFAULT 1,            -- coordinate per il bonifico su PDF ed email
    email_sent_at DATETIME NULL,
    email_sent_to VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    cancelled_at DATETIME NULL,
    UNIQUE KEY uq_manual_receipts_number (receipt_number),
    KEY idx_manual_receipts_created (created_at),
    CONSTRAINT fk_manual_receipts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
