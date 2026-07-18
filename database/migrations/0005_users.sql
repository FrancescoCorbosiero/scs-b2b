-- Profilazione clienti (vedi docs/06 e 07): account creati dall'admin con
-- invito via email (link monouso a scadenza per impostare la password —
-- la password NON viaggia mai in chiaro). La password condivisa resta
-- attiva come "modalità ospite" dietro GUEST_LOGIN_ENABLED.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NULL,          -- NULL = invito non ancora completato
    name VARCHAR(128) NOT NULL,
    company VARCHAR(128) NULL,
    phone VARCHAR(32) NULL,
    vat_number VARCHAR(32) NULL,
    address_street VARCHAR(255) NULL,
    address_city VARCHAR(128) NULL,
    address_zip VARCHAR(16) NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'IT',
    locale VARCHAR(5) NOT NULL DEFAULT 'it',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Token per invito e reset password: a DB vive SOLO l'hash sha256 del token
-- (il token in chiaro esiste solo nel link email), monouso e con scadenza.
CREATE TABLE IF NOT EXISTS user_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    purpose ENUM('invite','reset') NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_tokens_hash (token_hash),
    KEY idx_tokens_user (user_id, purpose),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Le richieste d'ordine si agganciano all'account (le storiche restano NULL
-- e vengono mostrate nell'area personale tramite match sull'email).
ALTER TABLE order_requests ADD COLUMN user_id INT UNSIGNED NULL AFTER id;
ALTER TABLE order_requests ADD KEY idx_orders_user (user_id);
ALTER TABLE order_requests ADD CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL;

-- scope libero (era ENUM): serve per il rate limiting dei nuovi flussi account
ALTER TABLE login_attempts MODIFY scope VARCHAR(16) NOT NULL;
