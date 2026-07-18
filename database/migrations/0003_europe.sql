-- Catalogo europeo (vedi docs/04-pricing.md riscritto):
-- listino unico con regole margine gestite da /admin, prezzi VAT ESCLUSA,
-- paesi/aliquote UE + UK/CH, dati VAT e ricevuta pro-forma sugli ordini.

-- ── Listino unico: un prezzo netto per taglia al posto dei tre piani ──
ALTER TABLE product_sizes ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER offer_price;
UPDATE product_sizes SET price = price_base;
ALTER TABLE product_sizes DROP COLUMN price_base;
ALTER TABLE product_sizes DROP COLUMN price_pro;
ALTER TABLE product_sizes DROP COLUMN price_max;

ALTER TABLE products ADD COLUMN min_price DECIMAL(10,2) NULL AFTER total_quantity;
UPDATE products SET min_price = min_price_base;
ALTER TABLE products DROP COLUMN min_price_base;
ALTER TABLE products DROP COLUMN min_price_pro;
ALTER TABLE products DROP COLUMN min_price_max;
ALTER TABLE products ADD KEY idx_products_min_price (min_price);

-- NB: i prezzi copiati dal vecchio listino Base includono ancora l'IVA 22%:
-- il primo reprice/sync dopo il deploy li ricalcola netti (bin/sync-feed.php --reprice).

-- ── Richieste d'ordine: paese, VAT, lingua, ricevuta (plan resta per lo storico) ──
ALTER TABLE order_requests MODIFY plan VARCHAR(8) NULL DEFAULT NULL;
ALTER TABLE order_requests ADD COLUMN locale VARCHAR(5) NOT NULL DEFAULT 'it' AFTER plan;
ALTER TABLE order_requests ADD COLUMN country_code CHAR(2) NOT NULL DEFAULT 'IT' AFTER locale;
ALTER TABLE order_requests ADD COLUMN vat_number VARCHAR(32) NULL AFTER country_code;
ALTER TABLE order_requests ADD COLUMN vat_scheme VARCHAR(20) NOT NULL DEFAULT 'domestic' AFTER vat_number;
ALTER TABLE order_requests ADD COLUMN vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER vat_scheme;
ALTER TABLE order_requests ADD COLUMN vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER vat_rate;
ALTER TABLE order_requests ADD COLUMN total_gross DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER vat_amount;
ALTER TABLE order_requests ADD COLUMN receipt_number VARCHAR(20) NULL AFTER total_gross;

-- ── Regole margine (gestite da /admin/margini) ───────────────────────
-- match_type 'brand' = uguaglianza col brand; 'name' = il nome prodotto contiene
-- match_value. Vince la prima regola attiva in ordine di priority (crescente).
CREATE TABLE IF NOT EXISTS margin_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    priority INT NOT NULL DEFAULT 100,
    match_type ENUM('brand','name') NOT NULL,
    match_value VARCHAR(128) NOT NULL,
    margin_type ENUM('percent','fixed') NOT NULL,
    margin_value DECIMAL(8,2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_margin_rules_lookup (is_active, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Impostazioni chiave/valore (margine di default, modificabile da /admin) ──
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 30% = continuità col vecchio MARKUP_BASE (al netto dell'IVA rimossa)
INSERT INTO settings (setting_key, setting_value, updated_at) VALUES
    ('default_margin_type', 'percent', NOW()),
    ('default_margin_value', '30', NOW());

-- ── Aliquote VAT standard per paese (UE-27 + UK e Svizzera) ──────────
-- is_eu = 0 → extra-UE: trattato come export (VAT 0%), l'aliquota resta
-- registrata a titolo informativo. Aggiornate a luglio 2026.
CREATE TABLE IF NOT EXISTS vat_rates (
    country_code CHAR(2) PRIMARY KEY,
    vat_rate DECIMAL(5,2) NOT NULL,
    is_eu TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO vat_rates (country_code, vat_rate, is_eu, sort_order, updated_at) VALUES
    ('IT', 22.00, 1, 1, NOW()),
    ('AT', 20.00, 1, 100, NOW()),
    ('BE', 21.00, 1, 100, NOW()),
    ('BG', 20.00, 1, 100, NOW()),
    ('CY', 19.00, 1, 100, NOW()),
    ('CZ', 21.00, 1, 100, NOW()),
    ('DE', 19.00, 1, 100, NOW()),
    ('DK', 25.00, 1, 100, NOW()),
    ('EE', 24.00, 1, 100, NOW()),
    ('ES', 21.00, 1, 100, NOW()),
    ('FI', 25.50, 1, 100, NOW()),
    ('FR', 20.00, 1, 100, NOW()),
    ('GR', 24.00, 1, 100, NOW()),
    ('HR', 25.00, 1, 100, NOW()),
    ('HU', 27.00, 1, 100, NOW()),
    ('IE', 23.00, 1, 100, NOW()),
    ('LT', 21.00, 1, 100, NOW()),
    ('LU', 17.00, 1, 100, NOW()),
    ('LV', 21.00, 1, 100, NOW()),
    ('MT', 18.00, 1, 100, NOW()),
    ('NL', 21.00, 1, 100, NOW()),
    ('PL', 23.00, 1, 100, NOW()),
    ('PT', 23.00, 1, 100, NOW()),
    ('RO', 21.00, 1, 100, NOW()),
    ('SE', 25.00, 1, 100, NOW()),
    ('SI', 22.00, 1, 100, NOW()),
    ('SK', 23.00, 1, 100, NOW()),
    ('GB', 20.00, 0, 200, NOW()),
    ('CH', 8.10, 0, 200, NOW());

-- ── Numerazione ricevute pro-forma: PF-<anno>-<progressivo> ──────────
CREATE TABLE IF NOT EXISTS receipt_counters (
    year SMALLINT UNSIGNED PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
