-- Richieste di attivazione profilo inviate dal sito pubblico (docs/06 §
-- /richiedi-accesso). Il cliente manda i propri dati e quelli aziendali;
-- l'admin approva (crea l'account e parte l'invito) o rifiuta.
-- I dati raccolti sono gli stessi che poi precompilano richiesta d'ordine,
-- ricevuta e ordine dropship GoldenSneakers: indirizzo e P.IVA inclusi.

CREATE TABLE IF NOT EXISTS account_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',   -- pending | approved | rejected
    reviewed_at DATETIME NULL,
    user_id INT UNSIGNED NULL,                       -- account creato all'approvazione
    company VARCHAR(128) NOT NULL,
    vat_number VARCHAR(32) NULL,
    name VARCHAR(128) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(32) NOT NULL,
    address_street VARCHAR(255) NOT NULL,
    address_city VARCHAR(128) NOT NULL,
    address_zip VARCHAR(16) NOT NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'IT',
    locale VARCHAR(5) NOT NULL DEFAULT 'it',
    notes TEXT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    KEY idx_account_requests_status (status, created_at),
    KEY idx_account_requests_email (email),
    CONSTRAINT fk_account_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- L'indirizzo del profilo alimenta il checkout e l'auto-dropship: fin qui
-- esisteva solo sugli utenti creati a mano, ora arriva dalla richiesta.
