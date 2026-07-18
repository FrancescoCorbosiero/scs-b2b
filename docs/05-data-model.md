# 05 — Modello dati (MySQL)

Schema indicativo: rifinire in fase di implementazione mantenendo nomi e semantica.

## `products`
- `id` PK
- `sku` VARCHAR unique — chiave naturale dal feed
- `name`, `brand`, `size_mapper` VARCHAR
- `image_url` VARCHAR NULL
- `is_recommended` TINYINT default 0 (il feed flat non lo fornisce: gestibile
  da /admin come flag manuale, oppure lasciare sempre 0 in v1)
- `is_active` TINYINT default 1 (0 = sparito dal feed)
- `total_quantity` INT — denormalizzato a sync (somma taglie) per filtri/ordinamenti
- `min_price` DECIMAL(10,2) — prezzo netto minimo denormalizzato per filtro range prezzo e card
- `created_at`, `updated_at`, `last_seen_at` (ultimo sync in cui era presente)
- Indici: `sku`, `brand`, `is_active`, `total_quantity`, `min_price`

## `product_sizes`
- `id` PK, `product_id` FK (ON DELETE CASCADE)
- `size_eu` VARCHAR(10) — stringa, mai numerico (`36 2/3`)
- `size_us` VARCHAR(10), `barcode` VARCHAR(32)
- `quantity` INT
- `offer_price` DECIMAL(10,2) — **riservato**
- `price` DECIMAL(10,2) — prezzo netto di listino (VAT esclusa), precalcolato a sync
  con le regole margine (docs/04)
- `supplier_size_id` INT NULL — `id` riga del feed: `size_id` per l'API dropship (docs/09)
- Unique: (`product_id`, `size_eu`)

## `users` (account clienti — M9, docs/07)
- `id` PK, `email` UNIQUE, `password_hash` NULL (NULL = invito non completato)
- `name`, `company` NULL, `phone` NULL, `vat_number` NULL
- `address_street|city|zip` NULL, `country_code`, `locale` — precompilano il checkout
- `is_active` TINYINT, `created_at`, `updated_at`, `last_login_at` NULL

## `user_tokens` (invito/reset — monouso, a scadenza)
- `id` PK, `user_id` FK (CASCADE), `token_hash` CHAR(64) UNIQUE (sha256: il
  chiaro esiste solo nel link email), `purpose` ENUM('invite','reset')
- `expires_at`, `used_at` NULL, `created_at`

## `order_requests`
- `id` PK, `user_id` FK NULL (ON DELETE SET NULL) — account del cliente;
  NULL per gli ordini ospite/storici (l'area personale li matcha per email)
- `created_at`
- `customer_name`, `company` NULL, `email`, `phone`, `notes` TEXT NULL
- `address_street`, `address_city`, `address_zip` — indirizzo di spedizione
  (alimenta anche l'auto-dropship, docs/09)
- `status` VARCHAR(16) — pending | confirmed | cancelled (+ `confirmed_at`,
  `cancelled_at`); la ricevuta e il suo numero esistono solo da 'confirmed'
- `plan` VARCHAR(8) NULL — solo storico pre-migrazione 0003 (i piani non esistono più)
- `locale` VARCHAR(5) — lingua del cliente al momento dell'ordine ('it'|'en')
- `country_code` CHAR(2), `vat_number` VARCHAR(32) NULL
- `vat_scheme` VARCHAR(20) — domestic | eu | reverse_charge | export (docs/04)
- `vat_rate` DECIMAL(5,2), `vat_amount` DECIMAL(10,2), `total_gross` DECIMAL(10,2)
- `receipt_number` VARCHAR(20) NULL — es. PF-2026-0001 (ricevuta pro-forma)
- `total_items` INT, `total_amount` DECIMAL(10,2) — **imponibile netto** (VAT esclusa)
- `cart_snapshot` JSON — righe complete: sku, nome, taglia EU/US, barcode, qty,
  prezzo unitario netto, subtotale (+ offer_price per riga, visibile solo lato admin)
- `email_admin_sent`, `email_customer_sent` TINYINT — per capire i fallimenti SMTP
- `ip_address`, `user_agent` — antiabuso

## `sync_logs`
- `id`, `started_at`, `finished_at`, `status` ENUM('ok','error')
- `rows_read`, `products_created`, `products_updated`, `products_deactivated` INT
- `message` TEXT NULL (errori)

## `login_attempts` (rate limiting)
- `id`, `ip_address`, `scope` ENUM('catalog','admin'), `attempted_at`, `success` TINYINT
- Query di lockout: >5 tentativi falliti / 15 min per (ip, scope)

## `dropship_orders` (anteprima — docs/09)
- `id` PK, `order_request_id` FK NULL (ON DELETE SET NULL), `created_at`, `updated_at`
- `mode` VARCHAR ('simulation'|'live') — in anteprima sempre 'simulation'
- `status` VARCHAR — stati fornitore: UNCONFIRMED, TO_SHIP, ENDED, CANCELED,
  WAITING_FOR_INVOICE
- `vendor_order_id`, `dropship_package_id` INT NULL — id restituiti dall'API
- `total_price` DECIMAL(10,2) NULL — stima a costo fornitore (il totale reale
  lo calcola l'API), `currency` VARCHAR default 'EUR'
- `request_payload` TEXT — payload JSON esatto per l'API
- `lines_snapshot` TEXT — righe per la vista admin (sku, taglia, qty, costo)
- `response_payload` TEXT NULL, `tracking_numbers` TEXT NULL (JSON)

## `margin_rules` (gestione margini da /admin/margini — docs/04)
- `id` PK, `priority` INT (crescente = valutata prima)
- `match_type` ENUM('brand','name'), `match_value` VARCHAR(128)
- `margin_type` ENUM('percent','fixed'), `margin_value` DECIMAL(8,2)
- `is_active` TINYINT, `created_at`, `updated_at`
- Indice: (`is_active`, `priority`)

## `settings` (chiave/valore)
- `setting_key` VARCHAR(64) PK, `setting_value` VARCHAR(255), `updated_at`
- Oggi: `default_margin_type`, `default_margin_value` (margine di fallback)

## `vat_rates` (aliquote per paese — docs/04)
- `country_code` CHAR(2) PK, `vat_rate` DECIMAL(5,2)
- `is_eu` TINYINT (0 = extra-UE → export), `sort_order` INT (IT in testa), `updated_at`
- Seed migrazione 0003: UE-27 + GB + CH; modificabili da /admin/margini

## `receipt_counters` (numerazione ricevute pro-forma)
- `year` SMALLINT PK, `last_number` INT — progressivo per anno (PF-<anno>-<NNNN>)

Il carrello NON è a DB: vive nella sessione server-side (vedi 06/07).
