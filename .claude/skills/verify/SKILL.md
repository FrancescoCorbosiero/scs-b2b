---
name: verify
description: Come buildare, lanciare e guidare l'app in locale (senza Docker) per verificare le modifiche end-to-end.
---

# Verifica locale (senza Docker/MySQL)

L'app gira con PHP built-in server + SQLite. Percorsi coperti: login catalogo/admin,
catalogo, carrello, richiesta d'ordine, area admin, flusso dropship.

## Setup (una volta per sessione)

```bash
composer install --no-dev --prefer-source   # dist GitHub bloccati: usare source; phpstan (solo dist) non installa
composer dump-autoload --dev                # per i test (App\Tests\)
npm ci && npm run build                     # asset in public/assets (servono per Alpine/Tailwind nel browser)
```

PHPUnit non è nel vendor (phpstan blocca l'install dev): usarlo globale
`composer global require phpunit/phpunit:^11 --prefer-source` →
`php ~/.config/composer/vendor/bin/phpunit`.

## Database e .env

SQLite con lo stesso schema logico di `tests/Support/TestDb.php` (crearlo via
script che replica quelle CREATE TABLE, in una dir scratch). Nel `.env`:

```
APP_ENV=development
DB_DRIVER=sqlite
DB_NAME=/percorso/assoluto/dev.sqlite
CATALOG_PASSWORD_HASH='<php bin/hash-password.php "pass1">'
ADMIN_PASSWORD_HASH='<php bin/hash-password.php "pass2">'
FEED_SOURCE=fixture
FEED_FIXTURE_PATH=fixtures/goldensneakers-dev.json
DROPSHIP_ENABLED=1
DROPSHIP_MODE=simulation
```

Poi `php bin/sync-feed.php` per popolare il catalogo (12 prodotti dalla fixture).

## Lancio

Il built-in server DEVE usare un router che serve i file statici, altrimenti
`/assets/*` va a Slim e torna 404 (Alpine non parte e i gate client-side
sembrano rotti):

```php
<?php // router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . '/public' . $path)) { return false; }
require __DIR__ . '/public/index.php';
```

```bash
php -S 127.0.0.1:8090 -t public router.php
```

## Guida dei flussi

- Ogni POST richiede `_csrf` (estrarlo dall'HTML della pagina precedente) e il
  cookie `b2b_session` (curl: `-b jar -c jar`).
- Richiesta ordine: minimo 5 pezzi (`MIN_ORDER_ITEMS`); usare un prodotto con
  stock alto (es. NK1001) + `POST /carrello/prendi-tutto`. Campi obbligatori:
  nome, email, telefono, indirizzo (address_street/address_city/address_zip),
  country. La richiesta nasce `pending`: conferma/annulla da
  `POST /admin/richieste/{id}/conferma|annulla`.
- SMTP assente: l'invio email fallisce ma è catturato, la richiesta si salva.
- Login admin: attenzione al lockout (5 errori/15min per IP+scope) — diagnosi
  con `php bin/check-auth.php`.
- Browser: Playwright con `executablePath: '/opt/pw-browsers/chromium'`
  (`npm install playwright-core` in scratch).
