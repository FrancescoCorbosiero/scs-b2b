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
- `min_price_base|pro|max` DECIMAL(10,2) — denormalizzati per filtro range prezzo e card
- `created_at`, `updated_at`, `last_seen_at` (ultimo sync in cui era presente)
- Indici: `sku`, `brand`, `is_active`, `total_quantity`

## `product_sizes`
- `id` PK, `product_id` FK (ON DELETE CASCADE)
- `size_eu` VARCHAR(10) — stringa, mai numerico (`36 2/3`)
- `size_us` VARCHAR(10), `barcode` VARCHAR(32)
- `quantity` INT
- `offer_price` DECIMAL(10,2) — **riservato**
- `price_base`, `price_pro`, `price_max` DECIMAL(10,2) — precalcolati a sync
- Unique: (`product_id`, `size_eu`)

## `order_requests`
- `id` PK, `created_at`
- `customer_name`, `company` NULL, `email`, `phone`, `notes` TEXT NULL
- `plan` ENUM('base','pro','max')
- `total_items` INT, `total_amount` DECIMAL(10,2)
- `cart_snapshot` JSON — righe complete: sku, nome, taglia EU/US, barcode, qty,
  prezzo unitario del piano, subtotale (+ offer_price per riga, visibile solo lato admin)
- `email_admin_sent`, `email_customer_sent` TINYINT — per capire i fallimenti SMTP
- `ip_address`, `user_agent` — antiabuso

## `sync_logs`
- `id`, `started_at`, `finished_at`, `status` ENUM('ok','error')
- `rows_read`, `products_created`, `products_updated`, `products_deactivated` INT
- `message` TEXT NULL (errori)

## `login_attempts` (rate limiting)
- `id`, `ip_address`, `scope` ENUM('catalog','admin'), `attempted_at`, `success` TINYINT
- Query di lockout: >5 tentativi falliti / 15 min per (ip, scope)

## `markup_rules` (opzionale, v2)
Override percentuale per brand e piano. In v1 bastano i tre valori globali in `.env`;
predisporre il PricingService perché aggiungere gli override non richieda refactoring.

Il carrello NON è a DB: vive nella sessione server-side (vedi 06/07).
