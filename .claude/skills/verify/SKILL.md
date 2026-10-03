---
name: verify
description: Come buildare, lanciare e guidare l'app in locale (senza Docker) per verificare le modifiche end-to-end.
---

# Verifica locale (senza Docker/MySQL)

L'app gira con PHP built-in server + SQLite. Percorsi coperti: login catalogo/admin,
catalogo, vetrina pubblica, carrello, richiesta d'ordine, area admin, prodotti
propri, ordini GoldenSneakers (API ordini, anche "live" contro un mock locale).

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
- Pagine pubbliche (`/`, `/spedizioni`, `/come-ordinare`, `/richiedi-accesso`):
  nessun login richiesto. Il catalogo è su `/catalogo` (redirect a `/login`).
- Richiesta profilo: POST `/richiedi-accesso` (CSRF + honeypot `website` vuoto,
  max 3/ora per IP) → riga `pending` in `account_requests`; l'admin approva da
  `/admin/richieste-profilo` (crea l'utente + invito; senza SMTP l'invito non
  parte ma l'account c'è).
- Catalogo: i filtri stanno nella query string (`?taglia[]=42&disponibilita=alta`);
  la scheda rapida si apre dalla card e scrive nel carrello via
  `/carrello/aggiorna`; "Carica altri" usa `?fragment=1&page=N` (solo card).
  Per provare la paginazione con la fixture (12 prodotti): `PRODUCTS_PER_PAGE=6`.
- Spedizione: gratuita da 7 paia (`FREE_SHIPPING_MIN_ITEMS`), sotto soglia
  `SHIPPING_FEE` (10,00 €). Verifica rapida via JSON:
  `POST /carrello/aggiorna` con `X-Requested-With: fetch` → `shipping_amount`.
- Richiesta ordine: minimo 5 pezzi (`MIN_ORDER_ITEMS`); usare un prodotto con
  stock alto (es. NK1001) + `POST /carrello/prendi-tutto`. Campi obbligatori:
  nome, email, telefono, indirizzo (address_street/address_city/address_zip),
  country. La richiesta nasce `pending`: conferma/annulla da
  `POST /admin/richieste/{id}/conferma|annulla`.
- SMTP assente: l'invio email fallisce ma è catturato, la richiesta si salva.
- Login admin: attenzione al lockout (5 errori/15min per IP+scope) — diagnosi
  con `php bin/check-auth.php`.
- Account clienti: creali da `/admin/clienti`; senza SMTP l'invito non parte,
  ma il token è a DB: `SELECT token_hash FROM user_tokens` non basta (è sha256),
  quindi in dev genera il link con
  `php -r '...UserTokenRepository->issue(id,"invite",72)'` e apri
  `/account/imposta-password?token=<chiaro>`. Login utente: email+password;
  ospite: solo password (con GUEST_LOGIN_ENABLED=1).
- Vetrina `/vetrina` (senza login): nell'HTML non deve comparire né "€" né
  la parola "price" (nemmeno nel JSON della scheda rapida e in
  `?fragment=1&page=N`); `?prezzo_min=…` e `?ordina=prezzo_desc` vanno
  ignorati; con la sessione catalogo redirige a `/catalogo` con gli stessi
  filtri. I prodotti propri stanno in `?sezione=sede` (stessa cosa su
  `/catalogo`).
- Prodotti propri: `/admin/prodotti-propri`, upload multipart
  (`curl -b jar -c jar -F _csrf=… -F file=@prodotti.csv [-F replace=1]`).
  Modelli: `/admin/prodotti-propri/modello.csv|.json`. Uno SKU già usato dal
  feed (es. NK1001) deve essere rifiutato con l'elenco errori; l'import è
  tutto o niente.
- Ordini GoldenSneakers: in `DROPSHIP_MODE=simulation` nessun ordine parte
  (id finti 900000–999999) e le pagine `/admin/ordini-fornitore` mostrano
  l'avviso se manca il token. Per provare il "live" SENZA il fornitore: un
  mock PHP in scratch (`php -S 127.0.0.1:8099 mock.php`) che risponde con gli
  esempi di docs/09 a `GET /api/orders/`, `GET /api/orders/{id}/` e
  `POST /api/orders/create/` (201) e logga metodo/path/body; nel `.env`
  `FEED_BASE_URL=http://127.0.0.1:8099`, `FEED_BEARER_TOKEN=tok-dev`,
  `DROPSHIP_MODE=live`, `AUTO_DROPSHIP_ON_REQUEST=1`,
  `AUTO_DROPSHIP_ALLOW_LIVE=1`. Il payload di creazione deve contenere solo
  le righe del feed (mai quelle "in sede"). **Mai** il token vero né
  `FEED_BASE_URL` del fornitore con `DROPSHIP_MODE=live` in verifica: crea
  ordini reali.
- Pro-forma manuali `/admin/proforma`: il form vuole JavaScript (righe Alpine
  `proformaLines` in `app.js`, caricato PRIMA di Alpine nel layout); via curl
  si postano `lines[0][name]`, `lines[0][qty]`, `lines[0][unit_price]`… La
  ricerca SKU è `GET /admin/proforma/prodotto?sku=NK1001` (JSON, mai
  offer_price). Il numero viene dalla stessa serie delle ricevute degli ordini
  (`receipt_counters`). Il PDF si controlla con `pdftotext`. Per provare
  l'invio email serve un SMTP locale: un sink Python minimale su
  `127.0.0.1:2525` che salva i messaggi in file, e nel `.env` `SMTP_HOST`,
  `SMTP_PORT=2525` (fuori produzione niente TLS). Il DB SQLite di sviluppo
  vuole la tabella `manual_receipts` (DDL in `tests/Support/TestDb.php`).
- Per fermare i server in background senza uccidere la propria shell:
  `pkill -f 'php -S 127.0.0.1:809[0-9]'` (il pattern tra parentesi non
  combacia con la riga di comando di pkill stesso).
- Browser: Playwright con `executablePath: '/opt/pw-browsers/chromium'`
  (`npm install playwright-core` in scratch).
